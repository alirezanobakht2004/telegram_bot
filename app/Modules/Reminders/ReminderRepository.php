<?php

declare(strict_types=1);

namespace SmartToolbox\Modules\Reminders;

use PDO;
use RuntimeException;

final class ReminderRepository
{
    public function __construct(
        private readonly PDO $pdo
    ) {
    }

    public function create(
        int $userId,
        int $chatId,
        string $text,
        int $scheduledAt,
        string $timezone
    ): int {
        if (
            $userId <= 0
            || $chatId === 0
        ) {
            throw new RuntimeException(
                'Reminder owner or chat is invalid.'
            );
        }

        $text = trim($text);

        if ($text === '') {
            throw new RuntimeException(
                'Reminder text cannot be empty.'
            );
        }

        if ($scheduledAt <= time()) {
            throw new RuntimeException(
                'Reminder must be scheduled in the future.'
            );
        }

        $now = date(DATE_ATOM);

        $statement = $this->pdo->prepare(
            'INSERT INTO reminders (
                user_id,
                chat_id,
                reminder_text,
                timezone,
                scheduled_at,
                next_attempt_at,
                status,
                attempts,
                created_at,
                updated_at
            ) VALUES (
                :user_id,
                :chat_id,
                :reminder_text,
                :timezone,
                :scheduled_at,
                :next_attempt_at,
                :status,
                0,
                :created_at,
                :updated_at
            )'
        );

        $statement->execute([
            'user_id' => $userId,
            'chat_id' => $chatId,
            'reminder_text' => $text,
            'timezone' => $timezone,
            'scheduled_at' => $scheduledAt,
            'next_attempt_at' => $scheduledAt,
            'status' => 'pending',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $this->pdo
            ->lastInsertId();
    }

    public function countActiveForUser(
        int $userId
    ): int {
        $statement = $this->pdo->prepare(
            "SELECT COUNT(*)
             FROM reminders
             WHERE user_id = :user_id
               AND status IN (
                    'pending',
                    'processing'
               )"
        );

        $statement->execute([
            'user_id' => $userId,
        ]);

        return (int) $statement
            ->fetchColumn();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function activeForUser(
        int $userId,
        int $limit = 20
    ): array {
        $statement = $this->pdo->prepare(
            "SELECT
                id,
                reminder_text,
                timezone,
                scheduled_at,
                status,
                attempts,
                created_at
             FROM reminders
             WHERE user_id = :user_id
               AND status IN (
                    'pending',
                    'processing'
               )
             ORDER BY scheduled_at ASC
             LIMIT :limit"
        );

        $statement->bindValue(
            ':user_id',
            $userId,
            PDO::PARAM_INT
        );

        $statement->bindValue(
            ':limit',
            max(1, min(50, $limit)),
            PDO::PARAM_INT
        );

        $statement->execute();

        $rows = $statement->fetchAll(
            PDO::FETCH_ASSOC
        );

        return is_array($rows)
            ? $rows
            : [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function historyForUser(
        int $userId,
        int $limit = 15
    ): array {
        $statement = $this->pdo->prepare(
            'SELECT
                id,
                reminder_text,
                timezone,
                scheduled_at,
                status,
                attempts,
                last_error,
                sent_at,
                cancelled_at,
                created_at
             FROM reminders
             WHERE user_id = :user_id
             ORDER BY id DESC
             LIMIT :limit'
        );

        $statement->bindValue(
            ':user_id',
            $userId,
            PDO::PARAM_INT
        );

        $statement->bindValue(
            ':limit',
            max(1, min(30, $limit)),
            PDO::PARAM_INT
        );

        $statement->execute();

        $rows = $statement->fetchAll(
            PDO::FETCH_ASSOC
        );

        return is_array($rows)
            ? $rows
            : [];
    }

    public function cancelForUser(
        int $userId,
        int $reminderId
    ): bool {
        $statement = $this->pdo->prepare(
            "UPDATE reminders
             SET
                status = 'cancelled',
                cancelled_at = :cancelled_at,
                locked_at = NULL,
                updated_at = :updated_at
             WHERE id = :id
               AND user_id = :user_id
               AND status IN (
                    'pending',
                    'failed'
               )"
        );

        $now = date(DATE_ATOM);

        $statement->execute([
            'cancelled_at' => $now,
            'updated_at' => $now,
            'id' => $reminderId,
            'user_id' => $userId,
        ]);

        return $statement->rowCount() === 1;
    }

    public function deleteForUser(
        int $userId,
        int $reminderId
    ): bool {
        $statement = $this->pdo->prepare(
            "DELETE FROM reminders
             WHERE id = :id
               AND user_id = :user_id
               AND status IN (
                    'sent',
                    'failed',
                    'cancelled'
               )"
        );

        $statement->execute([
            'id' => $reminderId,
            'user_id' => $userId,
        ]);

        return $statement->rowCount() === 1;
    }
}
