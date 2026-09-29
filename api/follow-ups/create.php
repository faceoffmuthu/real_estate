<?php
declare(strict_types=1);
require __DIR__ . '/../../bootstrap.php';
Request::allow('POST');
$actor = Auth::require([Users::ADMIN, Users::USER]);
$d = Request::body();
$r = CRM::record($actor, $d['record_id'] ?? null, true);
$v = (new Validator($d))->required('type', 'Type')->in('type', 'Type', CRM::TYPES)->string('notes', 'Notes', 0, 2000);
if ($v->fails()) Response::validation($v->errors());
$due = CRM::dueDate($d);
$assigned = CRM::assignee($actor, $r, $d['assigned_to'] ?? $actor['id']);
$id = Database::insert('INSERT INTO follow_ups (record_id, assigned_to, due_at, type, notes, created_by) VALUES (?, ?, ?, ?, ?, ?)',
    [$r['id'], $assigned, $due, $d['type'], trim($d['notes'] ?? ''), $actor['id']]);
ActivityLogger::log($actor['id'], 'follow_up.created', 'Scheduled ' . str_replace('_', ' ', $d['type']) . ' follow-up', 'record', (int) $r['id'], ['follow_up_id' => $id, 'due_at' => iso_datetime($due)]);
Response::success(['id' => $id], 'Follow-up scheduled.', 201);
