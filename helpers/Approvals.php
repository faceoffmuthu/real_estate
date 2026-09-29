<?php
declare(strict_types=1);

/**
 * Approval workflow — the single place that defines who may move a record
 * between approval statuses (separate from the business process stage).
 *
 *   User creates  → draft  ──submit──▶ pending ──approve──▶ approved
 *                                         │
 *                                         └──reject──▶ rejected ──resubmit──▶ pending
 *   Admin creates → approved (no review step)
 */
final class Approvals
{
    public const DRAFT = 'draft';
    public const PENDING = 'pending';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';
    public const STATUSES = [self::DRAFT, self::PENDING, self::APPROVED, self::REJECTED];

    /** Fields a User may still change on an approved record (day-to-day progress). */
    public const PROGRESS_FIELDS = ['process_stage_id', 'process_notes'];

    /** Allowed transitions: current status => [action => new status]. */
    private const TRANSITIONS = [
        self::DRAFT    => ['submit' => self::PENDING],
        self::REJECTED => ['resubmit' => self::PENDING],
        self::PENDING  => ['approve' => self::APPROVED, 'reject' => self::REJECTED],
    ];

    public static function next(string $status, string $action): ?string
    {
        return self::TRANSITIONS[$status][$action] ?? null;
    }

    /** The submit-type action available from $status ('submit', 'resubmit') or null. */
    public static function submitAction(string $status): ?string
    {
        return match ($status) {
            self::DRAFT    => 'submit',
            self::REJECTED => 'resubmit',
            default        => null,
        };
    }

    /** Approval status of a new record. Admin-created records never need review. */
    public static function initialStatus(array $actor, bool $submit): string
    {
        if ($actor['role'] === Users::ADMIN) {
            return self::APPROVED;
        }
        return $submit ? self::PENDING : self::DRAFT;
    }

    /** Admin may review pending records created by Users they manage — never their own. */
    public static function canReview(array $actor, array $record): bool
    {
        return $actor['role'] === Users::ADMIN
            && $record['created_by_role'] === Users::USER
            && (int) $record['created_by'] !== $actor['id']
            && Users::inTeam($actor, (int) $record['created_by']);
    }

    /** Only the User who created the record submits / resubmits it. */
    public static function canSubmit(array $actor, array $record): bool
    {
        return $actor['role'] === Users::USER
            && (int) $record['created_by'] === $actor['id']
            && self::submitAction($record['approval_status']) !== null;
    }

    /**
     * What the actor may edit: 'all', 'progress' (stage + notes only) or 'none'.
     * Users keep full edit rights on draft / pending / rejected records.
     */
    public static function editMode(array $actor, array $record): string
    {
        if (!Records::canEdit($actor, $record)) {
            return 'none';
        }
        return $actor['role'] === Users::USER && $record['approval_status'] === self::APPROVED ? 'progress' : 'all';
    }

    /**
     * Applies a workflow action atomically. The UPDATE is guarded by the current
     * status, so concurrent reviews cannot both succeed.
     * @return string|null New status, or null when the transition is not allowed.
     */
    public static function apply(array $actor, array $record, string $action, ?string $reason = null): ?string
    {
        $from = $record['approval_status'];
        $to = self::next($from, $action);
        if ($to === null) {
            return null;
        }

        $set = ['approval_status = ?'];
        $params = [$to];
        if ($to === self::PENDING) {
            // New review round: previous review is kept only in history.
            array_push($set, 'submitted_at = NOW()', 'reviewed_by = NULL', 'reviewed_at = NULL', 'rejection_reason = NULL');
        } else {
            array_push($set, 'reviewed_by = ?', 'reviewed_at = NOW()', 'rejection_reason = ?');
            array_push($params, $actor['id'], $to === self::REJECTED ? $reason : null);
        }
        $changed = Database::execute(
            'UPDATE real_estate_records SET ' . implode(', ', $set) . ' WHERE id = ? AND approval_status = ?',
            [...$params, (int) $record['id'], $from]
        );
        if ($changed !== 1) {
            return null;
        }

        $id = (int) $record['id'];
        $ref = $record['record_reference'];
        $historyAction = ['submit' => 'submitted', 'resubmit' => 'resubmitted', 'approve' => 'approved', 'reject' => 'rejected'][$action];
        self::log($id, $historyAction, $actor, $from, $to, $reason);

        $descriptions = [
            'submit'   => "Submitted $ref for approval",
            'resubmit' => "Resubmitted $ref for approval",
            'approve'  => "Approved $ref",
            'reject'   => "Rejected $ref",
        ];
        $activity = ['submit' => 'record.submitted', 'resubmit' => 'record.resubmitted', 'approve' => 'record.approved', 'reject' => 'record.rejected'][$action];
        ActivityLogger::log($actor['id'], $activity, $descriptions[$action], 'record', $id, array_filter([
            'from'   => $from,
            'to'     => $to,
            'reason' => $reason,
        ]));

        self::notify($action, $record, $reason);
        return $to;
    }

