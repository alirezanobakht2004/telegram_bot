<?php

declare(strict_types=1);

namespace SmartToolbox\Modules\Reminders;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

final class ReminderWorker
{
    /**
     * @var Closure(int|string, string): void
     */
    private Closure $sender;

    /**
     * @param callable(int|string, string): void $sender
     */
    public function __construct(
        private readonly PDO $pdo,
        callable $sender,
        private readonly string $logFile
    ) {
        $this->sender = Closure::fromCallable(
            $sender
        );
    }

    /**
     * @return array{
     *     claimed: int,
     *     sent: int,
     *     failed: int,
     *     retried: int,
     *     pruned: int
     * }
     */
    public function run(
        int $batchSize = 10,
        int $maxDeliveryAttempts = 3,
        int $retryBaseSeconds = 60,
        int $staleLockSeconds = 600,
        int $retentionDays = 90
    ): array {
        $batchSize = max(
            1,
            min(50, $batchSize)
        );

        $maxDeliveryAttempts = max(
            1,
            min(10, $maxDeliveryAttempts)
        );

        $retryBaseSeconds = max(
            10,
            min(3600, $retryBaseSeconds)
        );

        $staleLockSeconds = max(
            60,
            min(3600, $staleLockSeconds)
        );

        $retentionDays = max(
            1,
            min(3650, $retentionDays)
        );

        $runId = $this->startRun();

        $result = [
            'claimed' => 0,
            'sent' => 0,
            'failed' => 0,
            'retried' => 0,
            'pruned' => 0,
        ];

        try {
            $reminders = $this->claimDue(
                $batchSize,
                $staleLockSeconds
            );

            $result['claimed'] = count(
                $reminders
            );

            foreach ($reminders as $reminder) {
                try {
                    ($this->sender)(
                        (int) $reminder['chat_id'],
                        $this->message($reminder)
                    );

                    $this->markSent(
                        (int) $reminder['id']
                    );

                    $result['sent']++;
                } catch (Throwable $exception) {
                    $attempts = (int) (
                        $reminder['attempts']
                        ?? 1
                    );

                    $permanent =
                        $this->isPermanentFailure(
                            $exception->getMessage()
                        );

                    if (
                        $permanent
                        || $attempts
                            >= $maxDeliveryAttempts
                    ) {
                        $this->markFailed(
                            (int) $reminder['id'],
                            $exception->getMessage()
                        );

                        if ($permanent) {
                            $this->deactivateChat(
                                (int) $reminder[
                                    'chat_id'
                                ]
                            );
                        }

                        $result['failed']++;
                    } else {
                        $delay = min(
                            3600,
                            $retryBaseSeconds
                            * (2 ** max(
                                0,
                                $attempts - 1
                            ))
                        );

                        $this->markForRetry(
                            (int) $reminder['id'],
                            $exception->getMessage(),
                            $delay
                        );

                        $result['retried']++;
                    }

                    $this->log(
                        'delivery:'
                        . (int) $reminder['id'],
                        $exception
                    );
                }
            }

            $result['pruned'] =
                $this->pruneHistory(
                    $retentionDays
                );

            $this->completeRun(
                $runId,
                'completed',
                $result,
                null
            );

            $this->pruneWorkerRuns();

            return $result;
        } catch (Throwable $exception) {
            $this->completeRun(
                $runId,
                'failed',
                $result,
                $exception->getMessage()
            );

            $this->log(
                'worker',
                $exception
            );

            throw $exception;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function claimDue(
        int $limit,
        int $staleLockSeconds
    ): array {
        $now = time();

        $this->pdo->exec(
            'BEGIN IMMEDIATE'
        );

        try {
            $recover = $this->pdo->prepare(
                "UPDATE reminders
                 SET
                    status = 'pending',
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
                'stale_before' =>
                    $now - $staleLockSeconds,
            ]);

            $select = $this->pdo->prepare(
                "SELECT
                    id,
                    user_id,
                    chat_id,
                    reminder_text,
                    timezone,
                    scheduled_at,
                    attempts
                 FROM reminders
                 WHERE status = 'pending'
                   AND scheduled_at <= :now
                   AND next_attempt_at <= :now
                 ORDER BY
                    scheduled_at ASC,
                    id ASC
                 LIMIT :limit"
            );

            $select->bindValue(
                ':now',
                $now,
                PDO::PARAM_INT
            );

            $select->bindValue(
                ':limit',
                $limit,
                PDO::PARAM_INT
            );

            $select->execute();

            $rows = $select->fetchAll(
                PDO::FETCH_ASSOC
            );

            $claimed = [];

            if (is_array($rows)) {
                $update = $this->pdo->prepare(
                    "UPDATE reminders
                     SET
                        status = 'processing',
                        attempts = attempts + 1,
                        locked_at = :locked_at,
                        updated_at = :updated_at
                     WHERE id = :id
                       AND status = 'pending'"
                );

                foreach ($rows as $row) {
                    $update->execute([
                        'locked_at' => $now,
                        'updated_at' =>
                            date(DATE_ATOM),
                        'id' => (int) $row['id'],
                    ]);

                    if ($update->rowCount() !== 1) {
                        continue;
                    }

                    $row['attempts'] =
                        (int) $row['attempts']
                        + 1;

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

    /**
     * @param array<string, mixed> $reminder
     */
    private function message(
        array $reminder
    ): string {
        $timezone = $this->timezone(
            (string) (
                $reminder['timezone']
                ?? 'Asia/Tehran'
            )
        );

        $date = (
            new DateTimeImmutable(
                '@' . (int) (
                    $reminder['scheduled_at']
                    ?? time()
                )
            )
        )->setTimezone($timezone);

        return "⏰ یادآوری\n\n"
            . (string) (
                $reminder['reminder_text']
                ?? ''
            )
            . "\n\n"
            . "🆔 #"
            . (int) ($reminder['id'] ?? 0)
            . "\n"
            . "🗓 زمان تنظیم‌شده: "
            . $date->format('Y-m-d H:i')
            . "\n"
            . "🌐 "
            . $timezone->getName();
    }

    private function markSent(int $id): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE reminders
             SET
                status = 'sent',
                sent_at = :sent_at,
                last_error = NULL,
                locked_at = NULL,
                updated_at = :updated_at
             WHERE id = :id
               AND status = 'processing'"
        );

        $now = date(DATE_ATOM);

        $statement->execute([
            'sent_at' => $now,
            'updated_at' => $now,
            'id' => $id,
        ]);
    }

    private function markFailed(
        int $id,
        string $error
    ): void {
        $statement = $this->pdo->prepare(
            "UPDATE reminders
             SET
                status = 'failed',
                last_error = :last_error,
                locked_at = NULL,
                updated_at = :updated_at
             WHERE id = :id
               AND status = 'processing'"
        );

        $statement->execute([
            'last_error' => mb_substr(
                $error,
                0,
                1000
            ),
            'updated_at' => date(DATE_ATOM),
            'id' => $id,
        ]);
    }

    private function markForRetry(
        int $id,
        string $error,
        int $delaySeconds
    ): void {
        $statement = $this->pdo->prepare(
            "UPDATE reminders
             SET
                status = 'pending',
                next_attempt_at =
                    :next_attempt_at,
                last_error = :last_error,
                locked_at = NULL,
                updated_at = :updated_at
             WHERE id = :id
               AND status = 'processing'"
        );

        $statement->execute([
            'next_attempt_at' =>
                time() + $delaySeconds,
            'last_error' => mb_substr(
                $error,
                0,
                1000
            ),
            'updated_at' => date(DATE_ATOM),
            'id' => $id,
        ]);
    }

    private function deactivateChat(
        int $chatId
    ): void {
        $chat = $this->pdo->prepare(
            'UPDATE chats
             SET is_active = 0
             WHERE telegram_id = :chat_id'
        );

        $chat->execute([
            'chat_id' => $chatId,
        ]);

        $user = $this->pdo->prepare(
            'UPDATE users
             SET is_blocked = 1
             WHERE last_chat_id = :chat_id'
        );

        $user->execute([
            'chat_id' => $chatId,
        ]);
    }

    private function isPermanentFailure(
        string $message
    ): bool {
        $message = mb_strtolower($message);

        return str_contains(
            $message,
            'bot was blocked'
        ) || str_contains(
            $message,
            'chat not found'
        ) || str_contains(
            $message,
            'user is deactivated'
        ) || str_contains(
            $message,
            'forbidden'
        );
    }

    private function startRun(): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO reminder_worker_runs (
                status,
                claimed_count,
                sent_count,
                failed_count,
                retried_count,
                pruned_count,
                started_at
            ) VALUES (
                :status,
                0,
                0,
                0,
                0,
                0,
                :started_at
            )'
        );

        $statement->execute([
            'status' => 'running',
            'started_at' => date(DATE_ATOM),
        ]);

        return (int) $this->pdo
            ->lastInsertId();
    }

    /**
     * @param array{
     *     claimed: int,
     *     sent: int,
     *     failed: int,
     *     retried: int,
     *     pruned: int
     * } $result
     */
    private function completeRun(
        int $runId,
        string $status,
        array $result,
        ?string $error
    ): void {
        $statement = $this->pdo->prepare(
            'UPDATE reminder_worker_runs
             SET
                status = :status,
                claimed_count = :claimed_count,
                sent_count = :sent_count,
                failed_count = :failed_count,
                retried_count = :retried_count,
                pruned_count = :pruned_count,
                completed_at = :completed_at,
                error_message = :error_message
             WHERE id = :id'
        );

        $statement->execute([
            'status' => $status,
            'claimed_count' =>
                $result['claimed'],
            'sent_count' => $result['sent'],
            'failed_count' =>
                $result['failed'],
            'retried_count' =>
                $result['retried'],
            'pruned_count' =>
                $result['pruned'],
            'completed_at' => date(DATE_ATOM),
            'error_message' => $error !== null
                ? mb_substr(
                    $error,
                    0,
                    1000
                )
                : null,
            'id' => $runId,
        ]);
    }

    private function pruneHistory(
        int $retentionDays
    ): int {
        $cutoff = date(
            DATE_ATOM,
            time() - (
                $retentionDays * 86400
            )
        );

        $statement = $this->pdo->prepare(
            "DELETE FROM reminders
             WHERE status IN (
                    'sent',
                    'cancelled'
               )
               AND updated_at < :cutoff"
        );

        $statement->execute([
            'cutoff' => $cutoff,
        ]);

        return $statement->rowCount();
    }

    private function pruneWorkerRuns(): void
    {
        $this->pdo->exec(
            'DELETE FROM reminder_worker_runs
             WHERE id NOT IN (
                SELECT id
                FROM reminder_worker_runs
                ORDER BY id DESC
                LIMIT 100
             )'
        );
    }

    private function timezone(
        string $timezone
    ): DateTimeZone {
        try {
            return new DateTimeZone(
                trim($timezone)
            );
        } catch (Throwable) {
            return new DateTimeZone(
                'Asia/Tehran'
            );
        }
    }

    private function log(
        string $operation,
        Throwable $exception
    ): void {
        $directory = dirname(
            $this->logFile
        );

        if (!is_dir($directory)) {
            @mkdir(
                $directory,
                0700,
                true
            );
        }

        $entry = sprintf(
            "[%s] [operation:%s] %s\n%s\n\n",
            date(DATE_ATOM),
            str_replace(
                ["\r", "\n"],
                ' ',
                mb_substr(
                    $operation,
                    0,
                    150
                )
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
