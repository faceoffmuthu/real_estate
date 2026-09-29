<?php
declare(strict_types=1);

/*
 * Phase 3 tests — approval workflow, security, history, notifications.
 * Included by api_test.php after phase2_records.php (reuses its sessions:
 * $sa, $ad (Admin id 2), $us (User id 3, managed by Admin 2), $ad2, $ot (Admin2's user)).
 */

/** @var string $sa @var string $ad @var string $us @var string $ad2 @var string $ot @var string $runId */

$p3phone = '8' . substr((string) random_int(100000000, 999999999), 0, 9);
$p3 = fn (array $extra = []) => array_replace_recursive([
    'title' => "P3 villa $runId", 'property_type_id' => 2, 'transaction_type' => 'sale', 'property_category_id' => 1, 'property_category' => 'residential', 'purpose' => 'sale', 'process_stage_id' => 1,
    'city' => "P3city$runId", 'locality' => 'Avadi', 'sale_amount' => '9000000',
    'party' => ['name' => "P3 Party $runId", 'phone' => $p3phone], 'confirm_new_party' => true,
], $extra);
$view = fn (int $id, string $tok) => http('GET', "records/view.php?id=$id", null, $tok);
$status = fn (int $id) => Database::value('SELECT approval_status FROM real_estate_records WHERE id = ?', [$id]);
$queueIds = fn (string $tok, string $q = '') => array_column(http('GET', "approvals/pending.php?per_page=100$q", null, $tok)['body']['data']['items'] ?? [], 'id');
$notes = fn (string $tok) => http('GET', 'notifications/list.php?limit=50', null, $tok)['body']['data'] ?? [];

// ---------------------------------------------------------------------
section('Phase 3 — Drafts');
$r = http('POST', 'records/create.php', $p3(), $us);
check('user saves draft (no submit flag)', $r['status'] === 201 && ($r['body']['data']['record']['approval']['status'] ?? '') === 'draft' && str_contains($r['body']['message'], 'draft'));
$draft = $r['body']['data']['record']['id'] ?? 0;
expectStatus('draft is private: admin cannot view', $view($draft, $ad), 404);
expectStatus('draft is private: super admin cannot view', $view($draft, $sa), 404);
check('draft not in approval queue', !in_array($draft, $queueIds($ad), true));
$r = $view($draft, $us);
check('creator sees draft with can_submit', $r['status'] === 200 && $r['body']['data']['can_submit'] === true && $r['body']['data']['edit_mode'] === 'all');

// ---------------------------------------------------------------------
section('Phase 3 — Submit');
$adminUnreadBefore = $notes($ad)['unread_count'] ?? 0;
expectStatus('admin cannot call submit', http('POST', 'records/submit.php', ['id' => $draft], $ad), 403);
expectStatus("other user cannot submit someone's draft", http('POST', 'records/submit.php', ['id' => $draft], $ot), 404);
$r = http('POST', 'records/submit.php', ['id' => $draft], $us);
check('user submits draft → pending with submitted_at', $r['status'] === 200 && $r['body']['data']['record']['approval']['status'] === 'pending' && $r['body']['data']['record']['approval']['submitted_at'] !== null);
expectStatus('submit again → 409 (already pending)', http('POST', 'records/submit.php', ['id' => $draft], $us), 409);
check('admin sees record in approval queue', in_array($draft, $queueIds($ad), true));
check("other team's admin does not see it", !in_array($draft, $queueIds($ad2), true));
check('super admin sees queue read-only', in_array($draft, $queueIds($sa), true) && http('GET', 'approvals/pending.php', null, $sa)['body']['data']['can_review'] === false);
expectStatus('user cannot open approval queue', http('GET', 'approvals/pending.php', null, $us), 403);
check('admin notified of pending approval', ($notes($ad)['unread_count'] ?? 0) === $adminUnreadBefore + 1
    && ($notes($ad)['items'][0]['type'] ?? '') === 'approval.pending' && ($notes($ad)['items'][0]['entity_id'] ?? 0) === $draft);
check('user notified of submission', in_array('record.submitted', array_column($notes($us)['items'] ?? [], 'type'), true));
check('admin pending_approvals counter', ($notes($ad)['pending_approvals'] ?? 0) >= 1);

// Direct submit on create.
$r = http('POST', 'records/create.php', $p3(['title' => "P3 direct $runId", 'submit' => true]), $us);
check('user creates + submits in one step → pending', $r['status'] === 201 && ($r['body']['data']['record']['approval']['status'] ?? '') === 'pending');
$direct = $r['body']['data']['record']['id'] ?? 0;

