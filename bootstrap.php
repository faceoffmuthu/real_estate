<?php
declare(strict_types=1);

/*
 * Loaded by every API endpoint and CLI script. Sets up configuration, error
 * handling and (for HTTP requests) CORS / security headers.
 */

define('BASE_PATH', __DIR__);

require BASE_PATH . '/core/Env.php';
require BASE_PATH . '/core/Config.php';
require BASE_PATH . '/core/Database.php';
require BASE_PATH . '/core/Response.php';
require BASE_PATH . '/core/Request.php';
require BASE_PATH . '/core/Validator.php';
require BASE_PATH . '/helpers/functions.php';
require BASE_PATH . '/helpers/ActivityLogger.php';
require BASE_PATH . '/helpers/Users.php';
require BASE_PATH . '/helpers/Phone.php';
require BASE_PATH . '/helpers/Parties.php';
require BASE_PATH . '/helpers/Records.php';
require BASE_PATH . '/helpers/MapLookup.php';
require BASE_PATH . '/helpers/PropertyTypes.php';
require BASE_PATH . '/helpers/Approvals.php';
require BASE_PATH . '/helpers/Notifications.php';
require BASE_PATH . '/helpers/ApprovalRequest.php';
require BASE_PATH . '/helpers/Mailer.php';
require BASE_PATH . '/helpers/PasswordRecovery.php';
require BASE_PATH . '/helpers/CRM.php';
require BASE_PATH . '/helpers/PropertyMedia.php';
require BASE_PATH . '/helpers/IndiaLocations.php';
require BASE_PATH . '/middleware/Cors.php';
require BASE_PATH . '/middleware/Auth.php';

Env::load(BASE_PATH . '/.env');
Config::load(require BASE_PATH . '/config/config.php');

date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');

// Never show PHP errors to clients; log them instead.
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('zend.exception_ignore_args', '1');
ini_set('error_log', BASE_PATH . '/storage/logs/php-error.log');

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

if (PHP_SAPI === 'cli') {
    ini_set('display_errors', 'stderr'); // CLI scripts (setup, tests) report errors directly
    return;
}

set_exception_handler(static function (Throwable $e): void {
    error_log((string) $e);
    $message = $e instanceof PDOException
        ? 'A database error occurred. Please try again later.'
        : 'An unexpected server error occurred. Please try again later.';
    Response::error($message, 500);
});

Cors::handle();
