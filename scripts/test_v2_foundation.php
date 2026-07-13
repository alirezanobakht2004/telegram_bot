<?php

declare(strict_types=1);

use SmartToolbox\Core\AnalyticsMaintenance;
use SmartToolbox\Core\ApiMetricsTracker;
use SmartToolbox\Core\CacheMetricsTracker;
use SmartToolbox\Core\CommandHistory;
use SmartToolbox\Core\CommandRouter;
use SmartToolbox\Core\DeadLetterQueue;
use SmartToolbox\Core\EventDispatcher;
use SmartToolbox\Core\FeatureRegistry;
use SmartToolbox\Core\JobLock;
use SmartToolbox\Core\JobQueue;
use SmartToolbox\Core\JobRunner;
use SmartToolbox\Core\MessageContext;
use SmartToolbox\Core\SsrfGuard;
use SmartToolbox\Core\TemporaryFileManager;
use SmartToolbox\Core\TelemetryContext;
use SmartToolbox\Core\TelegramClient;
use SmartToolbox\Core\UpdateContext;
use SmartToolbox\Core\UsageTracker;
use SmartToolbox\Web\AdminSettingRegistry;

$rootPath = dirname(__DIR__);

require $rootPath
    . '/vendor/autoload.php';

$tests = [
    'configuration' => false,
    'admin_settings' => false,
    'update_context' => false,
    'event_dispatcher' => false,
    'command_router' => false,
    'ssrf_guard' => false,
    'temporary_files' => false,
    'database' => 'skipped',
];

$defaultConfig = require $rootPath
    . '/config/app.php';

$requiredModules = [
    'animals',
    'weather',
    'currency',
    'countries',
    'reminders',
    'calculator',
    'utilities',
    'settings',
    'admin',
];

foreach ($requiredModules as $requiredModule) {
    if (
        !is_array(
            $defaultConfig['modules'][
                $requiredModule
            ] ?? null
        )
    ) {
        throw new RuntimeException(
            'Configuration module is missing: '
            . $requiredModule
        );
    }
}

if (
    !in_array(
        'callback_query',
        $defaultConfig['telegram'][
            'allowed_updates'
        ] ?? [],
        true
    )
    || !in_array(
        'inline_query',
        $defaultConfig['telegram'][
            'allowed_updates'
        ] ?? [],
        true
    )
) {
    throw new RuntimeException(
        'V2 Telegram update configuration is incomplete.'
    );
}

$tests['configuration'] = true;

$settingRegistry = new AdminSettingRegistry();

if (
    $settingRegistry->validate(
        'analytics.sample_rate',
        '50'
    ) !== 50
    || $settingRegistry->validate(
        'jobs.enabled',
        '1'
    ) !== true
) {
    throw new RuntimeException(
        'Admin V2 setting validation failed.'
    );
}

$tests['admin_settings'] = true;

$context = UpdateContext::fromArray([
    'update_id' => 100,
    'message' => [
        'message_id' => 10,
        'from' => [
            'id' => 200,
            'is_bot' => false,
            'first_name' => 'Test',
        ],
        'chat' => [
            'id' => 300,
            'type' => 'private',
        ],
        'text' => '/start',
    ],
]);

if (
    $context->type !== 'message'
    || $context->userId() !== 200
    || $context->chatId() !== 300
    || $context->messageId() !== 10
) {
    throw new RuntimeException(
        'UpdateContext test failed.'
    );
}

$tests['update_context'] = true;

$dispatcher = new EventDispatcher();
$order = [];

$dispatcher->listen(
    'test',
    static function () use (&$order): void {
        $order[] = 'low';
    },
    1
);

$dispatcher->listen(
    'test',
    static function () use (&$order): void {
        $order[] = 'high';
    },
    10
);

$dispatcher->dispatch('test', $context);

if ($order !== ['high', 'low']) {
    throw new RuntimeException(
        'EventDispatcher priority test failed.'
    );
}

