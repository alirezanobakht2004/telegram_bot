<?php

declare(strict_types=1);

namespace SmartToolbox\Core;

use PDO;
use Throwable;

final class CacheMetricsTracker
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly bool $enabled = true,
        private readonly int $sampleRate = 100
    ) {
    }

    public function record(
        string $key,
        string $operation,
        ?bool $hit,
        float $durationMs,
        int $valueBytes = 0
    ): void {
        if ($operation === 'get' && $hit !== null) {
            TelemetryContext::recordCache($hit);
        }

        if (!$this->shouldRecord()) {
            return;
        }

        $scope = TelemetryContext::scope();
        $update = TelemetryContext::update();
        $now = time();

        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO cache_metrics (
                    correlation_id,
                    module,
                    action,
                    namespace,
                    operation,
                    key_hash,
                    hit,
                    duration_ms,
                    value_bytes,
                    occurred_at,
                    created_at
                 ) VALUES (
                    :correlation_id,
                    :module,
                    :action,
                    :namespace,
                    :operation,
                    :key_hash,
                    :hit,
                    :duration_ms,
                    :value_bytes,
                    :occurred_at,
                    :created_at
                 )'
            );

            $statement->execute([
                'correlation_id' => $update?->correlationId,
                'module' => $scope['module'],
                'action' => $scope['action'],
                'namespace' => $this->namespace($key),
                'operation' => mb_substr(trim($operation), 0, 30),
                'key_hash' => hash('sha256', $key),
                'hit' => $hit === null
                    ? null
                    : ($hit ? 1 : 0),
                'duration_ms' => round(max(0.0, $durationMs), 3),
                'value_bytes' => max(0, $valueBytes),
                'occurred_at' => $now,
                'created_at' => date(DATE_ATOM, $now),
            ]);
        } catch (Throwable) {
            /* Metrics are best-effort. */
        }
    }

    private function namespace(string $key): string
    {
        $key = trim($key);
        $position = strcspn($key, '.:');
        $namespace = $position < strlen($key)
            ? substr($key, 0, $position)
            : $key;

        return mb_substr(
            $namespace !== '' ? $namespace : 'unknown',
            0,
            120
        );
    }

    private function shouldRecord(): bool
    {
        if (!$this->enabled) {
            return false;
        }

        $rate = max(0, min(100, $this->sampleRate));

        if ($rate === 0) {
            return false;
        }

        if ($rate === 100) {
            return true;
        }

        try {
            return random_int(1, 100)
                <= $rate;
        } catch (Throwable) {
            return true;
        }
    }
}
