<?php

declare(strict_types=1);

use SmartToolbox\Core\AnalyticsMaintenance;
use SmartToolbox\Core\ApiMetricsTracker;
use SmartToolbox\Core\CacheMetricsTracker;
use SmartToolbox\Core\Database;
use SmartToolbox\Core\DeadLetterQueue;
use SmartToolbox\Core\FeatureRegistry;
use SmartToolbox\Core\FileCache;
use SmartToolbox\Core\JobLock;
use SmartToolbox\Core\JobQueue;
use SmartToolbox\Core\JobRunner;
use SmartToolbox\Core\HttpClient;
use SmartToolbox\Core\RuntimeSettings;
use SmartToolbox\Core\SsrfGuard;
use SmartToolbox\Core\TelegramClient;
use SmartToolbox\Core\TemporaryFileManager;
use SmartToolbox\Core\UsageTracker;
use SmartToolbox\Modules\Alerts\AlertDataProvider;
use SmartToolbox\Modules\Alerts\AlertRepository;
use SmartToolbox\Modules\Alerts\AlertWorker;
use SmartToolbox\Modules\Alerts\ConditionEvaluator;
use SmartToolbox\Modules\Alerts\ScheduleCalculator;
use SmartToolbox\Modules\Alerts\SubscriptionRepository;
use SmartToolbox\Modules\Alerts\SubscriptionWorker;
use SmartToolbox\Modules\Countries\CountriesDevProvider;
use SmartToolbox\Modules\Currency\FrankfurterProvider;
use SmartToolbox\Modules\GitHub\GitHubClient;
use SmartToolbox\Modules\GitHub\GitHubReleaseWatchService;
use SmartToolbox\Modules\GitHub\GitHubWatchRepository;
use SmartToolbox\Modules\GroupManagement\GroupModerationService;
use SmartToolbox\Modules\GroupManagement\GroupRepository;
use SmartToolbox\Modules\GroupManagement\GroupWorker;
use SmartToolbox\Modules\Monitoring\MonitorProbe;
use SmartToolbox\Modules\Monitoring\MonitorRepository;
use SmartToolbox\Modules\Monitoring\MonitorWorker;

$rootPath = dirname(__DIR__);

