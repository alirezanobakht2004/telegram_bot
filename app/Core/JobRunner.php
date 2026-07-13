<?php

declare(strict_types=1);

namespace SmartToolbox\Core;

use Closure;
use JsonException;
use PDO;
use RuntimeException;
use Throwable;

final class JobRunner
{
    /**
     * @var array<string, Closure>
     */
    private array $handlers = [];

    public function __construct(
        private readonly PDO $pdo,
        private readonly JobQueue $queue,
        private readonly JobLock $lock,
        private readonly DeadLetterQueue $deadLetters,
        private readonly ?UsageTracker $usageTracker = null,
        private readonly string $logFile = ''
    ) {
    }

    /**
     * @param callable(array<string, mixed>, array<string, mixed>): mixed $handler
     */
    public function register(
        string $jobType,
        callable $handler
    ): self {
        $jobType = mb_strtolower(trim($jobType));

        if (
            $jobType === ''
            || preg_match('/^[a-z0-9_.:-]{1,120}$/', $jobType) !== 1
        ) {
            throw new RuntimeException(
                'Job handler type is invalid.'
            );
        }

        $this->handlers[$jobType] = Closure::fromCallable($handler);

        return $this;
    }

    /**
     * @return array{
     *     status: string,
     *     claimed: int,
     *     succeeded: int,
     *     failed: int,
     *     retried: int,
     *     dead_lettered: int
     * }
     */
    public function run(
        int $batchSize,
        int $lockTtlSeconds,
        int $staleAfterSeconds,
        int $retryBaseSeconds
    ): array {
        $workerId = gethostname()
            . ':'
            . getmypid()
            . ':'
            . bin2hex(random_bytes(4));

        $result = [
            'status' => 'completed',
            'claimed' => 0,
            'succeeded' => 0,
            'failed' => 0,
            'retried' => 0,
            'dead_lettered' => 0,
        ];

        if (!$this->lock->acquire(
            'global-job-runner',
            $workerId,
            $lockTtlSeconds
        )) {
            $result['status'] = 'skipped';
            $this->recordRun($workerId, $result, null);

            return $result;
        }

        $runId = $this->startRun($workerId);

        try {
            $jobs = $this->queue->claim(
                $batchSize,
                $workerId,
                $staleAfterSeconds
            );

            $result['claimed'] = count($jobs);

            foreach ($jobs as $job) {
                $jobType = (string) ($job['job_type'] ?? '');
                $handler = $this->handlers[$jobType] ?? null;
                $span = $this->usageTracker?->start(
                    module: 'jobs',
                    action: $jobType !== '' ? $jobType : 'unknown',
                    inputKind: 'job',
                    metadata: [
                        'job_id' => (int) ($job['id'] ?? 0),
                        'attempt' => (int) ($job['attempts'] ?? 0),
                    ]
                );

                try {
                    if ($handler === null) {
                        throw new RuntimeException(
                            'No job handler is registered for type: '
                            . $jobType
                        );
                    }

                    $payload = $this->decodePayload(
                        (string) ($job['payload_json'] ?? '{}')
                    );

                    $handler($payload, $job);
                    $this->queue->complete((int) $job['id']);
                    $result['succeeded']++;
                    $span?->success();
                } catch (Throwable $exception) {
                    $span?->failure($exception);
                    $attempts = (int) ($job['attempts'] ?? 1);
                    $maxAttempts = max(1, (int) ($job['max_attempts'] ?? 3));

                    if ($attempts >= $maxAttempts) {
                        $this->deadLetters->move(
                            $job,
                            $exception->getMessage()
                        );
                        $result['dead_lettered']++;
                    } else {
                        $delay = min(
                            86400,
                            max(1, $retryBaseSeconds)
                            * (2 ** max(0, $attempts - 1))
                        );

                        $this->queue->retry(
                            (int) $job['id'],
                            $exception->getMessage(),
                            $delay
                        );
                        $result['retried']++;
                    }

                    $result['failed']++;
                    $this->log($jobType, $exception);
                }

                $this->lock->refresh(
                    'global-job-runner',
                    $workerId,
                    $lockTtlSeconds
                );
            }

            $this->completeRun($runId, $result, null);

            return $result;
        } catch (Throwable $exception) {
            $result['status'] = 'failed';
            $this->completeRun(
                $runId,
                $result,
                $exception->getMessage()
            );
            $this->log('runner', $exception);

            throw $exception;
        } finally {
            $this->lock->release(
                'global-job-runner',
                $workerId
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decodePayload(string $json): array
    {
        try {
            $payload = json_decode(
                $json,
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException $exception) {
            throw new RuntimeException(
                'Job payload contains invalid JSON.',
                previous: $exception
            );
        }

        if (!is_array($payload)) {
            throw new RuntimeException(
                'Job payload must be a JSON object.'
            );
        }

        return $payload;
    }

    private function startRun(string $workerId): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO job_runs (
                worker_id,
                status,
                started_at
             ) VALUES (
                :worker_id,
                :status,
                :started_at
             )'
        );

        $statement->execute([
            'worker_id' => $workerId,
            'status' => 'running',
            'started_at' => date(DATE_ATOM),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array{
     *     status: string,
     *     claimed: int,
     *     succeeded: int,
     *     failed: int,
     *     retried: int,
     *     dead_lettered: int
     * } $result
     */
    private function completeRun(
        int $runId,
        array $result,
        ?string $error
    ): void {
        $statement = $this->pdo->prepare(
            'UPDATE job_runs
             SET
                status = :status,
                claimed_count = :claimed_count,
                succeeded_count = :succeeded_count,
                failed_count = :failed_count,
                retried_count = :retried_count,
                dead_lettered_count = :dead_lettered_count,
                completed_at = :completed_at,
                error_message = :error_message
             WHERE id = :id'
        );

        $statement->execute([
            'status' => $result['status'],
            'claimed_count' => $result['claimed'],
            'succeeded_count' => $result['succeeded'],
            'failed_count' => $result['failed'],
            'retried_count' => $result['retried'],
            'dead_lettered_count' => $result['dead_lettered'],
            'completed_at' => date(DATE_ATOM),
            'error_message' => $error !== null
                ? mb_substr($error, 0, 1000)
                : null,
            'id' => $runId,
        ]);
    }

    /**
     * @param array{
     *     status: string,
     *     claimed: int,
     *     succeeded: int,
     *     failed: int,
     *     retried: int,
     *     dead_lettered: int
     * } $result
     */
    private function recordRun(
        string $workerId,
        array $result,
        ?string $error
    ): void {
        $runId = $this->startRun($workerId);
        $this->completeRun($runId, $result, $error);
    }

    private function log(
        string $operation,
        Throwable $exception
    ): void {
        if ($this->logFile === '') {
            return;
        }

        $directory = dirname($this->logFile);

        if (!is_dir($directory)) {
            @mkdir($directory, 0700, true);
        }

        $entry = sprintf(
            "[%s] [operation:%s] %s\n%s\n\n",
            date(DATE_ATOM),
            str_replace(
                ["\r", "\n"],
                ' ',
                mb_substr($operation, 0, 150)
            ),
            $exception->getMessage(),
            $exception->getTraceAsString()
        );

        @file_put_contents(
            $this->logFile,
            $entry,
            FILE_APPEND | LOCK_EX
        );
    }
}
