<?php
declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

Request::allow('GET');

// Used by the record form to offer reuse of an existing party with the same phone.
Auth::require([Users::ADMIN, Users::USER]);

$raw = Request::query('phone');
$phone = Phone::normalize($raw);
if ($phone === null) {
    Response::validation(['phone' => 'Enter a valid phone number.']);
}

Response::success([
    'phone'   => $phone,
    'matches' => array_map(static fn (array $p) => [
        'id'    => (int) $p['id'],
        'name'  => $p['name'],
        'phone' => $p['phone'],
    ], Parties::findByPhone($phone)),
]);
