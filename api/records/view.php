<?php
declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

Request::allow('GET');

$actor = Auth::require();

$id = Request::queryInt('id', 0, 0);
if ($id === 0) {
    Response::validation(['id' => 'A valid record ID is required.']);
}

$record = Records::findVisible($actor, $id);
if ($record === null) {
    Response::notFound('Record not found.');
}

// The party is visible to anyone who can see a record linked to it.
$party = Database::fetch('SELECT id, name, phone, alt_phone, email, address, notes, created_by FROM parties WHERE id = ?', [(int) $record['party_id']]);

$editMode = Approvals::editMode($actor, $record);
$canReview = Approvals::canReview($actor, $record) && $record['approval_status'] === Approvals::PENDING;
// Log that the reviewer opened the record (at most once per 10 minutes per reviewer).
if ($canReview && !Database::value(
    "SELECT 1 FROM activity_logs WHERE user_id = ? AND action = 'record.review_opened' AND entity_id = ? AND created_at > NOW() - INTERVAL 10 MINUTE",
    [$actor['id'], $id]
)) {
    ActivityLogger::log($actor['id'], 'record.review_opened', "Opened {$record['record_reference']} for review", 'record', $id);
}

Response::success([
    'record'   => Records::format($record),
    'media' => PropertyMedia::list($id),
    'crm_assignees' => CRM::assignees($actor, $record),
    'can_manage_crm' => Records::canEdit($actor, $record) && $record['record_status'] === 'active',
    'party'    => [
        'id'        => (int) $party['id'],
        'name'      => $party['name'],
        'phone'     => $party['phone'],
        'alt_phone' => $party['alt_phone'],
        'email'     => $party['email'],
        'address'   => $party['address'],
        'notes'     => $party['notes'],
    ],
    'can_edit'   => $editMode !== 'none',
    'edit_mode'  => $editMode,          // 'all' | 'progress' (stage + notes) | 'none'
    'can_review' => $canReview,
    'can_submit' => Approvals::canSubmit($actor, $record),
    'approval_history' => Approvals::history($id),
    'activity'   => ActivityLogger::recent("a.entity_type = 'record' AND a.entity_id = ?", [$id], 15),
]);
