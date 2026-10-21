<?php
declare(strict_types=1);
require __DIR__ . '/../../bootstrap.php';
Request::allow('GET');
$actor = Auth::require();
$partyId = Request::queryInt('party_id', 0, 0);
$recordId = Request::queryInt('record_id', 0, 0);
if (($partyId === 0 && $recordId === 0) || ($partyId !== 0 && $recordId !== 0)) Response::validation(['match_target' => 'Provide either a party ID or a record ID.']);
if ($partyId !== 0) {
    if (Parties::findVisible($actor, $partyId) === null) Response::notFound('Party not found.');
    $requirement = ClientRequirements::findByParty($partyId);
    Response::success(['requirement' => $requirement === null ? null : ClientRequirements::format($requirement), 'items' => $requirement === null ? [] : ClientRequirements::propertiesFor($actor, $requirement)]);
}
$record = Records::findVisible($actor, $recordId);
if ($record === null) Response::notFound('Record not found.');
Response::success(['items' => ClientRequirements::clientsFor($actor, $record)]);
