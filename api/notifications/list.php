<?php
declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

/*
 * The caller's own notifications (newest first) plus counters used by the
 * header bell and the Admin sidebar badge. Polled by the frontend.
 */

Request::allow('GET');

$actor = Auth::require();
CRM::reminders($actor);
$limit = Request::queryInt('limit', 15, 1, 50);
$unreadOnly = Request::query('unread_only') === '1';

$rows = Database::fetchAll(
    'SELECT id, type, title, message, entity_type, entity_id, read_at, created_at
       FROM notifications WHERE user_id = ?' . ($unreadOnly ? ' AND read_at IS NULL' : '') . '
      ORDER BY created_at DESC, id DESC LIMIT ?',
    [$actor['id'], $limit]
);

$pendingApprovals = null;
if ($actor['role'] === Users::ADMIN) {
    [$scope, $params] = Records::scope($actor);
    $pendingApprovals = (int) Database::value(
        "SELECT COUNT(*) FROM real_estate_records r
          WHERE $scope AND r.approval_status = 'pending' AND r.created_by_role = 'user' AND r.record_status = 'active'",
        $params
    );
}

Response::success([
    'items'             => array_map([Notifications::class, 'format'], $rows),
    'unread_count'      => (int) Database::value('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL', [$actor['id']]),
    'pending_approvals' => $pendingApprovals,
]);
