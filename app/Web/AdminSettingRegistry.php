<?php

declare(strict_types=1);

namespace SmartToolbox\Web;

use InvalidArgumentException;

final class AdminSettingRegistry
{
    /**
     * @return array<string, array{
     *     group: string,
     *     label: string,
     *     type: string,
     *     help: string,
     *     min?: int,
     *     max?: int
     * }>
     */
    public function definitions(): array
    {
        return [
            'http.connect_timeout' => [
                'group' => 'HTTP خارجی',
                ...$this->integer(
                    'مهلت اتصال',
                    1,
                    30,
                    'ثانیه'
                ),
            ],
            'http.timeout' => [
                'group' => 'HTTP خارجی',
                ...$this->integer(
                    'مهلت کل درخواست',
                    2,
                    60,
                    'ثانیه'
                ),
            ],
            'http.max_response_bytes' => [
                'group' => 'HTTP خارجی',
                ...$this->integer(
                    'حداکثر حجم پاسخ',
                    65536,
                    5242880,
                    'بایت'
                ),
            ],
            'http.max_redirects' => [
                'group' => 'HTTP خارجی',
                ...$this->integer(
                    'حداکثر Redirect',
                    0,
                    10,
                    'مرحله'
                ),
            ],

            'analytics.enabled' => [
                'group' => 'Analytics',
                'label' => 'فعال بودن Analytics',
                'type' => 'bool',
                'help' => 'ثبت رویدادهای عملیاتی و آماری.',
            ],
            'analytics.sample_rate' => [
                'group' => 'Analytics',
                ...$this->integer(
                    'نرخ نمونه‌برداری Usage',
                    0,
                    100,
                    'درصد'
                ),
            ],
            'analytics.command_history.enabled' => [
                'group' => 'Analytics',
                'label' => 'ثبت تاریخچه فرمان',
                'type' => 'bool',
                'help' => 'نام فرمان، منبع و زمان اجرا ثبت می‌شود.',
            ],
            'analytics.command_history.store_arguments' => [
                'group' => 'Analytics',
                'label' => 'ذخیره Preview آرگومان‌ها',
                'type' => 'bool',
                'help' => 'به‌دلایل حریم خصوصی پیش‌فرض خاموش است.',
            ],
            'analytics.command_history.max_argument_characters' => [
                'group' => 'Analytics',
                ...$this->integer(
                    'حداکثر طول Preview',
                    0,
                    1000,
                    'کاراکتر'
                ),
            ],
            'analytics.api_metrics.enabled' => [
                'group' => 'Analytics',
                'label' => 'ثبت API Metrics',
                'type' => 'bool',
                'help' => 'Latency، Status و حجم پاسخ سرویس‌ها.',
            ],
            'analytics.api_metrics.sample_rate' => [
                'group' => 'Analytics',
                ...$this->integer(
                    'نرخ نمونه‌برداری API',
                    0,
                    100,
                    'درصد'
                ),
            ],
            'analytics.cache_metrics.enabled' => [
                'group' => 'Analytics',
                'label' => 'ثبت Cache Metrics',
                'type' => 'bool',
                'help' => 'Hit، Miss، Write و زمان عملیات کش.',
            ],
            'analytics.cache_metrics.sample_rate' => [
                'group' => 'Analytics',
                ...$this->integer(
                    'نرخ نمونه‌برداری Cache',
                    0,
                    100,
                    'درصد'
                ),
            ],
            'analytics.retention.usage_days' => [
                'group' => 'Analytics Retention',
                ...$this->integer(
                    'نگهداری Usage Events',
                    1,
                    3650,
                    'روز'
                ),
            ],
            'analytics.retention.command_days' => [
                'group' => 'Analytics Retention',
                ...$this->integer(
                    'نگهداری Command History',
                    1,
                    3650,
                    'روز'
                ),
            ],
            'analytics.retention.api_days' => [
                'group' => 'Analytics Retention',
                ...$this->integer(
                    'نگهداری API Metrics',
                    1,
                    3650,
                    'روز'
                ),
            ],
            'analytics.retention.cache_days' => [
                'group' => 'Analytics Retention',
                ...$this->integer(
                    'نگهداری Cache Metrics',
                    1,
                    3650,
                    'روز'
                ),
            ],
            'analytics.retention.job_run_days' => [
                'group' => 'Analytics Retention',
                ...$this->integer(
                    'نگهداری Job Runs',
                    1,
                    3650,
                    'روز'
                ),
            ],
            'analytics.retention.dead_letter_days' => [
                'group' => 'Analytics Retention',
                ...$this->integer(
                    'نگهداری Dead Letter',
                    1,
                    3650,
                    'روز'
                ),
            ],
            'analytics.retention.max_usage_rows' => [
                'group' => 'Analytics Retention',
                ...$this->integer(
                    'سقف Usage Events',
                    1000,
                    2000000,
                    'رکورد'
                ),
            ],

            'jobs.enabled' => [
                'group' => 'Job Queue',
                'label' => 'فعال بودن Worker عمومی',
                'type' => 'bool',
                'help' => 'صف عمومی کارهای زمان‌بندی‌شده.',
            ],
            'jobs.batch_size' => [
                'group' => 'Job Queue',
                ...$this->integer(
                    'اندازه Batch',
                    1,
                    100,
                    'Job در هر اجرا'
                ),
            ],
            'jobs.lock_ttl_seconds' => [
                'group' => 'Job Queue',
                ...$this->integer(
                    'TTL قفل Worker',
                    30,
                    3600,
                    'ثانیه'
                ),
            ],
            'jobs.stale_after_seconds' => [
                'group' => 'Job Queue',
                ...$this->integer(
                    'زمان بازیابی Job رهاشده',
                    60,
                    86400,
                    'ثانیه'
                ),
            ],
            'jobs.retry_base_seconds' => [
                'group' => 'Job Queue',
                ...$this->integer(
                    'پایه Backoff',
                    1,
                    3600,
                    'ثانیه'
                ),
            ],
            'jobs.default_max_attempts' => [
                'group' => 'Job Queue',
                ...$this->integer(
                    'حداکثر تلاش پیش‌فرض',
                    1,
                    50,
                    'بار'
                ),
            ],
            'jobs.temporary_file_max_age_seconds' => [
                'group' => 'Job Queue',
                ...$this->integer(
                    'عمر فایل موقت',
                    60,
                    604800,
                    'ثانیه'
                ),
            ],

            ...$this->module(
                'animals',
                'حیوانات',
                [
                    'cache_ttl' => $this->integer(
                        'TTL کش تصویر',
                        1,
                        86400,
                        'ثانیه'
                    ),
                ],
                1000
            ),
            ...$this->module(
                'weather',
                'آب‌وهوا',
                [
                    'geocoding_cache_ttl' =>
                        $this->integer(
                            'TTL جست‌وجوی شهر',
                            60,
                            2592000,
                            'ثانیه'
                        ),
                    'forecast_cache_ttl' =>
                        $this->integer(
                            'TTL پیش‌بینی',
                            60,
                            86400,
                            'ثانیه'
                        ),
                    'state_ttl' =>
                        $this->integer(
                            'TTL مکالمه مرحله‌ای',
                            30,
                            86400,
                            'ثانیه'
                        ),
                    'forecast_days' =>
                        $this->integer(
                            'تعداد روز پیش‌بینی',
                            1,
                            7,
                            'روز'
                        ),
                ],
                1000
            ),
            ...$this->module(
                'currency',
                'ارز',
                [
                    'rate_cache_ttl' =>
                        $this->integer(
                            'TTL نرخ ارز',
                            60,
                            604800,
                            'ثانیه'
                        ),
                    'state_ttl' =>
                        $this->integer(
                            'TTL مکالمه مرحله‌ای',
                            30,
                            86400,
                            'ثانیه'
                        ),
                ],
                1000
            ),
            ...$this->module(
                'countries',
                'کشورها',
                [
                    'cache_ttl' =>
                        $this->integer(
                            'TTL اطلاعات کشور',
                            60,
                            2592000,
                            'ثانیه'
                        ),
                    'state_ttl' =>
                        $this->integer(
                            'TTL مکالمه مرحله‌ای',
                            30,
                            86400,
                            'ثانیه'
                        ),
                ],
                1000
            ),
            ...$this->module(
                'reminders',
                'یادآورها',
                [
                    'state_ttl' =>
                        $this->integer(
                            'TTL مکالمه مرحله‌ای',
                            30,
                            86400,
                            'ثانیه'
                        ),
                    'max_text_length' =>
                        $this->integer(
                            'حداکثر طول متن',
                            50,
                            3000,
                            'کاراکتر'
                        ),
                    'max_pending_per_user' =>
                        $this->integer(
                            'حداکثر یادآور فعال کاربر',
                            1,
                            500,
                            'یادآور'
                        ),
                    'max_future_days' =>
                        $this->integer(
                            'حداکثر فاصله زمانی',
                            1,
                            3650,
                            'روز'
                        ),
                    'retention_days' =>
                        $this->integer(
                            'نگهداری تاریخچه',
                            1,
                            3650,
                            'روز'
                        ),
                    'worker.batch_size' =>
                        $this->integer(
                            'اندازه Batch Worker',
                            1,
                            50,
                            'یادآور در هر اجرا'
                        ),
                    'worker.max_delivery_attempts' =>
                        $this->integer(
                            'حداکثر تلاش ارسال',
                            1,
                            10,
                            'بار'
                        ),
                    'worker.retry_base_seconds' =>
                        $this->integer(
                            'پایه تأخیر Retry',
                            10,
                            3600,
                            'ثانیه'
                        ),
                    'worker.stale_lock_seconds' =>
                        $this->integer(
                            'انقضای Lock پردازش',
                            60,
                            3600,
                            'ثانیه'
                        ),
                ],
                1000
            ),
            ...$this->module(
                'alerts',
                'هشدارها و اشتراک‌ها',
                [
                    'max_alerts_per_user' =>
                        $this->integer(
                            'حداکثر هشدار هر کاربر',
                            1,
                            500,
                            'هشدار'
                        ),
                    'max_subscriptions_per_user' =>
                        $this->integer(
                            'حداکثر اشتراک هر کاربر',
                            1,
                            200,
                            'اشتراک'
                        ),
                    'check_interval_seconds' =>
                        $this->integer(
                            'فاصله بررسی هشدار',
                            60,
                            86400,
                            'ثانیه'
                        ),
                    'scan_batch_size' =>
                        $this->integer(
                            'Batch هشدار',
                            1,
                            100,
                            'رکورد'
                        ),
                    'subscription_batch_size' =>
                        $this->integer(
                            'Batch اشتراک',
                            1,
                            100,
                            'رکورد'
                        ),
                    'scan_job_interval_seconds' =>
                        $this->integer(
                            'فاصله Job هشدار',
                            60,
                            86400,
                            'ثانیه'
                        ),
                    'subscription_job_interval_seconds' =>
                        $this->integer(
                            'فاصله Job اشتراک',
                            60,
                            86400,
                            'ثانیه'
                        ),
                    'default_cooldown_seconds' =>
                        $this->integer(
                            'Cooldown پیش‌فرض',
                            0,
                            604800,
                            'ثانیه'
                        ),
                    'default_hysteresis' =>
                        $this->number(
                            'Hysteresis پیش‌فرض',
                            0,
                            1000000
                        ),
                    'max_notifications_per_day' =>
                        $this->integer(
                            'حداکثر اعلان روزانه',
                            1,
                            100,
                            'اعلان'
                        ),
                    'notification_retention_days' =>
                        $this->integer(
                            'نگهداری لاگ اعلان',
                            1,
                            3650,
                            'روز'
                        ),
                    'weather_cache_ttl' =>
                        $this->integer(
                            'TTL داده هوا',
                            30,
                            86400,
                            'ثانیه'
                        ),
                    'currency_cache_ttl' =>
                        $this->integer(
                            'TTL نرخ ارز',
                            60,
                            86400,
                            'ثانیه'
                        ),
                    'country_cache_ttl' =>
                        $this->integer(
                            'TTL کشور',
                            300,
                            2592000,
                            'ثانیه'
                        ),
                ],
                2000
            ),
            ...$this->module(
                'monitoring',
                'مانیتورینگ سایت',
                [
                    'max_monitors_per_user' =>
                        $this->integer(
                            'حداکثر مانیتور هر کاربر',
                            1,
                            200,
                            'مانیتور'
                        ),
                    'minimum_interval_seconds' =>
                        $this->integer(
                            'حداقل فاصله مانیتور',
                            60,
                            86400,
                            'ثانیه'
                        ),
                    'maximum_interval_seconds' =>
                        $this->integer(
                            'حداکثر فاصله مانیتور',
                            300,
                            2592000,
                            'ثانیه'
                        ),
                    'scan_batch_size' =>
                        $this->integer(
                            'Batch مانیتور',
                            1,
                            50,
                            'مانیتور'
                        ),
                    'report_batch_size' =>
                        $this->integer(
                            'Batch گزارش روزانه',
                            1,
                            50,
                            'گزارش'
                        ),
                    'scan_job_interval_seconds' =>
                        $this->integer(
                            'فاصله Job مانیتور',
                            60,
                            86400,
                            'ثانیه'
                        ),
                    'report_job_interval_seconds' =>
                        $this->integer(
                            'فاصله Job گزارش',
                            60,
                            86400,
                            'ثانیه'
                        ),
                    'failure_threshold' =>
                        $this->integer(
                            'آستانه تشخیص Down',
                            1,
                            10,
                            'خطای متوالی'
                        ),
                    'recovery_threshold' =>
                        $this->integer(
                            'آستانه تشخیص Recovery',
                            1,
                            10,
                            'موفقیت متوالی'
                        ),
                    'retention_days' =>
                        $this->integer(
                            'نگهداری Checkها',
                            1,
                            3650,
                            'روز'
                        ),
                    'http.connect_timeout' =>
                        $this->integer(
                            'Connect Timeout مانیتور',
                            1,
                            30,
                            'ثانیه'
                        ),
                    'http.timeout' =>
                        $this->integer(
                            'Timeout مانیتور',
                            1,
                            60,
                            'ثانیه'
                        ),
                    'http.max_response_bytes' =>
                        $this->integer(
                            'حداکثر حجم پاسخ مانیتور',
                            1024,
                            2097152,
                            'بایت'
                        ),
                    'http.max_redirects' =>
                        $this->integer(
                            'حداکثر Redirect',
                            0,
                            10,
                            'Redirect'
                        ),
                ],
                2000
            ),
            ...$this->module(
                'file_tools',
                'ابزارهای فایل و تصویر',
                [
                    'max_file_bytes' =>
                        $this->integer(
                            'حداکثر اندازه فایل',
                            1048576,
                            10485760,
                            'بایت'
                        ),
                    'max_image_pixels' =>
                        $this->integer(
                            'حداکثر پیکسل تصویر',
                            1000000,
                            12000000,
                            'پیکسل'
                        ),
                    'max_pdf_pages' =>
                        $this->integer(
                            'حداکثر صفحات PDF',
                            1,
                            20,
                            'صفحه'
                        ),
                    'max_extracted_text_bytes' =>
                        $this->integer(
                            'حداکثر متن استخراجی',
                            10240,
                            512000,
                            'بایت'
                        ),
                    'max_text_input_bytes' =>
                        $this->integer(
                            'حداکثر متن ورودی',
                            10240,
                            512000,
                            'بایت'
                        ),
                    'max_qr_text_length' =>
                        $this->integer(
                            'حداکثر طول متن QR',
                            50,
                            3000,
                            'کاراکتر'
                        ),
                    'max_active_per_user' =>
                        $this->integer(
                            'Job فعال هر کاربر',
                            1,
                            1,
                            'Job'
                        ),
                    'max_global_processing' =>
                        $this->integer(
                            'Job هم‌زمان کل',
                            1,
                            2,
                            'Job'
                        ),
                    'job_timeout_seconds' =>
                        $this->integer(
                            'Timeout هر Job',
                            10,
                            180,
                            'ثانیه'
                        ),
                    'stale_processing_seconds' =>
                        $this->integer(
                            'تشخیص Job متوقف‌شده',
                            60,
                            3600,
                            'ثانیه'
                        ),
                    'retention_days' =>
                        $this->integer(
                            'نگهداری سوابق Job',
                            1,
                            365,
                            'روز'
                        ),
                    'default_image_quality' =>
                        $this->integer(
                            'کیفیت پیش‌فرض تصویر',
                            20,
                            95,
                            'درصد'
                        ),
                    'qr_default_size' =>
                        $this->integer(
                            'اندازه پیش‌فرض QR',
                            250,
                            1200,
                            'پیکسل'
                        ),
                    'worker.max_attempts' =>
                        $this->integer(
                            'حداکثر تلاش Worker',
                            1,
                            10,
                            'تلاش'
                        ),
                ],
                1000
            ),
            ...$this->module(
                'quiz_games',
                'مسابقه، آزمون و امتیاز',
                [
                    'leaderboard_size' =>
                        $this->integer(
                            'تعداد نفرات جدول',
                            3,
                            100,
                            'نفر'
                        ),
                    'retention_days' =>
                        $this->integer(
                            'نگهداری Sessionها',
                            7,
                            3650,
                            'روز'
                        ),
                    'scoring.time_bonus_max_percent' =>
                        $this->integer(
                            'حداکثر Bonus سرعت',
                            0,
                            100,
                            'درصد امتیاز پایه'
                        ),
                    'scoring.streak_bonus_percent' =>
                        $this->integer(
                            'Bonus هر Streak',
                            0,
                            50,
                            'درصد امتیاز پایه'
                        ),
                    'scoring.participation_xp' =>
                        $this->integer(
                            'XP پاسخ نادرست',
                            0,
                            100,
                            'XP'
                        ),
                    'scoring.xp_per_level' =>
                        $this->integer(
                            'XP هر Level',
                            10,
                            100000,
                            'XP'
                        ),
                    'scoring.points.easy' =>
                        $this->integer(
                            'امتیاز ریاضی آسان',
                            1,
                            1000,
                            'امتیاز'
                        ),
                    'scoring.points.medium' =>
                        $this->integer(
                            'امتیاز ریاضی متوسط',
                            1,
                            1000,
                            'امتیاز'
                        ),
                    'scoring.points.hard' =>
                        $this->integer(
                            'امتیاز ریاضی سخت',
                            1,
                            1000,
                            'امتیاز'
                        ),
                    'scoring.xp.easy' =>
                        $this->integer(
                            'XP ریاضی آسان',
                            1,
                            1000,
                            'XP'
                        ),
                    'scoring.xp.medium' =>
                        $this->integer(
                            'XP ریاضی متوسط',
                            1,
                            1000,
                            'XP'
                        ),
                    'scoring.xp.hard' =>
                        $this->integer(
                            'XP ریاضی سخت',
                            1,
                            1000,
                            'XP'
                        ),
                    'answer_timeouts.easy' =>
                        $this->integer(
                            'زمان پاسخ آسان',
                            5,
                            300,
                            'ثانیه'
                        ),
                    'answer_timeouts.medium' =>
                        $this->integer(
                            'زمان پاسخ متوسط',
                            5,
                            300,
                            'ثانیه'
                        ),
                    'answer_timeouts.hard' =>
                        $this->integer(
                            'زمان پاسخ سخت',
                            5,
                            300,
                            'ثانیه'
                        ),
                    'worker.batch_size' =>
                        $this->integer(
                            'Batch پاک‌سازی Quiz',
                            1,
                            1000,
                            'Session'
                        ),
                    'worker.interval_seconds' =>
                        $this->integer(
                            'فاصله Job Quiz',
                            60,
                            86400,
                            'ثانیه'
                        ),
                ],
                2000
            ),
            ...$this->module(
                'mini_app',
                'Mini App کاربران',
                [
                    'retention_days' =>
                        $this->integer(
                            'نگهداری Sessionهای منقضی',
                            1,
                            3650,
                            'روز'
                        ),
                    'audit_retention_days' =>
                        $this->integer(
                            'نگهداری Audit',
                            7,
                            3650,
                            'روز'
                        ),
                    'security.init_data_max_age_seconds' =>
                        $this->integer(
                            'حداکثر عمر initData',
                            30,
                            3600,
                            'ثانیه'
                        ),
                    'security.auth_date_future_skew_seconds' =>
                        $this->integer(
                            'تلورانس زمان آینده auth_date',
                            0,
                            300,
                            'ثانیه'
                        ),
                    'security.max_init_data_bytes' =>
                        $this->integer(
                            'حداکثر حجم initData',
                            1024,
                            65536,
                            'بایت'
                        ),
                    'security.max_request_bytes' =>
                        $this->integer(
                            'حداکثر حجم درخواست API',
                            1024,
                            1048576,
                            'بایت'
                        ),
                    'security.session_idle_ttl_seconds' =>
                        $this->integer(
                            'انقضای بیکاری Session',
                            300,
                            3600,
                            'ثانیه'
                        ),
                    'security.session_absolute_ttl_seconds' =>
                        $this->integer(
                            'عمر مطلق Session',
                            300,
                            86400,
                            'ثانیه'
                        ),
                    'security.max_active_sessions_per_user' =>
                        $this->integer(
                            'Session فعال هر کاربر',
                            1,
                            20,
                            'Session'
                        ),
                    'rate_limit.auth_max_attempts' =>
                        $this->integer(
                            'حداکثر تلاش احراز هویت',
                            1,
                            100,
                            'درخواست'
                        ),
                    'rate_limit.auth_window_seconds' =>
                        $this->integer(
                            'پنجره Rate Limit احراز هویت',
                            1,
                            86400,
                            'ثانیه'
                        ),
                    'rate_limit.api_max_attempts' =>
                        $this->integer(
                            'حداکثر درخواست API',
                            1,
                            1000,
                            'درخواست'
                        ),
                    'rate_limit.api_window_seconds' =>
                        $this->integer(
                            'پنجره Rate Limit API',
                            1,
                            86400,
                            'ثانیه'
                        ),
                    'worker.interval_seconds' =>
                        $this->integer(
                            'فاصله Maintenance',
                            60,
                            86400,
                            'ثانیه'
                        ),
                ],
                null
            ),
            ...$this->module(
                'group_management',
                'مدیریت حرفه‌ای گروه‌ها',
                [
                    'max_purge_messages' =>
                        $this->integer(
                            'حداکثر پیام Purge',
                            1,
                            100,
                            'پیام'
                        ),
                    'max_rules_length' =>
                        $this->integer(
                            'حداکثر طول قوانین',
                            100,
                            4000,
                            'کاراکتر'
                        ),
                    'max_template_length' =>
                        $this->integer(
                            'حداکثر طول Welcome/Goodbye',
                            100,
                            4000,
                            'کاراکتر'
                        ),
                    'invite_maximum_days' =>
                        $this->integer(
                            'حداکثر اعتبار لینک دعوت',
                            1,
                            3650,
                            'روز'
                        ),
                    'automod_notice_cooldown_seconds' =>
                        $this->integer(
                            'Cooldown پیام AutoMod',
                            5,
                            3600,
                            'ثانیه'
                        ),
                    'member_role_cache_ttl' =>
                        $this->integer(
                            'TTL کش نقش مدیران',
                            10,
                            3600,
                            'ثانیه'
                        ),
                    'retention_days' =>
                        $this->integer(
                            'نگهداری Audit و سوابق',
                            7,
                            3650,
                            'روز'
                        ),
                    'worker.batch_size' =>
                        $this->integer(
                            'Batch Worker مدیریت گروه',
                            1,
                            100,
                            'رکورد'
                        ),
                    'worker.scan_job_interval_seconds' =>
                        $this->integer(
                            'فاصله Job مدیریت گروه',
                            60,
                            3600,
                            'ثانیه'
                        ),
                    'defaults.warnings_threshold' =>
                        $this->integer(
                            'سقف پیش‌فرض اخطار',
                            1,
                            20,
                            'اخطار'
                        ),
                    'defaults.warning_action_duration_seconds' =>
                        $this->integer(
                            'مدت Action اخطار',
                            30,
                            31622400,
                            'ثانیه'
                        ),
                    'defaults.flood_max_messages' =>
                        $this->integer(
                            'پیام مجاز در پنجره Flood',
                            2,
                            100,
                            'پیام'
                        ),
                    'defaults.flood_window_seconds' =>
                        $this->integer(
                            'پنجره Flood',
                            1,
                            300,
                            'ثانیه'
                        ),
                    'defaults.duplicate_max_messages' =>
                        $this->integer(
                            'تکرار مجاز پیام',
                            1,
                            20,
                            'بار'
                        ),
                    'defaults.duplicate_window_seconds' =>
                        $this->integer(
                            'پنجره تکرار',
                            1,
                            600,
                            'ثانیه'
                        ),
                    'defaults.captcha_timeout_seconds' =>
                        $this->integer(
                            'مهلت پیش‌فرض کپچا',
                            30,
                            1800,
                            'ثانیه'
                        ),
                    'defaults.captcha_max_attempts' =>
                        $this->integer(
                            'تلاش پیش‌فرض کپچا',
                            1,
                            10,
                            'بار'
                        ),
                    'defaults.bot_slow_mode_seconds' =>
                        $this->integer(
                            'Slow Mode پیش‌فرض ربات',
                            0,
                            3600,
                            'ثانیه'
                        ),
                ],
                2000
            ),
            ...$this->module(
                'profile',
                'پروفایل و میان‌برها',
                [
                    'max_favorites' =>
                        $this->integer(
                            'حداکثر علاقه‌مندی',
                            1,
                            500,
                            'مورد برای هر کاربر'
                        ),
                    'max_shortcuts' =>
                        $this->integer(
                            'حداکثر میان‌بر',
                            1,
                            200,
                            'میان‌بر برای هر کاربر'
                        ),
                ],
                1000
            ),
            ...$this->module(
                'wiki',
                'ویکی‌پدیا',
                [
                    'search_cache_ttl' =>
                        $this->integer(
                            'TTL جست‌وجو',
                            60,
                            2592000,
                            'ثانیه'
                        ),
                    'random_cache_ttl' =>
                        $this->integer(
                            'TTL مقاله تصادفی',
                            60,
                            86400,
                            'ثانیه'
                        ),
                    'today_cache_ttl' =>
                        $this->integer(
                            'TTL رویدادهای تاریخی',
                            300,
                            2592000,
                            'ثانیه'
                        ),
                    'max_query_length' =>
                        $this->integer(
                            'حداکثر طول جست‌وجو',
                            20,
                            500,
                            'کاراکتر'
                        ),
                ],
                1000
            ),
            ...$this->module(
                'github',
                'GitHub',
                [
                    'cache_ttl' =>
                        $this->integer(
                            'TTL مخزن و Issue',
                            60,
                            86400,
                            'ثانیه'
                        ),
                    'release_cache_ttl' =>
                        $this->integer(
                            'TTL Release',
                            60,
                            86400,
                            'ثانیه'
                        ),
                    'max_watches_per_user' =>
                        $this->integer(
                            'حداکثر Release Watch',
                            1,
                            200,
                            'مخزن برای هر کاربر'
                        ),
                    'watch_scan_interval_seconds' =>
                        $this->integer(
                            'فاصله بررسی Release',
                            300,
                            86400,
                            'ثانیه'
                        ),
                    'watch_scan_batch_size' =>
                        $this->integer(
                            'Batch بررسی Release',
                            1,
                            100,
                            'Watch در هر Job'
                        ),
                ],
                1000
            ),
            ...$this->module(
                'developer',
                'ابزارهای توسعه‌دهندگان',
                [
                    'max_input_length' =>
                        $this->integer(
                            'حداکثر طول ورودی',
                            100,
                            3900,
                            'کاراکتر'
                        ),
                    'max_regex_pattern_length' =>
                        $this->integer(
                            'حداکثر طول Regex',
                            20,
                            1000,
                            'کاراکتر'
                        ),
                    'regex_backtrack_limit' =>
                        $this->integer(
                            'سقف Backtracking',
                            10000,
                            1000000,
                            'گام PCRE'
                        ),
                ],
                2000
            ),
            ...$this->module(
                'inline',
                'Inline Mode',
                [
                    'cache_time' =>
                        $this->integer(
                            'Cache Time پاسخ Inline',
                            1,
                            3600,
                            'ثانیه'
                        ),
                    'max_results' =>
                        $this->integer(
                            'حداکثر نتیجه Wiki',
                            1,
                            10,
                            'نتیجه'
                        ),
                    'weather_cache_ttl' =>
                        $this->integer(
                            'TTL آب‌وهوای Inline',
                            60,
                            86400,
                            'ثانیه'
                        ),
                ],
                2000
            ),
            ...$this->module(
                'calculator',
                'ماشین حساب',
                [
                    'state_ttl' =>
                        $this->integer(
                            'TTL مکالمه مرحله‌ای',
                            30,
                            86400,
                            'ثانیه'
                        ),
                    'max_expression_length' =>
                        $this->integer(
                            'حداکثر طول عبارت',
                            20,
                            1000,
                            'کاراکتر'
                        ),
                    'max_conversion_length' =>
                        $this->integer(
                            'حداکثر طول تبدیل',
                            20,
                            500,
                            'کاراکتر'
                        ),
                ],
                2000
            ),
            ...$this->module(
                'utilities',
                'ابزارها',
                [
                    'state_ttl' =>
                        $this->integer(
                            'TTL مکالمه مرحله‌ای',
                            30,
                            86400,
                            'ثانیه'
                        ),
                    'max_input_length' =>
                        $this->integer(
                            'حداکثر طول ورودی',
                            100,
                            3500,
                            'کاراکتر'
                        ),
                    'default_password_length' =>
                        $this->integer(
                            'طول پیش‌فرض رمز',
                            8,
                            128,
                            'کاراکتر'
                        ),
                ],
                2000
            ),
            ...$this->module(
                'settings',
                'تنظیمات کاربران',
                [
                    'state_ttl' =>
                        $this->integer(
                            'TTL مکالمه مرحله‌ای',
                            30,
                            86400,
                            'ثانیه'
                        ),
                    'default_password_length' =>
                        $this->integer(
                            'طول پیش‌فرض رمز',
                            8,
                            128,
                            'کاراکتر'
                        ),
                ],
                null
            ),
            ...$this->module(
                'admin',
                'مدیریت تلگرام',
                [
                    'state_ttl' =>
                        $this->integer(
                            'TTL عملیات مرحله‌ای',
                            60,
                            86400,
                            'ثانیه'
                        ),
                    'broadcast_batch_size' =>
                        $this->integer(
                            'اندازه Batch ارسال',
                            1,
                            20,
                            'گیرنده'
                        ),
                    'max_broadcast_length' =>
                        $this->integer(
                            'حداکثر طول Broadcast',
                            100,
                            3500,
                            'کاراکتر'
                        ),
                ],
                null
            ),
        ];
    }

