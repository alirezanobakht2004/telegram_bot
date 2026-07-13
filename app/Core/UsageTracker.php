<?php

declare(strict_types=1);

namespace SmartToolbox\Core;

use JsonException;
use PDO;
use Throwable;

final class UsageTracker
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly bool $enabled = true,
        private readonly int $sampleRate = 100
    ) {
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function start(
        string $module,
        string $action,
        string $inputKind,
        ?UpdateContext $context = null,
        ?int $userId = null,
        ?int $chatId = null,
        ?string $chatType = null,
        array $metadata = []
    ): UsageSpan {
        return new UsageSpan(
            tracker: $this,
            module: $module,
            action: $action,
            inputKind: $inputKind,
            context: $context ?? TelemetryContext::update(),
            userId: $userId,
            chatId: $chatId,
            chatType: $chatType,
            metadata: $metadata
        );
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function record(
        string $module,
        string $action,
        string $inputKind,
        float $durationMs,
        bool $success,
        ?bool $cacheHit,
        ?string $errorCode,
        ?string $errorMessage,
        ?UpdateContext $context,
        ?int $userId,
        ?int $chatId,
        ?string $chatType,
        array $metadata
    ): void {
        if (!$this->shouldRecord($success)) {
            return;
        }

        $context ??= TelemetryContext::update();

        try {
            $metadataJson = json_encode(
                $metadata,
                JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
            );
        } catch (JsonException) {
            $metadataJson = '{}';
        }

        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO usage_events (
                    event_uuid,
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
                    error_message,
                    metadata_json,
                    occurred_at,
                    created_at
                 ) VALUES (
                    :event_uuid,
                    :correlation_id,
                    :update_id,
                    :update_type,
                    :user_id,
                    :chat_id,
                    :chat_type,
                    :module,
                    :action,
                    :input_kind,
                    :duration_ms,
                    :success,
                    :cache_hit,
                    :error_code,
                    :error_message,
                    :metadata_json,
                    :occurred_at,
                    :created_at
                 )'
            );

            $now = time();

            $statement->execute([
                'event_uuid' => bin2hex(random_bytes(16)),
                'correlation_id' => $context?->correlationId,
                'update_id' => $context?->updateId,
                'update_type' => $context?->type,
                'user_id' => $userId ?? $context?->userId(),
                'chat_id' => $chatId ?? $context?->chatId(),
                'chat_type' => $chatType ?? $context?->chatType(),
                'module' => $this->normalize($module, 'unknown'),
                'action' => $this->normalize($action, 'unknown'),
                'input_kind' => $this->normalize($inputKind, 'unknown'),
                'duration_ms' => round(max(0.0, $durationMs), 3),
                'success' => $success ? 1 : 0,
                'cache_hit' => $cacheHit === null
                    ? null
                    : ($cacheHit ? 1 : 0),
                'error_code' => $errorCode !== null
                    ? mb_substr($errorCode, 0, 120)
                    : null,
                'error_message' => $errorMessage !== null
                    ? mb_substr($errorMessage, 0, 500)
                    : null,
                'metadata_json' => $metadataJson,
                'occurred_at' => $now,
                'created_at' => date(DATE_ATOM, $now),
            ]);
        } catch (Throwable) {
            /* Telemetry must never break the bot request. */
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
            return random_int(1, 100) <= $rate;
        } catch (Throwable) {
            return true;
        }
    }

    private function normalize(
        string $value,
        string $fallback
    ): string {
        $value = trim($value);

        if ($value === '') {
            return $fallback;
        }

        $value = preg_replace(
            '/[^\p{L}\p{N}_.:-]+/u',
            '_',
            $value
        ) ?? $value;

        return mb_substr($value, 0, 120);
    }
}
