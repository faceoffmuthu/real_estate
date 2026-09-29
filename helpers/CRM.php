<?php
declare(strict_types=1);

final class CRM
{
    public const TYPES = ['call', 'whatsapp', 'meeting', 'site_visit', 'email', 'other'];
    public const STATUSES = ['pending', 'completed', 'cancelled'];
    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];
    public const CONTACTS = ['new', 'attempted', 'contacted', 'unreachable', 'not_interested'];

    public static function record(array $actor, mixed $id, bool $write = false): array
    {
        $id = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$id) Response::validation(['record_id' => 'A valid record is required.']);
        $r = Records::findVisible($actor, $id);
        if (!$r) Response::notFound('Record not found.');
        if ($write && (!Records::canEdit($actor, $r) || $r['record_status'] !== 'active')) Response::forbidden();
        return $r;
    }

    /** Assignment is responsibility only; it never grants access to private records. */
    public static function assignees(array $actor, array $r): array
    {
        $ids = [(int) $r['created_by']];
        if ($actor['role'] !== Users::USER && $r['approval_status'] !== 'draft') {
            $owner = Users::find((int) $r['created_by']);
            if ($owner && $owner['manager_id']) $ids[] = (int) $owner['manager_id'];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        return array_map(static fn ($u) => ['id' => (int) $u['id'], 'name' => $u['name']], Database::fetchAll("SELECT id, name FROM users WHERE id IN ($in) AND status = 'active' ORDER BY name", $ids));
    }

    public static function assignee(array $actor, array $r, mixed $id): int
    {
        $id = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$id || !in_array($id, array_column(self::assignees($actor, $r), 'id'), true)) Response::validation(['assigned_to' => 'Select a permitted active person.']);
        return $id;
    }

    public static function format(array $f): array
    {
        return [
            'id' => (int) $f['id'], 'record_id' => (int) $f['record_id'],
            'reference' => $f['record_reference'], 'title' => $f['title'],
            'assigned_to' => (int) $f['assigned_to'], 'assigned_name' => $f['assigned_name'],
            'due_at' => iso_datetime($f['due_at']), 'type' => $f['type'], 'notes' => $f['notes'],
            'status' => $f['status'], 'completed_at' => iso_datetime($f['completed_at']),
        ];
    }

    public static function list(array $actor, array $f, int $page, int $perPage): array
    {
        [$scope, $params] = Records::scope($actor);
        $where = [$scope];
        foreach (['record_id', 'status', 'assigned_to', 'type'] as $key) {
            if (!empty($f[$key])) { $where[] = "f.$key = ?"; $params[] = $f[$key]; }
        }
        if (!empty($f['due_from'])) { $where[] = 'f.due_at >= ?'; $params[] = $f['due_from']; }
        if (!empty($f['due_to'])) { $where[] = 'f.due_at < ? + INTERVAL 1 DAY'; $params[] = $f['due_to']; }
        $where = implode(' AND ', $where);
        $from = ' FROM follow_ups f JOIN real_estate_records r ON r.id = f.record_id JOIN users u ON u.id = f.assigned_to';
        $total = (int) Database::value("SELECT COUNT(*) $from WHERE $where", $params);
        $items = Database::fetchAll("SELECT f.*, r.record_reference, r.title, u.name AS assigned_name $from WHERE $where ORDER BY f.due_at, f.id LIMIT ? OFFSET ?", [...$params, $perPage, ($page - 1) * $perPage]);
        return ['items' => array_map([self::class, 'format'], $items), 'pagination' => pagination($page, $perPage, $total)];
    }

    public static function dueDate(array $d): string
    {
        $value = $d['due_at'] ?? null;
        $parsed = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new DateTimeZone('UTC')) : false;
        if (!$parsed || $parsed->format('Y-m-d\TH:i:s\Z') !== $value) Response::validation(['due_at' => 'Enter a valid date and time.']);
        return $parsed->format('Y-m-d H:i:s');
    }

    public static function summary(array $actor): array
    {
        [$scope, $params] = Records::scope($actor);
        $row = Database::fetch("SELECT COUNT(*) AS total, COALESCE(SUM(f.status = 'pending'),0) AS pending,
            COALESCE(SUM(f.status = 'pending' AND f.due_at < NOW()),0) AS overdue
            FROM follow_ups f JOIN real_estate_records r ON r.id = f.record_id WHERE $scope", $params);
        return ['total' => (int) $row['total'], 'pending' => (int) $row['pending'], 'overdue' => (int) $row['overdue'],
            'upcoming' => self::list($actor, ['status' => 'pending'], 1, 5)['items']];
    }

    /** In-app reminders generated once when the assignee checks notifications. */
    public static function reminders(array $actor): void
    {
        [$scope, $params] = Records::scope($actor);
        Database::transaction(static function () use ($actor, $scope, $params): void {
            $rows = Database::fetchAll("SELECT f.id, f.record_id, r.record_reference FROM follow_ups f JOIN real_estate_records r ON r.id = f.record_id
                WHERE $scope AND f.assigned_to = ? AND f.status = 'pending' AND f.reminded_at IS NULL AND f.due_at <= NOW() + INTERVAL 1 DAY
                ORDER BY f.due_at LIMIT 50 FOR UPDATE", [...$params, $actor['id']]);
            foreach ($rows as $f) {
                Notifications::send($actor['id'], 'follow_up.reminder', 'Follow-up due', 'Follow-up due for ' . $f['record_reference'], 'record', (int) $f['record_id']);
                Database::execute('UPDATE follow_ups SET reminded_at = NOW() WHERE id = ?', [$f['id']]);
            }
        });
    }
}
