<?php

declare(strict_types=1);

namespace SmartToolbox\Core;

use JsonException;
use PDO;
use RuntimeException;

final class ConversationStateStore
{
    public function __construct(
        private readonly PDO $pdo
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function set(
        string $actorKey,
        string $state,
        array $payload = [],
        int $ttlSeconds = 300
    ): void {
        $actorKey = trim($actorKey);
        $state = trim($state);

        if ($actorKey === '') {
            throw new RuntimeException(
                'Conversation actor key cannot be empty.'
            );
        }

        if ($state === '') {
            throw new RuntimeException(
                'Conversation state cannot be empty.'
            );
        }

        if ($ttlSeconds < 1) {
            throw new RuntimeException(
                'Conversation state TTL must be positive.'
            );
        }

        try {
            $payloadJson = json_encode(
                $payload,
                JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
            );
        } catch (JsonException $exception) {
            throw new RuntimeException(
                'Could not encode conversation state payload.',
                previous: $exception
            );
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO conversation_states (
                actor_key,
                state,
                payload,
                expires_at,
                updated_at
            ) VALUES (
                :actor_key,
                :state,
                :payload,
                :expires_at,
                :updated_at
            )
            ON CONFLICT(actor_key) DO UPDATE SET
                state = excluded.state,
                payload = excluded.payload,
                expires_at = excluded.expires_at,
                updated_at = excluded.updated_at'
        );

        $statement->execute([
            'actor_key' => $actorKey,
            'state' => $state,
            'payload' => $payloadJson,
            'expires_at' => time() + $ttlSeconds,
            'updated_at' => date(DATE_ATOM),
        ]);

        $this->pruneOccasionally();
    }

    /**
     * @return array{
     *     state: string,
     *     payload: array<string, mixed>,
     *     expires_at: int
     * }|null
     */
    public function get(string $actorKey): ?array
    {
        $actorKey = trim($actorKey);

        if ($actorKey === '') {
            return null;
        }

        $statement = $this->pdo->prepare(
            'SELECT state, payload, expires_at
             FROM conversation_states
             WHERE actor_key = :actor_key
             LIMIT 1'
        );

        $statement->execute([
            'actor_key' => $actorKey,
        ]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }

        $expiresAt = (int) ($row['expires_at'] ?? 0);

        if ($expiresAt <= time()) {
            $this->clear($actorKey);

            return null;
        }

        $payload = [];

        $payloadJson = $row['payload'] ?? null;

        if (is_string($payloadJson) && $payloadJson !== '') {
            try {
                $decoded = json_decode(
                    $payloadJson,
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );

                if (is_array($decoded)) {
                    $payload = $decoded;
                }
            } catch (JsonException) {
                $this->clear($actorKey);

                return null;
            }
        }

        return [
            'state' => (string) ($row['state'] ?? ''),
            'payload' => $payload,
            'expires_at' => $expiresAt,
        ];
    }

    public function clear(string $actorKey): void
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM conversation_states
             WHERE actor_key = :actor_key'
        );

        $statement->execute([
            'actor_key' => trim($actorKey),
        ]);
    }

    private function pruneOccasionally(): void
    {
        if (random_int(1, 100) !== 1) {
            return;
        }

        $statement = $this->pdo->prepare(
            'DELETE FROM conversation_states
             WHERE expires_at <= :now'
        );

        $statement->execute([
            'now' => time(),
        ]);
    }
}
