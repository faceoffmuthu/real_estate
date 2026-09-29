<?php
declare(strict_types=1);

/*
 * DEVELOPMENT database setup (CLI only).
 *
 *   php database/setup.php           create DB + tables, apply pending migrations, seed dev accounts
 *   php database/setup.php --fresh   drop all tables first (DESTROYS DATA)
 *
 * Schema changes after Phase 2 live in database/migrations/NNN_name.sql and
 * are applied once each, in order, tracked in the `schema_migrations` table.
 *
 * Dev account credentials are read from backend/.env (SEED_* keys).
 * Refuses to run when APP_ENV=production.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../bootstrap.php';

function out(string $message): void
{
    fwrite(STDOUT, $message . PHP_EOL);
}

function fail(string $message): never
{
    fwrite(STDERR, "ERROR: $message" . PHP_EOL);
    exit(1);
}

if (Config::get('app.env') === 'production') {
    fail('setup.php is for development only and will not run with APP_ENV=production.');
}

$db = Config::get('db');
if (!preg_match('/^[A-Za-z0-9_]+$/', $db['name'])) {
    fail('DB_NAME may only contain letters, numbers and underscores.');
}

// 1. Create the database if needed (connect without selecting a database).
try {
    $server = new PDO(
        sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $db['host'], $db['port']),
        $db['user'],
        $db['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $server->exec("CREATE DATABASE IF NOT EXISTS `{$db['name']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    out("Database `{$db['name']}` ready.");
} catch (PDOException $e) {
    fail('Could not connect to MySQL. Check DB_* values in backend/.env and that MySQL is running.');
}

$pdo = Database::connection();

// 2. Optionally drop existing tables.
if (in_array('--fresh', $argv, true)) {
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach (['password_reset_tokens', 'follow_ups', 'schema_migrations', 'notifications', 'record_approval_history', 'property_media', 'real_estate_records', 'parties', 'record_process_stages', 'property_categories', 'property_types', 'activity_logs', 'auth_tokens', 'settings', 'users', 'roles'] as $table) {
        $pdo->exec("DROP TABLE IF EXISTS `$table`");
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    out('Dropped existing tables (--fresh).');
}

/** Executes a .sql file statement by statement (line comments stripped). */
function run_sql_file(PDO $pdo, string $path): void
{
    $sql = preg_replace('/^\s*--.*$/m', '', file_get_contents($path));
    foreach (preg_split('/;\s*(\r?\n|$)/', $sql) as $statement) {
        if (trim($statement) !== '') {
            $pdo->exec($statement);
        }
    }
}

// 3. Apply base schema (idempotent: CREATE TABLE IF NOT EXISTS / INSERT IGNORE).
run_sql_file($pdo, __DIR__ . '/schema.sql');
out('Schema applied.');

// 4. Apply pending migrations in filename order. MySQL DDL is not transactional,
//    so each migration must be safe to run against existing data.
$pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
    migration  VARCHAR(150) NOT NULL PRIMARY KEY,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
$applied = array_column(Database::fetchAll('SELECT migration FROM schema_migrations'), 'migration');
$files = glob(__DIR__ . '/migrations/*.sql') ?: [];
sort($files);
foreach ($files as $file) {
    $name = basename($file);
    if (in_array($name, $applied, true)) {
        continue;
    }
    try {
        run_sql_file($pdo, $file);
    } catch (PDOException $e) {
        fail("Migration $name failed: " . $e->getMessage());
    }
    Database::insert('INSERT INTO schema_migrations (migration) VALUES (?)', [$name]);
    out("  + migration applied: $name");
}

// 5. Seed development accounts.
$accounts = [
    Users::SUPER_ADMIN => 'SEED_SUPER_ADMIN',
    Users::ADMIN       => 'SEED_ADMIN',
    Users::USER        => 'SEED_USER',
];
$ids = [];
foreach ($accounts as $role => $prefix) {
    $data = [
        'name'     => Env::get("{$prefix}_NAME", ''),
        'username' => Env::get("{$prefix}_USERNAME", ''),
        'email'    => strtolower(Env::get("{$prefix}_EMAIL", '')),
        'password' => Env::get("{$prefix}_PASSWORD", ''),
    ];
    // Existing accounts do not need seed passwords; never reset their credentials.
    $existing = Database::value('SELECT id FROM users WHERE email = ? OR username = ?', [$data['email'], $data['username']]);
    if ($existing !== null) {
        $ids[$role] = (int) $existing;
        out("  - $role account already exists, skipped.");
        continue;
    }
    $v = (new Validator($data))
        ->required('name', 'Name')->string('name', 'Name', 2, 120)
        ->required('username', 'Username')->username('username')
        ->required('email', 'Email')->email('email')
        ->required('password', 'Password')->password('password');
    if ($v->fails()) {
        fail("Invalid {$prefix}_* values in .env: " . implode(' ', $v->errors()));
    }


    $ids[$role] = Database::insert(
        'INSERT INTO users (role_id, manager_id, name, username, email, password_hash, status)
         VALUES (?, ?, ?, ?, ?, ?, ?)',
        [
            Users::roleId($role),
            $role === Users::USER ? ($ids[Users::ADMIN] ?? null) : null,
            $data['name'],
            $data['username'],
            $data['email'],
            password_hash($data['password'], PASSWORD_DEFAULT),
            'active',
        ]
    );
    out("  + created $role account: {$data['email']}");
}

out('Setup complete. Seed credentials are DEVELOPMENT ONLY — see backend/.env.');
