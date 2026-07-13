<?php

declare(strict_types=1);

use SmartToolbox\Core\FileCache;
use SmartToolbox\Modules\Developer\CronExpression;
use SmartToolbox\Modules\Developer\JsonPathEvaluator;
use SmartToolbox\Modules\Developer\UlidGenerator;
use SmartToolbox\Modules\GitHub\GitHubClient;
use SmartToolbox\Modules\GitHub\GitHubWatchRepository;
use SmartToolbox\Modules\Inline\InlineResultFactory;
use SmartToolbox\Modules\Profile\ProfileRepository;

$rootPath = dirname(__DIR__);

require $rootPath
    . '/vendor/autoload.php';

$assert = static function (
    bool $condition,
    string $message
): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$jsonPath = new JsonPathEvaluator();

$value = $jsonPath->evaluate(
    [
        'users' => [
            ['name' => 'Ali'],
            ['name' => 'Sara'],
        ],
    ],
    '$.users[1].name'
);

$assert(
    $value === 'Sara',
    'JSONPath evaluation failed.'
);

$wildcard = $jsonPath->evaluate(
    [
        'users' => [
            ['name' => 'Ali'],
            ['name' => 'Sara'],
        ],
    ],
    '$.users[*].name'
);

$assert(
    $wildcard === ['Ali', 'Sara'],
    'JSONPath wildcard failed.'
);

$ulid = (new UlidGenerator())->generate();

$assert(
    preg_match(
        '/^[0-9A-HJKMNP-TV-Z]{26}$/',
        $ulid
    ) === 1,
    'ULID format is invalid.'
);

$cronRuns = (new CronExpression())->nextRuns(
    '*/15 * * * *',
    new DateTimeZone('UTC'),
    3
);

$assert(
    count($cronRuns) === 3,
    'Cron calculation failed.'
);

$temporaryDirectory =
    sys_get_temp_dir()
    . '/smart-toolbox-release-one-'
    . bin2hex(random_bytes(5));

if (
    !mkdir(
        $temporaryDirectory,
        0700,
        true
    )
) {
    throw new RuntimeException(
        'Temporary test directory could not be created.'
    );
}

$cache = new FileCache(
    $temporaryDirectory . '/cache'
);

$github = new GitHubClient(
    cache: $cache,
    userAgent: 'SmartToolboxReleaseOneTest/1.0'
);

$repository = $github->parseRepository(
    'https://github.com/php/php-src.git'
);

$assert(
    $repository['full_name']
        === 'php/php-src',
    'GitHub repository parsing failed.'
);

$article = (
    new InlineResultFactory()
)->article(
    'test',
    'one',
    'Result title',
    'Result description',
    'Result message'
);

$assert(
    ($article['type'] ?? null)
        === 'article'
    && is_string($article['id'] ?? null)
    && strlen($article['id']) <= 64,
    'Inline result factory failed.'
);

$databaseStatus = 'skipped';

