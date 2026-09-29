<?php
declare(strict_types=1);

/*
 * Phase 2 tests — property types, parties, records, scope and dashboards.
 * Included by api_test.php (uses its helpers, sessions and cleanup).
 *
 * Teams during this file:
 *   Admin (id 2)  → Demo User (id 3) and "mine" test user
 *   Admin2 (test) → "other" test user created below
 */

/** @var string $sa @var string $ad @var string $runId @var array $createdIds @var int $admin2 */

$us = login('user', Env::get('SEED_USER_PASSWORD'));
$ad2 = login("t_admin2_$runId", 'Passw0rdX');
$r = http('POST', 'users/create.php', ['name' => 'Other Team User', 'username' => "t_other_$runId", 'email' => "t_other_$runId@crm.local", 'password' => 'Passw0rdX'], $ad2);
$otherId = $r['body']['data']['user']['id'] ?? 0;
$createdIds[] = $otherId;
$ot = login("t_other_$runId", 'Passw0rdX');
check('phase 2 sessions ready (user, admin2, other-team user)', $us && $ad2 && $ot);

$phone = '9' . substr((string) random_int(100000000, 999999999), 0, 9);        // unique 10-digit mobile
$noStatePhone = (string) random_int(6000000000, 6999999999);
$cityPhone = (string) random_int(7000000000, 7999999999);
$record = fn (array $extra = []) => array_replace_recursive([
    'title' => "Test flat $runId", 'property_type_id' => 1, 'transaction_type' => 'rental', 'property_category_id' => 1, 'property_category' => 'residential', 'purpose' => 'rent', 'process_stage_id' => 1,
    'city' => "Testcity$runId", 'locality' => 'Anna Nagar', 'area_sqft' => '1200', 'rental_amount' => '25000', 'bedrooms' => '2',
    'party' => ['name' => "Party $runId", 'phone' => $phone],
    'submit' => true, // Phase 3: User records are submitted for approval (Admin records are approved directly)
], $extra);

// ---------------------------------------------------------------------
section('Phase 2 — Property types & process stages');
$locationData = http('GET', 'locations/india.php', null, $us)['body']['data'] ?? [];
check('authenticated India location master includes states and district lists', count($locationData['states'] ?? []) >= 28 && count($locationData['districts']['Tamil Nadu'] ?? []) > 30);
$noStateCreate = http('POST', 'records/create.php', $record(['title' => "No visible state $runId", 'state' => '', 'district' => 'coimbatore', 'city' => '', 'party' => ['name' => "No state party $runId", 'phone' => $noStatePhone], 'confirm_new_party' => true]), $ad);
check('district is accepted without a state and city remains optional', $noStateCreate['status'] === 201 && ($noStateCreate['body']['data']['record']['district'] ?? '') === 'Coimbatore' && ($noStateCreate['body']['data']['record']['city'] ?? null) === null && ($noStateCreate['body']['data']['record']['state'] ?? null) === null);
$cityCreate = http('POST', 'records/create.php', $record(['title' => "City search $runId", 'state' => '', 'district' => 'Coimbatore', 'city' => 'Coimbatore', 'party' => ['name' => "City party $runId", 'phone' => $cityPhone], 'confirm_new_party' => true]), $ad);
$cityOptions = http('GET', 'locations/cities.php?search=coi', null, $ad)['body']['data']['items'] ?? [];
check('city search API returns matching saved city values', $cityCreate['status'] === 201 && in_array('Coimbatore', $cityOptions, true));
foreach (['sa' => $sa, 'admin' => $ad, 'user' => $us] as $who => $tok) {
    $r = http('GET', 'property-types/list.php', null, $tok);
    check("$who lists canonical property types (Rental, Sale)", $r['status'] === 200
        && array_column($r['body']['data']['items'], 'slug') === ['rental', 'sale']);
}
foreach (['sa' => $sa, 'admin' => $ad, 'user' => $us] as $who => $tok) {
    $categories = http('GET', 'property-categories/list.php', null, $tok);
    check("$who lists separate Residential / Commercial categories", $categories['status'] === 200 && array_column($categories['body']['data']['items'], 'slug') === ['residential', 'commercial']);
}
check('process stages list has 8 stages', count(http('GET', 'process-stages/list.php', null, $us)['body']['data']['items'] ?? []) === 8);
expectStatus('admin cannot create property type', http('POST', 'property-types/create.php', ['name' => 'Plot', 'field_group' => 'general'], $ad), 403);
expectStatus('user cannot create property type', http('POST', 'property-types/create.php', ['name' => 'Plot', 'field_group' => 'general'], $us), 403);
expectStatus('super admin cannot add a third property type', http('POST', 'property-types/create.php', ['name' => "Plot $runId", 'field_group' => 'general'], $sa), 403);
expectStatus('super admin cannot mutate canonical property types', http('PUT', 'property-types/update.php', ['id' => 1, 'name' => 'Housing', 'field_group' => 'general'], $sa), 403);
check('inactive legacy Residential / Commercial types are hidden from the master API', array_column(http('GET', 'property-types/list.php?include_inactive=1', null, $sa)['body']['data']['items'], 'slug') === ['rental', 'sale']);
expectStatus('record with unknown transaction type ID rejected', http('POST', 'records/create.php', $record(['property_type_id' => 999]), $us), 422);

