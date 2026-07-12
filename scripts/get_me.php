<?php

declare(strict_types=1);

use SmartToolbox\Core\TelegramClient;

$rootPath = dirname(__DIR__);

$config = require $rootPath . '/bootstrap/app.php';

try {
    $telegram = new TelegramClient(
        (string) $config->get('telegram.token')
    );

    $bot = $telegram->getMe();

    $result = [
        'status' => 'connected',
        'id' => $bot['id'] ?? null,
        'name' => $bot['first_name'] ?? null,
        'username' => $bot['username'] ?? null,
        'can_join_groups' =>
            $bot['can_join_groups'] ?? null,
        'can_read_all_group_messages' =>
            $bot['can_read_all_group_messages'] ?? null,
        'supports_inline_queries' =>
            $bot['supports_inline_queries'] ?? null,
    ];

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