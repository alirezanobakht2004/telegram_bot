<?php

declare(strict_types=1);

namespace SmartToolbox\Core;

final class TelemetryContext
{
    private static ?UpdateContext $update = null;

    /**
     * @var list<array{module: string, action: string}>
     */
    private static array $scopeStack = [];

    private static int $cacheHits = 0;

    private static int $cacheMisses = 0;

    private static int $apiCalls = 0;

    private static int $apiFailures = 0;

    private static float $apiDurationMs = 0.0;

    public static function begin(UpdateContext $update): void
    {
        self::$update = $update;
        self::$scopeStack = [];
        self::$cacheHits = 0;
        self::$cacheMisses = 0;
        self::$apiCalls = 0;
        self::$apiFailures = 0;
        self::$apiDurationMs = 0.0;
    }

    public static function clear(): void
    {
        self::$update = null;
        self::$scopeStack = [];
        self::$cacheHits = 0;
        self::$cacheMisses = 0;
        self::$apiCalls = 0;
        self::$apiFailures = 0;
        self::$apiDurationMs = 0.0;
    }

    public static function update(): ?UpdateContext
    {
        return self::$update;
    }

    public static function pushScope(
        string $module,
        string $action
    ): int {
        self::$scopeStack[] = [
            'module' => self::normalize($module, 'unknown'),
            'action' => self::normalize($action, 'unknown'),
        ];

        return count(self::$scopeStack);
    }

    public static function popScope(int $token): void
    {
        if ($token < 1) {
            return;
        }

        while (count(self::$scopeStack) >= $token) {
            array_pop(self::$scopeStack);
        }
    }

    /**
     * @return array{module: ?string, action: ?string}
     */
    public static function scope(): array
    {
        $scope = self::$scopeStack[
            array_key_last(self::$scopeStack)
        ] ?? null;

        return [
            'module' => is_array($scope)
                ? $scope['module']
                : null,
            'action' => is_array($scope)
                ? $scope['action']
                : null,
        ];
    }

    public static function recordCache(bool $hit): void
    {
        if ($hit) {
            self::$cacheHits++;

            return;
        }

        self::$cacheMisses++;
    }

    public static function recordApi(
        float $durationMs,
        bool $success
    ): void {
        self::$apiCalls++;
        self::$apiDurationMs += max(0.0, $durationMs);

        if (!$success) {
            self::$apiFailures++;
        }
    }

    /**
     * @return array{
     *     cache_hits: int,
     *     cache_misses: int,
     *     api_calls: int,
     *     api_failures: int,
     *     api_duration_ms: float
     * }
     */
    public static function snapshot(): array
    {
        return [
            'cache_hits' => self::$cacheHits,
            'cache_misses' => self::$cacheMisses,
            'api_calls' => self::$apiCalls,
            'api_failures' => self::$apiFailures,
            'api_duration_ms' => self::$apiDurationMs,
        ];
    }

    private static function normalize(
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
