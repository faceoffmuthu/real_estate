<?php
declare(strict_types=1);
require __DIR__ . '/../../bootstrap.php';
Request::allow('GET');
Auth::require();
$rows = Database::fetchAll('SELECT id, slug, name, sort_order, is_active FROM property_categories WHERE is_active = 1 ORDER BY sort_order, name');
Response::success(['items' => array_map(static fn (array $r) => [
    'id' => (int) $r['id'],
    'slug' => $r['slug'],
    'name' => $r['name'],
    'sort_order' => (int) $r['sort_order'],
], $rows)]);
