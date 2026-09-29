<?php
declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

Request::allow('POST');

$actor = Auth::require();
$ids = Request::body()['ids'] ?? null;
if (!is_array($ids) || $ids === [] || count($ids) > 100
    || array_filter($ids, static fn ($id) => filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false)) {
    Response::validation(['ids' => 'Provide 1-100 notification IDs.']);
}
$ids = array_map('intval', $ids);

// Only the caller's own notifications are affected.
$placeholders = implode(', ', array_fill(0, count($ids), '?'));
$updated = Database::execute(
    "UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL AND id IN ($placeholders)",
    [$actor['id'], ...$ids]
);

Response::success(['updated' => $updated], 'Notifications marked as read');