if (
    extension_loaded('pdo_sqlite')
) {
    $databasePath =
        $temporaryDirectory
        . '/test.sqlite';

    $pdo = new PDO(
        'sqlite:' . $databasePath,
        options: [
            PDO::ATTR_ERRMODE =>
                PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE =>
                PDO::FETCH_ASSOC,
        ]
    );

    $pdo->exec(
        'PRAGMA foreign_keys = ON'
    );

    $pdo->exec(
        'CREATE TABLE users (
            telegram_id INTEGER PRIMARY KEY,
            first_name TEXT,
            last_name TEXT,
            username TEXT,
            language_code TEXT,
            first_seen_at TEXT,
            last_seen_at TEXT,
            request_count INTEGER NOT NULL DEFAULT 0
        )'
    );

    $pdo->exec(
        'CREATE TABLE chats (
            telegram_id INTEGER PRIMARY KEY
        )'
    );

    $pdo->exec(
        'CREATE TABLE feature_flags (
            flag_key TEXT PRIMARY KEY,
            enabled INTEGER NOT NULL,
            rollout_percentage INTEGER NOT NULL,
            description TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            updated_by TEXT NOT NULL
        )'
    );

    $pdo->exec(
        'CREATE TABLE command_history (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            module TEXT NOT NULL,
            command TEXT NOT NULL,
            source TEXT NOT NULL,
            arguments_preview TEXT,
            success INTEGER NOT NULL,
            duration_ms REAL NOT NULL,
            created_at TEXT NOT NULL
        )'
    );

    $pdo->exec(
        "CREATE TABLE reminders (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            status TEXT NOT NULL
        )"
    );

    $pdo->exec(
        "INSERT INTO users (
            telegram_id,
            first_name,
            username,
            first_seen_at,
            last_seen_at,
            request_count
         ) VALUES (
            1001,
            'Ali',
            'ali',
            '2026-07-13T00:00:00+00:00',
            '2026-07-13T00:00:00+00:00',
            10
         )"
    );

    $pdo->exec(
        'INSERT INTO chats (
            telegram_id
         ) VALUES (
            2001
         )'
    );

    $migration = file_get_contents(
        $rootPath
        . '/database/migrations/'
        . '009_release_one_inline_profile_wiki_github.sql'
    );

    if (!is_string($migration)) {
        throw new RuntimeException(
            'Release-one migration could not be read.'
        );
    }

    $pdo->exec($migration);

    $profiles = new ProfileRepository(
        $pdo
    );

    $favoriteId = $profiles->addFavorite(
        1001,
        'weather',
        'weather Tehran',
        'Weather Tehran',
        ['city' => 'Tehran']
    );

    $assert(
        $favoriteId > 0,
        'Favorite creation failed.'
    );

    $assert(
        $profiles->setFavoritePinned(
            1001,
            $favoriteId,
            true
        ),
        'Favorite pinning failed.'
    );

    $favorites = $profiles->favorites(
        1001
    );

    $assert(
        count($favorites) === 1
        && (int) $favorites[0][
            'is_pinned'
        ] === 1,
        'Favorite listing failed.'
    );

    $profiles->saveShortcut(
        1001,
        'officeweather',
        'weather Tehran'
    );

    $assert(
        $profiles->shortcut(
            1001,
            'officeweather'
        ) !== null,
        'Shortcut creation failed.'
    );

    $watches =
        new GitHubWatchRepository($pdo);

    $watchId = $watches->watch(
        1001,
        2001,
        'php',
        'php-src',
        null,
        null
    );

    $assert(
        $watchId > 0
        && count(
            $watches->forUser(1001)
        ) === 1,
        'GitHub watch repository failed.'
    );

    $tables = [
        'user_favorites',
        'user_shortcuts',
        'github_release_watches',
        'inline_result_selections',
    ];

    foreach ($tables as $table) {
        $statement = $pdo->prepare(
            "SELECT COUNT(*)
             FROM sqlite_master
             WHERE type = 'table'
               AND name = :name"
        );

        $statement->execute([
            'name' => $table,
        ]);

        $assert(
            (int) $statement->fetchColumn()
                === 1,
            "Missing table: {$table}"
        );
    }

    $databaseStatus = 'passed';
}

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator(
        $temporaryDirectory,
        FilesystemIterator::SKIP_DOTS
    ),
    RecursiveIteratorIterator::CHILD_FIRST
);

foreach ($iterator as $item) {
    if ($item->isDir()) {
        rmdir($item->getPathname());
    } else {
        unlink($item->getPathname());
    }
}

rmdir($temporaryDirectory);

echo json_encode(
    [
        'status' => 'passed',
        'tests' => [
            'jsonpath' => true,
            'ulid' => true,
            'cron' => true,
            'github_repository_parser' => true,
            'inline_result_factory' => true,
            'database' => $databaseStatus,
        ],
    ],
    JSON_PRETTY_PRINT
    | JSON_UNESCAPED_UNICODE
    | JSON_UNESCAPED_SLASHES
) . PHP_EOL;
