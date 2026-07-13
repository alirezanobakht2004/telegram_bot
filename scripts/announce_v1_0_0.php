<?php

declare(strict_types=1);

use SmartToolbox\Core\Database;
use SmartToolbox\Core\RuntimeSettings;
use SmartToolbox\Core\TelegramClient;
use SmartToolbox\Release\V100LaunchMessage;
use SmartToolbox\Web\AdminPanelService;
use SmartToolbox\Web\AdminSettingRegistry;

$rootPath = dirname(__DIR__);

$options = getopt(
    '',
    [
        'dry-run',
        'create-only',
        'send-all',
        'pause-ms::',
    ]
);

$dryRun = array_key_exists(
    'dry-run',
    $options
);
$createOnly = array_key_exists(
    'create-only',
    $options
);
$sendAll = array_key_exists(
    'send-all',
    $options
);
$pauseMilliseconds = max(
    100,
    min(
        10000,
        (int) (
            $options['pause-ms']
            ?? 1000
        )
    )
);

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

    $message = V100LaunchMessage::text();
    $maximumLength = max(
        100,
        min(
            3500,
            (int) $runtime->get(
                'modules.admin.max_broadcast_length',
                3000
            )
        )
    );

    if (mb_strlen($message) > $maximumLength) {
        throw new RuntimeException(
            'متن معرفی نسخه از سقف Broadcast بیشتر است.'
        );
    }

    $recipientStatement = $pdo->query(
        "SELECT COUNT(*)
         FROM chats
         WHERE type = 'private'
           AND is_active = 1
           AND admin_blocked = 0
           AND telegram_id > 0"
    );

    $recipientCount = (int)
        $recipientStatement->fetchColumn();

    if ($dryRun) {
        echo json_encode(
            [
                'status' => 'dry-run',
                'version' =>
                    V100LaunchMessage::VERSION,
                'characters' =>
                    mb_strlen($message),
                'bytes' => strlen($message),
                'eligible_recipients' =>
                    $recipientCount,
                'message' => $message,
            ],
            JSON_PRETTY_PRINT
            | JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
        ) . PHP_EOL;

        exit(0);
    }

    if (!$createOnly && !$sendAll) {
        throw new RuntimeException(
            'یکی از گزینه‌های --dry-run، --create-only یا --send-all لازم است.'
        );
    }

    $admins = (array) $config->get(
        'admins',
        []
    );
    $adminUserId = 0;

    foreach ($admins as $value) {
        if (
            is_int($value)
            && $value > 0
        ) {
            $adminUserId = $value;
            break;
        }

        if (
            is_string($value)
            && preg_match(
                '/^\d+$/',
                $value
            ) === 1
        ) {
            $adminUserId = (int) $value;
            break;
        }
    }

    if ($adminUserId <= 0) {
        throw new RuntimeException(
            'شناسه Admin Telegram در config پیدا نشد.'
        );
    }

    $service = new AdminPanelService(
        pdo: $pdo,
        runtime: $runtime,
        registry: new AdminSettingRegistry(),
        telegram: new TelegramClient(
            (string) $config->get(
                'telegram.token'
            )
        ),
        databasePath: (string) $config->get(
            'database.path'
        ),
        cacheDirectory: (string) $config->get(
            'paths.cache'
        ) . '/api',
        logsDirectory: (string) $config->get(
            'paths.logs'
        ),
        backupsDirectory: (string) $config->get(
            'paths.backups'
        )
    );

    $existing = $pdo->prepare(
        "SELECT
            id,
            status,
            total_recipients,
            sent_count,
            failed_count
         FROM admin_broadcasts
         WHERE message_text = :message
           AND status != 'cancelled'
         ORDER BY id DESC
         LIMIT 1"
    );
    $existing->execute([
        'message' => $message,
    ]);

    $campaign = $existing->fetch(
        PDO::FETCH_ASSOC
    );

    if (is_array($campaign)) {
        $broadcastId = (int) $campaign['id'];
    } else {
        $broadcastId = $service->createBroadcast(
            message: $message,
            adminUserId: $adminUserId,
            identity: 'release-v1-cli',
            ip: 'cli',
            userAgent: 'php-cli'
        );

        $campaign = [
            'id' => $broadcastId,
            'status' => 'pending',
            'total_recipients' =>
                $recipientCount,
            'sent_count' => 0,
            'failed_count' => 0,
        ];
    }

    if ($createOnly) {
        echo json_encode(
            [
                'status' => 'created',
                'version' =>
                    V100LaunchMessage::VERSION,
                'broadcast_id' =>
                    $broadcastId,
                'campaign_status' =>
                    $campaign['status'],
                'total' => (int) $campaign[
                    'total_recipients'
                ],
                'sent' => (int) $campaign[
                    'sent_count'
                ],
                'failed' => (int) $campaign[
                    'failed_count'
                ],
            ],
            JSON_PRETTY_PRINT
            | JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
        ) . PHP_EOL;

        exit(0);
    }

    if (
        (string) $campaign['status']
        === 'completed'
    ) {
        echo json_encode(
            [
                'status' =>
                    'already-completed',
                'version' =>
                    V100LaunchMessage::VERSION,
                'broadcast_id' =>
                    $broadcastId,
                'total' => (int) $campaign[
                    'total_recipients'
                ],
                'sent' => (int) $campaign[
                    'sent_count'
                ],
                'failed' => (int) $campaign[
                    'failed_count'
                ],
            ],
            JSON_PRETTY_PRINT
            | JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
        ) . PHP_EOL;

        exit(0);
    }

    $lastSummary = null;

    while (true) {
        $lastSummary = $service
            ->processBroadcast(
                broadcastId: $broadcastId,
                identity: 'release-v1-cli',
                ip: 'cli',
                userAgent: 'php-cli'
            );

        echo json_encode(
            [
                'status' => 'progress',
                'version' =>
                    V100LaunchMessage::VERSION,
                ...$lastSummary,
            ],
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
        ) . PHP_EOL;

        if (
            in_array(
                $lastSummary['status'],
                ['completed', 'cancelled'],
                true
            )
        ) {
            break;
        }

        usleep(
            $pauseMilliseconds * 1000
        );
    }

    echo json_encode(
        [
            'status' => 'finished',
            'version' =>
                V100LaunchMessage::VERSION,
            ...$lastSummary,
        ],
        JSON_PRETTY_PRINT
        | JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
    ) . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(
        STDERR,
        json_encode(
            [
                'status' => 'failed',
                'version' =>
                    V100LaunchMessage::VERSION,
                'error' =>
                    $exception->getMessage(),
            ],
            JSON_PRETTY_PRINT
            | JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
        ) . PHP_EOL
    );

    exit(1);
}
