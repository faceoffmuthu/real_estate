<?php
declare(strict_types=1);

/**
 * Token-based authentication.
 *
 * On login a random 256-bit token is issued in an HttpOnly cookie. Only its
 * SHA-256 hash is stored in `auth_tokens`, so a database leak does not leak
 * usable sessions. Every protected endpoint calls Auth::require().
 */
final class Auth
{
    private static ?array $user = null;
    private static ?int $tokenId = null;

    /**
     * Returns the authenticated user or stops with 401/403.
     * @param string[] $roles Allowed role slugs; empty = any authenticated user.
     */
    public static function require(array $roles = []): array
    {
        $user = self::user();
        if ($user === null) {
            Response::unauthorized();
        }
        if ($roles !== [] && !in_array($user['role'], $roles, true)) {
            ActivityLogger::log($user['id'], 'access.denied', 'Access denied to ' . self::endpoint(), null, null, [
                'endpoint' => self::endpoint(),
                'method'   => Request::method(),
                'role'     => $user['role'],
            ]);
            Response::forbidden();
        }
        return $user;
    }

    /** Resolves the current user from the session cookie (null when not logged in). */
    public static function user(): ?array
    {
        if (self::$user !== null) {
            return self::$user;
        }
        $token = $_COOKIE[Config::get('auth.cookie_name')] ?? '';
        if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }

        $row = Database::fetch(
            'SELECT t.id AS token_id, t.last_used_at, u.id, u.name, u.username, u.email, u.phone, u.status,
                    u.manager_id, u.last_login_at, u.created_at, r.slug AS role, r.name AS role_name
               FROM auth_tokens t
               JOIN users u ON u.id = t.user_id
               JOIN roles r ON r.id = u.role_id
              WHERE t.token_hash = ? AND t.revoked_at IS NULL AND t.expires_at > NOW() AND u.status = ?
              LIMIT 1',
            [hash('sha256', $token), 'active']
        );
        if ($row === null) {
            return null;
        }

        // Record usage at most once every 5 minutes to avoid a write per request.
        if ($row['last_used_at'] === null || strtotime($row['last_used_at'] . ' UTC') < time() - 300) {
            Database::execute('UPDATE auth_tokens SET last_used_at = NOW() WHERE id = ?', [(int) $row['token_id']]);
        }

        self::$tokenId = (int) $row['token_id'];
        unset($row['token_id'], $row['last_used_at']);
        $row['id'] = (int) $row['id'];
        $row['manager_id'] = $row['manager_id'] !== null ? (int) $row['manager_id'] : null;
        return self::$user = $row;
    }

    public static function issueToken(int $userId): void
    {
        $token = bin2hex(random_bytes(32));
        $hours = (int) Config::get('auth.session_hours');

        Database::insert(
            'INSERT INTO auth_tokens (user_id, token_hash, ip_address, user_agent, expires_at)
             VALUES (?, ?, ?, ?, NOW() + INTERVAL ? HOUR)',
            [$userId, hash('sha256', $token), Request::ip(), Request::userAgent(), $hours]
        );
        self::setCookie($token, time() + $hours * 3600);
    }

    public static function revokeCurrent(): void
    {
        if (self::$tokenId !== null) {
            Database::execute('UPDATE auth_tokens SET revoked_at = NOW() WHERE id = ?', [self::$tokenId]);
        }
        self::setCookie('', time() - 3600);
        self::$user = null;
        self::$tokenId = null;
    }

    /** Revokes every active session of a user, optionally keeping the current one. */
    public static function revokeAllFor(int $userId, bool $keepCurrent = false): void
    {
        $sql = 'UPDATE auth_tokens SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL';
        $params = [$userId];
        if ($keepCurrent && self::$tokenId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = self::$tokenId;
        }
        Database::execute($sql, $params);
    }

    public static function clearCookie(): void
    {
        self::setCookie('', time() - 3600);
    }

    private static function setCookie(string $value, int $expires): void
    {
        setcookie(Config::get('auth.cookie_name'), $value, [
            'expires'  => $expires,
            'path'     => Config::get('auth.cookie_path'),
            'secure'   => Config::get('auth.cookie_secure'),
            'httponly' => true,
            'samesite' => 'None',
        ]);
    }

    private static function endpoint(): string
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
        $pos = strpos($path, '/api/');
        return $pos === false ? $path : substr($path, $pos);
    }
}