// ---------------------------------------------------------------------
section('Phase 2 — Record creation & validation');
expectStatus('super admin cannot create records (view-only)', http('POST', 'records/create.php', $record(), $sa), 403);
expectStatus('unauthenticated record list', http('GET', 'records/list.php'), 401);
$withoutMedia = http('POST', 'records/create.php', $record(['map_url' => 'https://www.google.com/maps/place/Test']), $us, ['X-Test-No-Media: 1']);
check('record API refuses creation without property media', $withoutMedia['status'] === 422 && isset($withoutMedia['body']['errors']['media']));
$r = http('POST', 'records/create.php', [], $us);
check('empty record → 422 with field errors', $r['status'] === 422 && count(array_intersect(['title', 'property_type_id', 'process_stage_id', 'party_name'], array_keys($r['body']['errors'] ?? []))) === 4);
$r = http('POST', 'records/create.php', $record(['rental_amount' => '']), $us);
check('rental without rent amount → 422', $r['status'] === 422 && isset($r['body']['errors']['rental_amount']));
$r = http('POST', 'records/create.php', $record(['pincode' => '12', 'area_sqft' => 'abc', 'party' => ['phone' => '12ab']]), $us);
check('bad pincode / area / phone → 422', $r['status'] === 422 && isset($r['body']['errors']['pincode'], $r['body']['errors']['area_sqft'], $r['body']['errors']['party_phone']));
expectStatus('invalid area unit rejected', http('POST', 'records/create.php', $record(['area_value' => '100', 'area_unit' => 'football_field']), $us), 422);
expectStatus('invalid property facing rejected', http('POST', 'records/create.php', $record(['property_facing' => 'up']), $us), 422);
expectStatus('unknown process stage → 422', http('POST', 'records/create.php', $record(['process_stage_id' => 99]), $us), 422);
expectStatus('map lookup rejects an untrusted URL without fetching it', http('POST', 'records/map-lookup.php', ['map_url' => 'https://example.com/'], $us), 422);

$mapUrl = 'https://www.google.com/maps/place/Chennai';
$r = http('POST', 'records/create.php', $record(['created_by' => 1, 'created_by_role' => 'super_admin', 'sale_amount' => '5000000', 'approval_status' => 'approved', 'map_url' => $mapUrl]), $us);
expectStatus('user creates rental record', $r, 201);
$rec1 = $r['body']['data']['record'] ?? [];
check('reference generated by backend (RE-000000 format)', (bool) preg_match('/^RE-\d{6,}$/', $rec1['reference'] ?? ''));
check('created_by taken from session, not request', ($rec1['created_by']['id'] ?? 0) === 3 && ($rec1['created_by']['role'] ?? '') === 'user');
check('property party phone stored as local 10 digits', ($rec1['party']['phone'] ?? '') === $phone);
check('irrelevant field dropped (sale_amount on rental)', array_key_exists('sale_amount', $rec1) && $rec1['sale_amount'] === null);
check('approval_status cannot be set by client', Database::value('SELECT approval_status FROM real_estate_records WHERE id = ?', [$rec1['id'] ?? 0]) === 'pending'); // client sent 'approved'
check('secure map link is saved and returned from the database', ($rec1['map_url'] ?? null) === $mapUrl && Database::value('SELECT map_url FROM real_estate_records WHERE id = ?', [$rec1['id']]) === $mapUrl);
 $badMap = http('POST', 'records/create.php', $record(['map_url' => 'javascript:alert(1)']), $us);
