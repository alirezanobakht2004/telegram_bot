<?php

declare(strict_types=1);

namespace SmartToolbox\Core;

use PDO;
use RuntimeException;

final class FeatureRegistry
{
    /**
     * @param array<string, array{
     *     enabled?: bool,
     *     rollout_percentage?: int,
     *     description?: string
     * }|bool> $defaults
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly array $defaults = []
    ) {
    }

    public function isEnabled(
        string $key,
        int|string|null $subject = null
    ): bool {
        $feature = $this->get($key);

        if (!$feature['enabled']) {
            return false;
        }

        $rollout = $feature['rollout_percentage'];

        if ($rollout >= 100 || $subject === null) {
            return true;
        }

        if ($rollout <= 0) {
            return false;
        }

        $bucket = (int) sprintf(
            '%u',
            crc32($key . ':' . (string) $subject)
        ) % 100;

        return $bucket < $rollout;
    }

    /**
     * @return array{
     *     key: string,
     *     enabled: bool,
     *     rollout_percentage: int,
     *     description: string,
     *     source: string,
     *     updated_at: ?string,
     *     updated_by: ?string
     * }
     */
    public function get(string $key): array
    {
        $key = $this->normalizeKey($key);

        $statement = $this->pdo->prepare(
            'SELECT
                enabled,
                rollout_percentage,
                description,
                updated_at,
                updated_by
             FROM feature_flags
             WHERE flag_key = :flag_key
             LIMIT 1'
        );

        $statement->execute([
            'flag_key' => $key,
        ]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if (is_array($row)) {
            return [
                'key' => $key,
                'enabled' => (int) $row['enabled'] === 1,
                'rollout_percentage' => max(
                    0,
                    min(100, (int) $row['rollout_percentage'])
                ),
                'description' => (string) $row['description'],
                'source' => 'database',
                'updated_at' => (string) $row['updated_at'],
                'updated_by' => (string) $row['updated_by'],
            ];
        }

        $default = $this->defaults[$key] ?? false;

        if (is_bool($default)) {
            return [
                'key' => $key,
                'enabled' => $default,
                'rollout_percentage' => 100,
                'description' => '',
                'source' => 'config',
                'updated_at' => null,
                'updated_by' => null,
            ];
        }

        return [
            'key' => $key,
            'enabled' => (bool) ($default['enabled'] ?? false),
            'rollout_percentage' => max(
                0,
                min(100, (int) ($default['rollout_percentage'] ?? 100))
            ),
            'description' => (string) ($default['description'] ?? ''),
            'source' => 'config',
            'updated_at' => null,
            'updated_by' => null,
        ];
    }

    /**
     * @return list<array{
     *     key: string,
     *     enabled: bool,
     *     rollout_percentage: int,
     *     description: string,
     *     source: string,
     *     updated_at: ?string,
     *     updated_by: ?string
     * }>
     */
    public function all(): array
    {
        $keys = array_keys($this->defaults);

        $statement = $this->pdo->query(
            'SELECT flag_key
             FROM feature_flags
             ORDER BY flag_key ASC'
        );

        while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
            if (is_string($row['flag_key'] ?? null)) {
                $keys[] = $row['flag_key'];
            }
        }

        $keys = array_values(array_unique($keys));
        sort($keys, SORT_STRING);

        return array_map(
            fn (string $key): array => $this->get($key),
            $keys
        );
    }

    public function set(
        string $key,
        bool $enabled,
        int $rolloutPercentage,
        string $description,
        string $updatedBy
    ): void {
        $key = $this->normalizeKey($key);
        $rolloutPercentage = max(
            0,
            min(100, $rolloutPercentage)
        );

        $statement = $this->pdo->prepare(
            'INSERT INTO feature_flags (
                flag_key,
                enabled,
                rollout_percentage,
                description,
                updated_at,
                updated_by
             ) VALUES (
                :flag_key,
                :enabled,
                :rollout_percentage,
                :description,
                :updated_at,
                :updated_by
             )
             ON CONFLICT(flag_key) DO UPDATE SET
                enabled = excluded.enabled,
                rollout_percentage = excluded.rollout_percentage,
                description = excluded.description,
                updated_at = excluded.updated_at,
                updated_by = excluded.updated_by'
        );

        $statement->execute([
            'flag_key' => $key,
            'enabled' => $enabled ? 1 : 0,
            'rollout_percentage' => $rolloutPercentage,
            'description' => mb_substr(trim($description), 0, 500),
            'updated_at' => date(DATE_ATOM),
            'updated_by' => mb_substr(trim($updatedBy), 0, 200),
        ]);
    }

    public function reset(string $key): bool
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM feature_flags
             WHERE flag_key = :flag_key'
        );

        $statement->execute([
            'flag_key' => $this->normalizeKey($key),
        ]);

        return $statement->rowCount() > 0;
    }

    private function normalizeKey(string $key): string
    {
        $key = mb_strtolower(trim($key));

        if (
            $key === ''
            || preg_match('/^[a-z0-9_.:-]{1,120}$/', $key) !== 1
        ) {
            throw new RuntimeException(
                'Feature flag key is invalid.'
            );
        }

        return $key;
    }
}
