<?php

declare(strict_types=1);

namespace SmartToolbox\Core;

use PDO;
use RuntimeException;

final class UserPreferenceStore
{
    public function __construct(
        private readonly PDO $pdo
    ) {
    }

    public function get(
        string $actorKey,
        string $key,
        ?string $default = null
    ): ?string {
        $actorKey = $this->validateActorKey(
            $actorKey
        );

        $key = $this->validateKey($key);

        $statement = $this->pdo->prepare(
            'SELECT preference_value
             FROM user_preferences
             WHERE actor_key = :actor_key
               AND preference_key = :preference_key
             LIMIT 1'
        );

        $statement->execute([
            'actor_key' => $actorKey,
            'preference_key' => $key,
        ]);

        $value = $statement->fetchColumn();

        return is_string($value)
            ? $value
            : $default;
    }

    public function set(
        string $actorKey,
        string $key,
        string $value
    ): void {
        $actorKey = $this->validateActorKey(
            $actorKey
        );

        $key = $this->validateKey($key);

        if (strlen($value) > 1000) {
            throw new RuntimeException(
                'Preference value is too long.'
            );
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO user_preferences (
                actor_key,
                preference_key,
                preference_value,
                updated_at
            ) VALUES (
                :actor_key,
                :preference_key,
                :preference_value,
                :updated_at
            )
            ON CONFLICT(actor_key, preference_key)
            DO UPDATE SET
                preference_value =
                    excluded.preference_value,
                updated_at = excluded.updated_at'
        );

        $statement->execute([
            'actor_key' => $actorKey,
            'preference_key' => $key,
            'preference_value' => $value,
            'updated_at' => date(DATE_ATOM),
        ]);
    }

    public function delete(
        string $actorKey,
        string $key
    ): void {
        $statement = $this->pdo->prepare(
            'DELETE FROM user_preferences
             WHERE actor_key = :actor_key
               AND preference_key = :preference_key'
        );

        $statement->execute([
            'actor_key' => $this->validateActorKey(
                $actorKey
            ),
            'preference_key' => $this->validateKey(
                $key
            ),
        ]);
    }

    public function clear(string $actorKey): int
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM user_preferences
             WHERE actor_key = :actor_key'
        );

        $statement->execute([
            'actor_key' => $this->validateActorKey(
                $actorKey
            ),
        ]);

        return $statement->rowCount();
    }

    /**
     * @return array<string, string>
     */
    public function all(string $actorKey): array
    {
        $statement = $this->pdo->prepare(
            'SELECT preference_key, preference_value
             FROM user_preferences
             WHERE actor_key = :actor_key
             ORDER BY preference_key ASC'
        );

        $statement->execute([
            'actor_key' => $this->validateActorKey(
                $actorKey
            ),
        ]);

        $preferences = [];

        while (
            $row = $statement->fetch(PDO::FETCH_ASSOC)
        ) {
            if (
                !is_string(
                    $row['preference_key'] ?? null
                )
                || !is_string(
                    $row['preference_value'] ?? null
                )
            ) {
                continue;
            }

            $preferences[
                $row['preference_key']
            ] = $row['preference_value'];
        }

        return $preferences;
    }

    private function validateActorKey(
        string $actorKey
    ): string {
        $actorKey = trim($actorKey);

        if ($actorKey === '') {
            throw new RuntimeException(
                'Preference actor key cannot be empty.'
            );
        }

        if (strlen($actorKey) > 150) {
            throw new RuntimeException(
                'Preference actor key is too long.'
            );
        }

        return $actorKey;
    }

    private function validateKey(string $key): string
    {
        $key = trim($key);

        if (
            preg_match(
                '/^[a-z0-9_.-]{1,100}$/',
                $key
            ) !== 1
        ) {
            throw new RuntimeException(
                'Preference key is invalid.'
            );
        }

        return $key;
    }
}
