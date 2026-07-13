<?php

declare(strict_types=1);

use SmartToolbox\Core\Database;
use SmartToolbox\Core\FeatureRegistry;
use SmartToolbox\Release\V100LaunchMessage;

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

$versionPath = $rootPath . '/VERSION';
$version = is_file($versionPath)
    ? trim((string) file_get_contents($versionPath))
    : '';

$assert(
    $version === V100LaunchMessage::VERSION,
    'VERSION file does not match the release class.'
);

$config = require $rootPath
    . '/bootstrap/app.php';

$assert(
    (string) $config->get(
        'app.version',
        ''
    ) === V100LaunchMessage::VERSION,
    'app.version is not 1.0.0.'
);

$assert(
    (string) $config->get(
        'release.version',
        ''
    ) === V100LaunchMessage::VERSION,
    'release.version is not 1.0.0.'
);

$message = V100LaunchMessage::text();
$broadcastFile = rtrim(
    (string) file_get_contents(
        $rootPath
        . '/BROADCAST_V1_0_0_FA.txt'
    )
);

$assert(
    rtrim($message) === $broadcastFile,
    'Broadcast text file does not match the release message class.'
);
$admins = array_values(
    array_filter(
        (array) $config->get(
            'admins',
            []
        ),
        static fn (mixed $value): bool =>
            is_int($value)
            || (
                is_string($value)
                && preg_match(
                    '/^\d+$/',
                    $value
                ) === 1
            )
    )
);

$assert(
    $admins !== [],
    'No Telegram admin ID is configured.'
);

$maximumLength = (int) $config->get(
    'modules.admin.max_broadcast_length',
    3000
);

$assert(
    mb_strlen($message) > 500,
    'Release announcement is unexpectedly short.'
);

$assert(
    mb_strlen($message) <= $maximumLength,
    'Release announcement exceeds the broadcast limit.'
);

foreach (
    [
        '/start',
        '/weather',
        '/remind',
        '/alert',
        '/monitor',
        '/wiki',
        '/github',
        '/favorite',
        '/fileinfo',
        '/quiz',
        '/warn',
        '/app',
        '@SmartToolboxFaBot',
    ]
    as $requiredFragment
) {
    $assert(
        str_contains(
            $message,
            $requiredFragment
        ),
        "Announcement is missing {$requiredFragment}."
    );
}

$composerPath = $rootPath
    . '/composer.json';
$composer = json_decode(
    (string) file_get_contents(
        $composerPath
    ),
    true,
    512,
    JSON_THROW_ON_ERROR
);

foreach (
    [
        'v1:test',
        'v1:preview',
        'v1:create-announcement',
        'v1:announce',
    ]
    as $scriptName
) {
    $assert(
        isset(
            $composer['scripts'][
                $scriptName
            ]
        ),
        "Composer script {$scriptName} is missing."
    );
}

$requiredFiles = [
    'CHANGELOG.md',
    'RELEASE_V1_0_0.md',
    'USER_GUIDE_FA.md',
    'ADMIN_GUIDE_FA.md',
    'BROADCAST_V1_0_0_FA.txt',
    'scripts/announce_v1_0_0.php',
    'app/Release/V100LaunchMessage.php',
];

foreach ($requiredFiles as $relative) {
    $assert(
        is_file($rootPath . '/' . $relative),
        "Required release file is missing: {$relative}"
    );
}

$databaseStatus = 'skipped';
$tableCount = 0;
$featureCount = 0;
$eligibleRecipients = 0;

if (extension_loaded('pdo_sqlite')) {
    $pdo = Database::connect(
        (string) $config->get(
            'database.path'
        )
    );

    $requiredTables = [
        'users',
        'chats',
        'admin_broadcasts',
        'admin_broadcast_recipients',
        'feature_flags',
        'usage_events',
        'job_queue',
        'command_history',
        'user_favorites',
        'user_shortcuts',
        'smart_alerts',
        'smart_subscriptions',
        'site_monitors',
        'group_settings',
        'file_jobs',
        'quiz_questions',
        'quiz_user_scores',
        'mini_app_sessions',
    ];

    $tableStatement = $pdo->prepare(
        "SELECT COUNT(*)
         FROM sqlite_master
         WHERE type = 'table'
           AND name = :name"
    );

    foreach ($requiredTables as $table) {
        $tableStatement->execute([
            'name' => $table,
        ]);

        $assert(
            (int) $tableStatement
                ->fetchColumn() === 1,
            "Required database table is missing: {$table}"
        );

        $tableCount++;
    }

    $featureKeys = [
        'analytics',
        'generic_jobs',
        'callback_routing',
        'inline_routing',
        'smart_alerts',
        'scheduled_subscriptions',
        'site_monitoring',
        'group_management',
        'file_tools',
        'quiz_games',
        'mini_app',
    ];

    $featureRegistry = new FeatureRegistry(
        $pdo,
        (array) $config->get(
            'features.defaults',
            []
        )
    );

    $effectiveFeatures = [];

    foreach ($featureRegistry->all() as $feature) {
        $effectiveFeatures[
            $feature['key']
        ] = $feature;
    }

    foreach ($featureKeys as $flagKey) {
        $assert(
            isset($effectiveFeatures[$flagKey]),
            "Feature flag is not configured: {$flagKey}"
        );

        $feature = $effectiveFeatures[$flagKey];

        $assert(
            in_array(
                $feature['source'],
                ['config', 'database'],
                true
            ),
            "Feature flag source is invalid: {$flagKey}"
        );

        $assert(
            $feature['rollout_percentage'] >= 0
            && $feature['rollout_percentage'] <= 100,
            "Feature flag rollout is invalid: {$flagKey}"
        );

        $featureCount++;
    }

    $eligibleRecipients = (int) $pdo
        ->query(
            "SELECT COUNT(*)
             FROM chats
             WHERE type = 'private'
               AND is_active = 1
               AND admin_blocked = 0
               AND telegram_id > 0"
        )
        ->fetchColumn();

    $databaseStatus = 'passed';
}

echo json_encode(
    [
        'status' => 'passed',
        'version' =>
            V100LaunchMessage::VERSION,
        'tests' => [
            'version_files' => true,
            'release_configuration' => true,
            'announcement_content' => true,
            'announcement_characters' =>
                mb_strlen($message),
            'composer_scripts' => true,
            'release_documents' => true,
            'database' => $databaseStatus,
            'database_tables_checked' =>
                $tableCount,
            'feature_flags_checked' =>
                $featureCount,
            'eligible_broadcast_recipients' =>
                $eligibleRecipients,
        ],
    ],
    JSON_PRETTY_PRINT
    | JSON_UNESCAPED_UNICODE
    | JSON_UNESCAPED_SLASHES
) . PHP_EOL;
