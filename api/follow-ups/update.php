<?php
declare(strict_types=1);
require __DIR__ . '/../../bootstrap.php';
Request::allow('PUT');
$actor = Auth::require([Users::ADMIN, Users::USER]);
$d = Request::body();
$v = (new Validator($d))->required('id', 'Follow-up')->integer('id', 'Follow-up')
    ->required('status', 'Status')->in('status', 'Status', CRM::STATUSES)->in('type', 'Type', CRM::TYPES)->string('notes', 'Notes', 0, 2000);
if ($v->fails()) Response::validation($v->errors());
$f = Database::fetch('SELECT * FROM follow_ups WHERE id = ?', [(int) $d['id']]);
if (!$f) Response::notFound();
$r = CRM::record($actor, $f['record_id'], true);
if ($actor['role'] === Users::USER && (int) $f['assigned_to'] !== $actor['id']) Response::forbidden();
$due = array_key_exists('due_at', $d) ? CRM::dueDate($d) : $f['due_at'];
$assigned = array_key_exists('assigned_to', $d) ? CRM::assignee($actor, $r, $d['assigned_to']) : (int) $f['assigned_to'];
Database::execute("UPDATE follow_ups SET due_at = ?, assigned_to = ?, type = ?, notes = ?, status = ?, completed_at = IF(? = 'completed', COALESCE(completed_at, NOW()), NULL),
    reminded_at = IF(due_at <> ? OR ? <> 'pending' OR status <> 'pending', NULL, reminded_at) WHERE id = ?",
    [$due, $assigned, $d['type'] ?? $f['type'], $d['notes'] ?? $f['notes'], $d['status'], $d['status'], $f['due_at'], $f['status'], $f['id']]);
ActivityLogger::log($actor['id'], 'follow_up.' . ($d['status'] === 'pending' ? 'updated' : $d['status']), 'Follow-up ' . $d['status'], 'record', (int) $r['id'], ['follow_up_id' => (int) $f['id']]);
Response::success(null, 'Follow-up updated.');
