<?php

declare(strict_types=1);

use SmartToolbox\Core\Database;

$rootPath = dirname(__DIR__);

$config = require $rootPath . '/bootstrap/app.php';

$databasePath = (string) $config->get(
    'database.path'
);

$pdo = Database::connect($databasePath);

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS schema_migrations (
        migration TEXT PRIMARY KEY,
        applied_at TEXT NOT NULL
    )'
);

$migrationFiles = glob(
    $rootPath . '/database/migrations/*.sql'
);

if ($migrationFiles === false) {
    fwrite(
        STDERR,
        "Could not read migration directory.\n"
    );

    exit(1);
}

sort($migrationFiles, SORT_STRING);

$checkStatement = $pdo->prepare(
    'SELECT 1
     FROM schema_migrations
     WHERE migration = :migration
     LIMIT 1'
);

$insertStatement = $pdo->prepare(
    'INSERT INTO schema_migrations (
        migration,
        applied_at
    ) VALUES (
        :migration,
        :applied_at
    )'
);

$appliedCount = 0;

foreach ($migrationFiles as $migrationFile) {
    $migrationName = basename($migrationFile);

    $checkStatement->execute([
        'migration' => $migrationName,
    ]);

    if ($checkStatement->fetchColumn() !== false) {
        echo sprintf(
            "[SKIP] %s\n",
            $migrationName
        );

        continue;
    }

    $sql = file_get_contents($migrationFile);

    if ($sql === false || trim($sql) === '') {
        fwrite(
            STDERR,
            sprintf(
                "[ERROR] Could not read %s\n",
                $migrationName
            )
        );

        exit(1);
    }

    $pdo->beginTransaction();

    try {
        $pdo->exec($sql);

        $insertStatement->execute([
            'migration' => $migrationName,
            'applied_at' => date(DATE_ATOM),
        ]);

        $pdo->commit();

        $appliedCount++;

        echo sprintf(
            "[APPLIED] %s\n",
            $migrationName
        );
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        fwrite(
            STDERR,
            sprintf(
                "[FAILED] %s: %s\n",
                $migrationName,
                $exception->getMessage()
            )
        );

        exit(1);
    }
}

echo sprintf(
    "Migration completed. New migrations: %d\n",
    $appliedCount
);