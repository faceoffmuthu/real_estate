<?php
declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

Request::allow('GET');

$actor = Auth::require([Users::SUPER_ADMIN, Users::ADMIN]);

// Only the roles this caller may assign.
$assignable = Users::assignableRoles($actor);
$placeholders = implode(', ', array_fill(0, count($assignable), '?'));
$roles = Database::fetchAll(
    "SELECT slug, name, description FROM roles WHERE slug IN ($placeholders) ORDER BY level DESC",
    $assignable
);

Response::success(['items' => $roles]);
