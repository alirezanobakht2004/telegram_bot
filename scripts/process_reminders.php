<?php

declare(strict_types=1);

use SmartToolbox\Core\Database;
use SmartToolbox\Core\RuntimeSettings;
use SmartToolbox\Core\TelegramClient;
use SmartToolbox\Modules\Reminders\ReminderWorker;

$rootPath = dirname(__DIR__);

try {
    $config = require $rootPath
        . '/bootstrap/app.php';

    $pdo = Database::connect(
        (string) $config->get(
            'database.path'
        )
    );

    $runtime = new RuntimeSettings(
        $config,
        $pdo
    );

    if (
        !(bool) $runtime->get(
            'modules.reminders.enabled',
            true
        )
    ) {
        echo json_encode(
            [
                'status' => 'disabled',
                'claimed' => 0,
                'sent' => 0,
                'failed' => 0,
                'retried' => 0,
                'pruned' => 0,
            ],
            JSON_PRETTY_PRINT
            | JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
        ) . PHP_EOL;

        exit(0);
    }

    $telegram = new TelegramClient(
        (string) $config->get(
            'telegram.token'
        )
    );

    $worker = new ReminderWorker(
        pdo: $pdo,
        sender: static function (
            int|string $chatId,
            string $text
        ) use ($telegram): void {
            $telegram->sendMessage(
                $chatId,
                $text
            );
        },
        logFile: (string) $config->get(
            'paths.logs'
        ) . '/reminders.log'
    );

    $result = $worker->run(
        batchSize: (int) $runtime->get(
            'modules.reminders.worker.batch_size',
            10
        ),
        maxDeliveryAttempts: (int) $runtime->get(
            'modules.reminders.worker.max_delivery_attempts',
            3
        ),
        retryBaseSeconds: (int) $runtime->get(
            'modules.reminders.worker.retry_base_seconds',
            60
        ),
        staleLockSeconds: (int) $runtime->get(
            'modules.reminders.worker.stale_lock_seconds',
            600
        ),
        retentionDays: (int) $runtime->get(
            'modules.reminders.retention_days',
            90
        )
    );

    echo json_encode(
        [
            'status' => 'completed',
            ...$result,
        ],
        JSON_PRETTY_PRINT
        | JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
    ) . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(
        STDERR,
        sprintf(
            "[%s] Reminder worker failed: %s\n",
            date(DATE_ATOM),
            $exception->getMessage()
        )
    );

    exit(1);
}
