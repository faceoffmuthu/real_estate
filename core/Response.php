<?php
declare(strict_types=1);

/**
 * Consistent JSON envelope for every endpoint:
 *   success: { success: true,  message, data }
 *   error:   { success: false, message, errors }
 */
final class Response
{
    public static function json(array $body, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    public static function success(mixed $data = null, string $message = 'OK', int $status = 200): never
    {
        self::json(['success' => true, 'message' => $message, 'data' => $data ?? new stdClass()], $status);
    }

    public static function error(string $message, int $status = 400, array $errors = []): never
    {
        self::json(['success' => false, 'message' => $message, 'errors' => $errors ?: new stdClass()], $status);
    }

    public static function validation(array $errors, string $message = 'Please correct the highlighted fields.'): never
    {
        self::error($message, 422, $errors);
    }

    public static function unauthorized(string $message = 'Authentication required. Please log in.'): never
    {
        self::error($message, 401);
    }

    public static function forbidden(string $message = 'You do not have permission to perform this action.'): never
    {
        self::error($message, 403);
    }

    public static function notFound(string $message = 'The requested record was not found.'): never
    {
        self::error($message, 404);
    }
}
