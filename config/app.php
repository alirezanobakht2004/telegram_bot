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
        'webhook_url' => '',
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

    'http' => [
        'user_agent' => 'SmartToolboxFaBot/1.0',
        'connect_timeout' => 4,
        'timeout' => 8,
        'max_response_bytes' => 1048576,
    ],

    'modules' => [
        'animals' => [
            'enabled' => true,
            'cache_ttl' => 5,

            'rate_limit' => [
                'max_attempts' => 30,
                'window_seconds' => 60,
            ],

            'providers' => [
                'dog' => [
                    'endpoint' =>
                        'https://dog.ceo/api/breeds/image/random',
                ],

                'cat' => [
                    'endpoint' =>
                        'https://cataas.com/cat',
                ],

                'fox' => [
                    'endpoint' =>
                        'https://randomfox.ca/floof/',
                ],
            ],
        ],

        'weather' => [
            'enabled' => true,
            'geocoding_cache_ttl' => 86400,
            'forecast_cache_ttl' => 600,
            'state_ttl' => 300,

            'rate_limit' => [
                'max_attempts' => 30,
                'window_seconds' => 60,
            ],

            'forecast_days' => 4,

            'providers' => [
                'geocoding_endpoint' =>
                    'https://geocoding-api.open-meteo.com/v1/search',

                'forecast_endpoint' =>
                    'https://api.open-meteo.com/v1/forecast',
            ],
        ],

        'currency' => [
            'enabled' => true,
            'rate_cache_ttl' => 3600,
            'state_ttl' => 300,

            'rate_limit' => [
                'max_attempts' => 30,
                'window_seconds' => 60,
            ],

            'provider' => [
                'base_url' =>
                    'https://api.frankfurter.dev/v2',
            ],
        ],

        'countries' => [
            'enabled' => true,
            'cache_ttl' => 86400,
            'state_ttl' => 300,

            'rate_limit' => [
                'max_attempts' => 30,
                'window_seconds' => 60,
            ],

            'provider' => [
                'base_url' => 'https://countries.dev',
            ],
        ],


        /*
         * ماشین حساب و تبدیل واحد بدون API خارجی اجرا می‌شوند.
         * هیچ عبارت PHP یا eval اجرا نمی‌شود؛ Parser اختصاصی و
         * فهرست سفید عملگرها و توابع استفاده می‌شود.
         */
        'calculator' => [
            'enabled' => true,
            'state_ttl' => 300,
            'max_expression_length' => 500,
            'max_conversion_length' => 200,

            'rate_limit' => [
                'max_attempts' => 60,
                'window_seconds' => 60,
            ],
        ],

        /*
         * این ماژول هیچ API خارجی ندارد و تمام پردازش‌ها
         * مستقیماً داخل PHP انجام می‌شوند.
         */
        'utilities' => [
            'enabled' => true,
            'state_ttl' => 300,
            'max_input_length' => 2500,
            'default_password_length' => 20,

            'rate_limit' => [
                'max_attempts' => 60,
                'window_seconds' => 60,
            ],
        ],

        /*
         * تنظیمات در SQLite ذخیره می‌شوند و هیچ سرویس خارجی
         * یا هزینه‌ای ندارند.
         */
        'settings' => [
            'enabled' => true,
            'state_ttl' => 300,
            'default_timezone' => 'Asia/Tehran',
            'default_password_length' => 20,
        ],

        /*
         * پنل مدیریت فقط برای Telegram user IDهای موجود
         * در کلید admins فعال است.
         *
         * ارسال همگانی به‌صورت Batch کوچک انجام می‌شود تا
         * Webhook و منابع محدود سرور تحت فشار قرار نگیرند.
         */
        'admin' => [
            'enabled' => true,
            'state_ttl' => 600,
            'broadcast_batch_size' => 5,
            'max_broadcast_length' => 3000,
        ],
    ],

    'admins' => [
        47729048,
    ],
];
