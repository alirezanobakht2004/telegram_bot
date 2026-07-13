<?php

declare(strict_types=1);

use SmartToolbox\Core\TelegramClient;

$rootPath = dirname(__DIR__);

try {
    $config = require $rootPath
        . '/bootstrap/app.php';

    $url = trim(
        (string) $config->get(
            'modules.mini_app.url',
            ''
        )
    );

    if (
        $url === ''
        || filter_var(
            $url,
            FILTER_VALIDATE_URL
        ) === false
        || mb_strtolower(
            (string) parse_url(
                $url,
                PHP_URL_SCHEME
            )
        ) !== 'https'
    ) {
        throw new RuntimeException(
            'modules.mini_app.url must be a valid HTTPS URL.'
        );
    }

    $telegram = new TelegramClient(
        (string) $config->get(
            'telegram.token'
        )
    );

    $result = $telegram->call(
        'setChatMenuButton',
        [
            'menu_button' => [
                'type' => 'web_app',
                'text' => 'بازکردن اپ',
                'web_app' => [
                    'url' => $url,
                ],
            ],
        ]
    );

    echo json_encode(
        [
            'status' => 'configured',
            'result' => $result,
            'menu_button' => [
                'type' => 'web_app',
                'text' => 'بازکردن اپ',
                'url' => $url,
            ],
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
