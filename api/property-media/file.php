<?php
declare(strict_types=1);
require __DIR__ . '/../../bootstrap.php';
Request::allow('GET');
$actor = Auth::require();
$id = Request::queryInt('id', 0, 0);
if ($id < 1) Response::notFound('Media was not found.');
PropertyMedia::stream($id, $actor);
