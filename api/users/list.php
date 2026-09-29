<?php
declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

Request::allow('GET');

$actor = Auth::require([Users::SUPER_ADMIN, Users::ADMIN]);

$filters = [
    'role'       => Request::query('role'),
    'status'     => Request::query('status'),
    'search'     => Request::query('search'),
    'manager_id' => $actor['role'] === Users::SUPER_ADMIN ? Request::query('manager_id') : null,
];

$v = (new Validator($filters))
    ->in('role', 'Role', Users::assignableRoles($actor))
    ->in('status', 'Status', ['active', 'inactive'])
    ->string('search', 'Search', 0, 100)
    ->integer('manager_id', 'Manager');
if ($v->fails()) {
    Response::validation($v->errors());
}

$page = Request::queryInt('page', 1);
$perPage = Request::queryInt('per_page', 20, 1, 100);

[$items, $total] = Users::list($actor, $filters, $page, $perPage);

Response::success([
    'items'      => $items,
    'pagination' => pagination($page, $perPage, $total),
]);
