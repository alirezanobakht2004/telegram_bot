<?php

declare(strict_types=1);

namespace SmartToolbox\Core;

use PDO;
use Throwable;

final class ApiMetricsTracker
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly bool $enabled = true,
        private readonly int $sampleRate = 100
    ) {
    }

    public function record(
        string $provider,
        string $method,
        string $host,
        string $path,
        ?int $statusCode,
        float $durationMs,
        int $responseBytes,
        bool $success,
        ?string $errorCode = null
    ): void {
        TelemetryContext::recordApi(
            $durationMs,
            $success
        );

        if (!$this->shouldRecord($success)) {
            return;
        }

        $scope = TelemetryContext::scope();
        $update = TelemetryContext::update();
        $now = time();

        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO api_metrics (
                    correlation_id,
                    module,
                    action,
                    provider,
                    http_method,
                    host,
                    path,
                    status_code,
                    duration_ms,
                    response_bytes,
                    success,
                    error_code,
                    occurred_at,
                    created_at
                 ) VALUES (
                    :correlation_id,
                    :module,
                    :action,
                    :provider,
                    :http_method,
                    :host,
                    :path,
                    :status_code,
                    :duration_ms,
                    :response_bytes,
                    :success,
                    :error_code,
                    :occurred_at,
                    :created_at
                 )'
            );

            $statement->execute([
                'correlation_id' => $update?->correlationId,
                'module' => $scope['module'],
                'action' => $scope['action'],
                'provider' => mb_substr(trim($provider), 0, 120),
                'http_method' => mb_substr(strtoupper(trim($method)), 0, 12),
                'host' => mb_substr(mb_strtolower(trim($host)), 0, 255),
                'path' => mb_substr($path !== '' ? $path : '/', 0, 500),
                'status_code' => $statusCode,
                'duration_ms' => round(max(0.0, $durationMs), 3),
                'response_bytes' => max(0, $responseBytes),
                'success' => $success ? 1 : 0,
                'error_code' => $errorCode !== null
                    ? mb_substr($errorCode, 0, 120)
                    : null,
                'occurred_at' => $now,
                'created_at' => date(DATE_ATOM, $now),
            ]);
        } catch (Throwable) {
            /* Metrics are best-effort. */
        }
    }

    private function shouldRecord(bool $success): bool
    {
        if (!$this->enabled) {
            return false;
        }

        if (!$success) {
            return true;
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
