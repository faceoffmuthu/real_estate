<?php
declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

Request::allow('POST');

$actor = Auth::require();
$updated = Database::execute('UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL', [$actor['id']]);

Response::success(['updated' => $updated], 'All notifications marked as read');
