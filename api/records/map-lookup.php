<?php
declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

Request::allow('POST');
Auth::require([Users::ADMIN, Users::USER]);

$data = Request::body();
$url = trim((string) ($data['map_url'] ?? ''));
if ($url === '' || strlen($url) > 1000) {
    Response::validation(['map_url' => 'Paste a Google Maps link.']);
}

try {
    Response::success(['location' => MapLookup::address($url)], 'Location details found.');
} catch (RuntimeException $error) {
    Response::error($error->getMessage(), 422, ['map_url' => $error->getMessage()]);
}
