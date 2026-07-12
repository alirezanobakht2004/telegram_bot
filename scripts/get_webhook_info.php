<?php

declare(strict_types=1);

use SmartToolbox\Core\TelegramClient;

$rootPath = dirname(__DIR__);

$config = require $rootPath . '/bootstrap/app.php';

try {
    $telegram = new TelegramClient(
        (string) $config->get('telegram.token')
    );

    $result = $telegram->call('getWebhookInfo');

    echo json_encode(
        $result,
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