// ---------------------------------------------------------------------
section('Phase 3 — Queue search, filters, pagination');
$ref = Database::value('SELECT record_reference FROM real_estate_records WHERE id = ?', [$draft]);
check('queue search by reference', $queueIds($ad, '&search=' . urlencode($ref)) === [$draft]);
check('queue search by creator name', in_array($draft, $queueIds($ad, '&search=Demo%20User'), true));
check('queue search by phone digits', count(array_intersect([$draft, $direct], $queueIds($ad, '&search=' . substr($p3phone, 3, 6)))) === 2);
check('queue search by location', in_array($draft, $queueIds($ad, '&search=' . urlencode("P3city$runId")), true));
check('queue filter by property type', in_array($draft, $queueIds($ad, '&property_type_id=2'), true) && !in_array($draft, $queueIds($ad, '&property_type_id=1'), true));
$today = gmdate('Y-m-d');
check('queue filter by submitted date', in_array($draft, $queueIds($ad, "&submitted_from=$today&submitted_to=$today"), true) && $queueIds($ad, '&submitted_from=2099-01-01') === []);
check('queue filter by creator', in_array($draft, $queueIds($ad, '&created_by=3'), true) && !in_array($draft, $queueIds($ad, '&created_by=2'), true));
$r = http('GET', "approvals/pending.php?per_page=1&search=P3city$runId", null, $ad);
check('queue pagination', count($r['body']['data']['items']) === 1 && $r['body']['data']['pagination']['total'] === 2);
check('queue oldest submission first', $queueIds($ad, "&search=P3city$runId") === [$draft, $direct]);
expectStatus('queue invalid date → 422', http('GET', 'approvals/pending.php?submitted_from=2026-99-01', null, $ad), 422);

// ---------------------------------------------------------------------
section('Phase 3 — Review security');
expectStatus('user cannot approve own record', http('POST', 'approvals/approve.php', ['id' => $draft], $us), 403);
expectStatus('user cannot reject own record', http('POST', 'approvals/reject.php', ['id' => $draft, 'reason' => 'Self reject'], $us), 403);
expectStatus('super admin cannot approve (view-only)', http('POST', 'approvals/approve.php', ['id' => $draft], $sa), 403);
expectStatus("other team's admin cannot approve", http('POST', 'approvals/approve.php', ['id' => $draft], $ad2), 404);
expectStatus('unauthenticated approve', http('POST', 'approvals/approve.php', ['id' => $draft]), 401);
expectStatus('approve invalid id', http('POST', 'approvals/approve.php', ['id' => "1 OR 1=1"], $ad), 422);
expectStatus('approve missing record', http('POST', 'approvals/approve.php', ['id' => 99999999], $ad), 404);
$r = http('POST', 'records/create.php', $p3(['title' => "P3 admin direct $runId"]), $ad);
$adminRec = $r['body']['data']['record'] ?? [];
check('admin-created record is approved directly', $r['status'] === 201 && ($adminRec['approval']['status'] ?? '') === 'approved' && ($adminRec['approval']['reviewed_by']['id'] ?? 0) === 2);
check('admin-created record not in queue', !in_array($adminRec['id'] ?? 0, $queueIds($ad), true));
check('admin-created history = created_approved', array_column(Approvals::history($adminRec['id'] ?? 0), 'action') === ['created_approved']);
expectStatus('admin cannot "approve" own record', http('POST', 'approvals/approve.php', ['id' => $adminRec['id'] ?? 0], $ad), 403);
expectStatus('user cannot submit admin record', http('POST', 'records/submit.php', ['id' => $adminRec['id'] ?? 0], $us), 404);

// Client cannot manipulate approval columns through record updates.
$v = $view($draft, $us)['body']['data'];
$edit = $p3(['id' => $draft, 'title' => "P3 villa edited $runId", 'approval_status' => 'approved', 'reviewed_by' => 3, 'reviewed_at' => '2020-01-01 00:00:00', 'rejection_reason' => 'x']);
unset($edit['party']);
$edit['party_id'] = $v['party']['id'];
$r = http('PUT', 'records/update.php', $edit, $us);
check('user edits pending record → stays pending, approval fields ignored', $r['status'] === 200 && $status($draft) === 'pending'
    && Database::value('SELECT reviewed_by FROM real_estate_records WHERE id = ?', [$draft]) === null);
check('pending edit recorded in history', in_array('edited', array_column(Approvals::history($draft), 'action'), true));

