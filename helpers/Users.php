<?php
declare(strict_types=1);

/**
 * User account queries and the account-management hierarchy:
 *   - Super Admin manages every Admin and User (never other Super Admins).
 *   - Admin manages only Users whose manager_id is the Admin.
 *   - User manages nobody.
 */
final class Users
{
    public const SUPER_ADMIN = 'super_admin';
    public const ADMIN = 'admin';
    public const USER = 'user';

    private const SELECT = 'SELECT u.id, u.name, u.username, u.email, u.phone, u.status, u.manager_id,
                                   u.last_login_at, u.created_at, u.updated_at, u.created_by,
                                   r.slug AS role, r.name AS role_name, m.name AS manager_name
                              FROM users u
                              JOIN roles r ON r.id = u.role_id
                              LEFT JOIN users m ON m.id = u.manager_id';

    public static function find(int $id): ?array
    {
        return Database::fetch(self::SELECT . ' WHERE u.id = ? LIMIT 1', [$id]);
    }

    /**
     * Paginated list scoped to what $actor may see.
     * @return array{0: array, 1: int} [rows, total]
     */
    public static function list(array $actor, array $filters, int $page, int $perPage): array
    {
        [$where, $params] = self::scope($actor);

        if (!empty($filters['role'])) {
            $where[] = 'r.slug = ?';
            $params[] = $filters['role'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'u.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['manager_id'])) {
            $where[] = 'u.manager_id = ?';
            $params[] = (int) $filters['manager_id'];
        }
        if (!empty($filters['search'])) {
            $where[] = '(u.name LIKE ? OR u.email LIKE ? OR u.username LIKE ? OR u.phone LIKE ?)';
            array_push($params, ...array_fill(0, 4, like_escape($filters['search'])));
        }

        $whereSql = ' WHERE ' . implode(' AND ', $where);
        $total = (int) Database::value(
            'SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id' . $whereSql,
            $params
        );
        $rows = Database::fetchAll(
            self::SELECT . $whereSql . ' ORDER BY u.created_at DESC, u.id DESC LIMIT ? OFFSET ?',
            [...$params, $perPage, ($page - 1) * $perPage]
        );
        return [array_map([self::class, 'format'], $rows), $total];
    }

    /** WHERE fragments restricting users to the actor's management scope. */
    public static function scope(array $actor): array
    {
        switch ($actor['role']) {
            case self::SUPER_ADMIN:
                return [['r.slug IN (?, ?)'], [self::ADMIN, self::USER]];
            case self::ADMIN:
                return [['r.slug = ?', 'u.manager_id = ?'], [self::USER, $actor['id']]];
            default:
                return [['1 = 0'], []];
        }
    }

    /**
     * SQL condition "the user id in $column belongs to the actor's team":
     * Super Admin = everyone, Admin = self + managed Users, User = self.
     * @return array{0: string, 1: array}
     */
    public static function teamFilter(array $actor, string $column): array
    {
        switch ($actor['role']) {
            case self::SUPER_ADMIN:
                return ['1 = 1', []];
            case self::ADMIN:
                return ["$column IN (SELECT id FROM users WHERE id = ? OR manager_id = ?)", [$actor['id'], $actor['id']]];
            default:
                return ["$column = ?", [$actor['id']]];
        }
    }

    /** True when $userId belongs to the actor's team (see teamFilter). */
    public static function inTeam(array $actor, ?int $userId): bool
    {
        if ($userId === null) {
            return false;
        }
        [$condition, $params] = self::teamFilter($actor, '?');
        return Database::value("SELECT 1 FROM DUAL WHERE $condition", [$userId, ...$params]) !== null;
    }

    public static function canManage(array $actor, array $target): bool
    {
        if ((int) $target['id'] === $actor['id']) {
            return false; // own account is managed through the profile endpoints
        }
        switch ($actor['role']) {
            case self::SUPER_ADMIN:
                return in_array($target['role'], [self::ADMIN, self::USER], true);
            case self::ADMIN:
                return $target['role'] === self::USER && (int) $target['manager_id'] === $actor['id'];
            default:
                return false;
        }
    }

    /** Roles the actor is allowed to assign when creating/editing accounts. */
    public static function assignableRoles(array $actor): array
    {
        switch ($actor['role']) {
            case self::SUPER_ADMIN:
                return [self::ADMIN, self::USER];
            case self::ADMIN:
                return [self::USER];
            default:
                return [];
        }
    }

    public static function roleId(string $slug): int
    {
        return (int) Database::value('SELECT id FROM roles WHERE slug = ?', [$slug]);
    }

    /** Returns the id of an active Admin, or null when $id is not one. */
    public static function activeAdminId(int $id): ?int
    {
        $found = Database::value(
            'SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ? AND r.slug = ? AND u.status = ?',
            [$id, self::ADMIN, 'active']
        );
        return $found === null ? null : (int) $found;
    }

    /** Adds 'already taken' errors for duplicate username/email. */
    public static function checkUnique(Validator $v, ?string $username, ?string $email, ?int $exceptId = null): void
    {
        $except = $exceptId ?? 0;
        if ($username !== null && Database::value('SELECT 1 FROM users WHERE username = ? AND id <> ?', [$username, $except])) {
            $v->add('username', 'This username is already taken.');
        }
        if ($email !== null && Database::value('SELECT 1 FROM users WHERE email = ? AND id <> ?', [$email, $except])) {
            $v->add('email', 'This email address is already registered.');
        }
    }

    /** Public representation — never includes the password hash. */
    public static function format(array $row): array
    {
        return [
            'id'            => (int) $row['id'],
            'name'          => $row['name'],
            'username'      => $row['username'],
            'email'         => $row['email'],
            'phone'         => $row['phone'],
            'role'          => $row['role'],
            'role_name'     => $row['role_name'],
            'status'        => $row['status'],
            'manager'       => $row['manager_id'] === null ? null : [
                'id'   => (int) $row['manager_id'],
                'name' => $row['manager_name'] ?? null,
            ],
            'last_login_at' => iso_datetime($row['last_login_at']),
            'created_at'    => iso_datetime($row['created_at']),
            'updated_at'    => iso_datetime($row['updated_at'] ?? null),
        ];
    }
}