$stoppedContext = UpdateContext::fromArray([
    'update_id' => 101,
    'message' => [
        'message_id' => 11,
        'chat' => [
            'id' => 300,
            'type' => 'private',
        ],
    ],
]);

$stoppedOrder = [];
$stoppingDispatcher = new EventDispatcher();
$stoppingDispatcher->listen(
    'stop',
    static function (
        UpdateContext $update
    ) use (&$stoppedOrder): void {
        $stoppedOrder[] = 'first';
        $update->stopPropagation();
    },
    10
);
$stoppingDispatcher->listen(
    'stop',
    static function () use (&$stoppedOrder): void {
        $stoppedOrder[] = 'second';
    },
    1
);
$stoppingDispatcher->dispatch(
    'stop',
    $stoppedContext
);
$stoppingDispatcher->dispatch(
    'stop',
    $stoppedContext
);

if ($stoppedOrder !== ['first']) {
    throw new RuntimeException(
        'EventDispatcher propagation test failed.'
    );
}

$tests['event_dispatcher'] = true;

$telegram = new TelegramClient(
    'test-token'
);

$router = new CommandRouter('SmartToolboxFaBot');
$captured = null;

$router->command(
    'echo',
    static function (
        MessageContext $message,
        string $arguments
    ) use (&$captured): void {
        $captured = [
            'chat_id' => $message->chatId,
            'arguments' => $arguments,
        ];
    }
);

$handled = $router->dispatch(
    new MessageContext(
        chatId: 300,
        chatType: 'private',
        userId: 200,
        firstName: 'Test',
        text: '/echo hello world',
        telegram: $telegram,
        updateContext: $context,
        messageId: 10
    )
);

if (
    !$handled
    || $captured !== [
        'chat_id' => 300,
        'arguments' => 'hello world',
    ]
) {
    throw new RuntimeException(
        'CommandRouter compatibility test failed.'
    );
}

$captured = null;

$mentioned = $router->dispatch(
    new MessageContext(
        chatId: 300,
        chatType: 'private',
        userId: 200,
        firstName: 'Test',
        text: '/echo@SmartToolboxFaBot mentioned',
        telegram: $telegram,
        updateContext: $context
    )
);

$wrongMention = $router->dispatch(
    new MessageContext(
        chatId: 300,
        chatType: 'private',
        userId: 200,
        firstName: 'Test',
        text: '/echo@AnotherBot ignored',
        telegram: $telegram,
        updateContext: $context
    )
);

if (
    !$mentioned
    || $wrongMention
    || $captured !== [
        'chat_id' => 300,
        'arguments' => 'mentioned',
    ]
) {
    throw new RuntimeException(
        'CommandRouter mention filtering test failed.'
    );
}

$tests['command_router'] = true;

$guard = new SsrfGuard(
    allowHttp: false,
    allowedPorts: [443]
);

$blocked = false;

try {
    $guard->inspect('https://127.0.0.1/test');
} catch (RuntimeException) {
    $blocked = true;
}

if (!$blocked) {
    throw new RuntimeException(
        'SsrfGuard did not reject loopback.'
    );
}

foreach (
    [
        'https://10.0.0.1/',
        'https://100.64.0.1/',
        'https://169.254.169.254/',
        'https://[::1]/',
        'https://[::ffff:127.0.0.1]/',
    ]
    as $blockedUrl
) {
    $rejected = false;

    try {
        $guard->inspect($blockedUrl);
    } catch (RuntimeException) {
        $rejected = true;
    }

    if (!$rejected) {
        throw new RuntimeException(
            'SsrfGuard accepted blocked URL: '
            . $blockedUrl
        );
    }
}

$public = $guard->inspect(
    'https://93.184.216.34/'
);

if ($public['pinned_ip'] !== '93.184.216.34') {
    throw new RuntimeException(
        'SsrfGuard public IP test failed.'
    );
}

$publicIpv6 = $guard->inspect(
    'https://[2606:4700:4700::1111]/'
);

if (
    $publicIpv6['pinned_ip']
    !== '2606:4700:4700::1111'
) {
    throw new RuntimeException(
        'SsrfGuard public IPv6 test failed.'
    );
}

