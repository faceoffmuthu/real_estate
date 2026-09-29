<?php
declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

Request::allow('GET');

$actor = Auth::require();

$search = Request::query('search');
$v = (new Validator(['search' => $search]))->string('search', 'Search', 0, 100);
if ($v->fails()) {
    Response::validation($v->errors());
}

[$where, $params] = Parties::visibility($actor);
$where = [$where];
if ($search !== null) {
    $conditions = ['p.name LIKE ?', 'p.email LIKE ?'];
    $params = [...$params, like_escape($search), like_escape($search)];
    $digits = Phone::digits($search);
    if (strlen($digits) >= 3) {
        $conditions[] = 'p.phone LIKE ?';
        $conditions[] = 'p.alt_phone LIKE ?';
        array_push($params, like_escape($digits), like_escape($digits));
    }
    $where[] = '(' . implode(' OR ', $conditions) . ')';
}
$whereSql = implode(' AND ', $where);

$page = Request::queryInt('page', 1);
$perPage = Request::queryInt('per_page', 20, 1, 100);

$total = (int) Database::value("SELECT COUNT(*) FROM parties p WHERE $whereSql", $params);
[$select, $selectParams] = Parties::select($actor);
$rows = Database::fetchAll(
    "$select WHERE $whereSql ORDER BY p.created_at DESC, p.id DESC LIMIT ? OFFSET ?",
    [...$selectParams, ...$params, $perPage, ($page - 1) * $perPage]
);

Response::success([
    'items'      => array_map(static fn (array $row) => Parties::format($row), $rows),
    'pagination' => pagination($page, $perPage, $total),
]);
