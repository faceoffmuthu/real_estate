<?php
declare(strict_types=1);
require __DIR__ . '/../../bootstrap.php';
Request::allow('GET');
Auth::require([Users::ADMIN, Users::USER]);
Response::success(IndiaLocations::all());