check('unsafe map link rejected', $badMap['status'] === 422 && isset($badMap['body']['errors']['map_url']));
$party1 = $rec1['party']['id'] ?? 0;

// ---------------------------------------------------------------------
section('Phase 2 — Party duplicate handling');
$r = http('POST', 'records/create.php', $record(['title' => "Second $runId"]), $us);
check('same phone, new party without confirmation → 409', $r['status'] === 409 && isset($r['body']['errors']['party_phone']));
$r = http('GET', 'parties/lookup.php?phone=' . urlencode("+91 $phone"), null, $us);
check('lookup finds existing party by phone', ($r['body']['data']['matches'][0]['id'] ?? 0) === $party1);
expectStatus('lookup with invalid phone', http('GET', 'parties/lookup.php?phone=abc', null, $us), 422);
expectStatus('super admin cannot use lookup', http('GET', "parties/lookup.php?phone=$phone", null, $sa), 403);
$payload = $record(['title' => "Second $runId", 'property_type_id' => 2, 'transaction_type' => 'sale', 'property_category_id' => 1, 'property_category' => 'residential', 'purpose' => 'sale', 'sale_amount' => '7500000']);
unset($payload['party']);
$r = http('POST', 'records/create.php', $payload + ['party_id' => $party1], $us);
expectStatus('reuse existing party (residential record)', $r, 201);
$rec2 = $r['body']['data']['record'] ?? [];
check('both records share one party', ($rec2['party']['id'] ?? 0) === $party1);
check('rental fields dropped on residential record', array_key_exists('rental_amount', $rec2) && $rec2['rental_amount'] === null && ($rec2['sale_amount'] ?? 0) == 7500000);
$r = http('POST', 'records/create.php', $record(['title' => "Dup party $runId", 'confirm_new_party' => true]), $us);
check('confirmed duplicate creates a separate party', $r['status'] === 201 && ($r['body']['data']['record']['party']['id'] ?? $party1) !== $party1);
$rec3 = $r['body']['data']['record'] ?? [];

// Cross-team linking requires knowing the phone.
$r = http('POST', 'records/create.php', $payload + ['party_id' => $party1], $ot);
check('other team cannot link party by guessing its ID', $r['status'] === 422 && isset($r['body']['errors']['party_id']));
$r = http('POST', 'records/create.php', $payload + ['party_id' => $party1, 'party_phone' => $phone], $ot);
expectStatus('other team links party found via phone lookup', $r, 201);
$recOther = $r['body']['data']['record'] ?? [];

