<?php
declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

Request::allow('GET');

$actor = Auth::require([Users::SUPER_ADMIN]);

$accounts = Database::fetch(
    "SELECT SUM(r.slug = 'admin')                           AS total_admins,
            SUM(r.slug = 'admin' AND u.status = 'active')   AS active_admins,
            SUM(r.slug = 'user')                            AS total_users,
            SUM(r.slug = 'user' AND u.status = 'active')    AS active_users,
            SUM(u.status = 'active')                        AS active_accounts,
            SUM(u.status = 'inactive')                      AS inactive_accounts,
            COUNT(*)                                        AS total_accounts
       FROM users u JOIN roles r ON r.id = u.role_id"
);

$activity = Database::fetch(
    "SELECT SUM(action = 'auth.login')        AS logins_24h,
            SUM(action = 'auth.login_failed') AS failed_logins_24h,
            SUM(action = 'access.denied')     AS denied_requests_24h,
            COUNT(*)                          AS events_24h
       FROM activity_logs
      WHERE created_at > NOW() - INTERVAL 24 HOUR"
);

$activeSessions = (int) Database::value(
    'SELECT COUNT(DISTINCT user_id) FROM auth_tokens WHERE revoked_at IS NULL AND expires_at > NOW()'
);

$newAccounts = Database::fetchAll(
    "SELECT u.id, u.name, u.email, u.status, u.created_at, r.slug AS role
       FROM users u JOIN roles r ON r.id = u.role_id
      WHERE r.slug IN ('admin', 'user')
      ORDER BY u.created_at DESC, u.id DESC LIMIT 5"
);

Response::success([
    'stats' => array_map('intval', array_merge($accounts, $activity, ['active_sessions' => $activeSessions])),
    'activity_trend'  => ActivityLogger::dailyCounts(),
    'recent_activity' => ActivityLogger::recent(),
    'records'         => Records::stats($actor),
    'approvals'       => [
        'recent_approved' => Approvals::recentHistory($actor, ['approved'], 5),
        'recent_rejected' => Approvals::recentHistory($actor, ['rejected'], 5),
    ],
    'recent_accounts' => array_map(static fn (array $u) => [
        'id'         => (int) $u['id'],
        'name'       => $u['name'],
        'email'      => $u['email'],
        'role'       => $u['role'],
        'status'     => $u['status'],
        'created_at' => iso_datetime($u['created_at']),
    ], $newAccounts),
]);
