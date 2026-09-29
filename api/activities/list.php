<?php
declare(strict_types=1);
require __DIR__ . '/../../bootstrap.php';
Request::allow('GET');
$actor = Auth::require();
$r = CRM::record($actor, Request::query('record_id'));
$page = Request::queryInt('page', 1);
$perPage = Request::queryInt('per_page', 20, 1, 100);
$where = "a.entity_type = 'record' AND a.entity_id = ?";
$total = (int) Database::value("SELECT COUNT(*) FROM activity_logs a WHERE $where", [$r['id']]);
$rows = Database::fetchAll(ActivityLogger::SELECT . " WHERE $where ORDER BY a.created_at DESC, a.id DESC LIMIT ? OFFSET ?", [$r['id'], $perPage, ($page - 1) * $perPage]);
Response::success(['items' => array_map([ActivityLogger::class, 'format'], $rows), 'pagination' => pagination($page, $perPage, $total)]);