$tests['ssrf_guard'] = true;

$temporaryRoot = sys_get_temp_dir()
    . '/smart-toolbox-v2-'
    . bin2hex(random_bytes(5));

$temporaryFiles = new TemporaryFileManager(
    $temporaryRoot
);

$workspace = $temporaryFiles->createWorkspace(
    'test'
);
$file = $temporaryFiles->createFile(
    $workspace,
    'txt'
);
file_put_contents($file, 'ok');
$temporaryFiles->removeWorkspace($workspace);

$rootRemovalRejected = false;

try {
    $temporaryFiles->removeWorkspace(
        $temporaryRoot
    );
} catch (RuntimeException) {
    $rootRemovalRejected = true;
}

if (!$rootRemovalRejected) {
    throw new RuntimeException(
        'TemporaryFileManager allowed root removal.'
    );
}

if (is_dir($workspace)) {
    throw new RuntimeException(
        'TemporaryFileManager cleanup failed.'
    );
}

@rmdir($temporaryRoot);
$tests['temporary_files'] = true;

if (in_array(
    'sqlite',
    PDO::getAvailableDrivers(),
    true
)) {
    $databasePath = sys_get_temp_dir()
        . '/smart-toolbox-v2-db-'
        . bin2hex(random_bytes(5))
        . '.sqlite';

    $pdo = new PDO(
        'sqlite:' . $databasePath,
        options: [
            PDO::ATTR_ERRMODE =>
                PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE =>
                PDO::FETCH_ASSOC,
        ]
    );

    $migration = file_get_contents(
        $rootPath
        . '/database/migrations/008_v2_foundation_analytics.sql'
    );

    if (!is_string($migration)) {
        throw new RuntimeException(
            'V2 migration could not be read.'
        );
    }

    $pdo->exec($migration);

    $requiredTables = [
        'usage_events',
        'command_history',
        'api_metrics',
        'cache_metrics',
        'feature_flags',
        'job_queue',
        'job_runs',
        'dead_letter_jobs',
        'job_locks',
    ];

    $tableStatement = $pdo->query(
        "SELECT name
         FROM sqlite_master
         WHERE type = 'table'"
    );

    $tableRows = $tableStatement->fetchAll(
        PDO::FETCH_COLUMN
    );

    $tables = is_array($tableRows)
        ? array_map('strval', $tableRows)
        : [];

    foreach ($requiredTables as $requiredTable) {
        if (!in_array($requiredTable, $tables, true)) {
            throw new RuntimeException(
                'V2 migration table is missing: '
                . $requiredTable
            );
        }
    }

    $tracker = new UsageTracker(
        $pdo,
        true,
        100
    );

    $span = $tracker->start(
        module: 'test',
        action: 'foundation',
        inputKind: 'test',
        context: $context
    );
    $span->success();

    $events = (int) $pdo->query(
        'SELECT COUNT(*) FROM usage_events'
    )->fetchColumn();

    if ($events !== 1) {
        throw new RuntimeException(
            'UsageTracker database test failed.'
        );
    }

    TelemetryContext::begin($context);

    (new ApiMetricsTracker(
        $pdo,
        true,
        100
    ))->record(
        provider: 'test-provider',
        method: 'GET',
        host: 'example.com',
        path: '/test',
        statusCode: 200,
        durationMs: 5.5,
        responseBytes: 100,
        success: true
    );

    (new CacheMetricsTracker(
        $pdo,
        true,
        100
    ))->record(
        key: 'test.value',
        operation: 'get',
        hit: true,
        durationMs: 0.5,
        valueBytes: 20
    );

    (new CommandHistory(
        $pdo,
        true,
        false,
        200
    ))->record(
        module: 'test',
        command: 'echo',
        source: 'command',
        arguments: 'private value',
        success: true,
        durationMs: 1.0,
        updateContext: $context
    );

    TelemetryContext::clear();

    $metricCounts = [
        'api' => (int) $pdo->query(
            'SELECT COUNT(*) FROM api_metrics'
        )->fetchColumn(),
        'cache' => (int) $pdo->query(
            'SELECT COUNT(*) FROM cache_metrics'
        )->fetchColumn(),
        'history' => (int) $pdo->query(
            'SELECT COUNT(*) FROM command_history'
        )->fetchColumn(),
    ];

    if ($metricCounts !== [
        'api' => 1,
        'cache' => 1,
        'history' => 1,
    ]) {
        throw new RuntimeException(
            'Operational metrics database test failed.'
        );
    }

    $argumentsPreview = $pdo->query(
        'SELECT arguments_preview
         FROM command_history
         LIMIT 1'
    )->fetchColumn();

    if ($argumentsPreview !== null) {
        throw new RuntimeException(
            'Command history privacy default test failed.'
        );
    }

    $features = new FeatureRegistry(
        $pdo,
        [
            'test_feature' => [
                'enabled' => false,
                'rollout_percentage' => 100,
                'description' => 'Test',
            ],
        ]
    );

    $features->set(
        'test_feature',
        true,
        100,
        'Enabled',
        'test'
    );

    if (!$features->isEnabled(
        'test_feature',
        200
    )) {
        throw new RuntimeException(
            'FeatureRegistry test failed.'
        );
    }

    $queue = new JobQueue($pdo);
    $lock = new JobLock($pdo);
    $dead = new DeadLetterQueue(
        $pdo,
        $queue
    );

    $executed = 0;
    $runner = new JobRunner(
        pdo: $pdo,
        queue: $queue,
        lock: $lock,
        deadLetters: $dead,
        usageTracker: $tracker
    );

    $runner->register(
        'test.success',
        static function () use (&$executed): void {
            $executed++;
        }
    );

    $queue->enqueue(
        'test.success',
        maxAttempts: 1
    );

    $result = $runner->run(
        batchSize: 10,
        lockTtlSeconds: 60,
        staleAfterSeconds: 60,
        retryBaseSeconds: 1
    );

    if (
        $executed !== 1
        || $result['succeeded'] !== 1
    ) {
        throw new RuntimeException(
            'JobRunner success test failed.'
        );
    }

    $queue->enqueue(
        'test.unknown',
        maxAttempts: 1
    );

    $result = $runner->run(
        batchSize: 10,
        lockTtlSeconds: 60,
        staleAfterSeconds: 60,
        retryBaseSeconds: 1
    );

    if ($result['dead_lettered'] !== 1) {
        throw new RuntimeException(
            'DeadLetterQueue test failed.'
        );
    }

    $deadLetterId = (int) $pdo->query(
        'SELECT id
         FROM dead_letter_jobs
         ORDER BY id DESC
         LIMIT 1'
    )->fetchColumn();

    $replayedJobId = $dead->replay(
        $deadLetterId
    );

    $replayedStatus = $pdo->query(
        'SELECT status
         FROM job_queue
         WHERE id = '
        . $replayedJobId
    )->fetchColumn();

    if ($replayedStatus !== 'queued') {
        throw new RuntimeException(
            'DeadLetterQueue replay test failed.'
        );
    }

    $duplicateReplayRejected = false;

    try {
        $dead->replay($deadLetterId);
    } catch (RuntimeException) {
        $duplicateReplayRejected = true;
    }

    if (!$duplicateReplayRejected) {
        throw new RuntimeException(
            'DeadLetterQueue duplicate replay was not rejected.'
        );
    }

    (new AnalyticsMaintenance($pdo))->cleanup(
        usageDays: 90,
        commandDays: 30,
        apiDays: 30,
        cacheDays: 30,
        jobRunDays: 30,
        deadLetterDays: 90,
        maxUsageRows: 10000
    );

    $tests['database'] = 'passed';
    unset($pdo);
    @unlink($databasePath);
}

echo json_encode(
    [
        'status' => 'passed',
        'tests' => $tests,
    ],
    JSON_PRETTY_PRINT
    | JSON_UNESCAPED_UNICODE
    | JSON_UNESCAPED_SLASHES
) . PHP_EOL;