    public function validate(
        string $key,
        mixed $rawValue
    ): bool|int|float|string {
        $definition = $this->definitions()[$key]
            ?? null;

        if ($definition === null) {
            throw new InvalidArgumentException(
                'این تنظیم از پنل قابل‌ویرایش نیست.'
            );
        }

        if ($definition['type'] === 'bool') {
            $normalized = is_string($rawValue)
                ? mb_strtolower(trim($rawValue))
                : $rawValue;

            return match ($normalized) {
                true, 1, '1', 'true', 'on', 'yes' => true,
                false, 0, '0', 'false', 'off', 'no' => false,
                default => throw new InvalidArgumentException(
                    'مقدار بولی معتبر نیست.'
                ),
            };
        }

        if ($definition['type'] === 'float') {
            $value = is_string($rawValue)
                ? trim($rawValue)
                : $rawValue;

            if (!is_numeric($value)) {
                throw new InvalidArgumentException(
                    'مقدار باید عددی باشد.'
                );
            }

            $number = (float) $value;
            $minimum = (float) ($definition['min'] ?? -PHP_FLOAT_MAX);
            $maximum = (float) ($definition['max'] ?? PHP_FLOAT_MAX);

            if (
                !is_finite($number)
                || $number < $minimum
                || $number > $maximum
            ) {
                throw new InvalidArgumentException(
                    "مقدار باید بین {$minimum} و {$maximum} باشد."
                );
            }

            return $number;
        }

        if ($definition['type'] === 'int') {
            $value = is_string($rawValue)
                ? trim($rawValue)
                : $rawValue;

            if (
                filter_var(
                    $value,
                    FILTER_VALIDATE_INT
                ) === false
            ) {
                throw new InvalidArgumentException(
                    'مقدار باید عدد صحیح باشد.'
                );
            }

            $integer = (int) $value;
            $minimum = $definition['min']
                ?? PHP_INT_MIN;
            $maximum = $definition['max']
                ?? PHP_INT_MAX;

            if (
                $integer < $minimum
                || $integer > $maximum
            ) {
                throw new InvalidArgumentException(
                    "مقدار باید بین {$minimum} و {$maximum} باشد."
                );
            }

            return $integer;
        }

        $value = trim((string) $rawValue);

        if (mb_strlen($value) > 500) {
            throw new InvalidArgumentException(
                'مقدار بیش از حد طولانی است.'
            );
        }

        return $value;
    }