    /**
     * Column values for a new record's approval state (set by the backend only).
     * @return array<string, mixed>
     */
    public static function creationColumns(array $actor, bool $submit): array
    {
        $status = self::initialStatus($actor, $submit);
        return [
            'approval_status' => $status,
            'submitted_at'    => $status === self::PENDING ? gmdate('Y-m-d H:i:s') : null,
            'reviewed_by'     => $status === self::APPROVED ? $actor['id'] : null,
            'reviewed_at'     => $status === self::APPROVED ? gmdate('Y-m-d H:i:s') : null,
        ];
    }

    /** History, activity and notifications for a newly created record. */
    public static function recordCreated(array $actor, array $record): void
    {
        $status = $record['approval_status'];
        $action = match ($status) {
            self::APPROVED => 'created_approved',
            self::PENDING  => 'submitted',
            default        => 'created_draft',
        };
        self::log((int) $record['id'], $action, $actor, null, $status);
        if ($status === self::PENDING) {
            ActivityLogger::log($actor['id'], 'record.submitted', "Submitted {$record['record_reference']} for approval", 'record', (int) $record['id'], ['to' => $status]);
            self::notify('submit', $record, null);
        }
    }

    /** Append-only history entry. */
    public static function log(int $recordId, string $action, array $actor, ?string $from, string $to, ?string $reason = null): void
    {
        Database::insert(
            'INSERT INTO record_approval_history (record_id, action, performed_by, performed_by_role, previous_status, new_status, reason)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$recordId, $action, $actor['id'], $actor['role'], $from, $to, $reason]
        );
    }

    private const HISTORY_SELECT = 'SELECT h.id, h.record_id, h.action, h.performed_by, h.performed_by_role, h.previous_status,
                                           h.new_status, h.reason, h.created_at, u.name AS performed_by_name,
                                           r.record_reference, r.title
                                      FROM record_approval_history h
                                      JOIN real_estate_records r ON r.id = h.record_id
                                      LEFT JOIN users u ON u.id = h.performed_by';

    public static function history(int $recordId): array
    {
        return array_map([self::class, 'formatHistory'], Database::fetchAll(
            self::HISTORY_SELECT . ' WHERE h.record_id = ? ORDER BY h.created_at, h.id',
            [$recordId]
        ));
    }

    /**
     * Recent history entries on records within the actor's scope.
     * @param string[] $actions Limit to these actions (empty = all).
     */
    public static function recentHistory(array $actor, array $actions = [], int $limit = 6, int $offset = 0): array
    {
        [$scope, $params] = Records::scope($actor);
        $where = $scope;
        if ($actions) {
            $where .= ' AND h.action IN (' . implode(', ', array_fill(0, count($actions), '?')) . ')';
            array_push($params, ...$actions);
        }
        return array_map([self::class, 'formatHistory'], Database::fetchAll(
            self::HISTORY_SELECT . " WHERE $where ORDER BY h.created_at DESC, h.id DESC LIMIT ? OFFSET ?",
            [...$params, $limit, $offset]
        ));
    }

    public static function countHistory(array $actor, array $actions = []): int
    {
        [$scope, $params] = Records::scope($actor);
        $where = $scope;
        if ($actions) {
            $where .= ' AND h.action IN (' . implode(', ', array_fill(0, count($actions), '?')) . ')';
            array_push($params, ...$actions);
        }
        return (int) Database::value(
            "SELECT COUNT(*) FROM record_approval_history h JOIN real_estate_records r ON r.id = h.record_id WHERE $where",
            $params
        );
    }

    public static function formatHistory(array $h): array
    {
        return [
            'id'              => (int) $h['id'],
            'record'          => ['id' => (int) $h['record_id'], 'reference' => $h['record_reference'], 'title' => $h['title']],
            'action'          => $h['action'],
            'previous_status' => $h['previous_status'],
            'new_status'      => $h['new_status'],
            'reason'          => $h['reason'],
            'performed_by'    => $h['performed_by'] !== null
                ? ['id' => (int) $h['performed_by'], 'name' => $h['performed_by_name'], 'role' => $h['performed_by_role']]
                : null,
            'created_at'      => iso_datetime($h['created_at']),
        ];
    }

    /** In-app notifications for workflow events. */
    private static function notify(string $action, array $record, ?string $reason): void
    {
        $id = (int) $record['id'];
        $ref = $record['record_reference'];
        $creator = (int) $record['created_by'];

        if ($action === 'submit' || $action === 'resubmit') {
            $verb = $action === 'submit' ? 'submitted' : 'resubmitted';
            Notifications::send($creator, 'record.submitted', "$ref submitted", "Your record \"{$record['title']}\" was $verb for approval.", 'record', $id);
            $manager = Database::value(
                "SELECT m.id FROM users u JOIN users m ON m.id = u.manager_id WHERE u.id = ? AND m.status = 'active'",
                [$creator]
            );
            if ($manager !== null) {
                $who = (string) Database::value('SELECT name FROM users WHERE id = ?', [$creator]);
                Notifications::send((int) $manager, 'approval.pending', "$ref awaiting approval", "$who $verb \"{$record['title']}\" for your review.", 'record', $id);
            }
        } elseif ($action === 'approve') {
            Notifications::send($creator, 'record.approved', "$ref approved", "Your record \"{$record['title']}\" was approved.", 'record', $id);
        } elseif ($action === 'reject') {
            Notifications::send($creator, 'record.rejected', "$ref rejected", "Reason: $reason", 'record', $id);
        }
    }
}
