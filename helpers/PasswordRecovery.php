<?php
declare(strict_types=1);

final class PasswordRecovery
{
    public static function throttle(string $action, int $limit = 10): void
    {
        $count = (int) Database::value('SELECT COUNT(*) FROM activity_logs WHERE action = ? AND ip_address = ? AND created_at > NOW() - INTERVAL 1 HOUR', [$action, Request::ip()]);
        if ($count >= $limit) {
            Response::error('Too many requests. Please try again later.', 429);
        }
        ActivityLogger::log(null, $action, 'Public authentication request');
    }

    public static function validatePassword(array $data): void
    {
        $v = (new Validator($data))->required('password', 'Password')->password('password')
            ->required('password_confirmation', 'Password confirmation')->string('password_confirmation', 'Password confirmation', 1, 72);
        if (($data['password'] ?? null) !== ($data['password_confirmation'] ?? null)) {
            $v->add('password_confirmation', 'Passwords must match.');
        }
        if ($v->fails()) Response::validation($v->errors());
    }

    public static function request(string $email): void
    {
        $user = Database::fetch("SELECT id FROM users WHERE email = ? AND status = 'active'", [$email]);
        if (!$user) return;
        $token = bin2hex(random_bytes(32));
        $id = Database::transaction(static function () use ($user, $token): ?int {
            Database::fetch('SELECT id FROM users WHERE id = ? FOR UPDATE', [$user['id']]);
            if (Database::value('SELECT 1 FROM password_reset_tokens WHERE user_id = ? AND created_at > NOW() - INTERVAL 1 MINUTE', [$user['id']])) return null;
            Database::execute('UPDATE password_reset_tokens SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL', [$user['id']]);
            return Database::insert('INSERT INTO password_reset_tokens (user_id, token_hash, expires_at) VALUES (?, ?, NOW() + INTERVAL 30 MINUTE)', [$user['id'], hash('sha256', $token)]);
        });
        if ($id === null) return;
        try {
            $base = rtrim(Config::get('mail.frontend_url'), '/');
            if (!filter_var($base, FILTER_VALIDATE_URL) || !preg_match('#^https?://#', $base)) throw new RuntimeException('Invalid frontend URL');
            // Fragment keeps the bearer token out of HTTP access logs and referrers.
            Mailer::send($email, 'Reset your N Real Estate password', "Open this link to reset your password (valid for 30 minutes):\n\n$base/reset-password#token=$token\n\nIf you did not request this, ignore this email.");
            ActivityLogger::log((int) $user['id'], 'auth.reset_requested', 'Password reset requested');
        } catch (Throwable $e) {
            Database::execute('UPDATE password_reset_tokens SET used_at = NOW() WHERE id = ?', [$id]);
            // Never log transport exception context, message content or tokens.
            error_log('[password-reset] Delivery failed. Check SMTP configuration.');
        }
    }

    public static function reset(string $token, string $password): bool
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) return false;
        return Database::transaction(static function () use ($token, $password): bool {
            $candidate = Database::fetch('SELECT user_id FROM password_reset_tokens WHERE token_hash = ?', [hash('sha256', $token)]);
            if (!$candidate) return false;
            $user = Database::fetch('SELECT id, status FROM users WHERE id = ? FOR UPDATE', [$candidate['user_id']]);
            $row = Database::fetch('SELECT id FROM password_reset_tokens WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW() FOR UPDATE', [hash('sha256', $token)]);
            if (!$row || !$user || $user['status'] !== 'active') return false;
            Database::execute('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $user['id']]);
            Database::execute('UPDATE password_reset_tokens SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL', [$user['id']]);
            Auth::revokeAllFor((int) $user['id']);
            ActivityLogger::log((int) $user['id'], 'auth.reset_completed', 'Password reset completed');
            return true;
        });
    }
}
