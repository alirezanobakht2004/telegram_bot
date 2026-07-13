<?php

declare(strict_types=1);

namespace SmartToolbox\Modules\Alerts;

use PDO;
use RuntimeException;

final class SubscriptionRepository
{
    public function __construct(
        private readonly PDO $pdo
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): int
    {
        foreach (
            [
                'user_id',
                'chat_id',
                'subscription_type',
                'subject',
                'frequency',
                'schedule_time',
                'timezone',
                'next_run_at',
            ] as $key
        ) {
            if (!array_key_exists($key, $data)) {
                throw new RuntimeException(
                    'Subscription field is missing: ' . $key
                );
            }
        }

        $now = date(DATE_ATOM);
        $statement = $this->pdo->prepare(
            'INSERT INTO smart_subscriptions (
                user_id,
                chat_id,
                subscription_type,
                subject,
                frequency,
                schedule_time,
                weekday,
                month_day,
                timezone,
                next_run_at,
                status,
                created_at,
                updated_at
             ) VALUES (
                :user_id,
                :chat_id,
                :subscription_type,
                :subject,
                :frequency,
                :schedule_time,
                :weekday,
                :month_day,
                :timezone,
                :next_run_at,
                :status,
                :created_at,
                :updated_at
             )'
        );
        $statement->execute([
            'user_id' => (int) $data['user_id'],
            'chat_id' => (int) $data['chat_id'],
            'subscription_type' => (string) $data['subscription_type'],
            'subject' => (string) $data['subject'],
            'frequency' => (string) $data['frequency'],
            'schedule_time' => (string) $data['schedule_time'],
            'weekday' => isset($data['weekday'])
                ? (int) $data['weekday']
                : null,
            'month_day' => isset($data['month_day'])
                ? (int) $data['month_day']
                : null,
            'timezone' => (string) $data['timezone'],
            'next_run_at' => (int) $data['next_run_at'],
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function countActiveForUser(int $userId): int
    {
        $statement = $this->pdo->prepare(
            "SELECT COUNT(*)
             FROM smart_subscriptions
             WHERE user_id = :user_id
               AND status IN ('active', 'paused')"
        );
        $statement->execute(['user_id' => $userId]);

        return (int) $statement->fetchColumn();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forUser(
        int $userId,
        int $limit = 50
    ): array {
        $statement = $this->pdo->prepare(
            "SELECT *
             FROM smart_subscriptions
             WHERE user_id = :user_id
               AND status != 'cancelled'
             ORDER BY id DESC
             LIMIT :limit"
        );
        $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $statement->bindValue(
            ':limit',
            max(1, min(200, $limit)),
            PDO::PARAM_INT
        );
        $statement->execute();
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function due(int $limit): array
    {
        $statement = $this->pdo->prepare(
            "SELECT *
             FROM smart_subscriptions
             WHERE status = 'active'
               AND next_run_at <= :now
             ORDER BY next_run_at ASC, id ASC
             LIMIT :limit"
        );
        $statement->bindValue(':now', time(), PDO::PARAM_INT);
        $statement->bindValue(
            ':limit',
            max(1, min(200, $limit)),
            PDO::PARAM_INT
        );
        $statement->execute();
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    }

    public function cancelForUser(
        int $userId,
        int $subscriptionId
    ): bool {
        return $this->setStatus(
            $subscriptionId,
            $userId,
            'cancelled'
        );
    }

    public function pauseForUser(
        int $userId,
        int $subscriptionId
    ): bool {
        return $this->setStatus(
            $subscriptionId,
            $userId,
            'paused'
        );
    }

    public function resumeForUser(
        int $userId,
        int $subscriptionId,
        int $nextRunAt
    ): bool {
        $statement = $this->pdo->prepare(
            "UPDATE smart_subscriptions
             SET
                status = 'active',
                next_run_at = :next_run_at,
                updated_at = :updated_at
             WHERE id = :id
               AND user_id = :user_id
               AND status = 'paused'"
        );
        $statement->execute([
            'next_run_at' => $nextRunAt,
            'updated_at' => date(DATE_ATOM),
            'id' => $subscriptionId,
            'user_id' => $userId,
        ]);

        return $statement->rowCount() === 1;
    }

    public function markSent(
        int $subscriptionId,
        int $nextRunAt
    ): void {
        $statement = $this->pdo->prepare(
            'UPDATE smart_subscriptions
             SET
                last_run_at = :last_run_at,
                next_run_at = :next_run_at,
                failure_count = 0,
                last_error = NULL,
                updated_at = :updated_at
             WHERE id = :id'
        );
        $statement->execute([
            'last_run_at' => time(),
            'next_run_at' => $nextRunAt,
            'updated_at' => date(DATE_ATOM),
            'id' => $subscriptionId,
        ]);
    }

    public function markFailure(
        int $subscriptionId,
        string $error,
        int $nextRunAt
    ): void {
        $statement = $this->pdo->prepare(
            'UPDATE smart_subscriptions
             SET
                failure_count = failure_count + 1,
                last_error = :last_error,
                next_run_at = :next_run_at,
                updated_at = :updated_at
             WHERE id = :id'
        );
        $statement->execute([
            'last_error' => mb_substr($error, 0, 1000),
            'next_run_at' => $nextRunAt,
            'updated_at' => date(DATE_ATOM),
            'id' => $subscriptionId,
        ]);
    }

    private function setStatus(
        int $subscriptionId,
        int $userId,
        string $status
    ): bool {
        $statement = $this->pdo->prepare(
            "UPDATE smart_subscriptions
             SET
                status = :status,
                updated_at = :updated_at
             WHERE id = :id
               AND user_id = :user_id
               AND status != 'cancelled'"
        );
        $statement->execute([
            'status' => $status,
            'updated_at' => date(DATE_ATOM),
            'id' => $subscriptionId,
            'user_id' => $userId,
        ]);

        return $statement->rowCount() === 1;
    }
}
