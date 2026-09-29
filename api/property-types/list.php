<?php
declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

Request::allow('GET');

$actor = Auth::require();

// Record forms use active types only; the Super Admin management page also sees inactive ones.
$includeInactive = $actor['role'] === Users::SUPER_ADMIN && Request::query('include_inactive') === '1';

$rows = Database::fetchAll(
    'SELECT t.id, t.slug, t.name, t.field_group, t.description, t.is_active, t.sort_order, t.updated_at,
            (SELECT COUNT(*) FROM real_estate_records r WHERE r.property_type_id = t.id) AS record_count
       FROM property_types t
      WHERE t.slug IN (\'rental\', \'sale\')' . ($includeInactive ? '' : ' AND t.is_active = 1') . '
      ORDER BY t.sort_order, t.name'
);

Response::success(['items' => array_map(static fn (array $t) => [
    'id'           => (int) $t['id'],
    'slug'         => $t['slug'],
    'name'         => $t['name'],
    'field_group'  => $t['field_group'],
    'description'  => $t['description'],
    'is_active'    => (bool) $t['is_active'],
    'sort_order'   => (int) $t['sort_order'],
    'record_count' => $actor['role'] === Users::SUPER_ADMIN ? (int) $t['record_count'] : null,
    'updated_at'   => iso_datetime($t['updated_at']),
], $rows)]);
