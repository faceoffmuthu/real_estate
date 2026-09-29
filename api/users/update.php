<?php
declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

Request::allow('PUT');

$actor = Auth::require([Users::SUPER_ADMIN, Users::ADMIN]);
$data = Request::body();

$id = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($id === false) {
    Response::validation(['id' => 'A valid user ID is required.']);
}

$target = Users::find($id);
if ($target === null) {
    Response::notFound('User not found.');
}
if (!Users::canManage($actor, $target)) {
    Response::forbidden('You do not have permission to manage this account.');
}

// Only the Super Admin may change role or assigned admin.
$isSuperAdmin = $actor['role'] === Users::SUPER_ADMIN;
$roleChanged = array_key_exists('role', $data) && $data['role'] !== $target['role'];
$managerChanged = array_key_exists('manager_id', $data) && (int) $data['manager_id'] !== (int) $target['manager_id'];
if (!$isSuperAdmin && ($roleChanged || $managerChanged)) {
    Response::forbidden('You are not allowed to change the role or assigned admin of this account.');
}
if ($roleChanged && is_string($data['role']) && !in_array($data['role'], Users::assignableRoles($actor), true)) {
    Response::forbidden('You are not allowed to assign this role.');
}

$v = (new Validator($data))
    ->string('name', 'Name', 2, 120)
    ->username('username')
    ->email('email')->string('email', 'Email', 3, 190)
    ->phone('phone')
    ->password('password')
    ->in('role', 'Role', Users::assignableRoles($actor))
    ->in('status', 'Status', ['active', 'inactive']);

if ($isSuperAdmin) {
    $v->required('phone', 'Phone');
}

// Fields that were sent but are empty — these are required and cannot be cleared.
foreach (['name' => 'Name', 'username' => 'Username', 'email' => 'Email', 'role' => 'Role', 'status' => 'Status'] as $field => $label) {
    if (array_key_exists($field, $data)) {
        $v->required($field, $label);
    }
}

$newRole = $data['role'] ?? $target['role'];
$newManagerId = $target['manager_id'] !== null ? (int) $target['manager_id'] : null;

if ($isSuperAdmin && !$v->fails()) {
    if ($newRole === Users::ADMIN) {
        $newManagerId = null;
    } elseif ($roleChanged || $managerChanged) {
        $requested = filter_var($data['manager_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($requested === false || $requested === (int) $id || ($newManagerId = Users::activeAdminId($requested)) === null) {
            $v->add('manager_id', 'Assigned admin must be an active Admin account.');
        }
    }
    if ($roleChanged && $target['role'] === Users::ADMIN) {
        $managed = (int) Database::value('SELECT COUNT(*) FROM users WHERE manager_id = ?', [$id]);
        if ($managed > 0) {
            $v->add('role', "This admin still manages $managed user(s). Reassign them before changing the role.");
        }
    }
}

$username = array_key_exists('username', $data) && is_string($data['username']) ? trim($data['username']) : null;
$email = array_key_exists('email', $data) && is_string($data['email']) ? strtolower(trim($data['email'])) : null;
Users::checkUnique($v, $username, $email, $id);

if ($v->fails()) {
    Response::validation($v->errors());
}

// Build the change set from fields that actually differ.
$changes = [];
if (array_key_exists('name', $data) && trim($data['name']) !== $target['name']) {
    $changes['name'] = trim($data['name']);
}
if ($username !== null && $username !== $target['username']) {
    $changes['username'] = $username;
}
if ($email !== null && $email !== $target['email']) {
    $changes['email'] = $email;
}
if (array_key_exists('phone', $data)) {
    $phone = trim((string) $data['phone']);
    $phone = $phone === '' ? null : $phone;
    if ($phone !== $target['phone']) {
        $changes['phone'] = $phone;
    }
}
if (array_key_exists('status', $data) && $data['status'] !== $target['status']) {
    $changes['status'] = $data['status'];
}
if ($roleChanged) {
    $changes['role_id'] = Users::roleId($newRole);
}
if ($newManagerId !== ($target['manager_id'] !== null ? (int) $target['manager_id'] : null)) {
    $changes['manager_id'] = $newManagerId;
}
$passwordReset = !empty($data['password']);
if ($passwordReset) {
    $changes['password_hash'] = password_hash($data['password'], PASSWORD_DEFAULT);
}

if ($changes === []) {
    Response::success(['user' => Users::format($target)], 'No changes to save');
}

$columns = implode(', ', array_map(static fn (string $c) => "$c = ?", array_keys($changes)));
try {
    Database::execute("UPDATE users SET $columns WHERE id = ?", [...array_values($changes), $id]);
} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        Response::validation(['email' => 'This username or email is already registered.']);
    }
    throw $e;
}

// Deactivation or password reset signs the account out everywhere.
if (($changes['status'] ?? null) === 'inactive' || $passwordReset) {
    Auth::revokeAllFor($id);
}

// Activity log — never includes password values.
$label = $target['email'];
$profileFields = array_values(array_intersect(array_keys($changes), ['name', 'username', 'email', 'phone']));
if ($profileFields !== []) {
    ActivityLogger::log($actor['id'], 'user.updated', "Updated account details: $label", 'user', $id, ['fields' => $profileFields]);
}
if (isset($changes['status'])) {
    $verb = $changes['status'] === 'active' ? 'activated' : 'deactivated';
    ActivityLogger::log($actor['id'], "user.$verb", ucfirst($verb) . " account: $label", 'user', $id, [
        'from' => $target['status'],
        'to'   => $changes['status'],
    ]);
}
if ($roleChanged) {
    ActivityLogger::log($actor['id'], 'user.role_changed', "Changed role of $label to $newRole", 'user', $id, [
        'from' => $target['role'],
        'to'   => $newRole,
    ]);
}
if (array_key_exists('manager_id', $changes) && !$roleChanged) {
    ActivityLogger::log($actor['id'], 'user.reassigned', "Reassigned $label to another admin", 'user', $id, [
        'from' => $target['manager_id'] !== null ? (int) $target['manager_id'] : null,
        'to'   => $newManagerId,
    ]);
}
if ($passwordReset) {
    ActivityLogger::log($actor['id'], 'user.password_reset', "Reset password for $label", 'user', $id);
}

Response::success(['user' => Users::format(Users::find($id))], 'Account updated');
