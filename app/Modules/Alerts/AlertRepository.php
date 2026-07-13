<?php

declare(strict_types=1);

namespace SmartToolbox\Modules\Alerts;

use PDO;
use RuntimeException;

final class AlertRepository
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
        $required = [
            'user_id',
            'chat_id',
            'alert_type',
            'subject',
            'operator',
        ];

        foreach ($required as $key) {
            if (!array_key_exists($key, $data)) {
                throw new RuntimeException(
                    'Alert field is missing: ' . $key
                );
            }
        }

        $now = date(DATE_ATOM);
        $statement = $this->pdo->prepare(
            'INSERT INTO smart_alerts (
                user_id,
                chat_id,
                alert_type,
                subject,
                secondary_subject,
                operator,
                comparison_value,
                threshold_value,
                cooldown_seconds,
                hysteresis,
                max_notifications_per_day,
                check_interval_seconds,
                next_check_at,
                status,
                created_at,
                updated_at
             ) VALUES (
                :user_id,
                :chat_id,
                :alert_type,
                :subject,
                :secondary_subject,
                :operator,
                :comparison_value,
                :threshold_value,
                :cooldown_seconds,
                :hysteresis,
                :max_notifications_per_day,
                :check_interval_seconds,
                :next_check_at,
                :status,
                :created_at,
                :updated_at
             )'
        );

        $statement->execute([
            'user_id' => (int) $data['user_id'],
            'chat_id' => (int) $data['chat_id'],
            'alert_type' => (string) $data['alert_type'],
            'subject' => (string) $data['subject'],
            'secondary_subject' => isset($data['secondary_subject'])
                ? (string) $data['secondary_subject']
                : null,
            'operator' => (string) $data['operator'],
            'comparison_value' => isset($data['comparison_value'])
                ? (string) $data['comparison_value']
                : null,
            'threshold_value' => isset($data['threshold_value'])
                ? (float) $data['threshold_value']
                : null,
            'cooldown_seconds' => max(
                0,
                (int) ($data['cooldown_seconds'] ?? 3600)
            ),
            'hysteresis' => max(
                0.0,
                (float) ($data['hysteresis'] ?? 0.0)
            ),
            'max_notifications_per_day' => max(
                1,
                (int) ($data['max_notifications_per_day'] ?? 3)
            ),
            'check_interval_seconds' => max(
                60,
                (int) ($data['check_interval_seconds'] ?? 300)
            ),
            'next_check_at' => (int) ($data['next_check_at'] ?? time()),
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
             FROM smart_alerts
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
             FROM smart_alerts
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
             FROM smart_alerts
             WHERE status = 'active'
               AND next_check_at <= :now
             ORDER BY next_check_at ASC, id ASC
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
        int $alertId
    ): bool {
        return $this->setStatus(
            $alertId,
            'cancelled',
            $userId
        );
    }

    public function pauseForUser(
        int $userId,
        int $alertId
    ): bool {
        return $this->setStatus(
            $alertId,
            'paused',
            $userId
        );
    }

    public function resumeForUser(
        int $userId,
        int $alertId
    ): bool {
        $statement = $this->pdo->prepare(
            "UPDATE smart_alerts
             SET
                status = 'active',
                next_check_at = :next_check_at,
                updated_at = :updated_at
             WHERE id = :id
               AND user_id = :user_id
               AND status = 'paused'"
        );
        $statement->execute([
            'next_check_at' => time(),
            'updated_at' => date(DATE_ATOM),
            'id' => $alertId,
            'user_id' => $userId,
        ]);

        return $statement->rowCount() === 1;
    }

    /**
     * @param array{
     *     condition: bool,
     *     normalized_value: string
     * } $evaluation
     */
    public function markChecked(
        int $alertId,
        array $evaluation,
        int $nextCheckAt,
        ?string $error = null
    ): void {
        $statement = $this->pdo->prepare(
            'UPDATE smart_alerts
             SET
                last_observed_value = :last_observed_value,
                last_condition_state = :last_condition_state,
                last_checked_at = :last_checked_at,
                next_check_at = :next_check_at,
                failure_count = :failure_count,
                last_error = :last_error,
                updated_at = :updated_at
             WHERE id = :id'
        );
        $statement->execute([
            'last_observed_value' => $evaluation['normalized_value'],
            'last_condition_state' => $evaluation['condition'] ? 1 : 0,
            'last_checked_at' => time(),
            'next_check_at' => $nextCheckAt,
            'failure_count' => $error === null ? 0 : 1,
            'last_error' => $error !== null
                ? mb_substr($error, 0, 1000)
                : null,
            'updated_at' => date(DATE_ATOM),
            'id' => $alertId,
        ]);
    }

    public function markFailure(
        int $alertId,
        string $error,
        int $nextCheckAt
    ): void {
        $statement = $this->pdo->prepare(
            'UPDATE smart_alerts
             SET
                failure_count = failure_count + 1,
                last_error = :last_error,
                last_checked_at = :last_checked_at,
                next_check_at = :next_check_at,
                updated_at = :updated_at
             WHERE id = :id'
        );
        $statement->execute([
            'last_error' => mb_substr($error, 0, 1000),
            'last_checked_at' => time(),
            'next_check_at' => $nextCheckAt,
            'updated_at' => date(DATE_ATOM),
            'id' => $alertId,
        ]);
    }

    public function canNotify(array $alert, int $now): bool
    {
        $lastTriggered = isset($alert['last_triggered_at'])
            ? (int) $alert['last_triggered_at']
            : 0;
        $cooldown = max(
            0,
            (int) ($alert['cooldown_seconds'] ?? 0)
        );

        if (
            $lastTriggered > 0
            && $cooldown > 0
            && ($lastTriggered + $cooldown) > $now
        ) {
            return false;
        }

        $dateKey = date('Y-m-d', $now);
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM alert_notifications
             WHERE alert_id = :alert_id
               AND date_key = :date_key'
        );
        $statement->execute([
            'alert_id' => (int) $alert['id'],
            'date_key' => $dateKey,
        ]);

        return (int) $statement->fetchColumn()
            < max(1, (int) ($alert['max_notifications_per_day'] ?? 1));
    }

    public function recordNotification(
        int $alertId,
        string $dedupKey,
        string $observedValue,
        int $sentAt
    ): bool {
        try {
            $this->pdo->beginTransaction();

            $statement = $this->pdo->prepare(
                'INSERT INTO alert_notifications (
                    alert_id,
                    dedup_key,
                    observed_value,
                    date_key,
                    sent_at,
                    created_at
                 ) VALUES (
                    :alert_id,
                    :dedup_key,
                    :observed_value,
                    :date_key,
                    :sent_at,
                    :created_at
                 )'
            );
            $statement->execute([
                'alert_id' => $alertId,
                'dedup_key' => mb_substr($dedupKey, 0, 255),
                'observed_value' => mb_substr($observedValue, 0, 500),
                'date_key' => date('Y-m-d', $sentAt),
                'sent_at' => $sentAt,
                'created_at' => date(DATE_ATOM, $sentAt),
            ]);

            $update = $this->pdo->prepare(
                'UPDATE smart_alerts
                 SET
                    last_triggered_value = :value,
                    last_triggered_at = :sent_at,
                    updated_at = :updated_at
                 WHERE id = :id'
            );
            $update->execute([
                'value' => mb_substr($observedValue, 0, 500),
                'sent_at' => $sentAt,
                'updated_at' => date(DATE_ATOM, $sentAt),
                'id' => $alertId,
            ]);

            $this->pdo->commit();

            return true;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            if (str_contains(
                mb_strtolower($exception->getMessage()),
                'unique'
            )) {
                return false;
            }

            throw $exception;
        }
    }

    public function pruneNotifications(int $retentionDays): int
    {
        $cutoff = time() - max(1, $retentionDays) * 86400;
        $statement = $this->pdo->prepare(
            'DELETE FROM alert_notifications
             WHERE sent_at < :cutoff'
        );
        $statement->execute(['cutoff' => $cutoff]);

        return $statement->rowCount();
    }

    private function setStatus(
        int $alertId,
        string $status,
        int $userId
    ): bool {
        $statement = $this->pdo->prepare(
            "UPDATE smart_alerts
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
            'id' => $alertId,
            'user_id' => $userId,
        ]);

        return $statement->rowCount() === 1;
    }
}
