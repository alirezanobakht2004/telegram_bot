<?php

declare(strict_types=1);

use SmartToolbox\Core\AnalyticsMaintenance;
use SmartToolbox\Core\Database;
use SmartToolbox\Core\DeadLetterQueue;
use SmartToolbox\Core\FeatureRegistry;
use SmartToolbox\Core\JobLock;
use SmartToolbox\Core\JobQueue;
use SmartToolbox\Core\JobRunner;
use SmartToolbox\Core\RuntimeSettings;
use SmartToolbox\Core\TemporaryFileManager;
use SmartToolbox\Core\UsageTracker;

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
