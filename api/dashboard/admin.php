<?php
declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

Request::allow('GET');

$admin = Auth::require([Users::ADMIN]);

$team = Database::fetch(
    "SELECT COUNT(*)                                                    AS total_users,
            SUM(u.status = 'active')                                    AS active_users,
            SUM(u.status = 'inactive')                                  AS inactive_users,
            SUM(u.created_at > NOW() - INTERVAL 30 DAY)                 AS new_users_30d,
            SUM(u.last_login_at > NOW() - INTERVAL 7 DAY)               AS users_active_7d
       FROM users u JOIN roles r ON r.id = u.role_id
      WHERE r.slug = 'user' AND u.manager_id = ?",
    [$admin['id']]
);

// Activity scope: the Admin plus every User they manage.
$scope = 'a.user_id IN (SELECT id FROM users WHERE id = ? OR manager_id = ?)';
$scopeParams = [$admin['id'], $admin['id']];

$teamLogins = (int) Database::value(
    "SELECT COUNT(*) FROM activity_logs a WHERE $scope AND a.action = 'auth.login' AND a.created_at > NOW() - INTERVAL 7 DAY",
    $scopeParams
);

Response::success([
    'stats' => array_map('intval', array_merge($team, ['team_logins_7d' => $teamLogins])),
    'activity_trend'  => ActivityLogger::dailyCounts($scope, $scopeParams),
    'recent_activity' => ActivityLogger::recent($scope, $scopeParams),
    'records'         => Records::stats($admin),
    // Review queue preview (oldest first) + recent workflow events.
    'approvals'       => Records::paginate($admin, [
        'approval_status' => Approvals::PENDING,
        'created_by_role' => Users::USER,
        'record_status'   => 'active',
    ], 1, 5, 'r.submitted_at ASC, r.id ASC') + [
        'recent_activity' => Approvals::recentHistory($admin, ['submitted', 'resubmitted', 'approved', 'rejected'], 6),
    ],
]);
