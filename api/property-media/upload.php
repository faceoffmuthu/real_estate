<?php
declare(strict_types=1);
require __DIR__ . '/../../bootstrap.php';
Request::allow('POST');
$actor = Auth::require([Users::ADMIN, Users::USER]);
$recordId = filter_var($_POST['record_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$recordId) Response::validation(['record_id' => 'A valid record ID is required.']);
PropertyMedia::authorizeMutation($actor, (int) $recordId);
$items = [];
foreach (['images' => 'image', 'videos' => 'video'] as $field => $kind) {
    if (!isset($_FILES[$field])) continue;
    $files = $_FILES[$field];
    if (!is_array($files['name'] ?? null)) $files = array_map(static fn($v) => [$v], $files);
    foreach ($files['name'] as $i => $name) {
        $items[] = [$kind, ['name' => $name, 'type' => $files['type'][$i] ?? '', 'tmp_name' => $files['tmp_name'][$i] ?? '', 'error' => $files['error'][$i] ?? UPLOAD_ERR_NO_FILE, 'size' => $files['size'][$i] ?? 0]];
    }
}
if (!$items || count($items) > 5) Response::validation(['media' => 'Select between one and five files per upload.']);
$added = [];
foreach ($items as [$kind, $upload]) $added[] = PropertyMedia::store((int) $recordId, $actor, $upload, $kind);
Response::success(['items' => $added], 'Media uploaded.');
