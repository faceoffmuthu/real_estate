<?php
declare(strict_types=1);

/**
 * Writes to `activity_logs`. Logging must never break the request that
 * triggered it, so failures are written to the PHP error log instead.
 */
final class ActivityLogger
{
    public static function log(
        ?int $userId,
        string $action,
        string $description,
        ?string $entityType = null,
        ?int $entityId = null,
        array $metadata = []
    ): void {
        try {
            Database::insert(
                'INSERT INTO activity_logs (user_id, action, entity_type, entity_id, description, ip_address, user_agent, metadata)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $userId,
                    $action,
                    $entityType,
                    $entityId,
                    mb_substr($description, 0, 255),
                    Request::ip(),
                    Request::userAgent(),
                    $metadata === [] ? null : json_encode($metadata, JSON_UNESCAPED_UNICODE),
                ]
            );
        } catch (Throwable $e) {
            error_log('[activity-log] ' . $e->getMessage());
        }
    }

    /** Shapes a joined activity row (see self::SELECT) for API output. */
    public static function format(array $row): array
    {
        return [
            'id'          => (int) $row['id'],
            'action'      => $row['action'],
            'description' => $row['description'],
            'entity_type' => $row['entity_type'],
            'entity_id'   => $row['entity_id'] !== null ? (int) $row['entity_id'] : null,
            'ip_address'  => $row['ip_address'],
            'metadata'    => $row['metadata'] !== null ? json_decode($row['metadata'], true) : null,
            'created_at'  => iso_datetime($row['created_at']),
            'user'        => $row['user_id'] === null ? null : [
                'id'   => (int) $row['user_id'],
                'name' => $row['user_name'],
                'role' => $row['user_role'],
            ],
        ];
    }

    /** Most recent entries matching an optional WHERE fragment (columns aliased a/u/r). */
    public static function recent(string $where = '1 = 1', array $params = [], int $limit = 8): array
    {
        $rows = Database::fetchAll(self::SELECT . " WHERE $where ORDER BY a.created_at DESC, a.id DESC LIMIT ?", [...$params, $limit]);
        return array_map([self::class, 'format'], $rows);
    }

    /**
     * Per-day counts for the last $days days (UTC), gaps filled with zero.
     * @return array<int, array{date: string, count: int}>
     */
    public static function dailyCounts(string $where = '1 = 1', array $params = [], int $days = 7): array
    {
        $rows = Database::fetchAll(
            "SELECT DATE(a.created_at) AS day, COUNT(*) AS total
               FROM activity_logs a
              WHERE $where AND a.created_at >= CURDATE() - INTERVAL ? DAY
              GROUP BY DATE(a.created_at)",
            [...$params, $days - 1]
        );
        $byDay = array_column($rows, 'total', 'day');

        $series = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = gmdate('Y-m-d', strtotime("-$i days"));
            $series[] = ['date' => $day, 'count' => (int) ($byDay[$day] ?? 0)];
        }
        return $series;
    }

    /** Standard SELECT used by activity queries; append WHERE / ORDER / LIMIT. */
    public const SELECT = 'SELECT a.id, a.user_id, a.action, a.entity_type, a.entity_id, a.description, a.ip_address,
                                  a.metadata, a.created_at, u.name AS user_name, r.slug AS user_role
                             FROM activity_logs a
                             LEFT JOIN users u ON u.id = a.user_id
                             LEFT JOIN roles r ON r.id = u.role_id';
}
