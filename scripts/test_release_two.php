<?php

declare(strict_types=1);

use SmartToolbox\Core\SsrfGuard;
use SmartToolbox\Modules\Alerts\AlertRepository;
use SmartToolbox\Modules\Alerts\ConditionEvaluator;
use SmartToolbox\Modules\Alerts\ScheduleCalculator;
use SmartToolbox\Modules\Alerts\SubscriptionRepository;
use SmartToolbox\Modules\Monitoring\DnsInspector;
use SmartToolbox\Modules\Monitoring\MonitorRepository;
use SmartToolbox\Modules\Monitoring\MonitorProbe;

$rootPath = dirname(__DIR__);
require $rootPath . '/vendor/autoload.php';

$assert = static function (
    bool $condition,
    string $message
): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$evaluator = new ConditionEvaluator();
$above = $evaluator->evaluate(
    'above',
    41,
    40,
    39,
    false,
    0.5
);
$assert(
    $above['condition'] === true
    && $above['trigger'] === true,
    'Numeric above condition failed.'
);

$hysteresis = $evaluator->evaluate(
    'above',
    39.8,
    40,
    41,
    true,
    0.5
);
$assert(
    $hysteresis['condition'] === true
    && $hysteresis['trigger'] === false,
    'Numeric hysteresis failed.'
);

$starts = $evaluator->evaluate(
    'starts',
    'cloud,rain',
    'rain',
    'cloud',
    false
);
$assert(
    $starts['trigger'] === true,
    'String starts condition failed.'
);

$stops = $evaluator->evaluate(
    'stops',
    'clear',
    'rain',
    'rain',
    true
);
$assert(
    $stops['trigger'] === true,
    'String stops condition failed.'
);

$schedule = new ScheduleCalculator();
$timezone = new DateTimeZone('Asia/Tehran');
$fixedNow = new DateTimeImmutable(
    '2026-07-13 12:00:00',
    $timezone
);
$daily = $schedule->nextRun(
    'daily',
    '08:00',
    'Asia/Tehran',
    now: $fixedNow
);
$assert(
    (new DateTimeImmutable('@' . $daily))
        ->setTimezone($timezone)
        ->format('Y-m-d H:i')
        === '2026-07-14 08:00',
    'Daily schedule failed.'
);

$weekly = $schedule->nextRun(
    'weekly',
    '09:30',
    'Asia/Tehran',
    $schedule->weekday('saturday'),
    now: $fixedNow
);
$assert(
    (new DateTimeImmutable('@' . $weekly))
        ->setTimezone($timezone)
        ->format('Y-m-d H:i')
        === '2026-07-18 09:30',
    'Weekly schedule failed.'
);

$monthly = $schedule->nextRun(
    'monthly',
    '09:00',
    'Asia/Tehran',
    monthDay: 31,
    now: new DateTimeImmutable(
        '2026-02-20 12:00:00',
        $timezone
    )
);
$assert(
    (new DateTimeImmutable('@' . $monthly))
        ->setTimezone($timezone)
        ->format('Y-m-d H:i')
        === '2026-02-28 09:00',
    'Monthly end-of-month schedule failed.'
);

$guard = new SsrfGuard(
    allowHttp: true,
    allowedPorts: [80, 443]
);
$blocked = false;

try {
    $guard->inspect('http://127.0.0.1/');
} catch (RuntimeException) {
    $blocked = true;
}

$assert($blocked, 'SSRF loopback blocking failed.');

$probe = new MonitorProbe(
    userAgent: 'ReleaseTwoTest/1.0',
    guard: $guard
);
$blockedScheme = false;

try {
    $probe->normalizeUrl('file:///etc/passwd');
} catch (RuntimeException) {
    $blockedScheme = true;
}

$assert($blockedScheme, 'Unsafe URL scheme blocking failed.');

$dnsBlocked = false;

try {
    (new DnsInspector())->inspect('localhost');
} catch (RuntimeException) {
    $dnsBlocked = true;
}

$assert($dnsBlocked, 'Local DNS name blocking failed.');

$databaseStatus = 'skipped';

if (extension_loaded('pdo_sqlite')) {
    $directory = sys_get_temp_dir()
        . '/smart-toolbox-release-two-'
        . bin2hex(random_bytes(5));
    mkdir($directory, 0700, true);
    $pdo = new PDO(
        'sqlite:' . $directory . '/test.sqlite',
        options: [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec(
        'CREATE TABLE users (
            telegram_id INTEGER PRIMARY KEY
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
    $pdo->exec('INSERT INTO users (telegram_id) VALUES (1001)');
    $pdo->exec('INSERT INTO chats (telegram_id) VALUES (2001)');
    $migration = file_get_contents(
        $rootPath
        . '/database/migrations/'
        . '010_release_two_alerts_subscriptions_monitoring.sql'
    );

    if (!is_string($migration)) {
        throw new RuntimeException(
            'Release-two migration could not be read.'
        );
    }

    $pdo->exec($migration);
    $alerts = new AlertRepository($pdo);
    $alertId = $alerts->create([
        'user_id' => 1001,
        'chat_id' => 2001,
        'alert_type' => 'temperature',
        'subject' => 'Tehran',
        'operator' => 'above',
        'threshold_value' => 40,
        'next_check_at' => time(),
    ]);
    $assert($alertId > 0, 'Alert repository failed.');

    $subscriptions = new SubscriptionRepository($pdo);
    $subscriptionId = $subscriptions->create([
        'user_id' => 1001,
        'chat_id' => 2001,
        'subscription_type' => 'weather',
        'subject' => 'Tehran',
        'frequency' => 'daily',
        'schedule_time' => '08:00',
        'timezone' => 'Asia/Tehran',
        'next_run_at' => time() + 3600,
    ]);
    $assert($subscriptionId > 0, 'Subscription repository failed.');

    $monitors = new MonitorRepository($pdo);
    $monitorId = $monitors->create(
        1001,
        2001,
        'https://example.com',
        'https://example.com/',
        300
    );
    $assert($monitorId > 0, 'Monitor repository failed.');

    $monitors->recordCheck(
        $monitors->findForUser(1001, $monitorId) ?? [],
        [
            'status_code' => 200,
            'response_ms' => 120.5,
            'final_url' => 'https://example.com/',
            'primary_ip' => '93.184.216.34',
        ],
        true,
        2,
        1
    );
    $uptime = $monitors->uptime($monitorId, 1);
    $assert(
        $uptime['checks'] === 1
        && $uptime['uptime'] === 100.0,
        'Monitor uptime aggregation failed.'
    );

    $databaseStatus = 'passed';

    unset($pdo);
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            $directory,
            FilesystemIterator::SKIP_DOTS
        ),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $item) {
        $item->isDir()
            ? rmdir($item->getPathname())
            : unlink($item->getPathname());
    }
    rmdir($directory);
}

echo json_encode(
    [
        'status' => 'passed',
        'tests' => [
            'condition_engine' => true,
            'hysteresis' => true,
            'schedule_engine' => true,
            'ssrf_guard' => true,
            'unsafe_scheme_blocking' => true,
            'local_dns_blocking' => true,
            'database' => $databaseStatus,
        ],
    ],
    JSON_PRETTY_PRINT
    | JSON_UNESCAPED_UNICODE
    | JSON_UNESCAPED_SLASHES
) . PHP_EOL;
