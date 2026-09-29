<?php
declare(strict_types=1);
require __DIR__ . '/../../bootstrap.php';
Request::allow('POST');
$actor = Auth::require([Users::ADMIN, Users::USER]);
$d = Request::body();
$r = CRM::record($actor, $d['record_id'] ?? null, true);
$v = (new Validator($d))->required('type', 'Type')->in('type', 'Type', [...CRM::TYPES, 'note'])
    ->required('description', 'Description')->string('description', 'Description', 2, 255);
if ($v->fails()) Response::validation($v->errors());
ActivityLogger::log($actor['id'], 'crm.' . $d['type'], trim($d['description']), 'record', (int) $r['id']);
if ($d['type'] !== 'note' && $d['type'] !== 'other') Database::execute('UPDATE real_estate_records SET last_contacted_at = NOW(), contact_status = ? WHERE id = ?', ['contacted', $r['id']]);
Response::success(null, 'Interaction saved.', 201);
