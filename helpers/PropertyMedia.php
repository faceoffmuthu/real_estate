<?php
declare(strict_types=1);

final class PropertyMedia
{
    private const IMAGE_TYPES = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
    private const VIDEO_TYPES = ['mp4' => 'video/mp4', 'webm' => 'video/webm'];

    public static function uploadedFiles(array $files): array
    {
        $result = [];
        foreach (['images' => 'image', 'videos' => 'video'] as $field => $kind) {
            if (!isset($files[$field])) continue;
            $f = $files[$field];
            if (!is_array($f['name'] ?? null)) $f = array_map(static fn ($v) => [$v], $f);
            foreach (($f['name'] ?? []) as $i => $name) $result[] = ['name' => $name, 'type' => $f['type'][$i] ?? '', 'tmp_name' => $f['tmp_name'][$i] ?? '', 'error' => $f['error'][$i] ?? UPLOAD_ERR_NO_FILE, 'size' => $f['size'][$i] ?? 0, 'kind' => $kind];
        }
        return $result;
    }

    public static function validateUploads(array $uploads): array
    {
        $errors = [];
        if (!$uploads) return ['media' => 'Add at least one property image or video.'];
        if (count($uploads) > 5) $errors['media'] = 'Add up to five files per save.';
        $counts = ['image' => 0, 'video' => 0];
        foreach ($uploads as $file) {
            $kind = $file['kind']; $counts[$kind]++;
            $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
            $types = $kind === 'image' ? self::IMAGE_TYPES : self::VIDEO_TYPES;
            $max = $kind === 'image' ? 8_388_608 : 104_857_600;
            $mime = is_file((string) $file['tmp_name']) ? (new finfo(FILEINFO_MIME_TYPE))->file((string) $file['tmp_name']) : false;
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name']) || !isset($types[$ext]) || $mime !== $types[$ext] || (int) $file['size'] < 1 || (int) $file['size'] > $max || ($kind === 'image' && @getimagesize($file['tmp_name']) === false)) $errors['media'] = 'Use valid JPG, PNG, WebP, MP4 or WebM files within the allowed size.';
        }
        if ($counts['image'] > 10 || $counts['video'] > 3) $errors['media'] = 'A property can have at most 10 images and 3 videos.';
        return $errors;
    }

    public static function purgeRecordFiles(int $recordId): void
    {
        $base = realpath(BASE_PATH . '/uploads/properties');
        $dir = realpath(BASE_PATH . '/uploads/properties/' . $recordId);
        if (!$base || !$dir || !str_starts_with($dir, $base . DIRECTORY_SEPARATOR)) return;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $item) $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        @rmdir($dir);
    }

    public static function list(int $recordId): array
    {
        return array_map(static function (array $row): array {
            $row['id'] = (int) $row['id']; $row['file_size'] = (int) $row['file_size'];
            $row['url'] = '/api/property-media/file.php?id=' . $row['id'];
            unset($row['file_path']);
            return $row;
        }, Database::fetchAll('SELECT id, record_id, media_type, file_name, mime_type, file_size, created_at FROM property_media WHERE record_id = ? ORDER BY id', [$recordId]));
    }

    public static function authorizeMutation(array $actor, int $recordId): array
    {
        Auth::require([Users::ADMIN, Users::USER]);
        $record = Records::findVisible($actor, $recordId);
        if ($record === null) Response::notFound('Record not found.');
        if (!Records::canEdit($actor, $record) || $record['record_status'] !== 'active' || Approvals::editMode($actor, $record) !== 'all') Response::forbidden();
        return $record;
    }

    public static function store(int $recordId, array $actor, array $upload, string $kind): array
    {
        if (!in_array($kind, ['image', 'video'], true)) Response::validation(['media' => 'Unsupported media type.']);
        $limitCount = $kind === 'image' ? 10 : 3;
        $count = (int) Database::value('SELECT COUNT(*) FROM property_media WHERE record_id = ? AND media_type = ?', [$recordId, $kind]);
        if ($count >= $limitCount) Response::validation(['media' => "A property can have at most {$limitCount} {$kind}s."]);
        $ext = strtolower(pathinfo((string) ($upload['name'] ?? ''), PATHINFO_EXTENSION));
        $types = $kind === 'image' ? self::IMAGE_TYPES : self::VIDEO_TYPES;
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file((string) ($upload['tmp_name'] ?? ''));
        $expected = $types[$ext] ?? null;
        $max = $kind === 'image' ? 8_388_608 : 104_857_600;
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($upload['tmp_name'] ?? '')) || !$expected || $mime !== $expected || (int) ($upload['size'] ?? 0) < 1 || (int) $upload['size'] > $max) Response::validation(['media' => 'The selected file type or size is not allowed.']);
        if ($kind === 'image' && @getimagesize($upload['tmp_name']) === false) Response::validation(['media' => 'The image file is invalid.']);
        $name = bin2hex(random_bytes(16)) . '.' . $ext;
        $relative = 'properties/' . $recordId . '/' . ($kind === 'image' ? 'images' : 'videos') . '/' . $name;
        $dir = BASE_PATH . '/uploads/properties/' . $recordId . '/' . ($kind === 'image' ? 'images' : 'videos');
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) throw new RuntimeException('Media directory unavailable.');
        $target = BASE_PATH . '/uploads/' . $relative;
        if (!move_uploaded_file($upload['tmp_name'], $target)) throw new RuntimeException('Media upload failed.');
        try {
            $id = Database::insert('INSERT INTO property_media (record_id, media_type, file_name, file_path, mime_type, file_size, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)', [$recordId, $kind, $name, $relative, $mime, (int) $upload['size'], (int) $actor['id']]);
        } catch (Throwable $e) { @unlink($target); throw $e; }
        return ['id' => $id, 'media_type' => $kind, 'file_name' => $name, 'mime_type' => $mime, 'file_size' => (int) $upload['size'], 'created_at' => gmdate('Y-m-d H:i:s'), 'url' => '/api/property-media/file.php?id=' . $id];
    }

    public static function delete(int $id): void
    {
        $row = Database::fetch('SELECT file_path FROM property_media WHERE id = ?', [$id]);
        if ($row === null) Response::notFound('Media was not found.');
        $base = realpath(BASE_PATH . '/uploads');
        $target = realpath(BASE_PATH . '/uploads/' . $row['file_path']);
        if (Database::execute('DELETE FROM property_media WHERE id = ?', [$id]) && $base && $target && str_starts_with($target, $base . DIRECTORY_SEPARATOR) && is_file($target)) @unlink($target);
    }

    public static function stream(int $id, array $actor): never
    {
        $row = Database::fetch('SELECT pm.* FROM property_media pm JOIN real_estate_records r ON r.id = pm.record_id WHERE pm.id = ?', [$id]);
        if ($row === null || Records::findVisible($actor, (int) $row['record_id']) === null) Response::notFound('Media was not found.');
        $base = realpath(BASE_PATH . '/uploads');
        $target = realpath(BASE_PATH . '/uploads/' . $row['file_path']);
        if (!$base || !$target || !str_starts_with($target, $base . DIRECTORY_SEPARATOR) || !is_file($target)) Response::notFound('Media was not found.');
        header('Content-Type: ' . $row['mime_type']); header('Content-Length: ' . filesize($target)); header('Content-Disposition: inline; filename="' . basename($row['file_name']) . '"'); header('X-Content-Type-Options: nosniff'); header('Cache-Control: private, no-store');
        readfile($target); exit;
    }
}
