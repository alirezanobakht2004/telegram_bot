<?php

declare(strict_types=1);

use SmartToolbox\Modules\MiniApp\InitDataValidator;
use SmartToolbox\Modules\MiniApp\MiniAppAuditLogger;
use SmartToolbox\Modules\MiniApp\MiniAppException;
use SmartToolbox\Modules\MiniApp\MiniAppMaintenanceWorker;
use SmartToolbox\Modules\MiniApp\MiniAppRateLimiter;
use SmartToolbox\Modules\MiniApp\MiniAppRepository;
use SmartToolbox\Modules\MiniApp\MiniAppSessionRepository;

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

$botToken = '123456789:release-six-test-token';
$now = time();
$user = [
    'id' => 1001,
    'is_bot' => false,
    'first_name' => 'Ali',
    'last_name' => 'Test',
    'username' => 'ali_test',
    'language_code' => 'fa',
    'is_premium' => true,
];

$fields = [
    'auth_date' => (string) $now,
    'query_id' => 'AAEAAAE',
    'start_param' => 'dashboard',
    'user' => json_encode(
        $user,
        JSON_THROW_ON_ERROR
        | JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
    ),
];

ksort($fields, SORT_STRING);
$dataCheckString = implode(
    "\n",
    array_map(
        static fn (
            string $key,
            string $value
        ): string => $key . '=' . $value,
        array_keys($fields),
        array_values($fields)
    )
);
$secretKey = hash_hmac(
    'sha256',
    $botToken,
    'WebAppData',
    true
);
$hash = hash_hmac(
    'sha256',
    $dataCheckString,
    $secretKey
);
$initData = http_build_query(
    [...$fields, 'hash' => $hash],
    '',
    '&',
    PHP_QUERY_RFC3986
);

$validator = new InitDataValidator(
    botToken: $botToken,
    maxAgeSeconds: 300,
    futureSkewSeconds: 30,
    maxBytes: 16384
);
$validated = $validator->validate(
    $initData,
    $now
);

$assert(
    (int) $validated['user']['id'] === 1001
    && $validated['auth_date'] === $now
    && $validated['start_param'] === 'dashboard',
    'Telegram initData validation failed.'
);

$tamperRejected = false;

try {
    $validator->validate(
        str_replace(
            'dashboard',
            'settings',
            $initData
        ),
        $now
    );
} catch (MiniAppException $exception) {
    $tamperRejected = $exception->errorCode
        === 'init_data_signature_mismatch';
}

$assert(
    $tamperRejected,
    'Tampered Telegram initData was accepted.'
);

$expiredFields = $fields;
$expiredFields['auth_date'] = (string) (
    $now - 301
);
ksort($expiredFields, SORT_STRING);
$expiredCheck = implode(
    "\n",
    array_map(
        static fn (
            string $key,
            string $value
        ): string => $key . '=' . $value,
        array_keys($expiredFields),
        array_values($expiredFields)
    )
);
$expiredHash = hash_hmac(
    'sha256',
    $expiredCheck,
    $secretKey
);
$expiredData = http_build_query(
    [
        ...$expiredFields,
        'hash' => $expiredHash,
    ],
    '',
    '&',
    PHP_QUERY_RFC3986
);
$expiredRejected = false;

try {
    $validator->validate(
        $expiredData,
        $now
    );
} catch (MiniAppException $exception) {
    $expiredRejected = $exception->errorCode
        === 'init_data_expired';
}

$assert(
    $expiredRejected,
    'Expired Telegram initData was accepted.'
);

$databaseStatus = 'skipped';