// Admin creates directly.
$r = http('POST', 'records/create.php', $record(['title' => "Admin shop $runId", 'property_type_id' => 1, 'transaction_type' => 'rental', 'property_category_id' => 2, 'property_category' => 'commercial', 'purpose' => 'lease', 'commercial_usage' => 'Retail shop', 'rental_amount' => '80000', 'party' => ['name' => "Admin party $runId", 'phone' => $phone], 'confirm_new_party' => true]), $ad);
expectStatus('admin creates commercial record directly', $r, 201);
$recAdmin = $r['body']['data']['record'] ?? [];
check('record party phone kept as exactly 10 local digits', ($recAdmin['party']['phone'] ?? '') === $phone);
check('rental + commercial classification returned', ($recAdmin['transaction_type'] ?? null) === 'rental' && ($recAdmin['property_category'] ?? null) === 'commercial');
$saleCommercial = $record(['title' => "Sale showroom $runId", 'property_type_id' => 2, 'transaction_type' => 'sale', 'property_category_id' => 2, 'property_category' => 'commercial', 'purpose' => 'sale', 'sale_amount' => '12000000', 'market_price' => '13500000', 'commercial_usage' => 'Showroom', 'area_value' => '2.5', 'area_unit' => 'cent', 'property_facing' => 'south_west', 'party' => ['name' => "Sale party $runId", 'phone' => '9888877665']]);
$r = http('POST', 'records/create.php', $saleCommercial, $ad);
expectStatus('admin creates sale + commercial classification', $r, 201);
$saleCommercialId = (int) ($r['body']['data']['record']['id'] ?? 0);
check('sale + commercial classification returned', ($r['body']['data']['record']['transaction_type'] ?? null) === 'sale' && ($r['body']['data']['record']['property_category'] ?? null) === 'commercial');
check('property area keeps selected unit and normalized square feet', ($r['body']['data']['record']['area_value'] ?? null) == 2.5 && ($r['body']['data']['record']['area_unit'] ?? null) === 'cent' && abs(($r['body']['data']['record']['area_sqft'] ?? 0) - 1089) < 0.01);
check('square-foot area persists as Sq.ft', ($rec1['area_unit'] ?? null) === 'sq_ft' && ($rec1['area_sqft'] ?? null) == 1200);
check('sale market price and facing persist', ($r['body']['data']['record']['market_price'] ?? null) == 13500000 && ($r['body']['data']['record']['property_facing'] ?? null) === 'south_west');
$classificationEdit = $record(['id' => $recAdmin['id'], 'party_id' => $recAdmin['party']['id'], 'title' => "Edited showroom $runId", 'property_type_id' => 2, 'transaction_type' => 'sale', 'property_category_id' => 2, 'property_category' => 'commercial', 'purpose' => 'sale', 'commercial_usage' => 'Office', 'rental_amount' => '', 'sale_amount' => '9000000', 'market_price' => '10000000', 'area_value' => '80', 'area_unit' => 'acre', 'property_facing' => 'north_east']);
unset($classificationEdit['party']);
$updatedClassification = http('PUT', 'records/update.php', $classificationEdit, $ad);
check('edit saves sale + commercial classification', $updatedClassification['status'] === 200 && ($updatedClassification['body']['data']['record']['transaction_type'] ?? null) === 'sale' && ($updatedClassification['body']['data']['record']['property_category'] ?? null) === 'commercial');
check('edit saves Acre, facing and market price', $updatedClassification['status'] === 200 && ($updatedClassification['body']['data']['record']['area_value'] ?? null) == 80 && ($updatedClassification['body']['data']['record']['area_unit'] ?? null) === 'acre' && abs(($updatedClassification['body']['data']['record']['area_sqft'] ?? 0) - 3484800) < 0.01 && ($updatedClassification['body']['data']['record']['property_facing'] ?? null) === 'north_east' && ($updatedClassification['body']['data']['record']['market_price'] ?? null) == 10000000);
expectStatus('legacy area unit cannot be used for a new property', http('POST', 'records/create.php', $record(['area_value' => '100', 'area_unit' => 'sq_meter']), $us), 422);
Database::execute("UPDATE real_estate_records SET area_unit = 'sq_yard', area_value = 80, area_sqft = 720, purpose = 'sale' WHERE id = ?", [$saleCommercialId]);
$legacyUnitEdit = $record(['id' => $saleCommercialId, 'party_id' => $r['body']['data']['record']['party']['id'] ?? null, 'title' => "Legacy unit preserved $runId", 'property_type_id' => 2, 'transaction_type' => 'sale', 'property_category_id' => 2, 'property_category' => 'commercial', 'area_value' => '80']);
unset($legacyUnitEdit['party'], $legacyUnitEdit['area_unit'], $legacyUnitEdit['purpose']);
$legacyUnitResult = http('PUT', 'records/update.php', $legacyUnitEdit, $ad);
check('historical unit and purpose survive edits when omitted by the new form', $legacyUnitResult['status'] === 200 && ($legacyUnitResult['body']['data']['record']['area_unit'] ?? null) === 'sq_yard' && ($legacyUnitResult['body']['data']['record']['purpose'] ?? null) === 'sale' && abs(($legacyUnitResult['body']['data']['record']['area_sqft'] ?? 0) - 720) < 0.01);
$invalidLegacyChange = $legacyUnitEdit;
$invalidLegacyChange['area_unit'] = 'ground';
expectStatus('legacy unit cannot be changed to another legacy unit', http('PUT', 'records/update.php', $invalidLegacyChange, $ad), 422);

