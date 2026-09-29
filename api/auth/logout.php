<?php
declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

Request::allow('POST');

$user = Auth::user();
if ($user !== null) {
    Auth::revokeCurrent();
    ActivityLogger::log($user['id'], 'auth.logout', 'Logged out', 'user', $user['id']);
} else {
    Auth::clearCookie();
}

Response::success(null, 'Logged out successfully');
