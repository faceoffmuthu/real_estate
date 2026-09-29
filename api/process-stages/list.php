<?php
declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

Request::allow('GET');

Auth::require();

$rows = Database::fetchAll('SELECT id, slug, name, sort_order, is_final FROM record_process_stages WHERE is_active = 1 ORDER BY sort_order');

Response::success(['items' => array_map(static fn (array $s) => [
    'id'       => (int) $s['id'],
    'slug'     => $s['slug'],
    'name'     => $s['name'],
    'is_final' => (bool) $s['is_final'],
], $rows)]);