// ---------------------------------------------------------------------
section('Phase 3 — Reject');
expectStatus('reject without reason → 422', http('POST', 'approvals/reject.php', ['id' => $draft], $ad), 422);
expectStatus('reject with too-short reason → 422', http('POST', 'approvals/reject.php', ['id' => $draft, 'reason' => 'no'], $ad), 422);
$reason = 'Phone number needs verification';
$r = http('POST', 'approvals/reject.php', ['id' => $draft, 'reason' => $reason], $ad);
check('admin rejects with reason', $r['status'] === 200 && $r['body']['data']['record']['approval']['status'] === 'rejected'
    && $r['body']['data']['record']['approval']['rejection_reason'] === $reason && $r['body']['data']['record']['approval']['reviewed_by']['id'] === 2);
check('rejected record leaves the queue', !in_array($draft, $queueIds($ad), true));
expectStatus('reject again → 409', http('POST', 'approvals/reject.php', ['id' => $draft, 'reason' => $reason], $ad), 409);
expectStatus('approve a rejected record → 409 (must be resubmitted)', http('POST', 'approvals/approve.php', ['id' => $draft], $ad), 409);
$r = $view($draft, $us);
check('user sees rejection reason + can resubmit', $r['body']['data']['record']['approval']['rejection_reason'] === $reason && $r['body']['data']['can_submit'] === true);
$n = $notes($us)['items'][0] ?? [];
check('user notified of rejection with reason', ($n['type'] ?? '') === 'record.rejected' && str_contains($n['message'] ?? '', $reason));

// ---------------------------------------------------------------------
section('Phase 3 — Resubmit + approve');
$edit['title'] = "P3 villa corrected $runId";
$edit['submit'] = true;
$r = http('PUT', 'records/update.php', $edit, $us);
check('user corrects + resubmits → pending', $r['status'] === 200 && $status($draft) === 'pending' && str_contains($r['body']['message'], 'submitted'));
check('record rejection_reason cleared for new round', Database::value('SELECT rejection_reason FROM real_estate_records WHERE id = ?', [$draft]) === null);
check('rejection kept in history', in_array($reason, array_column(Approvals::history($draft), 'reason'), true));
check('resubmitted record back in queue', in_array($draft, $queueIds($ad), true));
$r = http('POST', 'approvals/approve.php', ['id' => $draft], $ad);
check('admin approves', $r['status'] === 200 && $r['body']['data']['record']['approval']['status'] === 'approved' && $r['body']['message'] === 'Record approved successfully');
expectStatus('approve again → 409', http('POST', 'approvals/approve.php', ['id' => $draft], $ad), 409);
expectStatus('submit approved record → 409', http('POST', 'records/submit.php', ['id' => $draft], $us), 409);
check('user notified of approval', ($notes($us)['items'][0]['type'] ?? '') === 'record.approved');
$h = http('GET', "approvals/history.php?record_id=$draft", null, $us)['body']['data']['items'] ?? [];
check('full timeline kept', array_column($h, 'action') === ['created_draft', 'submitted', 'edited', 'rejected', 'resubmitted', 'approved']);
check('timeline records who acted', ($h[3]['performed_by']['id'] ?? 0) === 2 && ($h[1]['performed_by']['id'] ?? 0) === 3);
foreach (['record.submitted', 'record.rejected', 'record.resubmitted', 'record.approved'] as $a) {
    check("activity logged: $a", (int) Database::value('SELECT COUNT(*) FROM activity_logs WHERE action = ? AND entity_id = ?', [$a, $draft]) > 0);
}
$view($direct, $ad);
check('activity logged: record.review_opened', (int) Database::value("SELECT COUNT(*) FROM activity_logs WHERE action = 'record.review_opened' AND entity_id = ?", [$direct]) === 1);

// ---------------------------------------------------------------------
section('Phase 3 — Edit rules after approval');
$r = $view($draft, $us);
check('approved record: user edit_mode = progress', $r['body']['data']['edit_mode'] === 'progress' && $r['body']['data']['can_submit'] === false);
unset($edit['submit']);
expectStatus('user cannot change core fields of approved record', http('PUT', 'records/update.php', ['title' => 'Hijack'] + $edit, $us), 403);
expectStatus('user cannot archive approved record', http('PUT', 'records/update.php', ['record_status' => 'archived'] + $edit, $us), 403);
$r = http('PUT', 'records/update.php', ['process_stage_id' => 4, 'process_notes' => 'Site visit booked'] + $edit, $us);
check('user updates stage + notes on approved record', $r['status'] === 200 && $status($draft) === 'approved');
$r = http('PUT', 'records/update.php', ['process_stage_id' => 4, 'process_notes' => 'Site visit booked', 'title' => "P3 admin retitled $runId"] + $edit, $ad);
check('admin can still edit approved team record (stays approved)', $r['status'] === 200 && $status($draft) === 'approved');

