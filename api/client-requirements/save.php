<?php
declare(strict_types=1);
require __DIR__ . '/../../bootstrap.php';
Request::allow('PUT');
$actor = Auth::require([Users::ADMIN, Users::USER]);
$data = Request::body();
$partyId = filter_var($data['party_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($partyId === false) Response::validation(['party_id' => 'A valid party ID is required.']);
$party = Parties::findVisible($actor, (int) $partyId);
if ($party === null) Response::notFound('Party not found.');
if (!Parties::canEdit($actor, $party)) Response::forbidden('Only the team that created this party can manage its requirements.');
[$values, $errors] = ClientRequirements::validate($data);
if ($errors) Response::validation($errors);
$existing = ClientRequirements::findByParty((int) $partyId);
$id = ClientRequirements::save((int) $partyId, $values, (int) $actor['id']);
ActivityLogger::log((int) $actor['id'], $existing === null ? 'client_requirement.created' : 'client_requirement.updated', ($existing === null ? 'Added' : 'Updated') . " client requirements for {$party['name']}", 'party', (int) $partyId);
Response::success(['requirement' => ClientRequirements::format(ClientRequirements::findByParty((int) $partyId))], $existing === null ? 'Client requirement created.' : 'Client requirement updated.');
