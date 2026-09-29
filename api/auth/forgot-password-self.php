<?php
declare(strict_types=1);
require __DIR__ . '/../../bootstrap.php';
Request::allow('POST');
$user = Auth::require();
PasswordRecovery::throttle('auth.forgot_self_attempt');
$email = (string) Database::value('SELECT email FROM users WHERE id = ? AND status = \'active\'', [$user['id']]);
if ($email !== '') PasswordRecovery::request($email);
Response::success(null, 'If your account is eligible, a reset link will be sent to its registered email address.');
