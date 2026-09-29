<?php
declare(strict_types=1);

/**
 * In-app notifications (Phase 3 foundation). Email / SMS / WhatsApp delivery
 * can later be added as additional channels behind send().
 */
final class Notifications
{
    /** Never breaks the calling request: failures go to the PHP error log. */
    public static function send(int $userId, string $type, string $title, string $message, ?string $entityType = null, ?int $entityId = null): void
    {
        try {
            Database::insert(
                'INSERT INTO notifications (user_id, type, title, message, entity_type, entity_id) VALUES (?, ?, ?, ?, ?, ?)',
                [$userId, $type, mb_substr($title, 0, 150), mb_substr($message, 0, 500), $entityType, $entityId]
            );
        } catch (Throwable $e) {
            error_log('[notifications] ' . $e->getMessage());
        }
    }

    public static function format(array $n): array
    {
        return [
            'id'          => (int) $n['id'],
            'type'        => $n['type'],
            'title'       => $n['title'],
            'message'     => $n['message'],
            'entity_type' => $n['entity_type'],
            'entity_id'   => $n['entity_id'] !== null ? (int) $n['entity_id'] : null,
            'read'        => $n['read_at'] !== null,
            'created_at'  => iso_datetime($n['created_at']),
        ];
    }
}
