<?php
declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

Request::allow('GET');

$user = Auth::require();

$settings = [];
foreach (Database::fetchAll('SELECT setting_key, setting_value FROM settings WHERE is_public = 1') as $row) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

Response::success([
    'user'     => Users::format(Users::find($user['id'])),
    'settings' => $settings ?: new stdClass(),
]);
