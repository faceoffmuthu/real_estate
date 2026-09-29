<?php
declare(strict_types=1);

section('Phase 5 — Reports and analytics');
foreach (['sa' => [$sa, 1], 'admin' => [$ad, 2], 'user' => [$us, 3]] as $role => [$token, $expectedId]) {
    $me = http('GET', 'auth/me.php?user_id=1', null, $token);
    check("$role profile comes from authenticated session, ignoring user_id", $me['status'] === 200 && (int) ($me['body']['data']['user']['id'] ?? 0) === $expectedId);
    $summary = http('GET', 'reports/summary.php', null, $token);
    check("$role receives live analytics", $summary['status'] === 200 && isset($summary['body']['data']['stats']['total'], $summary['body']['data']['by_combination'], $summary['body']['data']['by_stage'], $summary['body']['data']['financial']));
    $report = http('GET', 'reports/list.php?report=properties&per_page=100', null, $token);
    check("$role can access permitted property report", $report['status'] === 200 && isset($report['body']['data']['pagination']));
}
$saSummary = http('GET', 'reports/summary.php', null, $sa)['body']['data'] ?? [];
check('property and follow-up totals are distinct live metrics', isset($saSummary['stats']['total'], $saSummary['stats']['followups_total'])
    && (int) $saSummary['stats']['total'] === (int) Database::value("SELECT COUNT(*) FROM real_estate_records WHERE record_status = 'active'")
    && (int) $saSummary['stats']['followups_total'] === (int) Database::value('SELECT COUNT(*) FROM follow_ups'));
check('analytics returns all four classification combinations', count($saSummary['by_combination'] ?? []) === 4);
expectStatus('reports summary requires authentication', http('GET', 'reports/summary.php'), 401);
expectStatus('reports export requires authentication', http('GET', 'reports/list.php?report=properties&format=csv'), 401);
$userReport = http('GET', 'reports/list.php?report=properties&per_page=100', null, $us);
$userReportIds = array_column($userReport['body']['data']['items'] ?? [], 'id');
check('user report excludes other team records', in_array($rec1['id'], $userReportIds, true) && !in_array($recOther['id'], $userReportIds, true) && !in_array($recAdmin['id'], $userReportIds, true));
$adminReport = http('GET', 'reports/list.php?report=properties&per_page=100', null, $ad);
$adminReportIds = array_column($adminReport['body']['data']['items'] ?? [], 'id');
check('admin report excludes other teams', in_array($rec1['id'], $adminReportIds, true) && !in_array($recOther['id'], $adminReportIds, true));
$combo = http('GET', 'reports/list.php?report=properties&transaction_type=sale&property_category=commercial&per_page=100', null, $sa);
check('property report filters transaction + category server side', $combo['status'] === 200 && count($combo['body']['data']['items']) > 0 && count(array_filter($combo['body']['data']['items'], fn ($row) => $row['transaction_type'] === 'sale' && $row['property_category'] === 'commercial')) === count($combo['body']['data']['items']));
expectStatus('report rejects invalid date', http('GET', 'reports/list.php?report=properties&from=2026-02-31', null, $sa), 422);
expectStatus('report rejects unsafe sort', http('GET', 'reports/list.php?report=properties&sort=DROP%20TABLE', null, $sa), 422);
expectStatus('report rejects invalid follow-up type', http('GET', 'reports/list.php?report=followups&type=credential_dump', null, $sa), 422);
foreach (['approvals', 'followups', 'activity'] as $kind) {
    $r = http('GET', "reports/list.php?report=$kind&per_page=5", null, $sa);
    check("$kind report query succeeds", $r['status'] === 200 && isset($r['body']['data']['pagination']));
}
$csv = http('GET', 'reports/list.php?report=properties&format=csv&per_page=1', null, $ad, ['Accept: text/csv']);
check('CSV export returns attachment with report columns', $csv['status'] === 200 && stripos($csv['headers'], 'text/csv') !== false && str_contains($csv['raw'], 'transaction_type') && str_contains($csv['raw'], 'property_category'));
$scopedCsv = http('GET', 'reports/list.php?report=properties&format=csv&per_page=100', null, $us, ['Accept: text/csv']);
check('CSV export applies user scope', !str_contains($scopedCsv['raw'], $recOther['reference']) && !str_contains($scopedCsv['raw'], $recAdmin['reference']));
