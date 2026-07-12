<?php

declare(strict_types=1);

return [
    'app' => [
        'name' => 'جعبه ابزار',
        'environment' => 'production',
        'debug' => false,
        'timezone' => 'Asia/Tehran',
    ],

    'telegram' => [
        'username' => 'SmartToolboxFaBot',
        'token' => '',
        'webhook_secret' => '',
        'max_connections' => 2,
    ],

    'database' => [
        'path' => dirname(__DIR__) . '/storage/bot.sqlite',
    ],

    'paths' => [
        'storage' => dirname(__DIR__) . '/storage',
        'logs' => dirname(__DIR__) . '/storage/logs',
        'cache' => dirname(__DIR__) . '/storage/cache',
        'backups' => dirname(__DIR__) . '/storage/backups',
    ],

    'admins' => [],
];