<?php

declare(strict_types=1);

use SmartToolbox\Modules\GroupManagement\GroupDurationParser;
use SmartToolbox\Modules\GroupManagement\GroupRepository;
use SmartToolbox\Modules\GroupManagement\GroupTemplateRenderer;

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

$duration = new GroupDurationParser();

$assert(
    $duration->parse('10m') === 600,
    'English duration parsing failed.'
);

$assert(
    $duration->parse('۲ ساعت') === 7200,
    'Persian duration parsing failed.'
);

$assert(
    $duration->parse('forever') === null,
    'Permanent duration parsing failed.'
);

$renderer = new GroupTemplateRenderer();

$rendered = $renderer->render(
    'سلام {full_name} به {chat_title} خوش آمدی؛ {username}',
    [
        'id' => 1002,
        'first_name' => 'Sara',
        'last_name' => 'Ahmadi',
        'username' => 'sara',
    ],
    [
        'title' => 'Test Group',
    ]
);

$assert(
    $rendered ===
        'سلام Sara Ahmadi به Test Group خوش آمدی؛ @sara',
    'Template rendering failed.'
);

$databaseStatus = 'skipped';

if (extension_loaded('pdo_sqlite')) {
    $temporaryDirectory =
        sys_get_temp_dir()
        . '/smart-toolbox-release-three-'
        . bin2hex(random_bytes(5));

    if (
        !mkdir(
            $temporaryDirectory,
            0700,
            true
        )
    ) {
        throw new RuntimeException(
            'Temporary directory could not be created.'
        );
    }

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
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            telegram_id INTEGER NOT NULL UNIQUE,
            is_bot INTEGER NOT NULL DEFAULT 0,
            first_name TEXT,
            last_name TEXT,
            username TEXT,
            language_code TEXT,
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
        'CREATE TABLE feature_flags (
            flag_key TEXT PRIMARY KEY,
            enabled INTEGER NOT NULL,
            rollout_percentage INTEGER NOT NULL,
            description TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            updated_by TEXT NOT NULL
        )'
    );

    $now = date(DATE_ATOM);

    $insertUser = $pdo->prepare(
        'INSERT INTO users (
            telegram_id,
            first_name,
            username,
            first_seen_at,
            last_seen_at
         ) VALUES (
            :telegram_id,
            :first_name,
            :username,
            :first_seen_at,
            :last_seen_at
         )'
    );

    foreach (
        [
            [1001, 'Admin', 'admin'],
            [1002, 'Sara', 'sara'],
            [1003, 'Ali', 'ali'],
        ]
        as [$id, $name, $username]
    ) {
        $insertUser->execute([
            'telegram_id' => $id,
            'first_name' => $name,
            'username' => $username,
            'first_seen_at' => $now,
            'last_seen_at' => $now,
        ]);
    }

    $insertChat = $pdo->prepare(
        'INSERT INTO chats (
            telegram_id,
            type,
            title,
            first_seen_at,
            last_seen_at
         ) VALUES (
            :telegram_id,
            :type,
            :title,
            :first_seen_at,
            :last_seen_at
         )'
    );

    $insertChat->execute([
        'telegram_id' => -100200300,
        'type' => 'supergroup',
        'title' => 'Test Group',
        'first_seen_at' => $now,
        'last_seen_at' => $now,
    ]);

    $migration = file_get_contents(
        $rootPath
        . '/database/migrations/'
        . '011_release_three_group_management.sql'
    );

    if (!is_string($migration)) {
        throw new RuntimeException(
            'Release-three migration could not be read.'
        );
    }

    $pdo->exec($migration);

    $repository = new GroupRepository(
        pdo: $pdo,
        defaultSettings: [
            'warnings_threshold' => 4,
            'flood_max_messages' => 3,
            'flood_window_seconds' => 10,
            'duplicate_max_messages' => 2,
            'duplicate_window_seconds' => 30,
        ]
    );

    $settings = $repository->settings(
        -100200300
    );

    $assert(
        (int) $settings[
            'warnings_threshold'
        ] === 4,
        'Runtime group defaults were not applied.'
    );

    $warningId = $repository->addWarning(
        -100200300,
        1002,
        1001,
        'Test warning'
    );

    $assert(
        $warningId > 0
        && $repository->activeWarningCount(
            -100200300,
            1002
        ) === 1,
        'Warning creation or counting failed.'
    );

    $assert(
        $repository->revokeWarning(
            -100200300,
            $warningId,
            1001
        ),
        'Warning revocation failed.'
    );

    $repository->addBadWord(
        -100200300,
        'عبارت ممنوع',
        1001
    );

    $assert(
        $repository->badWords(
            -100200300
        ) === ['عبارت ممنوع'],
        'Bad-word storage failed.'
    );

    $repository->addDomain(
        -100200300,
        'example.com',
        1001
    );

    $assert(
        $repository->domains(
            -100200300
        ) === ['example.com'],
        'Domain whitelist storage failed.'
    );

    $activitySettings = [
        ...$settings,
        'flood_max_messages' => 2,
        'flood_window_seconds' => 10,
        'duplicate_max_messages' => 2,
        'duplicate_window_seconds' => 30,
        'bot_slow_mode_seconds' => 0,
    ];

    $first = $repository->recordActivity(
        -100200300,
        1002,
        hash('sha256', 'same'),
        time(),
        $activitySettings
    );

    $second = $repository->recordActivity(
        -100200300,
        1002,
        hash('sha256', 'same'),
        time(),
        $activitySettings
    );

    $third = $repository->recordActivity(
        -100200300,
        1002,
        hash('sha256', 'same'),
        time(),
        $activitySettings
    );

    $assert(
        $first['flood'] === false
        && $second['duplicate'] === false
        && $third['flood'] === true
        && $third['duplicate'] === true,
        'Flood or duplicate detection failed.'
    );

    $captchaId = $repository->createCaptcha(
        -100200300,
        1003,
        '2 + 2 = ?',
        '4',
        2,
        time() + 120
    );

    $wrong = $repository->answerCaptcha(
        $captchaId,
        1003,
        '5'
    );

    $correct = $repository->answerCaptcha(
        $captchaId,
        1003,
        '4'
    );

    $assert(
        $wrong['status'] === 'pending'
        && $correct['status'] === 'passed',
        'Captcha answer flow failed.'
    );

    $joinPayload = [
        'chat' => ['id' => -100200300],
        'from' => [
            'id' => 1002,
            'first_name' => 'Sara',
        ],
        'user_chat_id' => 1002,
        'date' => time(),
        'bio' => 'First request',
    ];

    $firstRequest = $repository
        ->storeJoinRequest($joinPayload);

    $assert(
        $repository->resolveJoinRequest(
            -100200300,
            $firstRequest,
            'approved',
            1001
        ),
        'First join request resolution failed.'
    );

    $joinPayload['bio'] =
        'Second request';

    $secondRequest = $repository
        ->storeJoinRequest($joinPayload);

    $assert(
        $secondRequest !== $firstRequest,
        'Repeated join request did not create a new history row.'
    );

    $assert(
        $repository->resolveJoinRequest(
            -100200300,
            $secondRequest,
            'approved',
            1001
        ),
        'Repeated join request resolution failed.'
    );

    $inviteId = $repository->storeInviteLink(
        -100200300,
        1001,
        [
            'invite_link' =>
                'https://t.me/+test-link',
            'name' => 'Test Link',
            'member_limit' => 10,
            'creates_join_request' => false,
        ]
    );

    $assert(
        $inviteId > 0
        && $repository->inviteLink(
            -100200300,
            $inviteId
        ) !== null,
        'Invite-link storage failed.'
    );

    $sanctionId = $repository->addSanction(
        -100200300,
        1002,
        1001,
        'mute',
        time() - 1,
        'Test sanction'
    );

    $dueSanctions =
        $repository->dueSanctions(10);

    $assert(
        count($dueSanctions) === 1
        && (int) $dueSanctions[0]['id']
            === $sanctionId,
        'Due-sanction selection failed.'
    );

    $noticeOne =
        $repository->claimAutomodNotice(
            -100200300,
            1002,
            30
        );

    $noticeTwo =
        $repository->claimAutomodNotice(
            -100200300,
            1002,
            30
        );

    $assert(
        $noticeOne === true
        && $noticeTwo === false,
        'AutoMod notice cooldown failed.'
    );

    $feature = $pdo->query(
        "SELECT
            enabled,
            rollout_percentage
         FROM feature_flags
         WHERE flag_key =
            'group_management'"
    )->fetch(PDO::FETCH_NUM);

    $assert(
        is_array($feature)
        && (int) $feature[0] === 1
        && (int) $feature[1] === 100,
        'Group-management feature flag failed.'
    );

    $requiredTables = [
        'group_settings',
        'group_warnings',
        'group_sanctions',
        'group_domain_whitelist',
        'group_bad_words',
        'group_member_roles',
        'group_member_activity',
        'group_captcha_challenges',
        'group_invite_links',
        'group_join_requests',
        'group_audit_logs',
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

    $databaseStatus = 'passed';

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
}

echo json_encode(
    [
        'status' => 'passed',
        'tests' => [
            'duration_parser' => true,
            'template_renderer' => true,
            'database' => $databaseStatus,
        ],
    ],
    JSON_PRETTY_PRINT
    | JSON_UNESCAPED_UNICODE
    | JSON_UNESCAPED_SLASHES
) . PHP_EOL;
