<?php
declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

/*
 * Approval history.
 *   ?record_id=N  full timeline of one record (any role that can see it)
 *   (no id)       recent approval activity within the caller's scope, paginated
 */

Request::allow('GET');

$actor = Auth::require();

$recordId = Request::query('record_id');
if ($recordId !== null) {
    if (!ctype_digit($recordId) || (int) $recordId < 1) {
        Response::validation(['record_id' => 'A valid record ID is required.']);
    }
    if (Records::findVisible($actor, (int) $recordId) === null) {
        Response::notFound('Record not found.');
    }
    Response::success(['items' => Approvals::history((int) $recordId)]);
}

$allowed = ['created_approved', 'created_draft', 'submitted', 'resubmitted', 'edited', 'approved', 'rejected'];
$action = Request::query('action');
$v = (new Validator(['action' => $action]))->in('action', 'Action', $allowed);
if ($v->fails()) {
    Response::validation($v->errors());
}
$actions = $action !== null ? [$action] : [];

$page = Request::queryInt('page', 1);
$perPage = Request::queryInt('per_page', 20, 1, 100);

Response::success([
    'items'      => Approvals::recentHistory($actor, $actions, $perPage, ($page - 1) * $perPage),
    'pagination' => pagination($page, $perPage, Approvals::countHistory($actor, $actions)),
]);
