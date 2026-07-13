<?php

declare(strict_types=1);

namespace SmartToolbox\Core;

use PDO;
use RuntimeException;
use Throwable;

final class DeadLetterQueue
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly JobQueue $queue
    ) {
    }

    /**
     * @param array<string, mixed> $job
     */
    public function move(array $job, string $error): int
    {
        $jobId = (int) ($job['id'] ?? 0);

        if ($jobId <= 0) {
            throw new RuntimeException(
                'Dead-letter source job ID is invalid.'
            );
        }

        $this->pdo->beginTransaction();

        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO dead_letter_jobs (
                    original_job_id,
                    job_type,
                    unique_key,
                    payload_json,
                    attempts,
                    error_message,
                    failed_at
                 ) VALUES (
                    :original_job_id,
                    :job_type,
                    :unique_key,
                    :payload_json,
                    :attempts,
                    :error_message,
                    :failed_at
                 )'
            );

            $statement->execute([
                'original_job_id' => $jobId,
                'job_type' => (string) ($job['job_type'] ?? 'unknown'),
                'unique_key' => $job['unique_key'] ?? null,
                'payload_json' => (string) ($job['payload_json'] ?? '{}'),
                'attempts' => (int) ($job['attempts'] ?? 0),
                'error_message' => mb_substr($error, 0, 2000),
                'failed_at' => date(DATE_ATOM),
            ]);

            $deadId = (int) $this->pdo->lastInsertId();
            $this->queue->markDead($jobId, $error);
            $this->pdo->commit();

            return $deadId;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    public function replay(int $deadLetterId): int
    {
        $this->pdo->beginTransaction();

        try {
            $statement = $this->pdo->prepare(
                'SELECT *
                 FROM dead_letter_jobs
                 WHERE id = :id
                 LIMIT 1'
            );

            $statement->execute([
                'id' => $deadLetterId,
            ]);

            $row = $statement->fetch(
                PDO::FETCH_ASSOC
            );

            if (!is_array($row)) {
                throw new RuntimeException(
                    'Dead-letter job was not found.'
                );
            }

            if (
                is_string($row['replayed_at'] ?? null)
                && $row['replayed_at'] !== ''
            ) {
                throw new RuntimeException(
                    'Dead-letter job has already been replayed.'
                );
            }

            $payload = json_decode(
                (string) $row['payload_json'],
                true
            );

            if (!is_array($payload)) {
                $payload = [];
            }

            $jobId = $this->queue->enqueue(
                jobType: (string) $row['job_type'],
                payload: $payload,
                maxAttempts: max(
                    1,
                    (int) $row['attempts']
                )
            );

            $update = $this->pdo->prepare(
                'UPDATE dead_letter_jobs
                 SET
                    replayed_at = :replayed_at,
                    replay_job_id = :replay_job_id
                 WHERE id = :id
                   AND replayed_at IS NULL'
            );

            $update->execute([
                'replayed_at' => date(DATE_ATOM),
                'replay_job_id' => $jobId,
                'id' => $deadLetterId,
            ]);

            if ($update->rowCount() !== 1) {
                throw new RuntimeException(
                    'Dead-letter replay state changed concurrently.'
                );
            }

            $this->pdo->commit();

            return $jobId;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }
}
