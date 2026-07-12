<?php

declare(strict_types=1);

use SmartToolbox\Core\TelegramClient;

$rootPath = dirname(__DIR__);

$config = require $rootPath . '/bootstrap/app.php';

try {
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

    $telegram = new TelegramClient(
        (string) $config->get('telegram.token')
    );

    $result = $telegram->call('setWebhook', [
        'url' => $webhookUrl,
        'secret_token' => $webhookSecret,
        'max_connections' => (int) $config->get(
            'telegram.max_connections',
            2
        ),
        'allowed_updates' => [
            'message',
        ],
    ]);

    echo json_encode(
        [
            'status' => 'configured',
            'result' => $result,
            'webhook_url' => $webhookUrl,
        ],
        JSON_PRETTY_PRINT
        | JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
    ) . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(
        STDERR,
        $exception->getMessage() . PHP_EOL
    );

    exit(1);
}