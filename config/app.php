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
        'drop_pending_updates' => false,
        'allowed_updates' => [
            'message',
            'edited_message',
            'callback_query',
            'inline_query',
            'chosen_inline_result',
            'my_chat_member',
            'chat_member',
            'chat_join_request',
            'poll',
            'poll_answer',
        ],
    ],

    'database' => [
        'path' => dirname(__DIR__) . '/storage/bot.sqlite',
    ],

    'paths' => [
        'storage' => dirname(__DIR__) . '/storage',
        'logs' => dirname(__DIR__) . '/storage/logs',
        'cache' => dirname(__DIR__) . '/storage/cache',
        'backups' => dirname(__DIR__) . '/storage/backups',
        'temporary' => dirname(__DIR__) . '/storage/tmp',
    ],

    'http' => [
        'user_agent' => 'SmartToolboxFaBot/1.0',
        'connect_timeout' => 4,
        'timeout' => 8,
        'max_response_bytes' => 1048576,
        'max_redirects' => 3,

        'ssrf' => [
            'allow_http' => false,
            'allowed_ports' => [443],
        ],
    ],


    /*
     * Telemetry only stores operational metadata. Raw message text and
     * command arguments are not stored by default.
     */
    'analytics' => [
        'enabled' => true,
        'sample_rate' => 100,

        'command_history' => [
            'enabled' => true,
            'store_arguments' => false,
            'max_argument_characters' => 200,
        ],

        'api_metrics' => [
            'enabled' => true,
            'sample_rate' => 100,
        ],

        'cache_metrics' => [
            'enabled' => true,
            'sample_rate' => 100,
        ],

        'retention' => [
            'usage_days' => 90,
            'command_days' => 30,
            'api_days' => 30,
            'cache_days' => 30,
            'job_run_days' => 30,
            'dead_letter_days' => 90,
            'max_usage_rows' => 250000,
        ],
    ],

    'jobs' => [
        'enabled' => true,
        'batch_size' => 10,
        'lock_ttl_seconds' => 180,
        'stale_after_seconds' => 600,
        'retry_base_seconds' => 30,
        'default_max_attempts' => 3,
        'temporary_file_max_age_seconds' => 3600,
    ],

    'features' => [
        'defaults' => [
            'analytics' => [
                'enabled' => true,
                'rollout_percentage' => 100,
                'description' => 'Usage analytics and operational metrics.',
            ],
            'generic_jobs' => [
                'enabled' => true,
                'rollout_percentage' => 100,
                'description' => 'Generic scheduled job queue and worker.',
            ],
            'callback_routing' => [
                'enabled' => true,
                'rollout_percentage' => 100,
                'description' => 'Callback query routing foundation.',
            ],
            'inline_routing' => [
                'enabled' => true,
                'rollout_percentage' => 100,
                'description' => 'Inline mode handlers for weather, currency, countries, calculator, Wikipedia, and GitHub.',
            ],
            'smart_alerts' => [
                'enabled' => true,
                'rollout_percentage' => 100,
                'description' => 'Weather, temperature, wind and currency smart alerts.',
            ],
            'scheduled_subscriptions' => [
                'enabled' => true,
                'rollout_percentage' => 100,
                'description' => 'Daily, weekly and monthly reports.',
            ],
            'site_monitoring' => [
                'enabled' => true,
                'rollout_percentage' => 100,
                'description' => 'SSRF-protected website, SSL and DNS monitoring.',
            ],
            'group_management' => [
                'enabled' => true,
                'rollout_percentage' => 100,
                'description' => 'Professional group moderation, anti-spam, captcha and join request management.',
            ],
        ],
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
         * یادآورها در SQLite ذخیره می‌شوند و Worker زمان‌بندی‌شده
         * آن‌ها را از طریق Telegram Bot API ارسال می‌کند.
         * این قابلیت API Key یا سرویس پولی جدیدی ندارد.
         */
        'reminders' => [
            'enabled' => true,
            'state_ttl' => 300,
            'max_text_length' => 1000,
            'max_pending_per_user' => 50,
            'max_future_days' => 365,
            'retention_days' => 90,

            'rate_limit' => [
                'max_attempts' => 30,
                'window_seconds' => 60,
            ],

            'worker' => [
                'batch_size' => 10,
                'max_delivery_attempts' => 3,
                'retry_base_seconds' => 60,
                'stale_lock_seconds' => 600,
            ],
        ],



        'alerts' => [
            'enabled' => true,
            'max_alerts_per_user' => 30,
            'max_subscriptions_per_user' => 20,
            'check_interval_seconds' => 300,
            'scan_batch_size' => 20,
            'subscription_batch_size' => 20,
            'scan_job_interval_seconds' => 60,
            'subscription_job_interval_seconds' => 60,
            'default_cooldown_seconds' => 3600,
            'default_hysteresis' => 0.5,
            'max_notifications_per_day' => 3,
            'notification_retention_days' => 90,
            'weather_cache_ttl' => 120,
            'currency_cache_ttl' => 900,
            'country_cache_ttl' => 21600,

            'rate_limit' => [
                'max_attempts' => 40,
                'window_seconds' => 60,
            ],
        ],

        'monitoring' => [
            'enabled' => true,
            'max_monitors_per_user' => 20,
            'minimum_interval_seconds' => 300,
            'maximum_interval_seconds' => 86400,
            'scan_batch_size' => 10,
            'report_batch_size' => 10,
            'scan_job_interval_seconds' => 60,
            'report_job_interval_seconds' => 60,
            'failure_threshold' => 2,
            'recovery_threshold' => 1,
            'retention_days' => 90,

            'http' => [
                'connect_timeout' => 4,
                'timeout' => 8,
                'max_response_bytes' => 131072,
                'max_redirects' => 3,
                'allowed_ports' => [80, 443],
            ],

            'rate_limit' => [
                'max_attempts' => 30,
                'window_seconds' => 60,
            ],
        ],


        'group_management' => [
            'enabled' => true,

            'max_purge_messages' => 100,
            'max_rules_length' => 3000,
            'max_template_length' => 2000,
            'invite_maximum_days' => 365,
            'automod_notice_cooldown_seconds' => 30,
            'member_role_cache_ttl' => 120,
            'retention_days' => 180,

            'worker' => [
                'batch_size' => 20,
                'scan_job_interval_seconds' => 60,
            ],

            'defaults' => [
                'warnings_threshold' => 3,
                'warning_action' => 'mute',
                'warning_action_duration_seconds' => 3600,

                'anti_spam_enabled' => 0,
                'flood_max_messages' => 6,
                'flood_window_seconds' => 10,
                'duplicate_max_messages' => 3,
                'duplicate_window_seconds' => 30,

                'anti_link_enabled' => 0,
                'bad_words_enabled' => 0,

                'captcha_enabled' => 0,
                'captcha_timeout_seconds' => 120,
                'captcha_max_attempts' => 3,
                'captcha_failure_action' => 'kick',

                'welcome_enabled' => 0,
                'goodbye_enabled' => 0,
                'bot_slow_mode_seconds' => 0,
                'join_request_mode' => 'manual',
            ],

            'rate_limit' => [
                'max_attempts' => 40,
                'window_seconds' => 60,
            ],
        ],

        'profile' => [
            'enabled' => true,
            'max_favorites' => 50,
            'max_shortcuts' => 30,

            'rate_limit' => [
                'max_attempts' => 60,
                'window_seconds' => 60,
            ],
        ],

        'wiki' => [
            'enabled' => true,
            'search_cache_ttl' => 21600,
            'random_cache_ttl' => 300,
            'today_cache_ttl' => 21600,
            'max_query_length' => 150,

            'rate_limit' => [
                'max_attempts' => 30,
                'window_seconds' => 60,
            ],
        ],

        'github' => [
            'enabled' => true,

            /*
             * Token اختیاری است و فقط باید در config/local.php
             * قرار گیرد. حالت بدون Token نیز کار می‌کند.
             */
            'token' => '',
            'api_version' => '2026-03-10',
            'cache_ttl' => 1800,
            'release_cache_ttl' => 900,
            'max_watches_per_user' => 20,
            'watch_scan_interval_seconds' => 900,
            'watch_scan_batch_size' => 20,

            'rate_limit' => [
                'max_attempts' => 30,
                'window_seconds' => 60,
            ],
        ],

        'developer' => [
            'enabled' => true,
            'max_input_length' => 3000,
            'max_regex_pattern_length' => 300,
            'regex_backtrack_limit' => 100000,

            'rate_limit' => [
                'max_attempts' => 60,
                'window_seconds' => 60,
            ],
        ],

        'inline' => [
            'enabled' => true,
            'cache_time' => 60,
            'max_results' => 5,
            'weather_cache_ttl' => 600,

            'rate_limit' => [
                'max_attempts' => 40,
                'window_seconds' => 60,
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

    /*
     * رمز پنل وب نباید در Git ذخیره شود.
     * مقدار password_hash را فقط در config/local.php قرار بده.
     */
    'web_admin' => [
        'enabled' => true,
        'base_path' => '/admin',
        'password_hash' => '',
        'session_name' => 'smart_toolbox_admin',
        'session_idle_seconds' => 3600,
        'session_absolute_seconds' => 43200,
        'login_max_attempts' => 5,
        'login_window_seconds' => 900,
        'login_block_seconds' => 900,
    ],

    'admins' => [
        47729048,
    ],
];
