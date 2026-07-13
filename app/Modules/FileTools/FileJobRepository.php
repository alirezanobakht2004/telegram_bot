<?php

declare(strict_types=1);

namespace SmartToolbox\Modules\FileTools;

use JsonException;
use PDO;
use RuntimeException;
use SmartToolbox\Core\JobQueue;
use Throwable;

final class FileJobRepository
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly JobQueue $queue,
        private readonly int $maxActivePerUser = 1,
        private readonly int $maxGlobalProcessing = 2,
        private readonly int $defaultMaxAttempts = 3,
        private readonly int $staleProcessingSeconds = 600
    ) {
    }

    /**
     * @param array<string, mixed> $source
     * @param array<string, mixed> $parameters
     */
    public function create(
        int $userId,
        int $chatId,
        ?int $requestMessageId,
        string $operation,
        string $sourceKind,
        array $source,
        array $parameters = []
    ): int {
        if ($this->activeCountForUser($userId) >= min(1, max(1, $this->maxActivePerUser))) {
            throw new FileToolException(
                'یک پردازش فایل فعال داری؛ پس از پایان یا لغو آن دوباره تلاش کن.',
                'user_active_job_limit'
            );
        }

        try {
            $parametersJson = json_encode(
                $parameters,
                JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
            );
        } catch (JsonException $exception) {
            throw new RuntimeException(
                'File job parameters could not be encoded.',
                previous: $exception
            );
        }

        $now = date(DATE_ATOM);
        $maxAttempts = max(1, min(10, $this->defaultMaxAttempts));

        $this->pdo->beginTransaction();

        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO file_jobs (
                    user_id,
                    chat_id,
                    request_message_id,
                    operation,
                    status,
                    source_kind,
                    file_id,
                    file_unique_id,
                    file_name,
                    mime_type,
                    file_size,
                    width,
                    height,
                    input_text,
                    parameters_json,
                    progress,
                    attempts,
                    max_attempts,
                    created_at,
                    updated_at
                 ) VALUES (
                    :user_id,
                    :chat_id,
                    :request_message_id,
                    :operation,
                    :status,
                    :source_kind,
                    :file_id,
                    :file_unique_id,
                    :file_name,
                    :mime_type,
                    :file_size,
                    :width,
                    :height,
                    :input_text,
                    :parameters_json,
                    0,
                    0,
                    :max_attempts,
                    :created_at,
                    :updated_at
                 )'
            );

            $statement->execute([
                'user_id' => $userId,
                'chat_id' => $chatId,
                'request_message_id' => $requestMessageId,
                'operation' => $operation,
                'status' => 'queued',
                'source_kind' => $sourceKind,
                'file_id' => $source['file_id'] ?? null,
                'file_unique_id' => $source['file_unique_id'] ?? null,
                'file_name' => isset($source['file_name'])
                    ? mb_substr((string) $source['file_name'], 0, 255)
                    : null,
                'mime_type' => isset($source['mime_type'])
                    ? mb_substr((string) $source['mime_type'], 0, 150)
                    : null,
                'file_size' => is_numeric($source['file_size'] ?? null)
                    ? (int) $source['file_size']
                    : null,
                'width' => is_numeric($source['width'] ?? null)
                    ? (int) $source['width']
                    : null,
                'height' => is_numeric($source['height'] ?? null)
                    ? (int) $source['height']
                    : null,
                'input_text' => isset($source['input_text'])
                    ? (string) $source['input_text']
                    : null,
                'parameters_json' => $parametersJson,
                'max_attempts' => $maxAttempts,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $id = (int) $this->pdo->lastInsertId();

            $this->queue->enqueue(
                jobType: 'file_tools.process',
                payload: ['file_job_id' => $id],
                maxAttempts: $maxAttempts,
                priority: 20,
                uniqueKey: 'file-job:' . $id
            );

            $this->pdo->commit();

            return $id;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            if (
                str_contains(
                    mb_strtolower($exception->getMessage()),
                    'unique constraint failed: file_jobs.user_id'
                )
            ) {
                throw new FileToolException(
                    'یک پردازش فایل فعال داری؛ ابتدا منتظر پایان آن بمان.',
                    'user_active_job_limit'
                );
            }

            throw $exception;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT *
             FROM file_jobs
             WHERE id = :id
             LIMIT 1'
        );

        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function history(int $userId, int $limit = 10): array
    {
        $statement = $this->pdo->prepare(
            'SELECT
                id,
                operation,
                status,
                file_name,
                output_name,
                output_size,
                progress,
                error_message,
                created_at,
                completed_at
             FROM file_jobs
             WHERE user_id = :user_id
             ORDER BY id DESC
             LIMIT :limit'
        );

        $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $statement->bindValue(':limit', max(1, min(50, $limit)), PDO::PARAM_INT);
        $statement->execute();
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    }

    public function cancel(int $id, int $userId): bool
    {
        $statement = $this->pdo->prepare(
            "UPDATE file_jobs
             SET
                status = 'cancelled',
                completed_at = :completed_at,
                updated_at = :updated_at,
                error_code = 'cancelled_by_user',
                error_message = 'Cancelled by user.'
             WHERE id = :id
               AND user_id = :user_id
               AND status = 'queued'"
        );

        $now = date(DATE_ATOM);

        $statement->execute([
            'completed_at' => $now,
            'updated_at' => $now,
            'id' => $id,
            'user_id' => $userId,
        ]);

        return $statement->rowCount() === 1;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function claim(int $id): ?array
    {
        $this->pdo->exec('BEGIN IMMEDIATE');
        $transactionActive = true;

        try {
            $job = $this->find($id);

            if (
                $job !== null
                && $job['status'] === 'processing'
                && strtotime((string) $job['updated_at'])
                    < time() - max(60, $this->staleProcessingSeconds)
            ) {
                $recover = $this->pdo->prepare(
                    "UPDATE file_jobs
                     SET
                        status = 'queued',
                        progress = 0,
                        updated_at = :updated_at,
                        error_code = 'stale_processing_recovered',
                        error_message = 'Recovered after a stale worker process.'
                     WHERE id = :id
                       AND status = 'processing'"
                );

                $recover->execute([
                    'updated_at' => date(DATE_ATOM),
                    'id' => $id,
                ]);

                $job = $this->find($id);
            }

            if ($job === null || $job['status'] !== 'queued') {
                $this->pdo->exec('COMMIT');
                $transactionActive = false;
                return null;
            }

            $processing = (int) $this->pdo->query(
                "SELECT COUNT(*)
                 FROM file_jobs
                 WHERE status = 'processing'"
            )->fetchColumn();

            if ($processing >= min(2, max(1, $this->maxGlobalProcessing))) {
                $this->pdo->exec('ROLLBACK');
                $transactionActive = false;

                throw new FileToolException(
                    'ظرفیت پردازش فایل سرور تکمیل است.',
                    'global_processing_limit',
                    true
                );
            }

            $statement = $this->pdo->prepare(
                "UPDATE file_jobs
                 SET
                    status = 'processing',
                    attempts = attempts + 1,
                    progress = 5,
                    started_at = COALESCE(started_at, :started_at),
                    updated_at = :updated_at,
                    error_code = NULL,
                    error_message = NULL
                 WHERE id = :id
                   AND status = 'queued'"
            );

            $now = date(DATE_ATOM);
            $statement->execute([
                'started_at' => $now,
                'updated_at' => $now,
                'id' => $id,
            ]);

            if ($statement->rowCount() !== 1) {
                $this->pdo->exec('ROLLBACK');
                $transactionActive = false;
                return null;
            }

            $this->pdo->exec('COMMIT');
            $transactionActive = false;

            return $this->find($id);
        } catch (Throwable $exception) {
            if ($transactionActive) {
                try {
                    $this->pdo->exec('ROLLBACK');
                } catch (Throwable) {
                }
            }

            throw $exception;
        }
    }

    public function progress(int $id, int $progress): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE file_jobs
             SET
                progress = :progress,
                updated_at = :updated_at
             WHERE id = :id
               AND status = :status'
        );

        $statement->execute([
            'progress' => max(0, min(100, $progress)),
            'updated_at' => date(DATE_ATOM),
            'id' => $id,
            'status' => 'processing',
        ]);
    }

    public function complete(
        int $id,
        string $outputName,
        string $outputMimeType,
        int $outputSize
    ): void {
        $statement = $this->pdo->prepare(
            "UPDATE file_jobs
             SET
                status = 'completed',
                progress = 100,
                output_name = :output_name,
                output_mime_type = :output_mime_type,
                output_size = :output_size,
                completed_at = :completed_at,
                updated_at = :updated_at
             WHERE id = :id"
        );

        $now = date(DATE_ATOM);
        $statement->execute([
            'output_name' => mb_substr($outputName, 0, 255),
            'output_mime_type' => mb_substr($outputMimeType, 0, 150),
            'output_size' => max(0, $outputSize),
            'completed_at' => $now,
            'updated_at' => $now,
            'id' => $id,
        ]);
    }

    public function fail(
        int $id,
        string $errorCode,
        string $message
    ): void {
        $statement = $this->pdo->prepare(
            "UPDATE file_jobs
             SET
                status = 'failed',
                completed_at = :completed_at,
                updated_at = :updated_at,
                error_code = :error_code,
                error_message = :error_message
             WHERE id = :id"
        );

        $now = date(DATE_ATOM);
        $statement->execute([
            'completed_at' => $now,
            'updated_at' => $now,
            'error_code' => mb_substr($errorCode, 0, 100),
            'error_message' => mb_substr($message, 0, 1000),
            'id' => $id,
        ]);
    }

    public function requeue(
        int $id,
        string $errorCode,
        string $message
    ): void {
        $statement = $this->pdo->prepare(
            "UPDATE file_jobs
             SET
                status = 'queued',
                progress = 0,
                updated_at = :updated_at,
                error_code = :error_code,
                error_message = :error_message
             WHERE id = :id
               AND status = 'processing'"
        );

        $statement->execute([
            'updated_at' => date(DATE_ATOM),
            'error_code' => mb_substr($errorCode, 0, 100),
            'error_message' => mb_substr($message, 0, 1000),
            'id' => $id,
        ]);
    }

    public function activeCountForUser(int $userId): int
    {
        $statement = $this->pdo->prepare(
            "SELECT COUNT(*)
             FROM file_jobs
             WHERE user_id = :user_id
               AND status IN ('queued', 'processing')"
        );

        $statement->execute(['user_id' => $userId]);

        return (int) $statement->fetchColumn();
    }

    public function cleanup(int $retentionDays): int
    {
        $cutoff = date(
            DATE_ATOM,
            time() - max(1, $retentionDays) * 86400
        );

        $statement = $this->pdo->prepare(
            "DELETE FROM file_jobs
             WHERE status IN (
                'completed',
                'failed',
                'cancelled'
             )
               AND updated_at < :cutoff"
        );

        $statement->execute(['cutoff' => $cutoff]);

        return $statement->rowCount();
    }
}
