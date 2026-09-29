<?php
declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

Request::allow('GET');

$actor = Auth::require([Users::SUPER_ADMIN, Users::ADMIN]);

$id = Request::queryInt('id', 0, 0);
if ($id === 0) {
    Response::validation(['id' => 'A valid user ID is required.']);
}

$target = Users::find($id);
if ($target === null) {
    Response::notFound('User not found.');
}
if (!Users::canManage($actor, $target)) {
    Response::forbidden('You do not have permission to view this account.');
}

$user = Users::format($target);
if ($target['role'] === Users::ADMIN) {
    $user['managed_users_count'] = (int) Database::value('SELECT COUNT(*) FROM users WHERE manager_id = ?', [$id]);
}

Response::success([
    'user'            => $user,
    // Actions performed by the account and actions performed on it.
    'recent_activity' => ActivityLogger::recent("(a.user_id = ? OR (a.entity_type = 'user' AND a.entity_id = ?))", [$id, $id], 10),
]);
