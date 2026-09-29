<?php
declare(strict_types=1);
require __DIR__ . '/../../bootstrap.php';
Request::allow('GET');
$actor = Auth::require([Users::SUPER_ADMIN, Users::ADMIN, Users::USER]);
[$scope, $params] = Records::scope($actor, 'r');
$recordWhere = "$scope AND r.record_status = 'active'";
$base = Database::fetch(
    "SELECT COUNT(*) AS total,
            SUM(r.transaction_type = 'rental') AS rental,
            SUM(r.transaction_type = 'sale') AS sale,
            SUM(r.property_category = 'residential') AS residential,
            SUM(r.property_category = 'commercial') AS commercial,
            SUM(r.approval_status = 'pending') AS pending_approvals,
            SUM(r.approval_status = 'approved') AS approved_records,
            SUM(r.approval_status = 'rejected') AS rejected_records
       FROM real_estate_records r WHERE $recordWhere",
    $params
);
$byCombination = Database::fetchAll(
    "SELECT t.slug AS transaction_type, c.slug AS property_category, COUNT(r.id) AS total
       FROM property_types t CROSS JOIN property_categories c
       LEFT JOIN real_estate_records r ON r.property_type_id = t.id AND r.property_category_id = c.id
            AND r.record_status = 'active' AND $scope
      WHERE t.is_active = 1 AND c.is_active = 1
      GROUP BY t.id, t.slug, c.id, c.slug ORDER BY t.sort_order, c.sort_order",
    $params
);
$stages = Database::fetchAll(
    "SELECT s.id, s.slug, s.name, COUNT(r.id) AS total
       FROM record_process_stages s
       LEFT JOIN real_estate_records r ON r.process_stage_id = s.id AND r.record_status = 'active' AND $scope
      GROUP BY s.id, s.slug, s.name, s.sort_order ORDER BY s.sort_order",
    $params
);
$months = Database::fetchAll(
    "SELECT DATE_FORMAT(r.created_at, '%Y-%m') AS month, COUNT(*) AS total
       FROM real_estate_records r
      WHERE $scope AND r.created_at >= DATE_FORMAT(CURRENT_DATE - INTERVAL 5 MONTH, '%Y-%m-01')
      GROUP BY month ORDER BY month",
    $params
);
$locations = Database::fetchAll(
    "SELECT COALESCE(NULLIF(r.city, ''), 'Unspecified') AS location, COUNT(*) AS total,
            SUM(r.transaction_type = 'rental') AS rental, SUM(r.transaction_type = 'sale') AS sale
       FROM real_estate_records r WHERE $recordWhere
      GROUP BY location ORDER BY total DESC, location LIMIT 10",
    $params
);
$financial = Database::fetch(
    "SELECT SUM(CASE WHEN r.transaction_type = 'rental' THEN r.rental_amount ELSE 0 END) AS monthly_rent_total,
            SUM(CASE WHEN r.transaction_type = 'rental' THEN r.security_deposit ELSE 0 END) AS rental_deposit_total,
            SUM(CASE WHEN r.transaction_type = 'sale' THEN r.sale_amount ELSE 0 END) AS sale_price_total,
            AVG(CASE WHEN r.transaction_type = 'sale' AND r.area_sqft > 0 AND r.sale_amount > 0 THEN r.sale_amount / r.area_sqft END) AS sale_price_per_sqft_avg
       FROM real_estate_records r WHERE $recordWhere",
    $params
);
$follow = Database::fetch(
    "SELECT COUNT(*) AS followups_total,
            SUM(f.status = 'pending') AS followups_pending,
            SUM(f.status = 'completed') AS followups_completed,
            SUM(f.status = 'cancelled') AS followups_cancelled,
            SUM(f.status = 'pending' AND f.due_at >= CURRENT_DATE AND f.due_at < CURRENT_DATE + INTERVAL 1 DAY) AS followups_due_today,
            SUM(f.status = 'pending' AND f.due_at >= CURRENT_DATE + INTERVAL 1 DAY) AS followups_upcoming,
            SUM(f.status = 'pending' AND f.due_at < NOW()) AS followups_overdue
       FROM follow_ups f JOIN real_estate_records r ON r.id = f.record_id
      WHERE $recordWhere",
    $params
);
$resubmitted = (int) Database::value(
    "SELECT COUNT(*) FROM record_approval_history h JOIN real_estate_records r ON r.id = h.record_id WHERE $scope AND h.action = 'resubmitted'",
    $params
);
$accounts = ['admins' => 0, 'users' => 0];
if ($actor['role'] === Users::SUPER_ADMIN) {
    $counts = Database::fetch("SELECT SUM(role.slug = 'admin') admins, SUM(role.slug = 'user') users FROM users u JOIN roles role ON role.id = u.role_id");
    $accounts = ['admins' => (int) $counts['admins'], 'users' => (int) $counts['users']];
}
$performance = [];
if ($actor['role'] === Users::SUPER_ADMIN) {
    $performance = Database::fetchAll(
        "SELECT u.id, u.name, role.slug AS role,
                (SELECT COUNT(*) FROM real_estate_records r WHERE r.created_by = u.id) AS records_created,
                (SELECT COUNT(*) FROM record_approval_history h WHERE h.performed_by = u.id AND h.action = 'approved') AS records_approved,
                (SELECT COUNT(*) FROM record_approval_history h WHERE h.performed_by = u.id AND h.action = 'rejected') AS records_rejected,
                (SELECT COUNT(*) FROM follow_ups f WHERE f.created_by = u.id) AS followups_created,
                (SELECT COUNT(*) FROM follow_ups f WHERE f.assigned_to = u.id AND f.status = 'completed') AS followups_completed
           FROM users u JOIN roles role ON role.id = u.role_id
          WHERE role.slug IN ('admin','user') ORDER BY role.level DESC, u.name LIMIT 50"
    );
    $performance = array_map(static fn (array $row) => array_map(
        static fn ($v) => is_numeric($v) ? (int) $v : $v,
        $row
    ), $performance);
}
$approvalRows = Database::fetchAll(
    "SELECT r.approval_status AS status, COUNT(*) AS total FROM real_estate_records r
      WHERE $recordWhere GROUP BY r.approval_status",
    $params
);
Response::success([
    'stats' => array_map('intval', array_merge($base, $accounts, ['resubmitted_records' => $resubmitted], $follow)),
    'by_combination' => array_map(static fn (array $row) => ['transaction_type' => $row['transaction_type'], 'property_category' => $row['property_category'], 'total' => (int) $row['total']], $byCombination),
    'by_approval' => array_map(static fn (array $row) => ['status' => $row['status'], 'total' => (int) $row['total']], $approvalRows),
    'by_stage' => array_map(static fn (array $row) => ['id' => (int) $row['id'], 'slug' => $row['slug'], 'name' => $row['name'], 'total' => (int) $row['total']], $stages),
    'created_by_month' => array_map(static fn (array $row) => ['month' => $row['month'], 'total' => (int) $row['total']], $months),
    'by_location' => array_map(static fn (array $row) => ['location' => $row['location'], 'total' => (int) $row['total'], 'rental' => (int) $row['rental'], 'sale' => (int) $row['sale']], $locations),
    'financial' => array_map(static fn ($value) => $value === null ? null : (float) $value, $financial),
    'user_performance' => $performance,
]);
