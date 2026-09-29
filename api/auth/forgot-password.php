<?php
declare(strict_types=1);
require __DIR__ . '/../../bootstrap.php';
Request::allow('POST');
$d = Request::body();
$v = (new Validator($d))->required('email', 'Email')->string('email', 'Email', 3, 190)->email('email');
if ($v->fails()) Response::validation($v->errors());
PasswordRecovery::throttle('auth.forgot_attempt');
PasswordRecovery::request(strtolower(trim($d['email'])));
Response::success(null, 'If an active account exists for this email, a reset link will be sent. Please check your inbox.');
