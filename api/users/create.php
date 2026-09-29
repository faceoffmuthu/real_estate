<?php
declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

Request::allow('POST');

$actor = Auth::require([Users::SUPER_ADMIN, Users::ADMIN]);
$data = Request::body();

// Admins may only create Users, always under themselves.
$allowedRoles = Users::assignableRoles($actor);
$role = $data['role'] ?? Users::USER;
if (is_string($role) && in_array($role, [Users::SUPER_ADMIN, Users::ADMIN, Users::USER], true) && !in_array($role, $allowedRoles, true)) {
    Response::forbidden('You are not allowed to create accounts with this role.');
}
$data['role'] = $role;

$v = (new Validator($data))
    ->required('name', 'Name')->string('name', 'Name', 2, 120)
    ->required('username', 'Username')->username('username')
    ->required('email', 'Email')->email('email')->string('email', 'Email', 3, 190)
    ->phone('phone');
if ($actor['role'] === Users::SUPER_ADMIN) {
    $v->required('phone', 'Phone');
}
$v
    ->required('password', 'Password')->password('password')
    ->required('role', 'Role')->in('role', 'Role', $allowedRoles)
    ->in('status', 'Status', ['active', 'inactive']);

$managerId = null;
if ($role === Users::USER) {
    if ($actor['role'] === Users::ADMIN) {
        $managerId = $actor['id'];
    } else {
        $v->required('manager_id', 'Assigned admin')->integer('manager_id', 'Assigned admin');
        if (!$v->fails() && ($managerId = Users::activeAdminId((int) $data['manager_id'])) === null) {
            $v->add('manager_id', 'Assigned admin must be an active Admin account.');
        }
    }
}

$username = is_string($data['username'] ?? null) ? trim($data['username']) : null;
$email = is_string($data['email'] ?? null) ? strtolower(trim($data['email'])) : null;
Users::checkUnique($v, $username, $email);

if ($v->fails()) {
    Response::validation($v->errors());
}

$phone = trim((string) ($data['phone'] ?? ''));
$status = $data['status'] ?? 'active';

try {
    $id = Database::insert(
        'INSERT INTO users (role_id, manager_id, name, username, email, phone, password_hash, status, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [
            Users::roleId($role),
            $managerId,
            trim($data['name']),
            $username,
            $email,
            $phone === '' ? null : $phone,
            password_hash($data['password'], PASSWORD_DEFAULT),
            $status,
            $actor['id'],
        ]
    );
} catch (PDOException $e) {
    if ($e->getCode() === '23000') { // unique constraint race
        Response::validation(['email' => 'This username or email is already registered.']);
    }
    throw $e;
}

$roleLabel = $role === Users::ADMIN ? 'Admin' : 'User';
ActivityLogger::log($actor['id'], 'user.created', "Created $roleLabel account: $email", 'user', $id, [
    'role'       => $role,
    'manager_id' => $managerId,
    'status'     => $status,
]);

Response::success(['user' => Users::format(Users::find($id))], "$roleLabel account created", 201);