$refs = Database::fetchAll('SELECT record_reference, COUNT(*) c FROM real_estate_records GROUP BY record_reference HAVING c > 1');
check('record references are unique', $refs === []);

// ---------------------------------------------------------------------
section('Phase 2 — Record scope & permissions');
$listIds = fn (string $tok, string $q = '') => array_column(http('GET', "records/list.php?per_page=100$q", null, $tok)['body']['data']['items'] ?? [], 'id');
$userIds = $listIds($us);
check('user sees own records only', in_array($rec1['id'], $userIds, true) && !in_array($recOther['id'], $userIds, true) && !in_array($recAdmin['id'], $userIds, true));
$adminIds = $listIds($ad);
check("admin sees own + team users' records", in_array($rec1['id'], $adminIds, true) && in_array($recAdmin['id'], $adminIds, true) && !in_array($recOther['id'], $adminIds, true));
$saIds = $listIds($sa);
check('super admin sees all records', count(array_intersect([$rec1['id'], $recAdmin['id'], $recOther['id']], $saIds)) === 3);
check('super admin list reports can_create = false', http('GET', 'records/list.php', null, $sa)['body']['data']['can_create'] === false);

expectStatus("user views another team's record", http('GET', "records/view.php?id={$recOther['id']}", null, $us), 404);
expectStatus("user views admin's record", http('GET', "records/view.php?id={$recAdmin['id']}", null, $us), 404);
$r = http('GET', "records/view.php?id={$rec1['id']}", null, $ad);
check("admin views team user's record (can_edit)", $r['status'] === 200 && $r['body']['data']['can_edit'] === true);
$r = http('GET', "records/view.php?id={$rec1['id']}", null, $sa);
check('super admin views any record (can_edit = false)', $r['status'] === 200 && $r['body']['data']['can_edit'] === false);
expectStatus('view missing record', http('GET', 'records/view.php?id=99999999', null, $sa), 404);
expectStatus('view with SQLi id', http('GET', 'records/view.php?id=1%20OR%201=1', null, $sa), 422);

$edit = $record(['id' => $rec1['id'], 'party_id' => $party1, 'title' => "Edited by admin $runId"]);
unset($edit['party']);
expectStatus('super admin cannot edit records', http('PUT', 'records/update.php', $edit, $sa), 403);
expectStatus("other team cannot edit user's record", http('PUT', 'records/update.php', $edit, $ot), 404);
expectStatus("user cannot edit admin's record", http('PUT', 'records/update.php', ['id' => $recAdmin['id']] + $edit, $us), 404);
$r = http('PUT', 'records/update.php', $edit, $ad);
check("admin edits team user's record (updated_by = admin)", $r['status'] === 200 && ($r['body']['data']['record']['updated_by']['id'] ?? 0) === 2 && ($r['body']['data']['record']['created_by']['id'] ?? 0) === 3);

