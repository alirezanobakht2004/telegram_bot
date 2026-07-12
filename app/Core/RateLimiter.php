<?php

declare(strict_types=1);

namespace SmartToolbox\Core;

use PDO;
use RuntimeException;
use Throwable;

final class RateLimiter
{
    public function __construct(
        private readonly PDO $pdo
    ) {
    }

    public function attempt(
        string $key,
        int $maxAttempts,
        int $windowSeconds
    ): RateLimitResult {
        if (trim($key) === '') {
            throw new RuntimeException(
                'Rate-limit key cannot be empty.'
            );
        }

        if ($maxAttempts < 1 || $windowSeconds < 1) {
            throw new RuntimeException(
                'Rate-limit configuration is invalid.'
            );
        }

        $now = time();

        $this->pdo->exec('BEGIN IMMEDIATE');

        try {
            $select = $this->pdo->prepare(
                'SELECT hits, expires_at
                 FROM rate_limits
                 WHERE key = :key
                 LIMIT 1'
            );

            $select->execute([
                'key' => $key,
            ]);

            $row = $select->fetch(PDO::FETCH_ASSOC);

            if (
                !is_array($row)
                || (int) $row['expires_at'] <= $now
            ) {
                $expiresAt = $now + $windowSeconds;

                $upsert = $this->pdo->prepare(
                    'INSERT INTO rate_limits (
                        key,
                        hits,
                        window_started_at,
                        expires_at
                    ) VALUES (
                        :key,
                        1,
                        :window_started_at,
                        :expires_at
                    )
                    ON CONFLICT(key) DO UPDATE SET
                        hits = 1,
                        window_started_at = excluded.window_started_at,
                        expires_at = excluded.expires_at'
                );

                $upsert->execute([
                    'key' => $key,
                    'window_started_at' => $now,
                    'expires_at' => $expiresAt,
                ]);

                $this->pdo->exec('COMMIT');
                $this->pruneOccasionally($now);

                return new RateLimitResult(
                    allowed: true,
                    remaining: max(0, $maxAttempts - 1),
                    retryAfter: 0
                );
            }

            $hits = (int) $row['hits'];
            $expiresAt = (int) $row['expires_at'];

            if ($hits >= $maxAttempts) {
                $this->pdo->exec('COMMIT');

                return new RateLimitResult(
                    allowed: false,
                    remaining: 0,
                    retryAfter: max(1, $expiresAt - $now)
                );
            }

            $update = $this->pdo->prepare(
                'UPDATE rate_limits
                 SET hits = hits + 1
                 WHERE key = :key'
            );

            $update->execute([
                'key' => $key,
            ]);

            $this->pdo->exec('COMMIT');
            $this->pruneOccasionally($now);

            return new RateLimitResult(
                allowed: true,
                remaining: max(0, $maxAttempts - $hits - 1),
                retryAfter: 0
            );
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            } else {
                try {
                    $this->pdo->exec('ROLLBACK');
                } catch (Throwable) {
                }
            }

            throw $exception;
        }
    }

    private function pruneOccasionally(int $now): void
    {
        if (random_int(1, 100) !== 1) {
            return;
        }

        $statement = $this->pdo->prepare(
            'DELETE FROM rate_limits
             WHERE expires_at <= :now'
        );

        $statement->execute([
            'now' => $now,
        ]);
    }
}
