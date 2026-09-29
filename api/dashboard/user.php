<?php
declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

Request::allow('GET');

$user = Auth::require([Users::USER]);

$stats = Database::fetch(
    "SELECT SUM(action = 'auth.login')                          AS total_logins,
            SUM(created_at > NOW() - INTERVAL 30 DAY)           AS activity_30d,
            COUNT(*)                                            AS total_activity
       FROM activity_logs a
      WHERE a.user_id = ?",
    [$user['id']]
);

$account = Users::find($user['id']);
$accountAgeDays = (int) floor((time() - strtotime($account['created_at'] . ' UTC')) / 86400);

Response::success([
    'account' => Users::format($account),
    'stats'   => array_map('intval', array_merge($stats, ['account_age_days' => $accountAgeDays])),
    'activity_trend'  => ActivityLogger::dailyCounts('a.user_id = ?', [$user['id']]),
    'recent_activity' => ActivityLogger::recent('a.user_id = ?', [$user['id']]),
    'records'         => Records::stats($user),
    // Rejected records needing correction + recent workflow events on own records.
    'approvals'       => [
        'rejected'        => Records::paginate($user, ['approval_status' => Approvals::REJECTED, 'record_status' => 'active'], 1, 5, 'r.reviewed_at DESC')['items'],
        'recent_activity' => Approvals::recentHistory($user, ['submitted', 'resubmitted', 'approved', 'rejected'], 6),
    ],
]);
