<?php
declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

Request::allow('POST');

$data = Request::body();
$v = (new Validator($data))
    ->required('login', 'Email or username')->string('login', 'Email or username', 1, 190)
    ->required('password', 'Password')->string('password', 'Password', 1, 255);
if ($v->fails()) {
    Response::validation($v->errors());
}

$login = trim($data['login']);
$password = (string) $data['password'];

// Brute-force protection: limit failed attempts per IP address.
$failed = (int) Database::value(
    'SELECT COUNT(*) FROM activity_logs
      WHERE action = ? AND ip_address = ? AND created_at > NOW() - INTERVAL ? MINUTE',
    ['auth.login_failed', Request::ip(), Config::get('auth.lockout_minutes')]
);
if ($failed >= Config::get('auth.max_failed_logins')) {
    Response::error('Too many failed login attempts. Please try again in ' . Config::get('auth.lockout_minutes') . ' minutes.', 429);
}

$user = Database::fetch(
    'SELECT u.id, u.password_hash, u.status FROM users u WHERE u.email = ? OR u.username = ? LIMIT 1',
    [$login, $login]
);

// Always run a hash verification so response time does not reveal whether the account exists.
$valid = password_verify($password, $user['password_hash'] ?? '$2y$10$' . str_repeat('a', 53)) && $user !== null;

if (!$valid) {
    $userId = $user !== null ? (int) $user['id'] : null;
    ActivityLogger::log($userId, 'auth.login_failed', 'Failed login attempt', 'user', $userId, ['login' => mb_substr($login, 0, 190)]);
    Response::error('Invalid email/username or password.', 401);
}

$userId = (int) $user['id'];

if ($user['status'] !== 'active') {
    ActivityLogger::log($userId, 'auth.login_blocked', 'Login blocked: account inactive', 'user', $userId);
    Response::error('Your account is inactive. Please contact your administrator.', 403);
}

if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
    Database::execute('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $userId]);
}

Auth::issueToken($userId);
Database::execute('UPDATE users SET last_login_at = NOW() WHERE id = ?', [$userId]);
ActivityLogger::log($userId, 'auth.login', 'Logged in', 'user', $userId);

Response::success(['user' => Users::format(Users::find($userId))], 'Login successful');