    /**
     * @param array<string, array{
     *     label: string,
     *     type: string,
     *     help: string,
     *     min?: int,
     *     max?: int
     * }> $extra
     *
     * @return array<string, array{
     *     group: string,
     *     label: string,
     *     type: string,
     *     help: string,
     *     min?: int,
     *     max?: int
     * }>
     */
    private function module(
        string $module,
        string $group,
        array $extra,
        ?int $rateMaximum
    ): array {
        $prefix = 'modules.' . $module . '.';

        $definitions = [
            $prefix . 'enabled' => [
                'group' => $group,
                'label' => 'فعال بودن ماژول',
                'type' => 'bool',
                'help' => 'از درخواست بعدی اعمال می‌شود.',
            ],
        ];

        foreach ($extra as $suffix => $definition) {
            $definitions[$prefix . $suffix] = [
                'group' => $group,
                ...$definition,
            ];
        }

        if ($rateMaximum !== null) {
            $definitions[
                $prefix
                . 'rate_limit.max_attempts'
            ] = [
                'group' => $group,
                ...$this->integer(
                    'حداکثر درخواست',
                    1,
                    $rateMaximum,
                    'درخواست در هر پنجره'
                ),
            ];

            $definitions[
                $prefix
                . 'rate_limit.window_seconds'
            ] = [
                'group' => $group,
                ...$this->integer(
                    'پنجره Rate Limit',
                    1,
                    86400,
                    'ثانیه'
                ),
            ];
        }

        return $definitions;
    }

    /**
     * @return array{
     *     label: string,
     *     type: string,
     *     min: int,
     *     max: int,
     *     help: string
     * }
     */
    /**
     * @return array{
     *     label: string,
     *     type: string,
     *     min: float,
     *     max: float,
     *     help: string
     * }
     */
    private function number(
        string $label,
        float $minimum,
        float $maximum,
        string $help = 'عدد اعشاری'
    ): array {
        return [
            'label' => $label,
            'type' => 'float',
            'min' => $minimum,
            'max' => $maximum,
            'help' => $help,
        ];
    }

    private function integer(
        string $label,
        int $minimum,
        int $maximum,
        string $help
    ): array {
        return [
            'label' => $label,
            'type' => 'int',
            'min' => $minimum,
            'max' => $maximum,
            'help' => $help,
        ];
    }
}
