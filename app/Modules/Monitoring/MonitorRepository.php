<?php

declare(strict_types=1);

namespace SmartToolbox\Modules\Monitoring;

use PDO;
use RuntimeException;

final class MonitorRepository
{
    public function __construct(
        private readonly PDO $pdo
    ) {
    }

    public function create(
        int $userId,
        int $chatId,
        string $url,
        string $normalizedUrl,
        int $intervalSeconds,
        bool $dailyReportEnabled = false,
        string $dailyReportTime = '09:00',
        string $timezone = 'Asia/Tehran',
        ?int $nextReportAt = null
    ): int {
        $now = date(DATE_ATOM);
        $statement = $this->pdo->prepare(
            'INSERT INTO site_monitors (
                user_id,
                chat_id,
                url,
                normalized_url,
                interval_seconds,
                status,
                next_check_at,
                daily_report_enabled,
                daily_report_time,
                timezone,
                next_report_at,
                created_at,
                updated_at
             ) VALUES (
                :user_id,
                :chat_id,
                :url,
                :normalized_url,
                :interval_seconds,
                :status,
                :next_check_at,
                :daily_report_enabled,
                :daily_report_time,
                :timezone,
                :next_report_at,
                :created_at,
                :updated_at
             )
             ON CONFLICT(user_id, normalized_url)
             DO UPDATE SET
                chat_id = excluded.chat_id,
                url = excluded.url,
                interval_seconds = excluded.interval_seconds,
                status = \'active\',
                next_check_at = excluded.next_check_at,
                updated_at = excluded.updated_at'
        );
        $statement->execute([
            'user_id' => $userId,
            'chat_id' => $chatId,
            'url' => $url,
            'normalized_url' => $normalizedUrl,
            'interval_seconds' => $intervalSeconds,
            'status' => 'active',
            'next_check_at' => time(),
            'daily_report_enabled' => $dailyReportEnabled ? 1 : 0,
            'daily_report_time' => $dailyReportTime,
            'timezone' => $timezone,
            'next_report_at' => $nextReportAt,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $id = (int) $this->pdo->lastInsertId();

        if ($id > 0) {
            return $id;
        }

        $find = $this->pdo->prepare(
            'SELECT id
             FROM site_monitors
             WHERE user_id = :user_id
               AND normalized_url = :normalized_url
             LIMIT 1'
        );
        $find->execute([
            'user_id' => $userId,
            'normalized_url' => $normalizedUrl,
        ]);
        $existing = $find->fetchColumn();

        if ($existing === false) {
            throw new RuntimeException(
                'Monitor could not be created.'
            );
        }

        return (int) $existing;
    }

    public function countActiveForUser(int $userId): int
    {
        $statement = $this->pdo->prepare(
            "SELECT COUNT(*)
             FROM site_monitors
             WHERE user_id = :user_id
               AND status IN ('active', 'paused')"
        );
        $statement->execute(['user_id' => $userId]);

        return (int) $statement->fetchColumn();
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function forUser(
        int $userId,
        int $limit = 50
    ): array {
        $statement = $this->pdo->prepare(
            "SELECT *
             FROM site_monitors
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
     * @return array<string,mixed>|null
     */
    public function findForUser(
        int $userId,
        int $monitorId
    ): ?array {
        $statement = $this->pdo->prepare(
            'SELECT *
             FROM site_monitors
             WHERE id = :id
               AND user_id = :user_id
             LIMIT 1'
        );
        $statement->execute([
            'id' => $monitorId,
            'user_id' => $userId,
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function due(int $limit): array
    {
        $statement = $this->pdo->prepare(
            "SELECT *
             FROM site_monitors
             WHERE status = 'active'
               AND next_check_at <= :now
             ORDER BY next_check_at ASC, id ASC
             LIMIT :limit"
        );
        $statement->bindValue(':now', time(), PDO::PARAM_INT);
        $statement->bindValue(
            ':limit',
            max(1, min(100, $limit)),
            PDO::PARAM_INT
        );
        $statement->execute();
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function dueReports(int $limit): array
    {
        $statement = $this->pdo->prepare(
            "SELECT *
             FROM site_monitors
             WHERE status = 'active'
               AND daily_report_enabled = 1
               AND next_report_at IS NOT NULL
               AND next_report_at <= :now
             ORDER BY next_report_at ASC, id ASC
             LIMIT :limit"
        );
        $statement->bindValue(':now', time(), PDO::PARAM_INT);
        $statement->bindValue(
            ':limit',
            max(1, min(100, $limit)),
            PDO::PARAM_INT
        );
        $statement->execute();
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    }

    public function pauseForUser(
        int $userId,
        int $monitorId
    ): bool {
        return $this->setStatus(
            $userId,
            $monitorId,
            'paused'
        );
    }

    public function cancelForUser(
        int $userId,
        int $monitorId
    ): bool {
        return $this->setStatus(
            $userId,
            $monitorId,
            'cancelled'
        );
    }

    public function resumeForUser(
        int $userId,
        int $monitorId
    ): bool {
        $statement = $this->pdo->prepare(
            "UPDATE site_monitors
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
            'id' => $monitorId,
            'user_id' => $userId,
        ]);

        return $statement->rowCount() === 1;
    }

    public function configureDailyReport(
        int $userId,
        int $monitorId,
        bool $enabled,
        string $time,
        string $timezone,
        ?int $nextReportAt
    ): bool {
        $statement = $this->pdo->prepare(
            'UPDATE site_monitors
             SET
                daily_report_enabled = :enabled,
                daily_report_time = :report_time,
                timezone = :timezone,
                next_report_at = :next_report_at,
                updated_at = :updated_at
             WHERE id = :id
               AND user_id = :user_id
               AND status != \'cancelled\''
        );
        $statement->execute([
            'enabled' => $enabled ? 1 : 0,
            'report_time' => $time,
            'timezone' => $timezone,
            'next_report_at' => $nextReportAt,
            'updated_at' => date(DATE_ATOM),
            'id' => $monitorId,
            'user_id' => $userId,
        ]);

        return $statement->rowCount() === 1;
    }

    /**
     * @param array<string,mixed> $probe
     * @return array{transition:?string,current_state:string}
     */
    public function recordCheck(
        array $monitor,
        array $probe,
        bool $up,
        int $failureThreshold,
        int $recoveryThreshold
    ): array {
        $monitorId = (int) $monitor['id'];
        $previousState = (string) ($monitor['last_state'] ?? 'unknown');
        $failures = $up
            ? 0
            : (int) ($monitor['consecutive_failures'] ?? 0) + 1;
        $successes = $up
            ? (int) ($monitor['consecutive_successes'] ?? 0) + 1
            : 0;
        $currentState = $previousState;
        $transition = null;

        if ($up && $successes >= max(1, $recoveryThreshold)) {
            $currentState = 'up';
        } elseif (!$up && $failures >= max(1, $failureThreshold)) {
            $currentState = 'down';
        }

        if (
            $previousState !== $currentState
            && $currentState !== 'unknown'
            && !(
                $previousState === 'unknown'
                && $currentState === 'up'
            )
        ) {
            $transition = $currentState;
        }

        $now = time();
        $this->pdo->beginTransaction();

        try {
            $check = $this->pdo->prepare(
                'INSERT INTO monitor_checks (
                    monitor_id,
                    checked_at,
                    state,
                    status_code,
                    response_ms,
                    final_url,
                    primary_ip,
                    error_code,
                    error_message,
                    created_at
                 ) VALUES (
                    :monitor_id,
                    :checked_at,
                    :state,
                    :status_code,
                    :response_ms,
                    :final_url,
                    :primary_ip,
                    :error_code,
                    :error_message,
                    :created_at
                 )'
            );
            $check->execute([
                'monitor_id' => $monitorId,
                'checked_at' => $now,
                'state' => $up ? 'up' : 'down',
                'status_code' => $probe['status_code'] ?? null,
                'response_ms' => $probe['response_ms'] ?? null,
                'final_url' => isset($probe['final_url'])
                    ? mb_substr((string) $probe['final_url'], 0, 2000)
                    : null,
                'primary_ip' => isset($probe['primary_ip'])
                    ? mb_substr((string) $probe['primary_ip'], 0, 100)
                    : null,
                'error_code' => $probe['error_code'] ?? null,
                'error_message' => isset($probe['error_message'])
                    ? mb_substr((string) $probe['error_message'], 0, 1000)
                    : null,
                'created_at' => date(DATE_ATOM, $now),
            ]);

            $update = $this->pdo->prepare(
                'UPDATE site_monitors
                 SET
                    last_state = :last_state,
                    last_status_code = :last_status_code,
                    last_response_ms = :last_response_ms,
                    consecutive_failures = :consecutive_failures,
                    consecutive_successes = :consecutive_successes,
                    last_error = :last_error,
                    last_checked_at = :last_checked_at,
                    last_changed_at = :last_changed_at,
                    next_check_at = :next_check_at,
                    updated_at = :updated_at
                 WHERE id = :id'
            );
            $update->execute([
                'last_state' => $currentState,
                'last_status_code' => $probe['status_code'] ?? null,
                'last_response_ms' => $probe['response_ms'] ?? null,
                'consecutive_failures' => $failures,
                'consecutive_successes' => $successes,
                'last_error' => isset($probe['error_message'])
                    ? mb_substr((string) $probe['error_message'], 0, 1000)
                    : null,
                'last_checked_at' => $now,
                'last_changed_at' => $transition !== null
                    ? $now
                    : ($monitor['last_changed_at'] ?? null),
                'next_check_at' => $now + max(60, (int) $monitor['interval_seconds']),
                'updated_at' => date(DATE_ATOM, $now),
                'id' => $monitorId,
            ]);

            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        return [
            'transition' => $transition,
            'current_state' => $currentState,
        ];
    }

    public function markNotification(
        int $monitorId,
        string $state,
        string $dedupKey
    ): bool {
        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO monitor_notifications (
                    monitor_id,
                    state,
                    dedup_key,
                    sent_at,
                    created_at
                 ) VALUES (
                    :monitor_id,
                    :state,
                    :dedup_key,
                    :sent_at,
                    :created_at
                 )'
            );
            $statement->execute([
                'monitor_id' => $monitorId,
                'state' => $state,
                'dedup_key' => $dedupKey,
                'sent_at' => time(),
                'created_at' => date(DATE_ATOM),
            ]);

            $update = $this->pdo->prepare(
                'UPDATE site_monitors
                 SET last_notified_at = :last_notified_at
                 WHERE id = :id'
            );
            $update->execute([
                'last_notified_at' => time(),
                'id' => $monitorId,
            ]);

            return true;
        } catch (\Throwable $exception) {
            if (str_contains(mb_strtolower($exception->getMessage()), 'unique')) {
                return false;
            }
            throw $exception;
        }
    }

    /**
     * @return array{
     *     checks:int,
     *     up:int,
     *     down:int,
     *     uptime:float,
     *     average_response_ms:float,
     *     incidents:int
     * }
     */
    public function uptime(
        int $monitorId,
        int $days
    ): array {
        $cutoff = time() - max(1, min(365, $days)) * 86400;
        $statement = $this->pdo->prepare(
            "SELECT
                COUNT(*) AS checks,
                SUM(CASE WHEN state = 'up' THEN 1 ELSE 0 END) AS up_count,
                SUM(CASE WHEN state = 'down' THEN 1 ELSE 0 END) AS down_count,
                AVG(CASE WHEN state = 'up' THEN response_ms END) AS avg_response
             FROM monitor_checks
             WHERE monitor_id = :monitor_id
               AND checked_at >= :cutoff"
        );
        $statement->execute([
            'monitor_id' => $monitorId,
            'cutoff' => $cutoff,
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
        $checks = (int) ($row['checks'] ?? 0);
        $up = (int) ($row['up_count'] ?? 0);
        $down = (int) ($row['down_count'] ?? 0);
        $incidentsStatement = $this->pdo->prepare(
            "SELECT COUNT(*)
             FROM monitor_notifications
             WHERE monitor_id = :monitor_id
               AND state = 'down'
               AND sent_at >= :cutoff"
        );
        $incidentsStatement->execute([
            'monitor_id' => $monitorId,
            'cutoff' => $cutoff,
        ]);

        return [
            'checks' => $checks,
            'up' => $up,
            'down' => $down,
            'uptime' => $checks > 0
                ? round(($up / $checks) * 100, 3)
                : 0.0,
            'average_response_ms' => round((float) ($row['avg_response'] ?? 0), 2),
            'incidents' => (int) $incidentsStatement->fetchColumn(),
        ];
    }

    /**
     * @return list<array{date:string,checks:int,up:int,uptime:float,average_response_ms:float}>
     */
    public function dailyUptime(
        int $monitorId,
        int $days = 30
    ): array {
        $days = max(1, min(365, $days));
        $cutoff = time() - $days * 86400;
        $statement = $this->pdo->prepare(
            "SELECT
                date(checked_at, 'unixepoch', 'localtime') AS day,
                COUNT(*) AS checks,
                SUM(CASE WHEN state = 'up' THEN 1 ELSE 0 END) AS up_count,
                AVG(CASE WHEN state = 'up' THEN response_ms END) AS avg_response
             FROM monitor_checks
             WHERE monitor_id = :monitor_id
               AND checked_at >= :cutoff
             GROUP BY day
             ORDER BY day ASC"
        );
        $statement->execute([
            'monitor_id' => $monitorId,
            'cutoff' => $cutoff,
        ]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $result = [];

        if (is_array($rows)) {
            foreach ($rows as $row) {
                $checks = (int) $row['checks'];
                $up = (int) $row['up_count'];
                $result[] = [
                    'date' => (string) $row['day'],
                    'checks' => $checks,
                    'up' => $up,
                    'uptime' => $checks > 0
                        ? round(($up / $checks) * 100, 2)
                        : 0.0,
                    'average_response_ms' => round((float) ($row['avg_response'] ?? 0), 2),
                ];
            }
        }

        return $result;
    }

    public function markReportSent(
        int $monitorId,
        int $nextReportAt
    ): void {
        $statement = $this->pdo->prepare(
            'UPDATE site_monitors
             SET
                last_report_at = :last_report_at,
                next_report_at = :next_report_at,
                updated_at = :updated_at
             WHERE id = :id'
        );
        $statement->execute([
            'last_report_at' => time(),
            'next_report_at' => $nextReportAt,
            'updated_at' => date(DATE_ATOM),
            'id' => $monitorId,
        ]);
    }

    public function prune(int $retentionDays): int
    {
        $cutoff = time() - max(1, $retentionDays) * 86400;
        $checks = $this->pdo->prepare(
            'DELETE FROM monitor_checks
             WHERE checked_at < :cutoff'
        );
        $checks->execute(['cutoff' => $cutoff]);
        $notifications = $this->pdo->prepare(
            'DELETE FROM monitor_notifications
             WHERE sent_at < :cutoff'
        );
        $notifications->execute(['cutoff' => $cutoff]);

        return $checks->rowCount() + $notifications->rowCount();
    }

    private function setStatus(
        int $userId,
        int $monitorId,
        string $status
    ): bool {
        $statement = $this->pdo->prepare(
            "UPDATE site_monitors
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
            'id' => $monitorId,
            'user_id' => $userId,
        ]);

        return $statement->rowCount() === 1;
    }
}
