<?php
declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

[$actor, $record, $body] = ApprovalRequest::load('reject');

$v = (new Validator($body))
    ->required('reason', 'Rejection reason')
    ->string('reason', 'Rejection reason', 5, 1000);
if ($v->fails()) {
    Response::validation($v->errors());
}

if (Approvals::apply($actor, $record, 'reject', trim($body['reason'])) === null) {
    Response::error('Record cannot be rejected in its current status.', 409);
}

Response::success(['record' => Records::format(Records::findVisible($actor, (int) $record['id']))], 'Record rejected');
