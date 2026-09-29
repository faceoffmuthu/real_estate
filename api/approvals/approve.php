<?php
declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

[$actor, $record] = ApprovalRequest::load('approve');

if (Approvals::apply($actor, $record, 'approve') === null) {
    // Status changed between the check and the update (e.g. another review).
    Response::error('Record cannot be approved in its current status.', 409);
}

Response::success(['record' => Records::format(Records::findVisible($actor, (int) $record['id']))], 'Record approved successfully');
