<?php
declare(strict_types=1);

/*
 * API test suite — Phase 1, 2 and 3 (development only, CLI).
 *
 *   php tests/api_test.php [base_url]
 *   default base_url: http://localhost:8080/Lordminds/Real_Estate_CRM/backend/api
 *
 * Exercises the real HTTP endpoints against the dev database: authentication,
 * role/permission matrix, validation, SQL-injection inputs, account lifecycle
 * and activity logging. Accounts it creates are removed at the end.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../bootstrap.php';

if (Config::get('app.env') === 'production') {
    fwrite(STDERR, "Refusing to run against APP_ENV=production\n");
    exit(1);
}

$BASE = rtrim($argv[1] ?? 'http://localhost:8080/Lordminds/Real_Estate_CRM/backend/api', '/');
$COOKIE = Config::get('auth.cookie_name');
$passed = 0;
$failed = [];
$runId = substr(bin2hex(random_bytes(3)), 0, 6);
$createdIds = [];
$mediaTestIds = [];
$testPng = BASE_PATH . '/storage/test-property.png';
file_put_contents($testPng, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jT9sAAAAASUVORK5CYII='));
// Rows above these ids were created by this run and are removed at the end.
$startIds = [
    'real_estate_records' => (int) Database::value('SELECT COALESCE(MAX(id), 0) FROM real_estate_records'),
    'parties'             => (int) Database::value('SELECT COALESCE(MAX(id), 0) FROM parties'),
    'property_types'      => (int) Database::value('SELECT COALESCE(MAX(id), 0) FROM property_types'),
    'notifications'       => (int) Database::value('SELECT COALESCE(MAX(id), 0) FROM notifications'),
];

/** @return array{status:int, body:array|null, token:?string, raw:string} */
function http(string $method, string $path, $body = null, ?string $token = null, array $headers = []): array
{
    global $BASE, $COOKIE, $testPng, $mediaTestIds;
    $ch = curl_init("$BASE/$path");
    $h = array_merge(['Accept: application/json'], $headers);
    if ($body !== null) {
        $isCreate = str_starts_with($path, 'records/create.php') && is_array($body) && !in_array('X-Test-No-Media: 1', $headers, true);
        if ($isCreate) {
            $body['map_url'] ??= 'https://www.google.com/maps/place/Test';
            $parts = parse_url($BASE);
            $h[] = 'Origin: ' . ($parts['scheme'] ?? 'http') . '://' . ($parts['host'] ?? '127.0.0.1') . (isset($parts['port']) ? ':' . $parts['port'] : '');
            curl_setopt($ch, CURLOPT_POSTFIELDS, ['payload' => json_encode($body), 'images[]' => new CURLFile($testPng, 'image/png', 'fixture.png')]);
        } else {
            if (str_starts_with($path, 'records/update.php') && is_array($body)) $body['map_url'] ??= 'https://www.google.com/maps/place/Test';
            $h[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($body) ? $body : json_encode($body));
        }
    }
    if ($token !== null) {
        $h[] = "Cookie: $COOKIE=$token";
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HEADER         => true,
        CURLOPT_HTTPHEADER     => $h,
    ]);
    $raw = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    $headerText = substr($raw, 0, $headerSize);
    $bodyText = substr($raw, $headerSize);
    $newToken = null;
    if (preg_match('/Set-Cookie: ' . preg_quote($COOKIE, '/') . '=([a-f0-9]{64})/i', $headerText, $m)) {
        $newToken = $m[1];
    }
    $decoded = json_decode($bodyText, true);
    if ($status === 201 && str_starts_with($path, 'records/create.php') && isset($decoded['data']['record']['id'])) $mediaTestIds[] = (int) $decoded['data']['record']['id'];
    return ['status' => $status, 'body' => $decoded, 'token' => $newToken, 'raw' => $bodyText, 'headers' => $headerText];
}

