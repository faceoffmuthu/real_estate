<?php
declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

Request::allow('GET');

Auth::require([Users::SUPER_ADMIN]);

$rows = Database::fetchAll(
    'SELECT s.setting_key, s.setting_value, s.value_type, s.label, s.description, s.is_public, s.updated_at,
            u.name AS updated_by_name
       FROM settings s LEFT JOIN users u ON u.id = s.updated_by
      ORDER BY s.id'
);

Response::success(['items' => array_map(static fn (array $s) => [
    'key'         => $s['setting_key'],
    'value'       => $s['setting_value'],
    'type'        => $s['value_type'],
    'label'       => $s['label'],
    'description' => $s['description'],
    'is_public'   => (bool) $s['is_public'],
    'updated_at'  => iso_datetime($s['updated_at']),
    'updated_by'  => $s['updated_by_name'],
], $rows)]);
