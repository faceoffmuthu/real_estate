<?php
declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

/*
 * Approval queue. Admin: pending records from Users they manage (oldest
 * submission first). Super Admin: read-only view of every pending record.
 * Same search/filters as records/list.php; the date range applies to submitted_at.
 */

Request::allow('GET');

$actor = Auth::require([Users::SUPER_ADMIN, Users::ADMIN]);

$filters = [
    'search'           => Request::query('search'),
    'property_type_id' => Request::query('property_type_id'),
    'transaction_type' => Request::query('transaction_type'),
    'property_category' => Request::query('property_category'),
    'process_stage_id' => Request::query('process_stage_id'),
    'created_by'       => Request::query('created_by'),
    'created_from'     => Request::query('submitted_from'),
    'created_to'       => Request::query('submitted_to'),
];
Records::validateFilters($filters);
$filters += ['approval_status' => Approvals::PENDING, 'record_status' => 'active', 'date_field' => 'submitted'];

// Admins only see records they are allowed to review (not their own).
if ($actor['role'] === Users::ADMIN) {
    $filters['created_by_role'] = Users::USER;
}

$page = Request::queryInt('page', 1);
$perPage = Request::queryInt('per_page', 20, 1, 100);
$result = Records::paginate($actor, $filters, $page, $perPage, 'r.submitted_at ASC, r.id ASC');

Response::success($result + ['can_review' => $actor['role'] === Users::ADMIN]);
