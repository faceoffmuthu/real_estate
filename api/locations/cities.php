<?php
declare(strict_types=1);
require __DIR__ . '/../../bootstrap.php';
Request::allow('GET');
$actor = Auth::require([Users::ADMIN, Users::USER]);
$query = Request::query('search', '') ?? '';
if (mb_strlen($query) > 100) Response::validation(['search' => 'Search must not exceed 100 characters.']);
[$scope, $params] = Records::scope($actor);
$term = '%' . like_escape($query) . '%';
$rows = Database::fetchAll(
    "SELECT DISTINCT r.city FROM real_estate_records r WHERE $scope AND r.city IS NOT NULL AND r.city <> '' AND r.city LIKE ? ORDER BY r.city LIMIT 100",
    [...$params, $term]
);
Response::success(['items' => array_column($rows, 'city')]);