if (extension_loaded('pdo_sqlite')) {
    $temporaryDirectory = sys_get_temp_dir()
        . '/smart-toolbox-release-six-'
        . bin2hex(random_bytes(5));

    if (!mkdir(
        $temporaryDirectory,
        0700,
        true
    )) {
        throw new RuntimeException(
            'Temporary directory could not be created.'
        );
    }

    $databasePath = $temporaryDirectory
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
    $pdo->exec('PRAGMA foreign_keys = ON');

    $pdo->exec(
        'CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            telegram_id INTEGER NOT NULL UNIQUE,
            is_bot INTEGER NOT NULL DEFAULT 0,
            first_name TEXT,
            last_name TEXT,
            username TEXT,
            language_code TEXT,
            is_premium INTEGER,
            first_seen_at TEXT NOT NULL,
            last_seen_at TEXT NOT NULL,
            last_chat_id INTEGER,
            request_count INTEGER NOT NULL DEFAULT 0,
            is_blocked INTEGER NOT NULL DEFAULT 0
        )'
    );

    $pdo->exec(
        'CREATE TABLE chats (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            telegram_id INTEGER NOT NULL UNIQUE,
            type TEXT NOT NULL,
            title TEXT,
            username TEXT,
            first_name TEXT,
            last_name TEXT,
            first_seen_at TEXT NOT NULL,
            last_seen_at TEXT NOT NULL,
            request_count INTEGER NOT NULL DEFAULT 0,
            is_active INTEGER NOT NULL DEFAULT 1,
            admin_blocked INTEGER NOT NULL DEFAULT 0
        )'
    );

    $pdo->exec(
        'CREATE TABLE user_preferences (
            actor_key TEXT NOT NULL,
            preference_key TEXT NOT NULL,
            preference_value TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            PRIMARY KEY (
                actor_key,
                preference_key
            )
        )'
    );

    foreach (
        [
            '007_reminders.sql',
            '008_v2_foundation_analytics.sql',
            '009_release_one_inline_profile_wiki_github.sql',
            '010_release_two_alerts_subscriptions_monitoring.sql',
            '013_release_five_quiz_games.sql',
            '014_release_six_mini_app.sql',
        ]
        as $migrationName
    ) {
        $migrationPath = $rootPath
            . '/database/migrations/'
            . $migrationName;
        $sql = file_get_contents(
            $migrationPath
        );

        if (!is_string($sql)) {
            throw new RuntimeException(
                "Migration could not be read: {$migrationName}"
            );
        }

        $pdo->exec($sql);
    }

    $repository = new MiniAppRepository(
        $pdo
    );
    $repository->ensureUserAndPrivateChat(
        $validated['user']
    );

    $sessions = new MiniAppSessionRepository(
        pdo: $pdo,
        idleTtlSeconds: 1200,
        absoluteTtlSeconds: 21600,
        maxActivePerUser: 2
    );
    $created = $sessions->create(
        userId: 1001,
        initDataHash:
            $validated['init_data_hash'],
        ipAddress: '127.0.0.1',
        userAgent: 'ReleaseSixTest/1.0'
    );
    $authenticated = $sessions->authenticate(
        $created['token'],
        'ReleaseSixTest/1.0',
        false
    );

    $assert(
        (int) $authenticated['user_id']
            === 1001,
        'Mini App session authentication failed.'
    );

    $sessions->verifyCsrf(
        $authenticated,
        $created['csrf_token']
    );

    $csrfRejected = false;

    try {
        $sessions->verifyCsrf(
            $authenticated,
            str_repeat('0', 64)
        );
    } catch (MiniAppException $exception) {
        $csrfRejected = $exception->errorCode
            === 'csrf_invalid';
    }

    $assert(
        $csrfRejected,
        'Invalid CSRF token was accepted.'
    );

    $contextRejected = false;

    try {
        $sessions->authenticate(
            $created['token'],
            'ChangedUserAgent/1.0',
            false
        );
    } catch (MiniAppException $exception) {
        $contextRejected = $exception->errorCode
            === 'session_context_mismatch';
    }

    $assert(
        $contextRejected,
        'Changed User-Agent did not revoke the session.'
    );

    $created = $sessions->create(
        userId: 1001,
        initDataHash:
            $validated['init_data_hash'],
        ipAddress: '127.0.0.1',
        userAgent: 'ReleaseSixTest/1.0'
    );

    $limiter = new MiniAppRateLimiter($pdo);
    $assert(
        $limiter->attempt(
            'test-key',
            2,
            60
        )['allowed'] === true,
        'First Mini App rate-limit attempt failed.'
    );
    $assert(
        $limiter->attempt(
            'test-key',
            2,
            60
        )['allowed'] === true,
        'Second Mini App rate-limit attempt failed.'
    );
    $assert(
        $limiter->attempt(
            'test-key',
            2,
            60
        )['allowed'] === false,
        'Mini App rate limit was not enforced.'
    );

    $audit = new MiniAppAuditLogger($pdo);
    $audit->record(
        userId: 1001,
        sessionId: (int)
            $created['session']['id'],
        action: 'test.action',
        success: true,
        ipAddress: '127.0.0.1',
        userAgent: 'ReleaseSixTest/1.0',
        details: [
            'session_token' =>
                $created['token'],
            'safe' => 'value',
        ]
    );

    $detailsJson = $pdo->query(
        "SELECT details_json
         FROM mini_app_audit_logs
         WHERE action = 'test.action'
         ORDER BY id DESC
         LIMIT 1"
    )->fetchColumn();

    $assert(
        is_string($detailsJson)
        && str_contains(
            $detailsJson,
            '[redacted]'
        )
        && !str_contains(
            $detailsJson,
            $created['token']
        ),
        'Mini App audit secret redaction failed.'
    );

    $settings = $repository->updateSettings(
        1001,
        [
            'timezone' => 'Asia/Tehran',
            'output_language' => 'fa',
            'number_format' => 'persian',
            'date_format' => 'local',
            'menu_order' =>
                'mini_app,weather,currency',
        ]
    );

    $assert(
        $settings['number_format']
            === 'persian',
        'Mini App settings update failed.'
    );

    $reminderId = $repository
        ->createReminder(
            userId: 1001,
            text: 'Release six reminder',
            scheduledAt: time() + 3600,
            timezone: 'Asia/Tehran',
            maxFutureDays: 365,
            maxPending: 50,
            maxTextLength: 1000
        );
    $favoriteId = $repository
        ->saveFavorite(
            userId: 1001,
            type: 'weather',
            commandText: 'weather Tehran',
            label: 'آب‌وهوای تهران',
            payload: ['city' => 'Tehran'],
            maxFavorites: 50
        );
    $shortcutId = $repository
        ->saveShortcut(
            userId: 1001,
            name: 'officeweather',
            commandText: 'weather Tehran',
            maxShortcuts: 30
        );
    $dashboard = $repository->dashboard(1001);

    $assert(
        $reminderId > 0
        && $favoriteId > 0
        && $shortcutId > 0
        && (int) $dashboard['counts'][
            'reminders'
        ] === 1
        && (int) $dashboard['counts'][
            'favorites'
        ] === 1
        && (int) $dashboard['counts'][
            'shortcuts'
        ] === 1,
        'Mini App dashboard aggregation failed.'
    );

    $feature = $pdo->query(
        "SELECT enabled, rollout_percentage
         FROM feature_flags
         WHERE flag_key = 'mini_app'"
    )->fetch(PDO::FETCH_NUM);

    $assert(
        is_array($feature)
        && (int) $feature[0] === 1
        && (int) $feature[1] === 100,
        'Mini App feature flag failed.'
    );

    $requiredTables = [
        'mini_app_sessions',
        'mini_app_rate_limits',
        'mini_app_audit_logs',
    ];

    foreach ($requiredTables as $table) {
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

    $maintenance = (
        new MiniAppMaintenanceWorker(
            $sessions
        )
    )->run(30, 180);

    $assert(
        isset(
            $maintenance['sessions'],
            $maintenance['rate_limits'],
            $maintenance['audit']
        ),
        'Mini App maintenance result is invalid.'
    );

    $databaseStatus = 'passed';

    unset(
        $maintenance,
        $audit,
        $limiter,
        $sessions,
        $repository,
        $statement,
        $pdo
    );
    gc_collect_cycles();

    $cleanupDirectory = static function (
        string $directory
    ): void {
        for (
            $attempt = 1;
            $attempt <= 10;
            $attempt++
        ) {
            if (!is_dir($directory)) {
                return;
            }

            $iterator =
                new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator(
                        $directory,
                        FilesystemIterator::SKIP_DOTS
                    ),
                    RecursiveIteratorIterator::CHILD_FIRST
                );

            foreach ($iterator as $item) {
                $path = $item->getPathname();

                if ($item->isDir()) {
                    @rmdir($path);
                } else {
                    @unlink($path);
                }
            }

            if (@rmdir($directory)) {
                return;
            }

            gc_collect_cycles();
            usleep(100000);
        }

        throw new RuntimeException(
            'Temporary release-six directory could not be removed: '
            . $directory
        );
    };

    $cleanupDirectory(
        $temporaryDirectory
    );
}

echo json_encode(
    [
        'status' => 'passed',
        'tests' => [
            'init_data_signature' => true,
            'init_data_expiry' => true,
            'tamper_rejection' => true,
            'session_authentication' => true,
            'csrf' => true,
            'session_context_binding' => true,
            'rate_limit' => true,
            'audit_redaction' => true,
            'dashboard_repository' => true,
            'database' => $databaseStatus,
        ],
    ],
    JSON_PRETTY_PRINT
    | JSON_UNESCAPED_UNICODE
    | JSON_UNESCAPED_SLASHES
) . PHP_EOL;
