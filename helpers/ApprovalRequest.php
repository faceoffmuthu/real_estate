<?php
declare(strict_types=1);

/**
 * Shared checks for approve.php / reject.php, in order:
 * authenticated Admin → valid id → record visible → reviewer allowed → valid transition.
 */
final class ApprovalRequest
{
    /** @return array{0: array, 1: array, 2: array} [actor, record, body] — responds with an error otherwise. */
    public static function load(string $action): array
    {
        Request::allow('POST');
        $actor = Auth::require([Users::ADMIN]);
        $body = Request::body();

        $id = filter_var($body['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            Response::validation(['id' => 'A valid record ID is required.']);
        }
        $record = Records::findVisible($actor, $id);
        if ($record === null) {
            Response::notFound('Record not found.');
        }
        if (!Approvals::canReview($actor, $record)) {
            Response::forbidden('You are not allowed to review this record.');
        }
        if (Approvals::next($record['approval_status'], $action) === null) {
            $verb = $action === 'approve' ? 'approved' : 'rejected';
            Response::error("Record cannot be $verb in its current status ({$record['approval_status']}).", 409);
        }
        return [$actor, $record, $body];
    }
}
