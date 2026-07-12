<?php

declare(strict_types=1);

use SmartToolbox\Core\Database;

$rootPath = dirname(__DIR__);

$config = require $rootPath . '/bootstrap/app.php';

$requiredExtensions = [
    'curl',
    'json',
    'mbstring',
    'openssl',
    'pdo',
    'pdo_sqlite',
    'sqlite3',
];

$missingExtensions = array_values(
    array_filter(
        $requiredExtensions,
        static fn (string $extension): bool =>
            !extension_loaded($extension)
    )
);

if ($missingExtensions !== []) {
    fwrite(
        STDERR,
        'Missing extensions: '
        . implode(', ', $missingExtensions)
        . PHP_EOL
    );

    exit(1);
}

$databasePath = (string) $config->get('database.path');

$pdo = Database::connect($databasePath);

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS _environment_check (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        checked_at TEXT NOT NULL
    )'
);

$statement = $pdo->prepare(
    'INSERT INTO _environment_check (checked_at)
     VALUES (:checked_at)'
);

$statement->execute([
    'checked_at' => date(DATE_ATOM),
]);

$count = (int) $pdo
    ->query('SELECT COUNT(*) FROM _environment_check')
    ->fetchColumn();

$result = [
    'status' => 'ready',
    'php_version' => PHP_VERSION,
    'environment' => $config->get('app.environment'),
    'timezone' => date_default_timezone_get(),
    'sqlite' => 'working',
    'database_path' => $databasePath,
    'database_writable' => is_writable($databasePath),
    'health_check_rows' => $count,
];

echo json_encode(
    $result,
    JSON_PRETTY_PRINT
    | JSON_UNESCAPED_UNICODE
    | JSON_UNESCAPED_SLASHES
) . PHP_EOL;