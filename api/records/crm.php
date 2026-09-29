<?php
declare(strict_types=1);
require __DIR__ . '/../../bootstrap.php';
Request::allow('PUT');
$actor = Auth::require([Users::ADMIN, Users::USER]);
$d = Request::body();
$r = CRM::record($actor, $d['record_id'] ?? null, true);
$v = (new Validator($d))->required('priority', 'Priority')->in('priority', 'Priority', CRM::PRIORITIES)
    ->required('contact_status', 'Contact status')->in('contact_status', 'Contact status', CRM::CONTACTS)
    ->string('next_action', 'Next action', 0, 500);
if ($v->fails()) Response::validation($v->errors());
$assigned = empty($d['assigned_to']) ? null : CRM::assignee($actor, $r, $d['assigned_to']);
// Users cannot remove or change a manager's assignment.
if ($actor['role'] === Users::USER && $r['assigned_to'] !== null && (int) $r['assigned_to'] !== $actor['id']) $assigned = (int) $r['assigned_to'];
Database::execute('UPDATE real_estate_records SET priority = ?, contact_status = ?, next_action = ?, assigned_to = ?, updated_by = ? WHERE id = ?',
    [$d['priority'], $d['contact_status'], trim($d['next_action'] ?? ''), $assigned, $actor['id'], $r['id']]);
ActivityLogger::log($actor['id'], 'record.crm_updated', 'Updated CRM details', 'record', (int) $r['id']);
Response::success(null, 'CRM details saved.');
