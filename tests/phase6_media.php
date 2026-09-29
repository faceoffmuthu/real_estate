<?php
declare(strict_types=1);

section('Property media — authorization and persistence');
expectStatus('media upload requires authentication', http('POST', 'property-media/upload.php', [], null), 401);
expectStatus('super admin cannot upload media (records remain view-only)', http('POST', 'property-media/upload.php', [], $sa), 403);

function mediaUpload(string $recordId, string $token, string $path, string $name = 'pixel.png'): array
{
    global $BASE, $COOKIE;
    $ch = curl_init("$BASE/property-media/upload.php");
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ["Cookie: $COOKIE=$token", 'Accept: application/json', 'Origin: ' . (parse_url($BASE, PHP_URL_SCHEME) . '://' . parse_url($BASE, PHP_URL_HOST) . (parse_url($BASE, PHP_URL_PORT) ? ':' . parse_url($BASE, PHP_URL_PORT) : ''))],
        CURLOPT_POSTFIELDS => ['record_id' => $recordId, 'images[]' => new CURLFile($path, 'image/png', $name)],
    ]);
    $raw = curl_exec($ch); $size = curl_getinfo($ch, CURLINFO_HEADER_SIZE); $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
    $body = substr($raw, $size);
    return ['status' => $status, 'body' => json_decode($body, true), 'raw' => $body];
}

$pixel = tempnam(sys_get_temp_dir(), 'nre-media-');
file_put_contents($pixel, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/pN8AAAAASUVORK5CYII='));
try {
    $otherTeam = mediaUpload((string) $recAdmin['id'], $ad2, $pixel);
    check('other admin team cannot upload media to an unrelated record', $otherTeam['status'] === 404, $otherTeam['status'] . ' ' . substr($otherTeam['raw'], 0, 180));
    $forbiddenUser = mediaUpload((string) $draft, $us, $pixel);
    check('approved user cannot add media when only progress edits are allowed', $forbiddenUser['status'] === 403, $forbiddenUser['status'] . ' ' . substr($forbiddenUser['raw'], 0, 180));
    $created = mediaUpload((string) $recAdmin['id'], $ad, $pixel);
    check('authorized admin uploads a validated image', $created['status'] === 200 && count($created['body']['data']['items'] ?? []) === 1, $created['status'] . ' ' . substr($created['raw'], 0, 180));
    $media = $created['body']['data']['items'][0] ?? [];
    $id = (int) ($media['id'] ?? 0);
    $details = http('GET', 'records/view.php?id=' . $recAdmin['id'], null, $ad);
    check('record detail returns persisted media metadata', $id > 0 && in_array($id, array_map(static fn (array $item): int => (int) $item['id'], $details['body']['data']['media'] ?? []), true));
    $stream = http('GET', 'property-media/file.php?id=' . $id, null, $ad);
    check('authenticated record viewer can stream image with safe MIME', $stream['status'] === 200 && str_contains($stream['headers'], 'Content-Type: image/png') && hash_equals(hash_file('sha256', $pixel), hash('sha256', $stream['raw'])));
    expectStatus('other team cannot stream the file by guessing media ID', http('GET', 'property-media/file.php?id=' . $id, null, $ad2), 404);
    expectStatus('other team cannot delete media by guessing its ID', http('POST', 'property-media/delete.php', ['id' => $id], $ad2), 404);
    $path = (string) Database::value('SELECT file_path FROM property_media WHERE id = ?', [$id]);
    expectStatus('record owner team removes media', http('POST', 'property-media/delete.php', ['id' => $id], $ad), 200);
    check('media deletion removes database metadata and private file', Database::value('SELECT id FROM property_media WHERE id = ?', [$id]) === null && !file_exists(BASE_PATH . '/uploads/' . $path));
} finally {
    @unlink($pixel);
}
