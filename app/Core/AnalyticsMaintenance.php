<?php

declare(strict_types=1);

namespace SmartToolbox\Core;

use PDO;

final class AnalyticsMaintenance
{
    public function __construct(
        private readonly PDO $pdo
    ) {
    }

    /**
     * @return array<string, int>
     */
    public function cleanup(
        int $usageDays,
        int $commandDays,
        int $apiDays,
        int $cacheDays,
        int $jobRunDays,
        int $deadLetterDays,
        int $maxUsageRows
    ): array {
        $now = time();

        $result = [
            'usage_events' => $this->deleteOlderThan(
                'usage_events',
                'occurred_at',
                $now - max(1, $usageDays) * 86400
            ),
            'command_history' => $this->deleteOlderThan(
                'command_history',
                'occurred_at',
                $now - max(1, $commandDays) * 86400
            ),
            'api_metrics' => $this->deleteOlderThan(
                'api_metrics',
                'occurred_at',
                $now - max(1, $apiDays) * 86400
            ),
            'cache_metrics' => $this->deleteOlderThan(
                'cache_metrics',
                'occurred_at',
                $now - max(1, $cacheDays) * 86400
            ),
            'job_runs' => $this->deleteIsoOlderThan(
                'job_runs',
                'started_at',
                date(DATE_ATOM, $now - max(1, $jobRunDays) * 86400)
            ),
            'dead_letter_jobs' => $this->deleteIsoOlderThan(
                'dead_letter_jobs',
                'failed_at',
                date(DATE_ATOM, $now - max(1, $deadLetterDays) * 86400)
            ),
            'completed_jobs' => $this->deleteIsoOlderThanWhere(
                'job_queue',
                'updated_at',
                date(DATE_ATOM, $now - max(1, $jobRunDays) * 86400),
                "status IN ('completed', 'cancelled')"
            ),
            'expired_locks' => $this->deleteExpiredLocks($now),
            'usage_overflow' => 0,
        ];

        $maxUsageRows = max(1000, $maxUsageRows);
        $count = (int) $this->pdo->query(
            'SELECT COUNT(*) FROM usage_events'
        )->fetchColumn();

        if ($count > $maxUsageRows) {
            $overflow = $count - $maxUsageRows;
            $statement = $this->pdo->prepare(
                'DELETE FROM usage_events
                 WHERE id IN (
                    SELECT id
                    FROM usage_events
                    ORDER BY id ASC
                    LIMIT :limit
                 )'
            );

            $statement->bindValue(':limit', $overflow, PDO::PARAM_INT);
            $statement->execute();
            $result['usage_overflow'] = $statement->rowCount();
        }

        return $result;
    }

    private function deleteOlderThan(
        string $table,
        string $column,
        int $cutoff
    ): int {
        $statement = $this->pdo->prepare(
            "DELETE FROM {$table}
             WHERE {$column} < :cutoff"
        );

        $statement->execute(['cutoff' => $cutoff]);

        return $statement->rowCount();
    }

    private function deleteIsoOlderThan(
        string $table,
        string $column,
        string $cutoff
    ): int {
        return $this->deleteIsoOlderThanWhere(
            $table,
            $column,
            $cutoff,
            '1 = 1'
        );
    }

    private function deleteIsoOlderThanWhere(
        string $table,
        string $column,
        string $cutoff,
        string $where
    ): int {
        $statement = $this->pdo->prepare(
            "DELETE FROM {$table}
             WHERE {$column} < :cutoff
               AND {$where}"
        );

        $statement->execute(['cutoff' => $cutoff]);

        return $statement->rowCount();
    }

    private function deleteExpiredLocks(int $now): int
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM job_locks
             WHERE expires_at <= :now'
        );

        $statement->execute(['now' => $now]);

        return $statement->rowCount();
    }
}
