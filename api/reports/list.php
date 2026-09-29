<?php
declare(strict_types=1);
require __DIR__ . '/../../bootstrap.php';
Request::allow('GET');
$actor = Auth::require([Users::SUPER_ADMIN, Users::ADMIN, Users::USER]);
$report = Request::query('report', 'properties');
if (!in_array($report, ['properties', 'approvals', 'followups', 'activity'], true)) Response::validation(['report' => 'Select a valid report.']);
$f = [
    'search' => Request::query('search'),
    'transaction_type' => Request::query('transaction_type'),
    'property_category' => Request::query('property_category'),
    'approval_status' => Request::query('approval_status'),
    'process_stage_id' => Request::query('process_stage_id'),
    'created_by' => Request::query('created_by'),
    'assigned_to' => Request::query('assigned_to'),
    'priority' => Request::query('priority'),
    'type' => Request::query('type'),
    'action' => Request::query('action'),
    'city' => Request::query('city'),
    'created_from' => Request::query('from'),
    'created_to' => Request::query('to'),
    'date_field' => $report === 'followups' ? 'due' : 'created',
];
$v = (new Validator($f))
    ->string('search', 'Search', 0, 100)
    ->in('transaction_type', 'Property type', ['rental', 'sale'])
    ->in('property_category', 'Property category', ['residential', 'commercial'])
    ->in('approval_status', 'Approval status', [...Approvals::STATUSES, ...CRM::STATUSES, 'all'])
    ->integer('process_stage_id', 'Business stage')->integer('created_by', 'Created by')
    ->integer('assigned_to', 'Assigned to')->in('priority', 'Priority', CRM::PRIORITIES)
    ->in('type', 'Follow-up type', CRM::TYPES)->string('action', 'Activity action', 0, 80)
    ->string('city', 'Location', 0, 100)->date('created_from', 'From date')->date('created_to', 'To date');