// ---------------------------------------------------------------------
section('Phase 2 — Record updates');
$edit['title'] = "Stage moved $runId";
$edit['process_stage_id'] = 4;
$r = http('PUT', 'records/update.php', $edit, $us);
check('user updates own record + stage', $r['status'] === 200 && ($r['body']['data']['record']['process_stage']['slug'] ?? '') === 'site_visit');
check('stage change logged', (int) Database::value("SELECT COUNT(*) FROM activity_logs WHERE action = 'record.stage_changed' AND entity_id = ?", [$rec1['id']]) === 1);
$r = http('PUT', 'records/update.php', $edit, $us);
check('unchanged update → "No changes to save"', $r['status'] === 200 && $r['body']['message'] === 'No changes to save');
$r = http('PUT', 'records/update.php', ['title' => ''] + $edit, $us);
check('invalid update → 422, nothing saved', $r['status'] === 422 && Database::value('SELECT title FROM real_estate_records WHERE id = ?', [$rec1['id']]) === "Stage moved $runId");
expectStatus('archive record', http('PUT', 'records/update.php', ['record_status' => 'archived'] + $edit, $us), 200);
check('archived hidden from default list', !in_array($rec1['id'], $listIds($us), true));
check('archived shown with record_status=archived', in_array($rec1['id'], $listIds($us, '&record_status=archived'), true));
expectStatus('restore record', http('PUT', 'records/update.php', ['record_status' => 'active'] + $edit, $us), 200);
check('archive + restore logged', (int) Database::value("SELECT COUNT(*) FROM activity_logs WHERE action IN ('record.archived','record.restored') AND entity_id = ?", [$rec1['id']]) === 2);
$r = http('GET', "records/view.php?id={$rec1['id']}", null, $us);
check('record view includes activity history', count($r['body']['data']['activity'] ?? []) >= 4);

// ---------------------------------------------------------------------
section('Phase 2 — Search, filters, pagination');
$search = fn (string $tok, string $q) => http('GET', "records/list.php?per_page=100&$q", null, $tok);
check('search by reference', in_array($rec1['id'], array_column($search($us, 'search=' . urlencode($rec1['reference']))['body']['data']['items'], 'id'), true));
check('search by party name', count($search($us, 'search=' . urlencode("Party $runId"))['body']['data']['items']) >= 2);
check('search by phone digits', in_array($rec1['id'], array_column($search($us, 'search=' . substr($phone, 2, 6))['body']['data']['items'], 'id'), true));
check('search by locality', in_array($rec1['id'], array_column($search($us, 'search=Anna%20Nagar')['body']['data']['items'], 'id'), true));
check('search by property type name', in_array($recAdmin['id'], array_column($search($ad, 'search=Commercial')['body']['data']['items'], 'id'), true));
$r = $search($us, 'property_type_id=2');
check('filter by property type', $r['body']['data']['items'] !== [] && array_unique(array_column(array_column($r['body']['data']['items'], 'property_type'), 'slug')) === ['sale']);
$r = $search($us, 'transaction_type=rental&property_category=residential');
check('filter by rental + residential', in_array($rec1['id'], array_column($r['body']['data']['items'], 'id'), true));
$r = $search($ad, 'transaction_type=sale&property_category=commercial');
check('filter by sale + commercial', in_array($saleCommercialId, array_column($r['body']['data']['items'], 'id'), true));
$r = $search($us, 'process_stage_id=4');
$items = $r['body']['data']['items'];
check('filter by process stage', in_array($rec1['id'], array_column($items, 'id'), true)
    && array_unique(array_column(array_column($items, 'process_stage'), 'slug')) === ['site_visit']);
$r = $search($us, 'city=' . urlencode("Testcity$runId"));
check('filter by city', $r['body']['data']['pagination']['total'] === 3 && in_array("Testcity$runId", $r['body']['data']['cities'], true));
$today = gmdate('Y-m-d');
check('filter by created date (today)', $search($us, "created_from=$today&created_to=$today&city=Testcity$runId")['body']['data']['pagination']['total'] === 3);
check('filter by created date (future) → none', $search($us, 'created_from=2099-01-01')['body']['data']['pagination']['total'] === 0);
expectStatus('invalid date filter', $search($us, 'created_from=2026-13-45'), 422);
expectStatus('from after to', $search($us, 'created_from=2026-02-01&created_to=2026-01-01'), 422);
expectStatus('invalid record_status filter', $search($us, 'record_status=deleted'), 422);
$r = http('GET', "records/list.php?per_page=1&page=2&city=Testcity$runId", null, $us);
check('pagination (per_page=1, page 2 of 3)', count($r['body']['data']['items']) === 1 && $r['body']['data']['pagination']['total_pages'] === 3);
check('SQLi in record search → 200, no rows', ($search($us, 'search=' . urlencode("' OR 1=1 -- "))['body']['data']['pagination']['total'] ?? -1) === 0);

