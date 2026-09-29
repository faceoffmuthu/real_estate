<?php
declare(strict_types=1);
require __DIR__ . '/../../bootstrap.php';
Request::allow('POST');
$actor = Auth::require([Users::ADMIN, Users::USER]);
$id = filter_var(Request::body()['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$id) Response::validation(['id' => 'A valid media ID is required.']);
$row = Database::fetch('SELECT record_id FROM property_media WHERE id = ?', [$id]);
if ($row === null) Response::notFound('Media was not found.');
PropertyMedia::authorizeMutation($actor, (int) $row['record_id']);
if ((int) Database::value('SELECT COUNT(*) FROM property_media WHERE record_id = ?', [(int) $row['record_id']]) <= 1) {
    Response::validation(['media' => 'A property must keep at least one image or video. Add replacement media before removing this file.']);
}
PropertyMedia::delete($id);
Response::success(null, 'Media removed.');
