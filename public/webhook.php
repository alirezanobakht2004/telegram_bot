<?php

declare(strict_types=1);

use SmartToolbox\Core\Database;
use SmartToolbox\Core\TelegramClient;
use SmartToolbox\Core\UpdateProcessor;

$rootPath = dirname(__DIR__);

try {
    $config = require $rootPath . '/bootstrap/app.php';

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);

        echo json_encode([
            'ok' => false,
            'error' => 'Method not allowed.',
        ]);

        exit;
    }

    $expectedSecret = (string) $config->get(
        'telegram.webhook_secret'
    );

    $receivedSecret = (string) (
        $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN']
        ?? ''
    );

    if (
        $expectedSecret === ''
        || $receivedSecret === ''
        || !hash_equals($expectedSecret, $receivedSecret)
    ) {
        http_response_code(403);

        echo json_encode([
            'ok' => false,
            'error' => 'Forbidden.',
        ]);

        exit;
    }

    $rawBody = file_get_contents('php://input');

    if ($rawBody === false || trim($rawBody) === '') {
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

    $processor = new UpdateProcessor(
        $pdo,
        $telegram,
        (string) $config->get('telegram.username')
    );

    $processor->process($update);

    http_response_code(200);

    echo json_encode([
        'ok' => true,
    ]);
} catch (Throwable $exception) {
    $logDirectory = $rootPath . '/storage/logs';

    if (!is_dir($logDirectory)) {
        @mkdir($logDirectory, 0700, true);
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

    header('Content-Type: application/json; charset=utf-8');

    echo json_encode([
        'ok' => false,
        'error' => 'Internal server error.',
    ]);
}