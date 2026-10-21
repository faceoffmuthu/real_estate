<?php
declare(strict_types=1);
require __DIR__ . '/../../bootstrap.php';
Request::allow('GET');
$actor = Auth::require();
$partyId = Request::queryInt('party_id', 0, 0);
if ($partyId === 0) Response::validation(['party_id' => 'A valid party ID is required.']);
if (Parties::findVisible($actor, $partyId) === null) Response::notFound('Party not found.');
$requirement = ClientRequirements::findByParty($partyId);
Response::success(['requirement' => $requirement === null ? null : ClientRequirements::format($requirement)]);
