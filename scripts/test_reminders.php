<?php

declare(strict_types=1);

use SmartToolbox\Modules\Reminders\ReminderRepository;
use SmartToolbox\Modules\Reminders\ReminderTimeParser;
use SmartToolbox\Modules\Reminders\ReminderWorker;

$rootPath = dirname(__DIR__);

require $rootPath
    . '/vendor/autoload.php';

$temporaryDirectory =
    sys_get_temp_dir()
    . '/smart-toolbox-reminders-'
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
        telegram_id INTEGER PRIMARY KEY,
        last_chat_id INTEGER,
        is_blocked INTEGER NOT NULL DEFAULT 0
    )'
);

$pdo->exec(
    'CREATE TABLE chats (
        telegram_id INTEGER PRIMARY KEY,
        is_active INTEGER NOT NULL DEFAULT 1
    )'
);

$pdo->exec(
    "INSERT INTO users (
        telegram_id,
        last_chat_id,
        is_blocked
     ) VALUES (
        1001,
        2001,
        0
     )"
);

$pdo->exec(
    "INSERT INTO chats (
        telegram_id,
        is_active
     ) VALUES (
        2001,
        1
     )"
);

$migration = file_get_contents(
    $rootPath
    . '/database/migrations/007_reminders.sql'
);

if (!is_string($migration)) {
    throw new RuntimeException(
        'Reminder migration could not be read.'
    );
}

$pdo->exec($migration);

$parser = new ReminderTimeParser();

$fixedNow = new DateTimeImmutable(
    '2026-07-13 12:00:00',
    new DateTimeZone(
        'Asia/Tehran'
    )
);

$relative = $parser->parse(
    '۱۰ دقیقه خرید شیر',
    'Asia/Tehran',
    365,
    $fixedNow
);

if (
    $relative['scheduled_at']
    !== $fixedNow
        ->modify('+10 minutes')
        ->getTimestamp()
) {
    throw new RuntimeException(
        'Relative Persian reminder parsing failed.'
    );
}

$tomorrow = $parser->parse(
    'فردا 09:30 جلسه',
    'Asia/Tehran',
    365,
    $fixedNow
);

if (
    $tomorrow['display_time']
    !== '2026-07-14 09:30'
) {
    throw new RuntimeException(
        'Tomorrow reminder parsing failed.'
    );
}

$absolute = $parser->parse(
    '2026-07-20 18:45 پرداخت قبض',
    'Asia/Tehran',
    365,
    $fixedNow
);

if (
    $absolute['display_time']
    !== '2026-07-20 18:45'
) {
    throw new RuntimeException(
        'Absolute reminder parsing failed.'
    );
}

$repository =
    new ReminderRepository($pdo);

$futureId = $repository->create(
    userId: 1001,
    chatId: 2001,
    text: 'Future reminder',
    scheduledAt: time() + 3600,
    timezone: 'Asia/Tehran'
);

if (
    $repository
        ->countActiveForUser(1001)
    !== 1
) {
    throw new RuntimeException(
        'Reminder repository count failed.'
    );
}

if (
    !$repository->cancelForUser(
        1001,
        $futureId
    )
) {
    throw new RuntimeException(
        'Reminder cancellation failed.'
    );
}

$insertDue = $pdo->prepare(
    'INSERT INTO reminders (
        user_id,
        chat_id,
        reminder_text,
        timezone,
        scheduled_at,
        next_attempt_at,
        status,
        attempts,
        created_at,
        updated_at
     ) VALUES (
        1001,
        2001,
        :text,
        :timezone,
        :scheduled_at,
        :next_attempt_at,
        :status,
        0,
        :created_at,
        :updated_at
     )'
);

$now = time();

$insertDue->execute([
    'text' => 'Worker success test',
    'timezone' => 'Asia/Tehran',
    'scheduled_at' => $now - 10,
    'next_attempt_at' => $now - 10,
    'status' => 'pending',
    'created_at' => date(DATE_ATOM),
    'updated_at' => date(DATE_ATOM),
]);

$sentMessages = [];

$worker = new ReminderWorker(
    pdo: $pdo,
    sender: static function (
        int|string $chatId,
        string $text
    ) use (&$sentMessages): void {
        $sentMessages[] = [
            'chat_id' => $chatId,
            'text' => $text,
        ];
    },
    logFile: $temporaryDirectory
        . '/reminders.log'
);

$result = $worker->run(
    batchSize: 10,
    maxDeliveryAttempts: 3,
    retryBaseSeconds: 10,
    staleLockSeconds: 60,
    retentionDays: 90
);

if (
    $result['sent'] !== 1
    || count($sentMessages) !== 1
) {
    throw new RuntimeException(
        'Reminder worker delivery failed.'
    );
}

$status = $pdo->query(
    "SELECT status
     FROM reminders
     WHERE reminder_text =
        'Worker success test'"
)->fetchColumn();

if ($status !== 'sent') {
    throw new RuntimeException(
        'Reminder worker did not mark reminder as sent.'
    );
}

$insertDue->execute([
    'text' => 'Worker retry test',
    'timezone' => 'Asia/Tehran',
    'scheduled_at' => $now - 10,
    'next_attempt_at' => $now - 10,
    'status' => 'pending',
    'created_at' => date(DATE_ATOM),
    'updated_at' => date(DATE_ATOM),
]);

$failingWorker = new ReminderWorker(
    pdo: $pdo,
    sender: static function (): void {
        throw new RuntimeException(
            'Temporary Telegram failure.'
        );
    },
    logFile: $temporaryDirectory
        . '/reminders.log'
);

$retryResult = $failingWorker->run(
    batchSize: 10,
    maxDeliveryAttempts: 2,
    retryBaseSeconds: 10,
    staleLockSeconds: 60,
    retentionDays: 90
);

if ($retryResult['retried'] !== 1) {
    throw new RuntimeException(
        'Reminder retry scheduling failed.'
    );
}

$pdo->exec(
    "UPDATE reminders
     SET next_attempt_at = "
    . (time() - 1)
    . "
     WHERE reminder_text =
        'Worker retry test'"
);

$failedResult = $failingWorker->run(
    batchSize: 10,
    maxDeliveryAttempts: 2,
    retryBaseSeconds: 10,
    staleLockSeconds: 60,
    retentionDays: 90
);

if ($failedResult['failed'] !== 1) {
    throw new RuntimeException(
        'Reminder max-attempt failure handling failed.'
    );
}

$workerRuns = (int) $pdo->query(
    'SELECT COUNT(*)
     FROM reminder_worker_runs'
)->fetchColumn();

if ($workerRuns < 3) {
    throw new RuntimeException(
        'Reminder worker run logging failed.'
    );
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
        'parser_tests' => 3,
        'repository_tests' => 2,
        'worker_tests' => 4,
    ],
    JSON_PRETTY_PRINT
    | JSON_UNESCAPED_UNICODE
    | JSON_UNESCAPED_SLASHES
) . PHP_EOL;
