<?php
declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

Request::allow('GET');

$actor = Auth::require();

$id = Request::queryInt('id', 0, 0);
if ($id === 0) {
    Response::validation(['id' => 'A valid party ID is required.']);
}

$party = Parties::findVisible($actor, $id);
if ($party === null) {
    Response::notFound('Party not found.');
}

// Linked records, limited to the actor's record scope.
[$scope, $scopeParams] = Records::scope($actor);
$records = Database::fetchAll(
    Records::SELECT . " WHERE r.party_id = ? AND $scope ORDER BY r.created_at DESC LIMIT 50",
    [$id, ...$scopeParams]
);

Response::success([
    'party'   => Parties::format($party, $actor),
    'records' => array_map([Records::class, 'formatSummary'], $records),
]);
