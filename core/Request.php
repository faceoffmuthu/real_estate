<?php
declare(strict_types=1);

final class Request
{
    private static ?array $body = null;

    public static function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    /** Rejects the request with 405 unless the HTTP method is one of $methods. */
    public static function allow(string ...$methods): void
    {
        if (!in_array(self::method(), $methods, true)) {
            header('Allow: ' . implode(', ', $methods));
            Response::error('Method not allowed.', 405);
        }
    }

    /** Decoded JSON request body (always an array). */
    public static function body(): array
    {
        if (self::$body !== null) {
            return self::$body;
        }
        $raw = file_get_contents('php://input') ?: '';
        if (trim($raw) === '') {
            return self::$body = [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            Response::error('Request body must be a valid JSON object.', 400);
        }
        return self::$body = $decoded;
    }

    public static function query(string $key, ?string $default = null): ?string
    {
        $value = $_GET[$key] ?? null;
        if (!is_scalar($value)) {
            return $default;
        }
        $value = trim((string) $value);
        return $value === '' ? $default : $value;
    }

    public static function queryInt(string $key, int $default, int $min = 1, int $max = PHP_INT_MAX): int
    {
        $value = self::query($key);
        if ($value === null || !ctype_digit($value)) {
            return $default;
        }
        return max($min, min($max, (int) $value));
    }

    public static function ip(): string
    {
        return substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    }

    public static function userAgent(): string
    {
        return substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }

    public static function isStateChanging(): bool
    {
        return in_array(self::method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true);
    }
}
