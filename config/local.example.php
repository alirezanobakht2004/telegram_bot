<?php

declare(strict_types=1);

return [
    'app' => [
        'environment' => 'local',
        'debug' => true,
    ],

    'telegram' => [
        'token' => 'PUT_BOT_TOKEN_HERE',
        'webhook_secret' => 'PUT_RANDOM_SECRET_HERE',
    ],

    'admins' => [
        // Telegram numeric user IDs
    ],
];