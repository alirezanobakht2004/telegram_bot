<?php

declare(strict_types=1);

namespace SmartToolbox\Core;

use JsonException;
use PDO;
use PDOException;
use RuntimeException;

final class RuntimeSettings
{
    /**
     * @var array<string, mixed>|null
     */
    private ?array $overrides = null;

    public function __construct(
        private readonly Config $config,
        private readonly PDO $pdo
    ) {
    }

    public function get(
        string $key,
        mixed $default = null
    ): mixed {
        $overrides = $this->loadOverrides();

        if (array_key_exists($key, $overrides)) {
            return $overrides[$key];
        }

        return $this->config->get(
            $key,
            $default
        );
    }

    public function base(
        string $key,
        mixed $default = null
    ): mixed {
        return $this->config->get(
            $key,
            $default
        );
    }

    public function hasOverride(string $key): bool
    {
        return array_key_exists(
            $key,
            $this->loadOverrides()
        );
    }

    public function override(string $key): mixed
    {
        return $this->loadOverrides()[$key]
            ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function allOverrides(): array
    {
        return $this->loadOverrides();
    }

    public function set(
        string $key,
        mixed $value,
        string $updatedBy
    ): void {
        $key = trim($key);

        if ($key === '') {
            throw new RuntimeException(
                'Runtime setting key cannot be empty.'
            );
        }

        try {
            $valueJson = json_encode(
                $value,
                JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
            );
        } catch (JsonException $exception) {
            throw new RuntimeException(
                'Runtime setting value could not be encoded.',
                previous: $exception
            );
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO runtime_settings (
                setting_key,
                value_json,
                updated_at,
                updated_by
            ) VALUES (
                :setting_key,
                :value_json,
                :updated_at,
                :updated_by
            )
            ON CONFLICT(setting_key) DO UPDATE SET
                value_json = excluded.value_json,
                updated_at = excluded.updated_at,
                updated_by = excluded.updated_by'
        );

        $statement->execute([
            'setting_key' => $key,
            'value_json' => $valueJson,
            'updated_at' => date(DATE_ATOM),
            'updated_by' => mb_substr(
                trim($updatedBy),
                0,
                200
            ),
        ]);

        $this->overrides = null;
    }

    public function delete(string $key): bool
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM runtime_settings
             WHERE setting_key = :setting_key'
        );

        $statement->execute([
            'setting_key' => trim($key),
        ]);

        $this->overrides = null;

        return $statement->rowCount() > 0;
    }

    public function clear(): int
    {
        $deleted = $this->pdo->exec(
            'DELETE FROM runtime_settings'
        );

        $this->overrides = null;

        return is_int($deleted)
            ? $deleted
            : 0;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadOverrides(): array
    {
        if ($this->overrides !== null) {
            return $this->overrides;
        }

        try {
            $statement = $this->pdo->query(
                'SELECT setting_key, value_json
                 FROM runtime_settings
                 ORDER BY setting_key ASC'
            );
        } catch (PDOException) {
            /*
             * در فاصله کوتاه بین Deploy کد و اجرای Migration،
             * ربات با مقادیر فایل به کار ادامه می‌دهد.
             */
            $this->overrides = [];

            return [];
        }

        $overrides = [];

        while (
            $row = $statement->fetch(PDO::FETCH_ASSOC)
        ) {
            $key = $row['setting_key'] ?? null;
            $valueJson = $row['value_json'] ?? null;

            if (
                !is_string($key)
                || !is_string($valueJson)
            ) {
                continue;
            }

            try {
                $overrides[$key] = json_decode(
                    $valueJson,
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );
            } catch (JsonException) {
                continue;
            }
        }

        $this->overrides = $overrides;

        return $overrides;
    }
}