// ---------------------------------------------------------------------
section('Phase 2 — Parties');
$r = http('GET', 'parties/list.php?search=' . urlencode("Party $runId"), null, $us);
check('user party list (search by name)', $r['status'] === 200 && count($r['body']['data']['items']) === 2);
$r = http('GET', "parties/view.php?id=$party1", null, $us);
check('party view lists linked records within scope', $r['status'] === 200 && count($r['body']['data']['records']) === 2 && $r['body']['data']['party']['can_edit'] === true);
$r = http('GET', "parties/view.php?id=$party1", null, $ot);
check('other team sees linked party but only its own record, cannot edit', $r['status'] === 200 && count($r['body']['data']['records']) === 1 && $r['body']['data']['party']['can_edit'] === false);
expectStatus('other team cannot see unlinked party', http('GET', "parties/view.php?id={$rec3['party']['id']}", null, $ot), 404);
$partyEdit = ['id' => $party1, 'name' => "Party $runId Renamed", 'phone' => $phone, 'email' => 'party@example.com'];
expectStatus('other team cannot edit party', http('PUT', 'parties/update.php', $partyEdit, $ot), 403);
expectStatus('super admin cannot edit party', http('PUT', 'parties/update.php', $partyEdit, $sa), 403);
expectStatus('invalid party email', http('PUT', 'parties/update.php', ['email' => 'nope'] + $partyEdit, $us), 422);
expectStatus('creator edits party', http('PUT', 'parties/update.php', $partyEdit, $us), 200);
expectStatus('admin edits team party', http('PUT', 'parties/update.php', ['notes' => 'Prefers calls after 6pm'] + $partyEdit, $ad), 200);
$r = http('POST', 'parties/create.php', ['name' => 'Standalone', 'phone' => $phone], $us);
check('standalone party with existing phone → 409', $r['status'] === 409);
expectStatus('standalone party with confirmation', http('POST', 'parties/create.php', ['name' => "Standalone $runId", 'phone' => $phone, 'confirm_duplicate' => true], $us), 201);
check('super admin sees all parties', http('GET', 'parties/list.php?search=' . urlencode($runId), null, $sa)['body']['data']['pagination']['total'] >= 4);

// ---------------------------------------------------------------------
section('Phase 2 — Dashboards & integrity');
$u = http('GET', 'dashboard/user.php', null, $us)['body']['data']['records'] ?? [];
check('user dashboard: record totals by transaction type', ($u['total'] ?? 0) >= 3 && count($u['by_type'] ?? []) === 2 && count($u['by_stage'] ?? []) === 8);
$a = http('GET', 'dashboard/admin.php', null, $ad)['body']['data']['records'] ?? [];
check('admin dashboard counts own + team records', ($a['total'] ?? 0) >= ($u['total'] ?? 0) + 1);
$s = http('GET', 'dashboard/super-admin.php', null, $sa)['body']['data'] ?? [];
check('super admin dashboard: records + Phase 1 stats', ($s['records']['total'] ?? 0) >= ($a['total'] ?? 0) + 1 && isset($s['stats']['total_admins']));
check('dashboard totals match database', ($s['records']['total'] ?? -1) === (int) Database::value("SELECT COUNT(*) FROM real_estate_records WHERE record_status = 'active' AND approval_status <> 'draft'"));
foreach (['property_type_id' => 9999, 'party_id' => 99999999, 'process_stage_id' => 99, 'created_by' => 99999999] as $column => $bad) {
    $ok = false;
    try {
        Database::execute("UPDATE real_estate_records SET $column = ? WHERE id = ?", [$bad, $rec1['id']]);
    } catch (PDOException $e) {
        $ok = $e->getCode() === '23000';
    }
    check("foreign key enforced: $column", $ok);
}