if (!$v->fails() && $f['created_from'] && $f['created_to'] && $f['created_from'] > $f['created_to']) $v->add('created_to', 'To date must be on or after the from date.');
if ($v->fails()) Response::validation($v->errors());
$page = Request::queryInt('page', 1);
$perPage = Request::queryInt('per_page', 25, 1, 100);
$sort = Request::query('sort', 'newest');
$direction = Request::query('direction', 'desc');
if (!in_array($sort, ['newest', 'oldest', 'reference', 'title', 'location', 'date'], true) || !in_array($direction, ['asc', 'desc'], true)) Response::validation(['sort' => 'Select a valid sort order.']);
$sortColumn = match ($sort) {
    'reference' => $report === 'activity' ? 'r.record_reference' : ($report === 'followups' ? 'r.record_reference' : 'r.record_reference'),
    'title' => $report === 'activity' ? 'a.description' : 'r.title',
    'location' => 'r.city',
    'date' => $report === 'followups' ? 'f.due_at' : ($report === 'activity' ? 'a.created_at' : 'r.created_at'),
    'oldest', 'newest' => $report === 'followups' ? 'f.created_at' : ($report === 'activity' ? 'a.created_at' : 'r.created_at'),
};
$tieId = $report === 'followups' ? 'f.id' : ($report === 'activity' ? 'a.id' : 'r.id');
$order = $sortColumn . ' ' . ($sort === 'newest' ? 'DESC' : ($sort === 'oldest' ? 'ASC' : strtoupper($direction))) . ", $tieId DESC";
$where = [];
$params = [];
$rowsSql = '';
$countSql = '';
if (in_array($report, ['properties', 'approvals'], true)) {
    [$scope, $scopeParams] = Records::scope($actor, 'r');
    $where[] = $scope;
    array_push($params, ...$scopeParams);
    foreach ([
        ['transaction_type', 'r.transaction_type'], ['property_category', 'r.property_category'], ['approval_status', 'r.approval_status'],
        ['process_stage_id', 'r.process_stage_id'], ['created_by', 'r.created_by'], ['assigned_to', 'r.assigned_to'], ['priority', 'r.priority'], ['city', 'r.city'],
    ] as [$key, $column]) {
        if ($f[$key] !== null && $f[$key] !== '' && !($key === 'approval_status' && $f[$key] === 'all')) {
            $where[] = "$column = ?"; $params[] = $f[$key];
        }
    }
    $dateField = $report === 'approvals' ? 'r.submitted_at' : 'r.created_at';
    if ($f['created_from']) { $where[] = "$dateField >= ?"; $params[] = $f['created_from'] . ' 00:00:00'; }
    if ($f['created_to']) { $where[] = "$dateField < DATE_ADD(?, INTERVAL 1 DAY)"; $params[] = $f['created_to'] . ' 00:00:00'; }
    if ($f['search'] !== null) {
        $like = like_escape($f['search']);
        $where[] = '(r.record_reference LIKE ? OR r.title LIKE ? OR p.name LIKE ? OR p.email LIKE ? OR r.city LIKE ? OR r.locality LIKE ?)';
        array_push($params, $like, $like, $like, $like, $like, $like);
    }
    $whereSql = implode(' AND ', $where);
    $rowsSql = "SELECT r.id, r.record_reference AS reference, r.title, r.transaction_type, r.property_category, p.name AS party,
                       r.city, r.locality, r.area_sqft, r.priority, r.approval_status, r.created_at, r.submitted_at, r.reviewed_at,
                       r.rejection_reason, s.name AS business_stage, creator.name AS created_by, reviewer.name AS reviewed_by, assignee.name AS assigned_to
                  FROM real_estate_records r JOIN parties p ON p.id = r.party_id
                  JOIN record_process_stages s ON s.id = r.process_stage_id
                  JOIN users creator ON creator.id = r.created_by
                  LEFT JOIN users reviewer ON reviewer.id = r.reviewed_by
                  LEFT JOIN users assignee ON assignee.id = r.assigned_to
                 WHERE $whereSql";
    $countSql = "SELECT COUNT(*) FROM real_estate_records r JOIN parties p ON p.id = r.party_id WHERE $whereSql";
} elseif ($report === 'followups') {
    [$scope, $scopeParams] = Records::scope($actor, 'r');
    $where[] = $scope; array_push($params, ...$scopeParams);
    foreach ([['assigned_to', 'f.assigned_to'], ['city', 'r.city'], ['process_stage_id', 'r.process_stage_id'], ['created_by', 'r.created_by'], ['priority', 'r.priority']] as [$key, $column]) {
        if ($f[$key] !== null && $f[$key] !== '') { $where[] = "$column = ?"; $params[] = $f[$key]; }
    }
    if ($f['type']) { $where[] = 'f.type = ?'; $params[] = $f['type']; }
    if ($f['approval_status'] !== null && $f['approval_status'] !== '' && $f['approval_status'] !== 'all') { $where[] = 'f.status = ?'; $params[] = $f['approval_status']; }
    if ($f['transaction_type']) { $where[] = 'r.transaction_type = ?'; $params[] = $f['transaction_type']; }
    if ($f['property_category']) { $where[] = 'r.property_category = ?'; $params[] = $f['property_category']; }
    if ($f['created_from']) { $where[] = 'f.due_at >= ?'; $params[] = $f['created_from'] . ' 00:00:00'; }
    if ($f['created_to']) { $where[] = 'f.due_at < DATE_ADD(?, INTERVAL 1 DAY)'; $params[] = $f['created_to'] . ' 00:00:00'; }
    if ($f['search'] !== null) { $like = like_escape($f['search']); $where[] = '(r.record_reference LIKE ? OR r.title LIKE ? OR p.name LIKE ? OR assigned.name LIKE ?)'; array_push($params, $like, $like, $like, $like); }
    $whereSql = implode(' AND ', $where);
    $rowsSql = "SELECT f.id, f.due_at, f.type, f.status, f.notes, f.created_at, r.record_reference AS reference, r.title,
                       r.transaction_type, r.property_category, p.name AS party, assigned.name AS assigned_to, creator.name AS created_by
                  FROM follow_ups f JOIN real_estate_records r ON r.id = f.record_id JOIN parties p ON p.id = r.party_id
                  JOIN users assigned ON assigned.id = f.assigned_to JOIN users creator ON creator.id = f.created_by WHERE $whereSql";
    $countSql = "SELECT COUNT(*) FROM follow_ups f JOIN real_estate_records r ON r.id = f.record_id JOIN parties p ON p.id = r.party_id JOIN users assigned ON assigned.id = f.assigned_to WHERE $whereSql";
} else {
    [$scope, $scopeParams] = Records::scope($actor, 'r');
    $recordJoin = "LEFT JOIN real_estate_records r ON a.entity_type = 'record' AND r.id = a.entity_id";
    if ($actor['role'] === Users::SUPER_ADMIN) {
        $where[] = "a.action IN ('record.created','record.updated','record.submitted','record.approved','record.rejected','record.resubmitted','follow_up.created','follow_up.updated','follow_up.completed','follow_up.cancelled','auth.login','auth.logout','auth.password_changed','auth.reset_completed')";
    } elseif ($actor['role'] === Users::ADMIN) {
        $where[] = "(a.user_id IN (SELECT id FROM users WHERE id = ? OR manager_id = ?) OR (r.id IS NOT NULL AND $scope))";
        array_push($params, (int) $actor['id'], (int) $actor['id'], ...$scopeParams);
    } else {
        $where[] = "(a.user_id = ? OR (r.id IS NOT NULL AND $scope))";
        array_push($params, (int) $actor['id'], ...$scopeParams);
    }
    if ($f['created_from']) { $where[] = 'a.created_at >= ?'; $params[] = $f['created_from'] . ' 00:00:00'; }
    if ($f['created_to']) { $where[] = 'a.created_at < DATE_ADD(?, INTERVAL 1 DAY)'; $params[] = $f['created_to'] . ' 00:00:00'; }
    if ($f['search'] !== null) { $like = like_escape($f['search']); $where[] = '(a.action LIKE ? OR a.description LIKE ? OR u.name LIKE ? OR r.record_reference LIKE ?)'; array_push($params, $like, $like, $like, $like); }
    if ($f['action']) { $where[] = 'a.action = ?'; $params[] = $f['action']; }
    $whereSql = implode(' AND ', $where);
    $rowsSql = "SELECT a.id, a.action, a.description, a.created_at, a.entity_type, r.record_reference AS reference, u.name AS performed_by
                  FROM activity_logs a JOIN users u ON u.id = a.user_id $recordJoin WHERE $whereSql";
    $countSql = "SELECT COUNT(*) FROM activity_logs a JOIN users u ON u.id = a.user_id $recordJoin WHERE $whereSql";
}
$total = (int) Database::value($countSql, $params);
if (Request::query('format') === 'csv') {
    $rows = Database::fetchAll("$rowsSql ORDER BY $order LIMIT 10000", $params);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="n-real-estate-' . $report . '-report.csv"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'wb');
    if ($rows !== []) {
        fputcsv($out, array_keys($rows[0]));
        foreach ($rows as $row) fputcsv($out, array_map(static fn ($value) => is_string($value) ? preg_replace('/^[=+@\-\t\r]/u', "'", $value) : $value, array_values($row)));
    }
    fclose($out);
    exit;
}
$items = Database::fetchAll("$rowsSql ORDER BY $order LIMIT ? OFFSET ?", [...$params, $perPage, ($page - 1) * $perPage]);
Response::success(['items' => $items, 'pagination' => pagination($page, $perPage, $total), 'export_limit' => 10000]);
