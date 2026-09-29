<?php
declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

Request::allow('POST');

$actor = Auth::require([Users::SUPER_ADMIN]);
Response::forbidden('Property transaction types are system masters and cannot be extended.');
