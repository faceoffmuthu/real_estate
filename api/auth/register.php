<?php
declare(strict_types=1);
require __DIR__ . '/../../bootstrap.php';
Request::allow('POST');
Response::error('Public registration is disabled.', 410);