try {
    $config = require $rootPath
        . '/bootstrap/app.php';

    $pdo = Database::connect(
        (string) $config->get(
            'database.path'
        )
    );

    $runtime = new RuntimeSettings(
        $config,
        $pdo
    );

    $features = new FeatureRegistry(
        $pdo,
        (array) $config->get(
            'features.defaults',
            []
        )
    );

    if (
        !(bool) $runtime->get(
            'jobs.enabled',
            true
        )
        || !$features->isEnabled(
            'generic_jobs'
        )
    ) {
        echo json_encode(
            [
                'status' => 'disabled',
                'claimed' => 0,
                'succeeded' => 0,
                'failed' => 0,
                'retried' => 0,
                'dead_lettered' => 0,
            ],
            JSON_PRETTY_PRINT
            | JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
        ) . PHP_EOL;

        exit(0);
    }

    $usageTracker = new UsageTracker(
        pdo: $pdo,
        enabled: (bool) $runtime->get(
            'analytics.enabled',
            true
        ),
        sampleRate: (int) $runtime->get(
            'analytics.sample_rate',
            100
        )
    );

    $apiMetrics = new ApiMetricsTracker(
        pdo: $pdo,
        enabled: (bool) $runtime->get(
            'analytics.api_metrics.enabled',
            true
        ),
        sampleRate: (int) $runtime->get(
            'analytics.api_metrics.sample_rate',
            100
        )
    );

    $cacheMetrics = new CacheMetricsTracker(
        pdo: $pdo,
        enabled: (bool) $runtime->get(
            'analytics.cache_metrics.enabled',
            true
        ),
        sampleRate: (int) $runtime->get(
            'analytics.cache_metrics.sample_rate',
            100
        )
    );

    $http = new HttpClient(
        userAgent: (string) $config->get(
            'http.user_agent',
            'SmartToolboxFaBot/1.0'
        ),
        connectTimeout: (int) $runtime->get(
            'http.connect_timeout',
            4
        ),
        timeout: (int) $runtime->get(
            'http.timeout',
            8
        ),
        maxResponseBytes: (int) $runtime->get(
            'http.max_response_bytes',
            1048576
        ),
        metrics: $apiMetrics,
        ssrfGuard: new SsrfGuard(
            allowHttp: false,
            allowedPorts: [443]
        ),
        maxRedirects: (int) $runtime->get(
            'http.max_redirects',
            3
        )
    );

    $cache = new FileCache(
        directory: (string) $config->get(
            'paths.cache'
        ) . '/api',
        metrics: $cacheMetrics
    );

    $telegram = new TelegramClient(
        token: (string) $config->get(
            'telegram.token'
        ),
        metrics: $apiMetrics
    );

    $queue = new JobQueue($pdo);
    $lock = new JobLock($pdo);
    $deadLetters = new DeadLetterQueue(
        $pdo,
        $queue
    );

    $runner = new JobRunner(
        pdo: $pdo,
        queue: $queue,
        lock: $lock,
        deadLetters: $deadLetters,
        usageTracker: $usageTracker,
        logFile: (string) $config->get(
            'paths.logs'
        ) . '/jobs.log'
    );

    $groupRepository = new GroupRepository(
        pdo: $pdo,
        defaultSettings: (array) $runtime->get(
            'modules.group_management.defaults',
            []
        )
    );

    $groupWorker = new GroupWorker(
        repository: $groupRepository,
        moderation: new GroupModerationService(
            telegram: $telegram,
            repository: $groupRepository
        ),
        logFile: (string) $config->get(
            'paths.logs'
        ) . '/group_management.log'
    );

    $runner->register(
        'group_management.scan',
        static function () use (
            $groupWorker,
            $runtime
        ): void {
            $groupWorker->run(
                batchSize: (int) $runtime->get(
                    'modules.group_management.worker.batch_size',
                    20
                ),
                retentionDays: (int) $runtime->get(
                    'modules.group_management.retention_days',
                    180
                )
            );
        }
    );

    $runner->register(
        'analytics.cleanup',
        static function () use (
            $pdo,
            $runtime
        ): void {
            (new AnalyticsMaintenance($pdo))->cleanup(
                usageDays: (int) $runtime->get(
                    'analytics.retention.usage_days',
                    90
                ),
                commandDays: (int) $runtime->get(
                    'analytics.retention.command_days',
                    30
                ),
                apiDays: (int) $runtime->get(
                    'analytics.retention.api_days',
                    30
                ),
                cacheDays: (int) $runtime->get(
                    'analytics.retention.cache_days',
                    30
                ),
                jobRunDays: (int) $runtime->get(
                    'analytics.retention.job_run_days',
                    30
                ),
                deadLetterDays: (int) $runtime->get(
                    'analytics.retention.dead_letter_days',
                    90
                ),
                maxUsageRows: (int) $runtime->get(
                    'analytics.retention.max_usage_rows',
                    250000
                )
            );
        }
    );

    $githubClient = new GitHubClient(
        cache: $cache,
        userAgent: (string) $config->get(
            'http.user_agent',
            'SmartToolboxFaBot/1.0'
        ),
        token: (string) $config->get(
            'modules.github.token',
            ''
        ),
        apiVersion: (string) $runtime->get(
            'modules.github.api_version',
            '2026-03-10'
        ),
        cacheTtl: (int) $runtime->get(
            'modules.github.cache_ttl',
            1800
        ),
        releaseCacheTtl: (int) $runtime->get(
            'modules.github.release_cache_ttl',
            900
        ),
        connectTimeout: (int) $runtime->get(
            'http.connect_timeout',
            4
        ),
        timeout: (int) $runtime->get(
            'http.timeout',
            8
        ),
        maxResponseBytes: (int) $runtime->get(
            'http.max_response_bytes',
            1048576
        )
    );

    $releaseWatchService =
        new GitHubReleaseWatchService(
            client: $githubClient,
            repository:
                new GitHubWatchRepository($pdo),
            telegram: $telegram,
            logFile: (string) $config->get(
                'paths.logs'
            ) . '/github.log'
        );

    $runner->register(
        'github.release_watch.scan',
        static function () use (
            $releaseWatchService,
            $runtime
        ): void {
            $releaseWatchService->scan(
                (int) $runtime->get(
                    'modules.github.watch_scan_batch_size',
                    20
                )
            );
        }
    );

    $scheduleCalculator = new ScheduleCalculator();
    $alertDataProvider = new AlertDataProvider(
        http: $http,
        cache: $cache,
        currency: new FrankfurterProvider(
            http: $http,
            baseUrl: (string) $config->get(
                'modules.currency.provider.base_url'
            )
        ),
        countries: new CountriesDevProvider(
            http: $http,
            baseUrl: (string) $config->get(
                'modules.countries.provider.base_url'
            )
        ),
        geocodingEndpoint: (string) $config->get(
            'modules.weather.providers.geocoding_endpoint'
        ),
        forecastEndpoint: (string) $config->get(
            'modules.weather.providers.forecast_endpoint'
        ),
        weatherCacheTtl: (int) $runtime->get(
            'modules.alerts.weather_cache_ttl',
            120
        ),
        currencyCacheTtl: (int) $runtime->get(
            'modules.alerts.currency_cache_ttl',
            900
        ),
        countryCacheTtl: (int) $runtime->get(
            'modules.alerts.country_cache_ttl',
            21600
        )
    );

    $alertWorker = new AlertWorker(
        repository: new AlertRepository($pdo),
        data: $alertDataProvider,
        evaluator: new ConditionEvaluator(),
        telegram: $telegram,
        logFile: (string) $config->get(
            'paths.logs'
        ) . '/alerts.log'
    );

    $subscriptionWorker = new SubscriptionWorker(
        repository: new SubscriptionRepository($pdo),
        data: $alertDataProvider,
        schedule: $scheduleCalculator,
        telegram: $telegram,
        logFile: (string) $config->get(
            'paths.logs'
        ) . '/alerts.log'
    );

    $monitorGuard = new SsrfGuard(
        allowHttp: true,
        allowedPorts: (array) $runtime->get(
            'modules.monitoring.http.allowed_ports',
            [80, 443]
        )
    );
    $monitorWorker = new MonitorWorker(
        repository: new MonitorRepository($pdo),
        probe: new MonitorProbe(
            userAgent: (string) $config->get(
                'http.user_agent',
                'SmartToolboxFaBot/1.0'
            ),
            guard: $monitorGuard,
            connectTimeout: (int) $runtime->get(
                'modules.monitoring.http.connect_timeout',
                4
            ),
            timeout: (int) $runtime->get(
                'modules.monitoring.http.timeout',
                8
            ),
            maxResponseBytes: (int) $runtime->get(
                'modules.monitoring.http.max_response_bytes',
                131072
            ),
            maxRedirects: (int) $runtime->get(
                'modules.monitoring.http.max_redirects',
                3
            )
        ),
        schedule: $scheduleCalculator,
        telegram: $telegram,
        logFile: (string) $config->get(
            'paths.logs'
        ) . '/monitoring.log'
    );

    $runner->register(
        'alerts.scan',
        static function () use (
            $alertWorker,
            $runtime
        ): void {
            $alertWorker->scan(
                (int) $runtime->get(
                    'modules.alerts.scan_batch_size',
                    20
                ),
                (int) $runtime->get(
                    'modules.alerts.notification_retention_days',
                    90
                )
            );
        }
    );

    $runner->register(
        'subscriptions.scan',
        static function () use (
            $subscriptionWorker,
            $runtime
        ): void {
            $subscriptionWorker->scan(
                (int) $runtime->get(
                    'modules.alerts.subscription_batch_size',
                    20
                )
            );
        }
    );

    $runner->register(
        'monitoring.scan',
        static function () use (
            $monitorWorker,
            $runtime
        ): void {
            $monitorWorker->scan(
                limit: (int) $runtime->get(
                    'modules.monitoring.scan_batch_size',
                    10
                ),
                failureThreshold: (int) $runtime->get(
                    'modules.monitoring.failure_threshold',
                    2
                ),
                recoveryThreshold: (int) $runtime->get(
                    'modules.monitoring.recovery_threshold',
                    1
                ),
                retentionDays: (int) $runtime->get(
                    'modules.monitoring.retention_days',
                    90
                )
            );
        }
    );

    $runner->register(
        'monitoring.daily_reports',
        static function () use (
            $monitorWorker,
            $runtime
        ): void {
            $monitorWorker->dailyReports(
                (int) $runtime->get(
                    'modules.monitoring.report_batch_size',
                    10
                )
            );
        }
    );

    $runner->register(
        'temporary.cleanup',
        static function () use (
            $config,
            $runtime
        ): void {
            (new TemporaryFileManager(
                (string) $config->get(
                    'paths.temporary'
                )
            ))->cleanup(
                (int) $runtime->get(
                    'jobs.temporary_file_max_age_seconds',
                    3600
                )
            );
        }
    );

    $defaultMaxAttempts = (int) $runtime->get(
        'jobs.default_max_attempts',
        3
    );

    $queue->enqueue(
        jobType: 'analytics.cleanup',
        maxAttempts: $defaultMaxAttempts,
        uniqueKey: 'maintenance:analytics:'
            . date('Y-m-d')
    );

    if (
        (bool) $runtime->get(
            'modules.github.enabled',
            true
        )
    ) {
        $watchInterval = max(
            300,
            (int) $runtime->get(
                'modules.github.watch_scan_interval_seconds',
                900
            )
        );

        $queue->enqueue(
            jobType:
                'github.release_watch.scan',
            maxAttempts:
                $defaultMaxAttempts,
            uniqueKey:
                'github-release-watch:'
                . intdiv(
                    time(),
                    $watchInterval
                )
        );
    }

    if (
        (bool) $runtime->get(
            'modules.alerts.enabled',
            true
        )
    ) {
        if ($features->isEnabled('smart_alerts')) {
            $alertInterval = max(
                60,
                (int) $runtime->get(
                    'modules.alerts.scan_job_interval_seconds',
                    60
                )
            );

            $queue->enqueue(
                jobType: 'alerts.scan',
                maxAttempts: $defaultMaxAttempts,
                uniqueKey: 'alerts-scan:'
                    . intdiv(time(), $alertInterval)
            );
        }

        if (
            $features->isEnabled(
                'scheduled_subscriptions'
            )
        ) {
            $subscriptionInterval = max(
                60,
                (int) $runtime->get(
                    'modules.alerts.subscription_job_interval_seconds',
                    60
                )
            );

            $queue->enqueue(
                jobType: 'subscriptions.scan',
                maxAttempts: $defaultMaxAttempts,
                uniqueKey: 'subscriptions-scan:'
                    . intdiv(
                        time(),
                        $subscriptionInterval
                    )
            );
        }
    }

    if (
        (bool) $runtime->get(
            'modules.monitoring.enabled',
            true
        )
        && $features->isEnabled('site_monitoring')
    ) {
        $monitorInterval = max(
            60,
            (int) $runtime->get(
                'modules.monitoring.scan_job_interval_seconds',
                60
            )
        );
        $reportInterval = max(
            60,
            (int) $runtime->get(
                'modules.monitoring.report_job_interval_seconds',
                60
            )
        );

        $queue->enqueue(
            jobType: 'monitoring.scan',
            maxAttempts: $defaultMaxAttempts,
            uniqueKey: 'monitoring-scan:'
                . intdiv(time(), $monitorInterval)
        );
        $queue->enqueue(
            jobType: 'monitoring.daily_reports',
            maxAttempts: $defaultMaxAttempts,
            uniqueKey: 'monitoring-reports:'
                . intdiv(time(), $reportInterval)
        );
    }

    if (
        (bool) $runtime->get(
            'modules.group_management.enabled',
            true
        )
        && $features->isEnabled(
            'group_management'
        )
    ) {
        $groupInterval = max(
            60,
            (int) $runtime->get(
                'modules.group_management.worker.scan_job_interval_seconds',
                60
            )
        );

        $queue->enqueue(
            jobType: 'group_management.scan',
            maxAttempts: $defaultMaxAttempts,
            uniqueKey: 'group-management-scan:'
                . intdiv(
                    time(),
                    $groupInterval
                )
        );
    }

    $queue->enqueue(
        jobType: 'temporary.cleanup',
        maxAttempts: $defaultMaxAttempts,
        uniqueKey: 'maintenance:temporary:'
            . date('Y-m-d-H')
    );

    $result = $runner->run(
        batchSize: (int) $runtime->get(
            'jobs.batch_size',
            10
        ),
        lockTtlSeconds: (int) $runtime->get(
            'jobs.lock_ttl_seconds',
            180
        ),
        staleAfterSeconds: (int) $runtime->get(
            'jobs.stale_after_seconds',
            600
        ),
        retryBaseSeconds: (int) $runtime->get(
            'jobs.retry_base_seconds',
            30
        )
    );

    echo json_encode(
        $result,
        JSON_PRETTY_PRINT
        | JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
    ) . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(
        STDERR,
        sprintf(
            "[%s] Job worker failed: %s\n",
            date(DATE_ATOM),
            $exception->getMessage()
        )
    );

    exit(1);
}
