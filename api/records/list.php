<?php
declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

Request::allow('GET');

$actor = Auth::require();

$filters = [
    'assigned_to' => Request::query('assigned_to'),
    'priority' => Request::query('priority'),
    'follow_up_status' => Request::query('follow_up_status'),
    'search'           => Request::query('search'),
    'property_type_id' => Request::query('property_type_id'),
    'transaction_type' => Request::query('transaction_type'),
    'property_category' => Request::query('property_category'),
    'process_stage_id' => Request::query('process_stage_id'),
    'approval_status'  => Request::query('approval_status'),
    'created_by'       => Request::query('created_by'),
    'city'             => Request::query('city'),
    'created_from'     => Request::query('created_from'),
    'created_to'       => Request::query('created_to'),
    'record_status'    => Request::query('record_status', 'active'),
];
Records::validateFilters($filters);

$page = Request::queryInt('page', 1);
$perPage = Request::queryInt('per_page', 20, 1, 100);
$sorts = ['newest' => 'r.created_at DESC, r.id DESC', 'oldest' => 'r.created_at ASC, r.id ASC', 'title' => 'r.title ASC, r.id ASC', 'priority' => 'r.priority DESC, r.id DESC'];
$sort = Request::query('sort', 'newest');
if (!isset($sorts[$sort])) Response::validation(['sort' => 'Select a valid sort order.']);
$result = Records::paginate($actor, $filters, $page, $perPage, $sorts[$sort]);

// Distinct cities in the actor's scope, for the location filter.
[$scope, $scopeParams] = Records::scope($actor);
$cities = array_column(
    Database::fetchAll("SELECT DISTINCT r.city FROM real_estate_records r WHERE $scope ORDER BY r.city LIMIT 200", $scopeParams),
    'city'
);

// Record creators within scope, for the "Created by" filter (Admin / Super Admin).
$creators = [];
if ($actor['role'] !== Users::USER) {
    $creators = array_map(static fn (array $u) => ['id' => (int) $u['id'], 'name' => $u['name']], Database::fetchAll(
        "SELECT DISTINCT u.id, u.name FROM real_estate_records r JOIN users u ON u.id = r.created_by WHERE $scope ORDER BY u.name LIMIT 200",
        $scopeParams
    ));
}

Response::success($result + [
    'cities'     => $cities,
    'assignees' => array_map(static fn ($u) => ['id' => (int) $u['id'], 'name' => $u['name']], Database::fetchAll("SELECT DISTINCT u.id, u.name FROM real_estate_records r JOIN users u ON u.id = r.assigned_to WHERE $scope ORDER BY u.name LIMIT 200", $scopeParams)),
    'creators'   => $creators,
    'can_create' => in_array($actor['role'], [Users::ADMIN, Users::USER], true),
]);
