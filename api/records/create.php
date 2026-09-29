<?php
declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

Request::allow('POST');

// Super Admin is view-only for records.
$actor = Auth::require([Users::ADMIN, Users::USER]);
$isMultipart = str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/form-data');
if ($isMultipart) {
    $data = json_decode((string) ($_POST['payload'] ?? ''), true);
    if (!is_array($data)) Response::error('Property details are missing or invalid.', 400);
} else {
    $data = Request::body();
}
$uploads = $isMultipart ? PropertyMedia::uploadedFiles($_FILES) : [];
$mediaErrors = PropertyMedia::validateUploads($uploads);

[$values, $errors] = Records::validate($data);
$party = Records::resolveParty($actor, $data);
$errors += $party['errors'];
$errors += $mediaErrors;
if ($errors) {
    Response::validation($errors);
}
if ($party['duplicate']) {
    Response::error('A party with this phone number already exists.', 409, [
        'party_phone' => 'A party with this phone number already exists. Use the existing party or confirm creating a new one.',
    ]);
}

// Ownership and approval state always come from the session, never from the request.
// Users choose Save Draft (submit = false) or Submit for Approval; Admin records are approved directly.
$approval = Approvals::creationColumns($actor, ($data['submit'] ?? false) === true);

$id = 0;
try {
[$id, $partyId] = Database::transaction(static function () use ($values, $party, $actor, $approval, $uploads, &$id): array {
    $partyId = $party['party_id'] ?? Parties::insert($party['new_party'], $actor['id']);

    $columns = [...array_keys($values), ...array_keys($approval), 'party_id', 'created_by', 'created_by_role', 'updated_by'];
    $params = [...array_values($values), ...array_values($approval), $partyId, $actor['id'], $actor['role'], $actor['id']];
    $id = Database::insert(
        'INSERT INTO real_estate_records (' . implode(', ', $columns) . ') VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')',
        $params
    );
    foreach ($uploads as $upload) PropertyMedia::store($id, $actor, $upload, $upload['kind']);
    Database::execute('UPDATE real_estate_records SET record_reference = ? WHERE id = ?', [Records::reference($id), $id]);
    return [$id, $partyId];
});
} catch (Throwable $e) {
    if ($id > 0) PropertyMedia::purgeRecordFiles($id);
    throw $e;
}

$reference = Records::reference($id);
if ($party['new_party'] !== null) {
    ActivityLogger::log($actor['id'], 'party.created', "Created party: {$party['new_party']['name']}", 'party', $partyId);
}
$record = Records::findVisible($actor, $id);
ActivityLogger::log($actor['id'], 'record.created', "Created record $reference: {$values['title']}", 'record', $id, [
    'reference'       => $reference,
    'party_id'        => $partyId,
    'approval_status' => $approval['approval_status'],
]);
Approvals::recordCreated($actor, $record);

$message = match ($approval['approval_status']) {
    Approvals::PENDING  => "Record $reference submitted for approval",
    Approvals::DRAFT    => "Record $reference saved as draft",
    default             => "Record $reference created",
};
Response::success(['record' => Records::format($record)], $message, 201);
