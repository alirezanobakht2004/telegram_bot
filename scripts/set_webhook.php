<?php

declare(strict_types=1);

use SmartToolbox\Core\TelegramClient;

$rootPath = dirname(__DIR__);

try {
    $config = require $rootPath
        . '/bootstrap/app.php';

    $webhookUrl = (string) $config->get(
        'telegram.webhook_url'
    );

    $webhookSecret = (string) $config->get(
        'telegram.webhook_secret'
    );

    if ($webhookUrl === '') {
        throw new RuntimeException(
            'Webhook URL is not configured.'
        );
    }

    if ($webhookSecret === '') {
        throw new RuntimeException(
            'Webhook secret is not configured.'
        );
    }

    $allowedUpdates = $config->get(
        'telegram.allowed_updates',
        ['message']
    );

    if (!is_array($allowedUpdates)) {
        throw new RuntimeException(
            'telegram.allowed_updates must be an array.'
        );
    }

    $allowedUpdates = array_values(
        array_filter(
            array_map(
                static fn (mixed $value): string =>
                    is_string($value)
                        ? trim($value)
                        : '',
                $allowedUpdates
            ),
            static fn (string $value): bool =>
                $value !== ''
        )
    );

    $telegram = new TelegramClient(
        (string) $config->get('telegram.token')
    );

    $result = $telegram->call(
        'setWebhook',
        [
            'url' => $webhookUrl,
            'secret_token' => $webhookSecret,
            'max_connections' => (int) $config->get(
                'telegram.max_connections',
                2
            ),
            'allowed_updates' => $allowedUpdates,
            'drop_pending_updates' => (bool) $config->get(
                'telegram.drop_pending_updates',
                false
            ),
        ]
    );

    echo json_encode(
        [
            'status' => 'configured',
            'result' => $result,
            'webhook_url' => $webhookUrl,
            'allowed_updates' => $allowedUpdates,
        ],
        JSON_PRETTY_PRINT
        | JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
    ) . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(
        STDERR,
        $exception->getMessage()
        . PHP_EOL
    );

    exit(1);
}
