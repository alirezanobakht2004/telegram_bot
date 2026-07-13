<?php

declare(strict_types=1);

namespace SmartToolbox\Core;

use Throwable;

final class UsageSpan
{
    private readonly int $startedAtNanoseconds;

    /**
     * @var array{
     *     cache_hits: int,
     *     cache_misses: int,
     *     api_calls: int,
     *     api_failures: int,
     *     api_duration_ms: float
     * }
     */
    private readonly array $telemetrySnapshot;

    private readonly int $scopeToken;

    private bool $finished = false;

    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private readonly UsageTracker $tracker,
        private readonly string $module,
        private readonly string $action,
        private readonly string $inputKind,
        private readonly ?UpdateContext $context,
        private readonly ?int $userId,
        private readonly ?int $chatId,
        private readonly ?string $chatType,
        private array $metadata = []
    ) {
        $this->startedAtNanoseconds = hrtime(true);
        $this->telemetrySnapshot = TelemetryContext::snapshot();
        $this->scopeToken = TelemetryContext::pushScope(
            $this->module,
            $this->action
        );
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function success(array $metadata = []): float
    {
        return $this->finish(
            success: true,
            errorCode: null,
            errorMessage: null,
            metadata: $metadata
        );
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function failure(
        Throwable|string $error,
        ?string $errorCode = null,
        array $metadata = []
    ): float {
        $message = $error instanceof Throwable
            ? $error->getMessage()
            : $error;

        $code = $errorCode;

        if ($code === null && $error instanceof Throwable) {
            $class = $error::class;
            $separator = strrpos($class, '\\');
            $code = $separator === false
                ? $class
                : substr($class, $separator + 1);
        }

        return $this->finish(
            success: false,
            errorCode: $code ?? 'error',
            errorMessage: $message,
            metadata: $metadata
        );
    }

    public function discard(): float
    {
        if ($this->finished) {
            return 0.0;
        }

        $this->finished = true;
        TelemetryContext::popScope($this->scopeToken);

        return $this->durationMs();
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function finish(
        bool $success,
        ?string $errorCode,
        ?string $errorMessage,
        array $metadata
    ): float {
        if ($this->finished) {
            return 0.0;
        }

        $this->finished = true;
        $durationMs = $this->durationMs();
        $current = TelemetryContext::snapshot();

        $cacheHits = max(
            0,
            $current['cache_hits']
            - $this->telemetrySnapshot['cache_hits']
        );

        $cacheMisses = max(
            0,
            $current['cache_misses']
            - $this->telemetrySnapshot['cache_misses']
        );

        $apiCalls = max(
            0,
            $current['api_calls']
            - $this->telemetrySnapshot['api_calls']
        );

        $apiFailures = max(
            0,
            $current['api_failures']
            - $this->telemetrySnapshot['api_failures']
        );

        $apiDuration = max(
            0.0,
            $current['api_duration_ms']
            - $this->telemetrySnapshot['api_duration_ms']
        );

        $cacheHit = null;

        if ($cacheHits > 0) {
            $cacheHit = true;
        } elseif ($cacheMisses > 0) {
            $cacheHit = false;
        }

        $this->metadata = [
            ...$this->metadata,
            ...$metadata,
            'cache_hits' => $cacheHits,
            'cache_misses' => $cacheMisses,
            'api_calls' => $apiCalls,
            'api_failures' => $apiFailures,
            'api_duration_ms' => round($apiDuration, 3),
        ];

        TelemetryContext::popScope($this->scopeToken);

        $this->tracker->record(
            module: $this->module,
            action: $this->action,
            inputKind: $this->inputKind,
            durationMs: $durationMs,
            success: $success,
            cacheHit: $cacheHit,
            errorCode: $errorCode,
            errorMessage: $errorMessage,
            context: $this->context,
            userId: $this->userId,
            chatId: $this->chatId,
            chatType: $this->chatType,
            metadata: $this->metadata
        );

        return $durationMs;
    }

    private function durationMs(): float
    {
        return max(
            0.0,
            (hrtime(true) - $this->startedAtNanoseconds)
            / 1_000_000
        );
    }
}