// ---------------------------------------------------------------------
section('Phase 3 — History, lists & dashboards');
expectStatus("other team cannot read record history", http('GET', "approvals/history.php?record_id=$draft", null, $ot), 404);
expectStatus('super admin reads record history', http('GET', "approvals/history.php?record_id=$draft", null, $sa), 200);
$r = http('GET', 'approvals/history.php?action=rejected&per_page=100', null, $sa);
check('super admin global history (filter: rejected)', in_array($draft, array_column(array_column($r['body']['data']['items'], 'record'), 'id'), true)
    && array_unique(array_column($r['body']['data']['items'], 'action')) === ['rejected']);
expectStatus('history invalid action → 422', http('GET', 'approvals/history.php?action=hacked', null, $sa), 422);
$r = http('GET', 'approvals/history.php?per_page=100', null, $ot);
check('user history scoped to own records', !in_array($draft, array_column(array_column($r['body']['data']['items'], 'record'), 'id'), true));

$ids = fn (string $tok, string $q) => array_column(http('GET', "records/list.php?per_page=100&$q", null, $tok)['body']['data']['items'] ?? [], 'id');
check('records filter approval_status=approved', in_array($draft, $ids($us, 'approval_status=approved'), true) && !in_array($direct, $ids($us, 'approval_status=approved'), true));
check('records filter approval_status=pending', in_array($direct, $ids($ad, 'approval_status=pending'), true));
check('records filter created_by', in_array($adminRec['id'], $ids($ad, 'created_by=2'), true) && !in_array($direct, $ids($ad, 'created_by=2'), true));
expectStatus('records invalid approval filter → 422', http('GET', 'records/list.php?approval_status=hacked', null, $us), 422);
check('records list returns creators for admin filter', count(http('GET', 'records/list.php', null, $ad)['body']['data']['creators'] ?? []) >= 2);

$u = http('GET', 'dashboard/user.php', null, $us)['body']['data'] ?? [];
check('user dashboard approval counts', ($u['records']['by_approval']['approved'] ?? 0) >= 1 && ($u['records']['by_approval']['pending'] ?? 0) >= 1 && array_key_exists('rejected', $u['approvals'] ?? []));
$a = http('GET', 'dashboard/admin.php', null, $ad)['body']['data'] ?? [];
check('admin dashboard queue preview + activity', in_array($direct, array_column($a['approvals']['items'] ?? [], 'id'), true) && count($a['approvals']['recent_activity'] ?? []) > 0);
$s = http('GET', 'dashboard/super-admin.php', null, $sa)['body']['data'] ?? [];
check('super admin dashboard approval stats', isset($s['records']['by_approval']['pending']) && in_array($draft, array_column(array_column($s['approvals']['recent_approved'] ?? [], 'record'), 'id'), true)
    && in_array($draft, array_column(array_column($s['approvals']['recent_rejected'] ?? [], 'record'), 'id'), true));
check('by_approval counts match database', ($s['records']['by_approval']['pending'] ?? -1) === (int) Database::value("SELECT COUNT(*) FROM real_estate_records WHERE approval_status = 'pending' AND record_status = 'active'"));

// ---------------------------------------------------------------------
section('Phase 3 — Notifications');
$mine = $notes($us);
$theirs = $notes($ad)['items'][0]['id'] ?? 0;
$r = http('POST', 'notifications/mark-read.php', ['ids' => [$theirs]], $us);
check("cannot mark another user's notification", $r['status'] === 200 && $r['body']['data']['updated'] === 0);
$first = $mine['items'][0]['id'] ?? 0;
$r = http('POST', 'notifications/mark-read.php', ['ids' => [$first]], $us);
check('mark one as read', $r['body']['data']['updated'] === 1 && $notes($us)['unread_count'] === $mine['unread_count'] - 1);
expectStatus('mark-read invalid ids → 422', http('POST', 'notifications/mark-read.php', ['ids' => ['x']], $us), 422);
http('POST', 'notifications/mark-all-read.php', [], $us);
check('mark all as read', $notes($us)['unread_count'] === 0);
expectStatus('notifications require auth', http('GET', 'notifications/list.php'), 401);
check('user sees no pending_approvals counter', array_key_exists('pending_approvals', $notes($us)) && $notes($us)['pending_approvals'] === null);
