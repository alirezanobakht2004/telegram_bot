<?php

declare(strict_types=1);

$config = require dirname(__DIR__) . '/bootstrap/app.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

echo json_encode(
    [
        'ok' => true,
        'service' => $config->get('app.name'),
        'bot' => '@' . $config->get('telegram.username'),
        'time' => date(DATE_ATOM),
    ],
    JSON_UNESCAPED_UNICODE
    | JSON_UNESCAPED_SLASHES
    | JSON_PRETTY_PRINT
);