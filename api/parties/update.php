<?php
declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

Request::allow('PUT');

$actor = Auth::require([Users::ADMIN, Users::USER]);
$data = Request::body();

$id = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($id === false) {
    Response::validation(['id' => 'A valid party ID is required.']);
}

$party = Parties::findVisible($actor, $id);
if ($party === null) {
    Response::notFound('Party not found.');
}
if (!Parties::canEdit($actor, $party)) {
    Response::forbidden('Only the team that created this party can edit it.');
}

[$values, $errors] = Parties::validate($data);
if ($errors) {
    Response::validation($errors);
}

$changed =array_values(array_filter(Parties::FIELDS, static fn (string $f) => $values[$f] !== $party[$f]));
if ($changed === []) {
    Response::success(['party' => Parties::format($party, $actor)], 'No changes to save');
}

Database::execute(
    'UPDATE parties SET name = ?, phone = ?, alt_phone = ?, email = ?, address = ?, notes = ?, updated_by = ? WHERE id = ?',
    [$values['name'], $values['phone'], $values['alt_phone'], $values['email'], $values['address'], $values['notes'], $actor['id'], $id]
);
ActivityLogger::log($actor['id'], 'party.updated', "Updated party: {$values['name']}", 'party', $id, ['fields' => $changed]);

Response::success(['party' => Parties::format(Parties::findVisible($actor, $id), $actor)], 'Party updated');
