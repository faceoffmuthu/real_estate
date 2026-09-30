<?php
declare(strict_types=1);

/**
 * CORS + baseline security headers + CSRF guard.
 *
 * Auth uses an HttpOnly SameSite=Strict cookie. As an extra CSRF defence,
 * state-changing requests must be JSON: a plain HTML form cannot send
 * `Content-Type: application/json` cross-site without a CORS preflight.
 */
final class Cors
{
    public static function handle(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: same-origin');
        header('Cache-Control: no-store');

        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $allowed = Config::get('cors.allowed_origins', []);
        if (empty($allowed)) {
            $allowed = ['http://localhost:5173', 'http://127.0.0.1:5173'];
        }
        
        if ($origin !== '' && in_array($origin, $allowed, true)) {
            header("Access-Control-Allow-Origin: $origin");
            header('Access-Control-Allow-Credentials: true');
            header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type, Accept');
            header('Access-Control-Max-Age: 600');
            header('Vary: Origin');
        }

        if (Request::method() === 'OPTIONS') {
            http_response_code(204);
            exit;
        }

        if (Request::isStateChanging()) {
            $contentType = strtolower($_SERVER['CONTENT_TYPE'] ?? '');
            $requestPath = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '');
            $isMediaUpload = str_starts_with($contentType, 'multipart/form-data')
                && (str_ends_with($requestPath, '/property-media/upload.php') || str_ends_with($requestPath, '/records/create.php'));
            if ($isMediaUpload) {
                // Multipart forms are a browser-simple content type. Require an explicit
                // same-origin/allow-listed Origin before accepting this upload endpoint.
                $sameOrigin = self::allowedOrigin($origin);
                if ($origin === '' || !$sameOrigin) Response::error('Cross-origin media uploads are not allowed.', 403);
            } elseif (!str_starts_with($contentType, 'application/json')) {
                Response::error('Requests must be sent as JSON (Content-Type: application/json).', 415);
            }
        }
    }

    private static function allowedOrigin(string $origin): bool
    {
        if (in_array($origin, Config::get('cors.allowed_origins', []), true)) return true;
        $originParts = parse_url($origin);
        if (!is_array($originParts) || !isset($originParts['scheme'], $originParts['host'])) return false;
        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        $requestHost = strtolower((string) ($originParts['host'] ?? '') . (isset($originParts['port']) ? ':' . $originParts['port'] : ''));
        $requestScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        return $requestHost === $host && strtolower($originParts['scheme']) === $requestScheme;
    }
}
