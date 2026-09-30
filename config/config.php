<?php
declare(strict_types=1);

/*
 * Central configuration. All values come from backend/.env so that
 * credentials are never hardcoded in the codebase.
 */
return [
    'mail' => [
        'host' => Env::get('SMTP_HOST', ''),
        'port' => (int) Env::get('SMTP_PORT', '587'),
        'encryption' => Env::get('SMTP_ENCRYPTION', 'tls'),
        'username' => Env::get('SMTP_USERNAME', ''),
        'password' => Env::get('SMTP_PASSWORD', ''),
        'from' => Env::get('MAIL_FROM_EMAIL', ''),
        'from_name' => Env::get('MAIL_FROM_NAME', 'N Real Estate'),
        'frontend_url' => Env::get('FRONTEND_URL', 'http://localhost:5173'),
    ],
    'app' => [
        'env' => Env::get('APP_ENV', 'production'),
    ],

    'db' => [
        'host' => Env::get('DB_HOST', '127.0.0.1'),
        'port' => (int) Env::get('DB_PORT', '3306'),
        'name' => Env::get('DB_NAME', 'real_estate_crm'),
        'user' => Env::get('DB_USER', 'root'),
        'pass' => Env::get('DB_PASS', ''),
    ],

    'auth' => [
        'cookie_name'       => Env::get('AUTH_COOKIE_NAME', 'recrm_session'),
        'session_hours'     => max(1, (int) Env::get('AUTH_SESSION_HOURS', '8')),
        'cookie_secure'     => Env::bool('AUTH_COOKIE_SECURE', false),
        'cookie_path'       => Env::get('AUTH_COOKIE_PATH', '/'),
        'max_failed_logins' => max(1, (int) Env::get('AUTH_MAX_FAILED_LOGINS', '10')),
        'lockout_minutes'   => max(1, (int) Env::get('AUTH_LOCKOUT_MINUTES', '15')),
    ],

    'cors' => [
    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(
            ',',
            Env::get('CORS_ALLOWED_ORIGINS', 'http://localhost:5173')
        )
    ))),
],
];
