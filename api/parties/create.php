<?php
declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

Request::allow('POST');

$actor = Auth::require([Users::ADMIN, Users::USER]);
$data = Request::body();

[$values, $errors] = Parties::validate($data);
if ($errors) {
    Response::validation($errors);
}

// Never merge silently: an existing phone requires explicit confirmation to create another party.
if (($data['confirm_duplicate'] ?? false) !== true && Parties::findByPhone($values['phone']) !== []) {
    Response::error('A party with this phone number already exists.', 409, [
        'phone' => 'A party with this phone number already exists. Confirm to create a separate party.',
    ]);
}

$id = Parties::insert($values, $actor['id']);
ActivityLogger::log($actor['id'], 'party.created', "Created party: {$values['name']}", 'party', $id);

Response::success(['party' => Parties::format(Parties::findVisible($actor, $id), $actor)], 'Party created', 201);
