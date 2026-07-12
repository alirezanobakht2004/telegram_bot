<?php

declare(strict_types=1);

use SmartToolbox\Core\CommandRouter;
use SmartToolbox\Core\Database;
use SmartToolbox\Core\TelegramClient;
use SmartToolbox\Core\UpdateProcessor;
use SmartToolbox\Modules\Core\CoreModule;

$rootPath = dirname(__DIR__);

try {
    $config = require $rootPath . '/bootstrap/app.php';

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);

        echo json_encode(
            [
                'ok' => false,
                'error' => 'Method not allowed.',
            ],
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
        ) . PHP_EOL;

        exit;
    }

    $expectedSecret = (string) $config->get(
        'telegram.webhook_secret'
    );

    $receivedSecret = (string) (
        $_SERVER[
            'HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'
        ] ?? ''
    );

    if (
        $expectedSecret === ''
        || $receivedSecret === ''
        || !hash_equals(
            $expectedSecret,
            $receivedSecret
        )
    ) {
        http_response_code(403);

        echo json_encode(
            [
                'ok' => false,
                'error' => 'Forbidden.',
            ],
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
        ) . PHP_EOL;

        exit;
    }

    $rawBody = file_get_contents('php://input');

    if (
        $rawBody === false
        || trim($rawBody) === ''
    ) {
        throw new RuntimeException(
            'Webhook request body is empty.'
        );
    }

    try {
        $update = json_decode(
            $rawBody,
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    } catch (\JsonException $exception) {
        throw new RuntimeException(
            'Webhook body contains invalid JSON.',
            previous: $exception
        );
    }

    if (!is_array($update)) {
        throw new RuntimeException(
            'Webhook update must be a JSON object.'
        );
    }

    $pdo = Database::connect(
        (string) $config->get('database.path')
    );

    $telegram = new TelegramClient(
        (string) $config->get('telegram.token')
    );

    $router = new CommandRouter(
        (string) $config->get('telegram.username')
    );

    $coreModule = new CoreModule();
    $coreModule->register($router);

    $processor = new UpdateProcessor(
        $pdo,
        $telegram,
        $router
    );

    $processor->process($update);

    http_response_code(200);

    echo json_encode(
        [
            'ok' => true,
        ],
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
    ) . PHP_EOL;
} catch (Throwable $exception) {
    $logDirectory = $rootPath . '/storage/logs';

    if (!is_dir($logDirectory)) {
        @mkdir(
            $logDirectory,
            0700,
            true
        );
    }

    $logEntry = sprintf(
        "[%s] %s\n%s\n\n",
        date(DATE_ATOM),
        $exception->getMessage(),
        $exception->getTraceAsString()
    );

    @file_put_contents(
        $logDirectory . '/webhook.log',
        $logEntry,
        FILE_APPEND | LOCK_EX
    );

    http_response_code(500);

    header(
        'Content-Type: application/json; charset=utf-8'
    );
    header('Cache-Control: no-store');

    echo json_encode(
        [
            'ok' => false,
            'error' => 'Internal server error.',
        ],
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
    ) . PHP_EOL;
}