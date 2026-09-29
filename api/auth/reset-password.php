<?php
declare(strict_types=1);
require __DIR__ . '/../../bootstrap.php';
Request::allow('POST');
$d = Request::body();
PasswordRecovery::validatePassword($d);
PasswordRecovery::throttle('auth.reset_attempt', 30);
if (!is_string($d['token'] ?? null) || !PasswordRecovery::reset($d['token'], $d['password'])) {
    Response::error('Invalid or expired reset link. Please request a new link.', 422);
}
Auth::clearCookie();
Response::success(null, 'Password reset successfully.');
