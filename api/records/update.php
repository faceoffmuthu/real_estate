<?php
declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

Request::allow('PUT');

$actor = Auth::require([Users::ADMIN, Users::USER]);
$data = Request::body();

$id = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($id === false) {
    Response::validation(['id' => 'A valid record ID is required.']);
}

$record = Records::findVisible($actor, $id);
if ($record === null) {
    Response::notFound('Record not found.');
}
if (!Records::canEdit($actor, $record)) {
    Response::forbidden('You do not have permission to edit this record.');
}
if (Approvals::editMode($actor, $record) === 'all' && (int) Database::value('SELECT COUNT(*) FROM property_media WHERE record_id = ?', [$id]) < 1) {
    Response::validation(['media' => 'Add at least one property image or video before saving this record.']);
}

[$values, $errors] = Records::validate($data, $record);
$party = Records::resolveParty($actor, $data, $record);
$errors += $party['errors'];
if ($errors) {
    Response::validation($errors);
}
if ($party['duplicate']) {
    Response::error('A party with this phone number already exists.', 409, [
        'party_phone' => 'A party with this phone number already exists. Use the existing party or confirm creating a new one.',
    ]);
}

// Only columns whose value actually changes are written.
$numeric = ['area_sqft', 'area_value', 'market_price', 'rental_amount', 'security_deposit', 'sale_amount', 'bedrooms', 'bathrooms', 'property_type_id', 'property_category_id', 'process_stage_id'];
$changes = [];
foreach ($values as $column => $value) {
    $old = $record[$column];
    $same = in_array($column, $numeric, true)
        ? ($old === null ? $value === null : $value !== null && (float) $old === (float) $value)
        : $old === $value;
    if (!$same) {
        $changes[$column] = $value;
    }
}

// Approved User records: only day-to-day progress (stage + notes) may change.
$partyChanges = $party['new_party'] !== null || ($party['party_id'] !== null && $party['party_id'] !== (int) $record['party_id']);
if (Approvals::editMode($actor, $record) === 'progress'
    && ($partyChanges || array_diff(array_keys($changes), Approvals::PROGRESS_FIELDS) !== [])) {
    Response::forbidden('This record is approved. You can only update the process stage and process notes.');
}

$reference = $record['record_reference'];
$newPartyId = Database::transaction(static function () use (&$changes, $party, $record, $actor, $id): ?int {
    $partyId = $party['party_id'] ?? Parties::insert($party['new_party'], $actor['id']);
    if ($partyId !== (int) $record['party_id']) {
        $changes['party_id'] = $partyId;
    }
    if ($changes !== []) {
        $set = implode(', ', array_map(static fn (string $c) => "$c = ?", array_keys($changes)));
        Database::execute("UPDATE real_estate_records SET $set, updated_by = ? WHERE id = ?", [...array_values($changes), $actor['id'], $id]);
        // Edits while awaiting review stay pending but are visible to the reviewer.
        if ($record['approval_status'] === Approvals::PENDING) {
            Approvals::log($id, 'edited', $actor, Approvals::PENDING, Approvals::PENDING);
        }
    }
    return $party['new_party'] !== null ? $partyId : null;
});

// Optional "save and submit / resubmit" in the same request (User's draft or rejected record).
$submitted = null;
if (($data['submit'] ?? false) === true && Approvals::canSubmit($actor, $record)) {
    $submitted = Approvals::apply($actor, Records::findVisible($actor, $id), Approvals::submitAction($record['approval_status']));
}

if ($changes === []) {
    $fresh = $submitted !== null ? Records::findVisible($actor, $id) : $record;
    Response::success(['record' => Records::format($fresh)], $submitted !== null ? "Record $reference submitted for approval" : 'No changes to save');
}

if ($newPartyId !== null) {
    ActivityLogger::log($actor['id'], 'party.created', "Created party: {$party['new_party']['name']}", 'party', $newPartyId);
}
$fields = array_values(array_diff(array_keys($changes), ['process_stage_id', 'record_status']));
if ($fields !== []) {
    ActivityLogger::log($actor['id'], 'record.updated', "Updated record $reference", 'record', $id, ['fields' => $fields]);
}
if (isset($changes['process_stage_id'])) {
    $to = (string) Database::value('SELECT name FROM record_process_stages WHERE id = ?', [$changes['process_stage_id']]);
    ActivityLogger::log($actor['id'], 'record.stage_changed', "Moved $reference from {$record['stage_name']} to $to", 'record', $id, [
        'from' => $record['stage_slug'],
        'to'   => (int) $changes['process_stage_id'],
    ]);
}
if (isset($changes['record_status'])) {
    $verb = $changes['record_status'] === 'archived' ? 'archived' : 'restored';
    ActivityLogger::log($actor['id'], "record.$verb", ucfirst($verb) . " record $reference", 'record', $id);
}

Response::success(
    ['record' => Records::format(Records::findVisible($actor, $id))],
    $submitted !== null ? "Record $reference updated and submitted for approval" : 'Record updated'
);
