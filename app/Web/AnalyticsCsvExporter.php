<?php

declare(strict_types=1);

namespace SmartToolbox\Web;

use PDO;
use RuntimeException;

final class AnalyticsCsvExporter
{
    public function __construct(
        private readonly PDO $pdo
    ) {
    }

    /**
     * @param resource $stream
     */
    public function stream(
        string $dataset,
        int $days,
        mixed $stream
    ): void {
        if (!is_resource($stream)) {
            throw new RuntimeException(
                'CSV output stream is invalid.'
            );
        }

        $days = max(1, min(365, $days));
        $cutoff = time() - ($days * 86400);

        [$headers, $sql, $parameters] =
            $this->query($dataset, $cutoff);

        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv(
            $stream,
            $headers,
            ',',
            '"',
            ''
        );

        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);

        while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
            $values = [];

            foreach ($headers as $header) {
                $values[] = $this->safeCell(
                    $row[$header] ?? ''
                );
            }

            fputcsv(
                $stream,
                $values,
                ',',
                '"',
                ''
            );
        }
    }

    /**
     * @return array{0: list<string>, 1: string, 2: array<string, int>}
     */
    private function query(
        string $dataset,
        int $cutoff
    ): array {
        return match ($dataset) {
            'daily' => [
                [
                    'day',
                    'events',
                    'users',
                    'avg_duration_ms',
                    'success_rate',
                ],
                'SELECT
                    substr(created_at, 1, 10) AS day,
                    COUNT(*) AS events,
                    COUNT(DISTINCT user_id) AS users,
                    ROUND(COALESCE(AVG(duration_ms), 0), 3) AS avg_duration_ms,
                    ROUND(COALESCE(AVG(success) * 100, 100), 3) AS success_rate
                 FROM usage_events
                 WHERE occurred_at >= :cutoff
                 GROUP BY day
                 ORDER BY day ASC',
                ['cutoff' => $cutoff],
            ],
            'commands' => [
                [
                    'module',
                    'command',
                    'source',
                    'total',
                    'avg_duration_ms',
                    'success_rate',
                ],
                'SELECT
                    module,
                    command,
                    source,
                    COUNT(*) AS total,
                    ROUND(COALESCE(AVG(duration_ms), 0), 3) AS avg_duration_ms,
                    ROUND(COALESCE(AVG(success) * 100, 100), 3) AS success_rate
                 FROM command_history
                 WHERE occurred_at >= :cutoff
                 GROUP BY module, command, source
                 ORDER BY total DESC',
                ['cutoff' => $cutoff],
            ],
            'modules' => [
                [
                    'module',
                    'events',
                    'users',
                    'avg_duration_ms',
                    'max_duration_ms',
                    'success_rate',
                ],
                'SELECT
                    module,
                    COUNT(*) AS events,
                    COUNT(DISTINCT user_id) AS users,
                    ROUND(COALESCE(AVG(duration_ms), 0), 3) AS avg_duration_ms,
                    ROUND(COALESCE(MAX(duration_ms), 0), 3) AS max_duration_ms,
                    ROUND(COALESCE(AVG(success) * 100, 100), 3) AS success_rate
                 FROM usage_events
                 WHERE occurred_at >= :cutoff
                 GROUP BY module
                 ORDER BY events DESC',
                ['cutoff' => $cutoff],
            ],
            'api' => [
                [
                    'provider',
                    'host',
                    'path',
                    'calls',
                    'avg_duration_ms',
                    'max_duration_ms',
                    'failures',
                    'response_bytes',
                ],
                'SELECT
                    provider,
                    host,
                    path,
                    COUNT(*) AS calls,
                    ROUND(COALESCE(AVG(duration_ms), 0), 3) AS avg_duration_ms,
                    ROUND(COALESCE(MAX(duration_ms), 0), 3) AS max_duration_ms,
                    COALESCE(SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END), 0) AS failures,
                    COALESCE(SUM(response_bytes), 0) AS response_bytes
                 FROM api_metrics
                 WHERE occurred_at >= :cutoff
                 GROUP BY provider, host, path
                 ORDER BY calls DESC',
                ['cutoff' => $cutoff],
            ],
            'cache' => [
                [
                    'namespace',
                    'operations',
                    'hits',
                    'misses',
                    'avg_duration_ms',
                    'value_bytes',
                ],
                "SELECT
                    namespace,
                    COUNT(*) AS operations,
                    COALESCE(SUM(CASE WHEN operation = 'get' AND hit = 1 THEN 1 ELSE 0 END), 0) AS hits,
                    COALESCE(SUM(CASE WHEN operation = 'get' AND hit = 0 THEN 1 ELSE 0 END), 0) AS misses,
                    ROUND(COALESCE(AVG(duration_ms), 0), 3) AS avg_duration_ms,
                    COALESCE(SUM(value_bytes), 0) AS value_bytes
                 FROM cache_metrics
                 WHERE occurred_at >= :cutoff
                 GROUP BY namespace
                 ORDER BY operations DESC",
                ['cutoff' => $cutoff],
            ],
            'errors' => [
                [
                    'module',
                    'action',
                    'error_code',
                    'error_message',
                    'total',
                    'last_seen_at',
                ],
                "SELECT
                    module,
                    action,
                    COALESCE(error_code, 'unknown') AS error_code,
                    MAX(error_message) AS error_message,
                    COUNT(*) AS total,
                    MAX(created_at) AS last_seen_at
                 FROM usage_events
                 WHERE occurred_at >= :cutoff
                   AND success = 0
                 GROUP BY module, action, error_code
                 ORDER BY total DESC, last_seen_at DESC",
                ['cutoff' => $cutoff],
            ],
            'raw' => [
                [
                    'id',
                    'correlation_id',
                    'update_id',
                    'update_type',
                    'user_id',
                    'chat_id',
                    'chat_type',
                    'module',
                    'action',
                    'input_kind',
                    'duration_ms',
                    'success',
                    'cache_hit',
                    'error_code',
                    'created_at',
                ],
                'SELECT
                    id,
                    correlation_id,
                    update_id,
                    update_type,
                    user_id,
                    chat_id,
                    chat_type,
                    module,
                    action,
                    input_kind,
                    duration_ms,
                    success,
                    cache_hit,
                    error_code,
                    created_at
                 FROM usage_events
                 WHERE occurred_at >= :cutoff
                 ORDER BY id DESC
                 LIMIT 10000',
                ['cutoff' => $cutoff],
            ],
            default => throw new RuntimeException(
                'Unknown analytics CSV dataset.'
            ),
        };
    }

    private function safeCell(mixed $value): string
    {
        $value = (string) $value;

        $trimmed = ltrim($value);

        if (
            $trimmed !== ''
            && in_array(
                $trimmed[0],
                ['=', '+', '-', '@'],
                true
            )
        ) {
            return "'" . $value;
        }

        return $value;
    }
}
