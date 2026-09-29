<?php
declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

Request::allow('GET');

$actor = Auth::require();

// Scope: Super Admin sees everything, Admin sees self + managed Users, User sees self.
[$where, $params] = match ($actor['role']) {
    Users::SUPER_ADMIN => [['1 = 1'], []],
    Users::ADMIN       => [['a.user_id IN (SELECT id FROM users WHERE id = ? OR manager_id = ?)'], [$actor['id'], $actor['id']]],
    default            => [['a.user_id = ?'], [$actor['id']]],
};
$scopeWhere = implode(' AND ', $where);
$scopeParams = $params;

$filters = [
    'action'  => Request::query('action'),
    'user_id' => Request::query('user_id'),
    'search'  => Request::query('search'),
];
$v = (new Validator($filters))
    ->string('action', 'Action', 1, 60)
    ->integer('user_id', 'User')
    ->string('search', 'Search', 0, 100);
if ($v->fails()) {
    Response::validation($v->errors());
}

if ($filters['action'] !== null) {
    $where[] = 'a.action = ?';
    $params[] = $filters['action'];
}
if ($filters['user_id'] !== null) {
    $where[] = 'a.user_id = ?';
    $params[] = (int) $filters['user_id'];
}
if ($filters['search'] !== null) {
    $where[] = '(a.description LIKE ? OR u.name LIKE ?)';
    array_push($params, like_escape($filters['search']), like_escape($filters['search']));
}

$page = Request::queryInt('page', 1);
$perPage = Request::queryInt('per_page', 25, 1, 100);
$whereSql = implode(' AND ', $where);

$total = (int) Database::value(
    "SELECT COUNT(*) FROM activity_logs a LEFT JOIN users u ON u.id = a.user_id WHERE $whereSql",
    $params
);
$rows = Database::fetchAll(
    ActivityLogger::SELECT . " WHERE $whereSql ORDER BY a.created_at DESC, a.id DESC LIMIT ? OFFSET ?",
    [...$params, $perPage, ($page - 1) * $perPage]
);

// Distinct actions within the caller's scope, for the filter dropdown.
$actions = array_column(
    Database::fetchAll("SELECT DISTINCT a.action FROM activity_logs a WHERE $scopeWhere ORDER BY a.action", $scopeParams),
    'action'
);

Response::success([
    'items'      => array_map([ActivityLogger::class, 'format'], $rows),
    'pagination' => pagination($page, $perPage, $total),
    'actions'    => $actions,
]);
