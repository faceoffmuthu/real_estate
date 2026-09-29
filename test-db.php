<?php

require __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

try {
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $_ENV['DB_HOST'],
        $_ENV['DB_PORT'],
        $_ENV['DB_NAME']
    );

    $pdo = new PDO(
        $dsn,
        $_ENV['DB_USER'],
        $_ENV['DB_PASS'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    echo "SUCCESS: Connected to Clever Cloud MySQL!" . PHP_EOL;

    $result = $pdo->query('SELECT VERSION() AS version');
    $row = $result->fetch();

    echo "MySQL Version: " . $row['version'] . PHP_EOL;

} catch (PDOException $e) {
    echo "DATABASE CONNECTION FAILED" . PHP_EOL;
    echo $e->getMessage() . PHP_EOL;
}