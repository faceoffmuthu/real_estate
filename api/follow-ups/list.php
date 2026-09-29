<?php
declare(strict_types=1);
require __DIR__ . '/../../bootstrap.php';
Request::allow('GET');
$actor = Auth::require();
$f = [];
foreach (['record_id','assigned_to','status','type','due_from','due_to'] as $key) $f[$key] = Request::query($key);
$v = (new Validator($f))->integer('record_id', 'Record')->integer('assigned_to', 'Assigned person')
    ->in('status', 'Status', CRM::STATUSES)->in('type', 'Type', CRM::TYPES)->date('due_from', 'From date')->date('due_to', 'To date');
if ($f['due_from'] && $f['due_to'] && $f['due_from'] > $f['due_to']) $v->add('due_to', 'To date must follow from date.');
if ($v->fails()) Response::validation($v->errors());
if ($f['record_id']) CRM::record($actor, $f['record_id']);
Response::success(CRM::list($actor, $f, Request::queryInt('page', 1), Request::queryInt('per_page', 20, 1, 100)) + ['summary' => CRM::summary($actor)]);
