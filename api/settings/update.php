<?php
declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

Request::allow('PUT');

$actor = Auth::require([Users::SUPER_ADMIN]);
$input = Request::body()['settings'] ?? null;
if (!is_array($input) || $input === []) {
    Response::validation(['settings' => 'Provide at least one setting to update.']);
}

$known = [];
foreach (Database::fetchAll('SELECT setting_key, setting_value, value_type, label FROM settings') as $row) {
    $known[$row['setting_key']] = $row;
}

$v = new Validator($input);
$changes = [];
foreach ($input as $key => $value) {
    if (!isset($known[$key])) {
        $v->add((string) $key, 'Unknown setting.');
        continue;
    }
    if ($value !== null && !is_scalar($value)) {
        $v->add($key, 'Invalid value.');
        continue;
    }
    $setting = $known[$key];
    $v->string($key, $setting['label'], 0, 500);
    match ($setting['value_type']) {
        'email'  => $v->email($key, $setting['label']),
        'phone'  => $v->phone($key),
        'int'    => $v->integer($key, $setting['label']),
        'bool'   => $v->in($key, $setting['label'], ['0', '1']),
        default  => null,
    };
    if ($key === 'company_name') {
        $v->required($key, $setting['label']);
    }
    $normalized = $value === null || trim((string) $value) === '' ? null : trim((string) $value);
    if ($normalized !== $setting['setting_value']) {
        $changes[$key] = $normalized;
    }
}
if ($v->fails()) {
    Response::validation($v->errors());
}

foreach ($changes as $key => $value) {
    Database::execute('UPDATE settings SET setting_value = ?, updated_by = ? WHERE setting_key = ?', [$value, $actor['id'], $key]);
}
if ($changes !== []) {
    ActivityLogger::log($actor['id'], 'settings.updated', 'Updated system settings: ' . implode(', ', array_keys($changes)), 'settings', null, [
        'keys' => array_keys($changes),
    ]);
}

Response::success(['updated' => array_keys($changes)], $changes === [] ? 'No changes to save' : 'Settings saved');
