<?php

declare(strict_types=1);

namespace SmartToolbox\Core;

use JsonException;
use PDO;
use RuntimeException;
use Throwable;

final class JobQueue
{
    public function __construct(
        private readonly PDO $pdo
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function enqueue(
        string $jobType,
        array $payload = [],
        ?int $availableAt = null,
        int $maxAttempts = 3,
        int $priority = 0,
        ?string $uniqueKey = null
    ): int {
        $jobType = $this->normalizeType($jobType);
        $availableAt ??= time();
        $maxAttempts = max(1, min(50, $maxAttempts));
        $priority = max(-1000, min(1000, $priority));

        if ($uniqueKey !== null) {
            $uniqueKey = trim($uniqueKey);
            $uniqueKey = $uniqueKey !== ''
                ? mb_substr($uniqueKey, 0, 255)
                : null;
        }

        try {
            $payloadJson = json_encode(
                $payload,
                JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
            );
        } catch (JsonException $exception) {
            throw new RuntimeException(
                'Job payload could not be encoded.',
                previous: $exception
            );
        }

        $now = date(DATE_ATOM);

        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO job_queue (
                    job_type,
                    unique_key,
                    payload_json,
                    status,
                    priority,
                    available_at,
                    attempts,
                    max_attempts,
                    created_at,
                    updated_at
                 ) VALUES (
                    :job_type,
                    :unique_key,
                    :payload_json,
                    :status,
                    :priority,
                    :available_at,
                    0,
                    :max_attempts,
                    :created_at,
                    :updated_at
                 )'
            );

            $statement->execute([
                'job_type' => $jobType,
                'unique_key' => $uniqueKey,
                'payload_json' => $payloadJson,
                'status' => 'queued',
                'priority' => $priority,
                'available_at' => $availableAt,
                'max_attempts' => $maxAttempts,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return (int) $this->pdo->lastInsertId();
        } catch (Throwable $exception) {
            if ($uniqueKey !== null) {
                $existing = $this->findByUniqueKey($uniqueKey);

                if ($existing !== null) {
                    return (int) $existing['id'];
                }
            }

            throw $exception;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function claim(
        int $limit,
        string $workerId,
        int $staleAfterSeconds = 600
    ): array {
        $limit = max(1, min(100, $limit));
        $workerId = mb_substr(trim($workerId), 0, 200);
        $staleAfterSeconds = max(60, $staleAfterSeconds);
        $now = time();

        $this->pdo->exec('BEGIN IMMEDIATE');

        try {
            $recover = $this->pdo->prepare(
                "UPDATE job_queue
                 SET
                    status = 'queued',
                    locked_by = NULL,
                    locked_at = NULL,
                    updated_at = :updated_at
                 WHERE status = 'processing'
                   AND (
                        locked_at IS NULL
                        OR locked_at <= :stale_before
                   )"
            );

            $recover->execute([
                'updated_at' => date(DATE_ATOM),
                'stale_before' => $now - $staleAfterSeconds,
            ]);

            $select = $this->pdo->prepare(
                "SELECT *
                 FROM job_queue
                 WHERE status = 'queued'
                   AND available_at <= :now
                 ORDER BY
                    priority DESC,
                    available_at ASC,
                    id ASC
                 LIMIT :limit"
            );

            $select->bindValue(':now', $now, PDO::PARAM_INT);
            $select->bindValue(':limit', $limit, PDO::PARAM_INT);
            $select->execute();

            $rows = $select->fetchAll(PDO::FETCH_ASSOC);
            $claimed = [];

            if (is_array($rows)) {
                $update = $this->pdo->prepare(
                    "UPDATE job_queue
                     SET
                        status = 'processing',
                        attempts = attempts + 1,
                        locked_by = :locked_by,
                        locked_at = :locked_at,
                        updated_at = :updated_at
                     WHERE id = :id
                       AND status = 'queued'"
                );

                foreach ($rows as $row) {
                    $update->execute([
                        'locked_by' => $workerId,
                        'locked_at' => $now,
                        'updated_at' => date(DATE_ATOM),
                        'id' => (int) $row['id'],
                    ]);

                    if ($update->rowCount() !== 1) {
                        continue;
                    }

                    $row['attempts'] = (int) $row['attempts'] + 1;
                    $row['locked_by'] = $workerId;
                    $row['locked_at'] = $now;
                    $claimed[] = $row;
                }
            }

            $this->pdo->exec('COMMIT');

            return $claimed;
        } catch (Throwable $exception) {
            try {
                $this->pdo->exec('ROLLBACK');
            } catch (Throwable) {
            }

            throw $exception;
        }
    }

    public function complete(int $jobId): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE job_queue
             SET
                status = 'completed',
                locked_by = NULL,
                locked_at = NULL,
                last_error = NULL,
                updated_at = :updated_at,
                completed_at = :completed_at
             WHERE id = :id
               AND status = 'processing'"
        );

        $now = date(DATE_ATOM);

        $statement->execute([
            'updated_at' => $now,
            'completed_at' => $now,
            'id' => $jobId,
        ]);
    }

    public function retry(
        int $jobId,
        string $error,
        int $delaySeconds
    ): void {
        $statement = $this->pdo->prepare(
            "UPDATE job_queue
             SET
                status = 'queued',
                available_at = :available_at,
                locked_by = NULL,
                locked_at = NULL,
                last_error = :last_error,
                updated_at = :updated_at
             WHERE id = :id
               AND status = 'processing'"
        );

        $statement->execute([
            'available_at' => time() + max(1, $delaySeconds),
            'last_error' => mb_substr($error, 0, 1000),
            'updated_at' => date(DATE_ATOM),
            'id' => $jobId,
        ]);
    }

    public function markDead(
        int $jobId,
        string $error
    ): void {
        $statement = $this->pdo->prepare(
            "UPDATE job_queue
             SET
                status = 'dead',
                locked_by = NULL,
                locked_at = NULL,
                last_error = :last_error,
                updated_at = :updated_at,
                completed_at = :completed_at
             WHERE id = :id"
        );

        $now = date(DATE_ATOM);

        $statement->execute([
            'last_error' => mb_substr($error, 0, 1000),
            'updated_at' => $now,
            'completed_at' => $now,
            'id' => $jobId,
        ]);
    }

    public function cancel(int $jobId): bool
    {
        $statement = $this->pdo->prepare(
            "UPDATE job_queue
             SET
                status = 'cancelled',
                locked_by = NULL,
                locked_at = NULL,
                updated_at = :updated_at,
                completed_at = :completed_at
             WHERE id = :id
               AND status IN ('queued', 'failed')"
        );

        $now = date(DATE_ATOM);

        $statement->execute([
            'updated_at' => $now,
            'completed_at' => $now,
            'id' => $jobId,
        ]);

        return $statement->rowCount() > 0;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $jobId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT *
             FROM job_queue
             WHERE id = :id
             LIMIT 1'
        );

        $statement->execute(['id' => $jobId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row)
            ? $row
            : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findByUniqueKey(string $uniqueKey): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT *
             FROM job_queue
             WHERE unique_key = :unique_key
             LIMIT 1'
        );

        $statement->execute([
            'unique_key' => $uniqueKey,
        ]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row)
            ? $row
            : null;
    }

    private function normalizeType(string $jobType): string
    {
        $jobType = mb_strtolower(trim($jobType));

        if (
            $jobType === ''
            || preg_match('/^[a-z0-9_.:-]{1,120}$/', $jobType) !== 1
        ) {
            throw new RuntimeException(
                'Job type is invalid.'
            );
        }

        return $jobType;
    }
}
