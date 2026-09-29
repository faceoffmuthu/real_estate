<?php
declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

Request::allow('POST');

$user = Auth::require();
$data = Request::body();

$v = (new Validator($data))
    ->required('current_password', 'Current password')
    ->required('new_password', 'New password')->password('new_password')
    ->required('confirm_password', 'Password confirmation');

if (!$v->fails() && $data['new_password'] !== $data['confirm_password']) {
    $v->add('confirm_password', 'Passwords do not match.');
}

$hash = (string) Database::value('SELECT password_hash FROM users WHERE id = ?', [$user['id']]);
if (!$v->fails() && !password_verify((string) $data['current_password'], $hash)) {
    $v->add('current_password', 'Current password is incorrect.');
}
if (!$v->fails() && password_verify((string) $data['new_password'], $hash)) {
    $v->add('new_password', 'New password must be different from the current password.');
}
if ($v->fails()) {
    Response::validation($v->errors());
}

Database::execute('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($data['new_password'], PASSWORD_DEFAULT), $user['id']]);
Auth::revokeAllFor($user['id'], keepCurrent: true);
ActivityLogger::log($user['id'], 'auth.password_changed', 'Changed own password', 'user', $user['id']);

Response::success(null, 'Password updated successfully. Other sessions have been signed out.');