function check(string $name, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  \033[32mPASS\033[0m $name\n";
    } else {
        $failed[] = $name;
        echo "  \033[31mFAIL\033[0m $name" . ($detail ? "  → $detail" : '') . "\n";
    }
}

function expectStatus(string $name, array $res, int $expected): void
{
    $envelope = is_array($res['body']) && array_key_exists('success', $res['body']) && array_key_exists('message', $res['body']);
    check("$name → $expected", $res['status'] === $expected && $envelope, "got {$res['status']} " . substr($res['raw'], 0, 160));
}

function login(string $login, string $password): ?string
{
    $res = http('POST', 'auth/login.php', ['login' => $login, 'password' => $password]);
    return $res['status'] === 200 ? $res['token'] : null;
}

function section(string $title): void
{
    echo "\n\033[1m$title\033[0m\n";
}

// ---------------------------------------------------------------------
section('Database');
check('PDO connection works', Database::value('SELECT 1') == 1);
foreach (['roles', 'users', 'auth_tokens', 'activity_logs', 'settings'] as $t) {
    check("table `$t` exists", Database::value(
        'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
        [$t]
    ) === $t);
}

// ---------------------------------------------------------------------
section('Unauthenticated requests are rejected');
$protected = [
    ['GET', 'auth/me.php'], ['GET', 'dashboard/super-admin.php'], ['GET', 'dashboard/admin.php'],
    ['GET', 'dashboard/user.php'], ['GET', 'users/list.php'], ['GET', 'users/view.php?id=1'],
    ['POST', 'users/create.php'], ['PUT', 'users/update.php'], ['POST', 'records/map-lookup.php'], ['GET', 'activity/list.php'],
    ['GET', 'roles/list.php'], ['GET', 'settings/list.php'], ['GET', 'locations/india.php'], ['GET', 'locations/cities.php?search=coi'], ['PUT', 'settings/update.php'],
    ['POST', 'auth/change-password.php'],
    ['POST', 'auth/forgot-password-self.php'],
];
foreach ($protected as [$m, $p]) {
    expectStatus("$m $p (no session)", http($m, $p, $m === 'GET' ? null : []), 401);
}
expectStatus('forged token', http('GET', 'auth/me.php', null, str_repeat('a', 64)), 401);
expectStatus('malformed token', http('GET', 'auth/me.php', null, "x' OR 1=1 --"), 401);

// ---------------------------------------------------------------------
section('Login validation & invalid credentials');
expectStatus('wrong password', http('POST', 'auth/login.php', ['login' => 'superadmin', 'password' => 'nope']), 401);
expectStatus('unknown user', http('POST', 'auth/login.php', ['login' => 'ghost@crm.local', 'password' => 'Whatever1']), 401);
$r = http('POST', 'auth/login.php', ['login' => '', 'password' => '']);
expectStatus('empty fields', $r, 422);
check('field errors returned for login + password', isset($r['body']['errors']['login'], $r['body']['errors']['password']));
expectStatus('SQLi in login', http('POST', 'auth/login.php', ['login' => "' OR '1'='1' -- ", 'password' => "' OR '1'='1"]), 401);
expectStatus('GET on login (wrong method)', http('GET', 'auth/login.php'), 405);
expectStatus('form-encoded POST rejected (CSRF guard)', http('POST', 'auth/login.php', null, null, ['Content-Type: application/x-www-form-urlencoded']), 415);
expectStatus('invalid JSON body', http('POST', 'auth/login.php', '{bad json'), 400);

// ---------------------------------------------------------------------
section('Login per role');
$sa = login('superadmin', Env::get('SEED_SUPER_ADMIN_PASSWORD'));
$ad = login('admin@crm.local', Env::get('SEED_ADMIN_PASSWORD'));
$us = login('user', Env::get('SEED_USER_PASSWORD'));
check('super admin login (username)', $sa !== null);
check('admin login (email)', $ad !== null);
check('user login (username)', $us !== null);
$me = http('GET', 'auth/me.php', null, $us);
check('me returns role from backend', ($me['body']['data']['user']['role'] ?? null) === 'user');
check('me never exposes password hash', !str_contains($me['raw'], 'password'));

// ---------------------------------------------------------------------
section('Role / permission matrix');
$matrix = [
    // [method, path, body, SA, Admin, User]
    ['GET', 'dashboard/super-admin.php', null, 200, 403, 403],
    ['GET', 'dashboard/admin.php', null, 403, 200, 403],
    ['GET', 'dashboard/user.php', null, 403, 403, 200],
    ['GET', 'users/list.php', null, 200, 200, 403],
    ['GET', 'users/view.php?id=3', null, 200, 200, 403],
    ['POST', 'users/create.php', [], 422, 422, 403],
    ['PUT', 'users/update.php', ['id' => 3, 'phone' => '+91 90000 00000'], 200, 200, 403],
    ['POST', 'records/map-lookup.php', ['map_url' => 'not-a-url'], 403, 422, 422],
    ['GET', 'roles/list.php', null, 200, 200, 403],
    ['GET', 'settings/list.php', null, 200, 403, 403],
    ['PUT', 'settings/update.php', ['settings' => ['company_name' => 'N Real Estate']], 200, 403, 403],
    ['GET', 'activity/list.php', null, 200, 200, 200],
];
foreach ($matrix as [$m, $p, $b, $eSa, $eAd, $eUs]) {
    expectStatus("super_admin $m $p", http($m, $p, $b, $sa), $eSa);
    expectStatus("admin       $m $p", http($m, $p, $b, $ad), $eAd);
    expectStatus("user        $m $p", http($m, $p, $b, $us), $eUs);
}
$roles = http('GET', 'roles/list.php', null, $ad);
check('admin can only assign the user role', array_column($roles['body']['data']['items'] ?? [], 'slug') === ['user']);

section('Google Maps link parsing');
$mapParser = new ReflectionMethod(MapLookup::class, 'locationFromUrl');
$mapCoordinates = $mapParser->invoke(null, 'https://www.google.com/maps/place/Chennai/@13.0827,80.2707,12z');
check('Google Maps place URLs use the selected place name before viewport coordinates', $mapCoordinates[2] === 'Chennai');
$queryCoordinates = $mapParser->invoke(null, 'https://maps.google.com/maps?q=13.0827%2C80.2707');
check('Google Maps q=coordinate links are read', $queryCoordinates[0] === '13.0827' && $queryCoordinates[1] === '80.2707');
$mapDataCoordinates = $mapParser->invoke(null, 'https://www.google.com/maps/place/Chennai/data=!4m2!3m1!1s0x3a525d5a86a4cbad:0x0!3d13.0827!4d80.2707');
check('Google Maps data coordinates are read', $mapDataCoordinates[0] === '13.0827' && $mapDataCoordinates[1] === '80.2707');
$mapLegacyDataCoordinates = $mapParser->invoke(null, 'https://www.google.com/maps/place/RS+Puram,+Coimbatore/@10.7905,78.7047,10z/data=!4m7!1m5!3m4!1s0x3ba859e8b7d3a369:0x4c2ac0f0e5819!2m2!1d76.9508196!2d11.0067991');
check('Google Maps !1d longitude and !2d latitude point to RS Puram, Coimbatore', $mapLegacyDataCoordinates[0] === '11.0067991' && $mapLegacyDataCoordinates[1] === '76.9508196');
$mapPinBeatsViewport = $mapParser->invoke(null, 'https://www.google.com/maps/place/Chennai/@12.9716,77.5946,17z/data=!4m2!3m1!1s0x3a525d5a86a4cbad:0x0!3d13.0827!4d80.2707');
check('selected place pin coordinates take precedence over viewport center', $mapPinBeatsViewport[0] === '13.0827' && $mapPinBeatsViewport[1] === '80.2707');
$namedPlaceBeatsViewport = $mapParser->invoke(null, 'https://www.google.com/maps/place/Chennai/@12.9716,77.5946,17z');
check('place name takes precedence over a viewport-only coordinate', $namedPlaceBeatsViewport[2] === 'Chennai');
$mapSearch = $mapParser->invoke(null, 'https://www.google.com/maps/search/?api=1&query=Chennai%2C%20India');
check('Google Maps search links provide a place query', $mapSearch[2] === 'Chennai, India');
$mapDirections = $mapParser->invoke(null, 'https://google.com/maps?sca_esv=example&daddr=SF+201,+Sivanandapuram,+Sathy+Rd,+Coimbatore,+Tamil+Nadu+641035');
check('Google Maps directions links use daddr as the place query', $mapDirections[2] === 'SF 201, Sivanandapuram, Sathy Rd, Coimbatore, Tamil Nadu 641035');
$destinationParts = (new ReflectionMethod(MapLookup::class, 'destinationAddressParts'))->invoke(null, 'https://google.com/maps?daddr=SF+201,+Sivanandapuram,+Sathy+Rd,+Coimbatore,+Tamil+Nadu+641035');
check('structured directions address preserves locality, city, state and PIN', ($destinationParts['locality'] ?? null) === 'Sivanandapuram' && ($destinationParts['city'] ?? null) === 'Coimbatore' && ($destinationParts['district'] ?? null) === 'Coimbatore' && ($destinationParts['state'] ?? null) === 'Tamil Nadu' && ($destinationParts['pincode'] ?? null) === '641035');
$resolvedDirections = MapLookup::address('https://google.com/maps?daddr=SF+201,+Sivanandapuram,+Sathy+Rd,+Coimbatore,+Tamil+Nadu+641035');
check('complete Google directions address resolves without fuzzy geocoder substitution', $resolvedDirections['locality'] === 'Sivanandapuram' && $resolvedDirections['city'] === 'Coimbatore' && $resolvedDirections['pincode'] === '641035');
$nestedMap = $mapParser->invoke(null, 'https://www.google.com/maps?link=' . rawurlencode('https://maps.google.com/maps?q=Chennai%2C+India'));
check('nested Google Maps share links are followed', $nestedMap[2] === 'Chennai, India');

// ---------------------------------------------------------------------
section('User management — validation');
$good = fn (string $tag, array $extra = []) => array_merge([
    'name' => "Test $tag", 'username' => "t_{$tag}_$runId", 'email' => "t_{$tag}_$runId@crm.local",
    'password' => 'Passw0rdX', 'phone' => '+91 90000 00000',
], $extra);

$r = http('POST', 'users/create.php', ['name' => '', 'username' => 'a', 'email' => 'bad', 'password' => 'short'], $ad);
expectStatus('invalid payload', $r, 422);
check('errors for name/username/email/password', count(array_intersect(['name', 'username', 'email', 'password'], array_keys($r['body']['errors'] ?? []))) === 4);
foreach (['admin', 'user'] as $newRole) {
    $missingPhone = http('POST', 'users/create.php', $good("no-phone-$newRole", ['role' => $newRole, 'phone' => '', 'manager_id' => $newRole === 'user' ? 1 : null]), $sa);
    check("super admin cannot create $newRole without a phone number", $missingPhone['status'] === 422 && isset($missingPhone['body']['errors']['phone']));
}
$r = http('POST', 'users/create.php', $good('dup', ['email' => 'admin@crm.local', 'username' => 'admin']), $sa);
check('duplicate email + username rejected', $r['status'] === 422 && isset($r['body']['errors']['email'], $r['body']['errors']['username']));
expectStatus('invalid role value', http('POST', 'users/create.php', $good('role', ['role' => 'root']), $sa), 422);
expectStatus('invalid status value', http('POST', 'users/create.php', $good('st', ['status' => 'banned', 'role' => 'admin']), $sa), 422);
expectStatus('super admin creating super_admin', http('POST', 'users/create.php', $good('sa', ['role' => 'super_admin']), $sa), 403);
expectStatus('admin creating an admin', http('POST', 'users/create.php', $good('adm', ['role' => 'admin']), $ad), 403);
expectStatus('user without assigned admin (super admin)', http('POST', 'users/create.php', $good('nomgr', ['role' => 'user']), $sa), 422);

// ---------------------------------------------------------------------
section('User management — lifecycle & hierarchy');
$r = http('POST', 'users/create.php', $good('admin2', ['role' => 'admin']), $sa);
expectStatus('super admin creates Admin', $r, 201);
$admin2 = $r['body']['data']['user']['id'] ?? 0;
$createdIds[] = $admin2;

$r = http('POST', 'users/create.php', $good('user2', ['role' => 'user', 'manager_id' => $admin2]), $sa);
expectStatus('super admin creates User under Admin2', $r, 201);
$user2 = $r['body']['data']['user']['id'] ?? 0;
$createdIds[] = $user2;
check('User2 assigned to Admin2', ($r['body']['data']['user']['manager']['id'] ?? null) === $admin2);

$r = http('POST', 'users/create.php', $good('mine', ['role' => 'user', 'manager_id' => $admin2]), $ad);
expectStatus('admin creates User', $r, 201);
$mine = $r['body']['data']['user']['id'] ?? 0;
$createdIds[] = $mine;
check('admin-created User is forced under that admin', ($r['body']['data']['user']['manager']['id'] ?? null) === 2);

$list = http('GET', 'users/list.php?per_page=100', null, $ad);
$ids = array_column($list['body']['data']['items'] ?? [], 'id');
check("admin list contains own users only (not Admin2's user)", in_array($mine, $ids, true) && !in_array($user2, $ids, true) && !in_array($admin2, $ids, true));
expectStatus("admin views Admin2's user", http('GET', "users/view.php?id=$user2", null, $ad), 403);
expectStatus("admin edits Admin2's user", http('PUT', 'users/update.php', ['id' => $user2, 'name' => 'Hacked'], $ad), 403);
expectStatus('admin edits super admin', http('PUT', 'users/update.php', ['id' => 1, 'name' => 'Hacked'], $ad), 403);
expectStatus('admin promotes own user to admin', http('PUT', 'users/update.php', ['id' => $mine, 'role' => 'admin'], $ad), 403);
expectStatus('admin moves own user to another admin', http('PUT', 'users/update.php', ['id' => $mine, 'manager_id' => $admin2], $ad), 403);
expectStatus('super admin edits own account via users API', http('PUT', 'users/update.php', ['id' => 1, 'status' => 'inactive'], $sa), 403);
expectStatus('view missing record', http('GET', 'users/view.php?id=999999', null, $sa), 404);
expectStatus('view with SQLi id', http('GET', 'users/view.php?id=1%20OR%201=1', null, $sa), 422);

$missingEditPhone = http('PUT', 'users/update.php', ['id' => $mine, 'name' => 'Needs phone'], $sa);
check('super admin cannot edit a user without a phone number', $missingEditPhone['status'] === 422 && isset($missingEditPhone['body']['errors']['phone']));
$missingEditPhone = http('PUT', 'users/update.php', ['id' => $admin2, 'name' => 'Needs phone'], $sa);
check('super admin cannot edit an admin without a phone number', $missingEditPhone['status'] === 422 && isset($missingEditPhone['body']['errors']['phone']));

$r = http('PUT', 'users/update.php', ['id' => $mine, 'name' => 'Renamed User', 'phone' => ''], $ad);
check('admin edits own user', $r['status'] === 200 && ($r['body']['data']['user']['name'] ?? '') === 'Renamed User');

$search = http('GET', 'users/list.php?search=' . urlencode("' OR 1=1 -- %"), null, $sa);
check('SQLi in search returns 200 with no rows', $search['status'] === 200 && ($search['body']['data']['pagination']['total'] ?? -1) === 0);

// Deactivation signs the account out and blocks login.
$tok = login("t_mine_$runId", 'Passw0rdX');
check('new user can log in', $tok !== null);
expectStatus('admin deactivates own user', http('PUT', 'users/update.php', ['id' => $mine, 'status' => 'inactive'], $ad), 200);
expectStatus('deactivated user session revoked', http('GET', 'auth/me.php', null, $tok), 401);
expectStatus('deactivated user cannot log in', http('POST', 'auth/login.php', ['login' => "t_mine_$runId", 'password' => 'Passw0rdX']), 403);
expectStatus('admin reactivates user', http('PUT', 'users/update.php', ['id' => $mine, 'status' => 'active'], $ad), 200);
check('reactivated user can log in', login("t_mine_$runId", 'Passw0rdX') !== null);

// Role changes (super admin).
$r = http('PUT', 'users/update.php', ['id' => $admin2, 'role' => 'user', 'manager_id' => 2, 'phone' => '+91 90000 00000'], $sa);
check('cannot demote an admin who still manages users', $r['status'] === 422 && isset($r['body']['errors']['role']));
expectStatus('super admin reassigns User2 to Admin', http('PUT', 'users/update.php', ['id' => $user2, 'manager_id' => 2, 'phone' => '+91 90000 00000'], $sa), 200);
expectStatus('super admin demotes Admin2 to User', http('PUT', 'users/update.php', ['id' => $admin2, 'role' => 'user', 'manager_id' => 2, 'phone' => '+91 90000 00000'], $sa), 200);
expectStatus('super admin promotes back to Admin', http('PUT', 'users/update.php', ['id' => $admin2, 'role' => 'admin', 'phone' => '+91 90000 00000'], $sa), 200);

// ---------------------------------------------------------------------
section('Activity scope & logging');
$act = http('GET', 'activity/list.php?per_page=100', null, $us);
$owners = array_values(array_unique(array_map(fn ($i) => $i['user']['id'] ?? null, $act['body']['data']['items'] ?? [])));
check('user sees only own activity', $owners === [3]);
$act = http('GET', 'activity/list.php?per_page=100', null, $ad);
$owners = array_values(array_unique(array_map(fn ($i) => $i['user']['id'] ?? null, $act['body']['data']['items'] ?? [])));
check('admin activity excludes super admin', !in_array(1, $owners, true));
foreach (['auth.login', 'auth.login_failed', 'access.denied', 'user.created', 'user.updated', 'user.deactivated', 'user.activated', 'user.role_changed', 'user.reassigned'] as $action) {
    check("activity logged: $action", (int) Database::value('SELECT COUNT(*) FROM activity_logs WHERE action = ?', [$action]) > 0);
}
check('activity log stores IP address', (int) Database::value("SELECT COUNT(*) FROM activity_logs WHERE ip_address IS NOT NULL AND ip_address <> ''") > 0);

// ---------------------------------------------------------------------
section('Passwords & change password');
$hashes = Database::fetchAll('SELECT password_hash FROM users');
check('all passwords stored as bcrypt hashes', count(array_filter($hashes, fn ($h) => !str_starts_with($h['password_hash'], '$2y$'))) === 0);
check('no plaintext seed password in DB', (int) Database::value('SELECT COUNT(*) FROM users WHERE password_hash IN (?, ?, ?)', [
    Env::get('SEED_SUPER_ADMIN_PASSWORD'), Env::get('SEED_ADMIN_PASSWORD'), Env::get('SEED_USER_PASSWORD'),
]) === 0);
$t1 = login("t_mine_$runId", 'Passw0rdX');
$t2 = login("t_mine_$runId", 'Passw0rdX');
$r = http('POST', 'auth/change-password.php', ['current_password' => 'wrong', 'new_password' => 'NewPassw0rd', 'confirm_password' => 'NewPassw0rd'], $t1);
check('wrong current password rejected', $r['status'] === 422 && isset($r['body']['errors']['current_password']));
$r = http('POST', 'auth/change-password.php', ['current_password' => 'Passw0rdX', 'new_password' => 'NewPassw0rd', 'confirm_password' => 'Mismatch1A'], $t1);
check('mismatched confirmation rejected', $r['status'] === 422 && isset($r['body']['errors']['confirm_password']));
expectStatus('password changed', http('POST', 'auth/change-password.php', ['current_password' => 'Passw0rdX', 'new_password' => 'NewPassw0rd', 'confirm_password' => 'NewPassw0rd'], $t1), 200);
expectStatus('current session kept', http('GET', 'auth/me.php', null, $t1), 200);
expectStatus('other session revoked', http('GET', 'auth/me.php', null, $t2), 401);
check('login with new password', login("t_mine_$runId", 'NewPassw0rd') !== null);

require __DIR__ . '/phase2_records.php';
require __DIR__ . '/phase3_approvals.php';
if (Env::get('TEST_MAILBOX_URL')) require __DIR__ . '/phase4_crm.php';
require __DIR__ . '/phase5_reports.php';
require __DIR__ . '/phase6_media.php';

// ---------------------------------------------------------------------
section('Logout');
expectStatus('logout', http('POST', 'auth/logout.php', [], $us), 200);
expectStatus('token rejected after logout', http('GET', 'auth/me.php', null, $us), 401);
check('logout logged', (int) Database::value("SELECT COUNT(*) FROM activity_logs WHERE action = 'auth.logout'") > 0);

// ---------------------------------------------------------------------
section('Security headers & error handling');
$r = http('GET', 'auth/me.php', null, $sa);
check('X-Content-Type-Options: nosniff', stripos($r['headers'], 'X-Content-Type-Options: nosniff') !== false);
check('Cache-Control: no-store', stripos($r['headers'], 'Cache-Control: no-store') !== false);
$r = http('POST', 'auth/login.php', ['login' => 'superadmin', 'password' => Env::get('SEED_SUPER_ADMIN_PASSWORD')]);
check('session cookie is HttpOnly + SameSite=Strict', (bool) preg_match('/Set-Cookie:[^\r\n]*HttpOnly[^\r\n]*SameSite=Strict|Set-Cookie:[^\r\n]*SameSite=Strict[^\r\n]*HttpOnly/i', $r['headers']));

// ---------------------------------------------------------------------
section('Brute-force protection');
Database::execute("DELETE FROM activity_logs WHERE action = 'auth.login_failed'");
$limit = (int) Config::get('auth.max_failed_logins');
for ($i = 0; $i < $limit; $i++) {
    http('POST', 'auth/login.php', ['login' => 'superadmin', 'password' => 'wrong']);
}
expectStatus("login blocked after $limit failures", http('POST', 'auth/login.php', ['login' => 'superadmin', 'password' => Env::get('SEED_SUPER_ADMIN_PASSWORD')]), 429);
Database::execute("DELETE FROM activity_logs WHERE action = 'auth.login_failed'"); // unlock for development

// ---------------------------------------------------------------------
section('Cleanup');
foreach ($startIds as $table => $maxId) {
    if ($table === 'real_estate_records') {
        foreach ($mediaTestIds as $mediaRecordId) PropertyMedia::purgeRecordFiles($mediaRecordId);
    }
    $removed = Database::execute("DELETE FROM $table WHERE id > ?", [$maxId]);
    echo "  removed $removed row(s) from $table\n";
}
@unlink($testPng);
$createdIds = array_values(array_filter($createdIds));
if ($createdIds) {
    $in = implode(',', array_fill(0, count($createdIds), '?'));
    Database::execute("UPDATE users SET manager_id = NULL WHERE manager_id IN ($in)", $createdIds);
    Database::execute("DELETE FROM users WHERE id IN ($in)", $createdIds);
    echo '  removed test accounts: ' . implode(', ', $createdIds) . "\n";
}

echo "\n\033[1mResult: $passed passed, " . count($failed) . " failed\033[0m\n";
exit($failed ? 1 : 0);
