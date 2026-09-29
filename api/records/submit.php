<?php
declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

/*
 * Submit a draft, or resubmit a rejected record, for Admin approval.
 * (One endpoint for both: the action follows from the current status.)
 */

Request::allow('POST');

$actor = Auth::require([Users::USER]);
$id = filter_var(Request::body()['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($id === false) {
    Response::validation(['id' => 'A valid record ID is required.']);
}

$record = Records::findVisible($actor, $id);
if ($record === null) {
    Response::notFound('Record not found.');
}
if ((int) $record['created_by'] !== $actor['id']) {
    Response::forbidden('Only the creator of this record can submit it.');
}
$action = Approvals::submitAction($record['approval_status']);
if ($action === null || Approvals::apply($actor, $record, $action) === null) {
    Response::error("Record cannot be submitted while it is {$record['approval_status']}.", 409);
}

$message = $action === 'resubmit' ? 'Record resubmitted for approval' : 'Record submitted for approval';
Response::success(['record' => Records::format(Records::findVisible($actor, $id))], $message);
