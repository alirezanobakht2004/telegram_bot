<?php

declare(strict_types=1);

namespace SmartToolbox\Modules\MiniApp;

use PDO;
use Throwable;

final class MiniAppRateLimiter
{
    public function __construct(
        private readonly PDO $pdo
    ) {
    }

    /**
     * @return array{allowed:bool,retry_after:int,remaining:int}
     */
    public function attempt(
        string $key,
        int $maxAttempts,
        int $windowSeconds
    ): array {
        $maxAttempts = max(
            1,
            min(1000, $maxAttempts)
        );
        $windowSeconds = max(
            1,
            min(86400, $windowSeconds)
        );
        $now = time();
        $keyHash = hash('sha256', $key);

        $this->pdo->exec('BEGIN IMMEDIATE');

        try {
            $statement = $this->pdo->prepare(
                'SELECT
                    window_started_at,
                    attempts,
                    expires_at
                 FROM mini_app_rate_limits
                 WHERE key_hash = :key_hash
                 LIMIT 1'
            );
            $statement->execute([
                'key_hash' => $keyHash,
            ]);

            $row = $statement->fetch(
                PDO::FETCH_ASSOC
            );

            if (
                !is_array($row)
                || (int) $row['expires_at'] <= $now
            ) {
                $upsert = $this->pdo->prepare(
                    'INSERT INTO mini_app_rate_limits (
                        key_hash,
                        window_started_at,
                        attempts,
                        expires_at,
                        updated_at
                     ) VALUES (
                        :key_hash,
                        :window_started_at,
                        1,
                        :expires_at,
                        :updated_at
                     )
                     ON CONFLICT(key_hash)
                     DO UPDATE SET
                        window_started_at = excluded.window_started_at,
                        attempts = 1,
                        expires_at = excluded.expires_at,
                        updated_at = excluded.updated_at'
                );

                $upsert->execute([
                    'key_hash' => $keyHash,
                    'window_started_at' => $now,
                    'expires_at' => $now + $windowSeconds,
                    'updated_at' => date(DATE_ATOM),
                ]);

                $this->pdo->exec('COMMIT');

                return [
                    'allowed' => true,
                    'retry_after' => 0,
                    'remaining' => max(0, $maxAttempts - 1),
                ];
            }

            $attempts = (int) $row['attempts'];
            $expiresAt = (int) $row['expires_at'];

            if ($attempts >= $maxAttempts) {
                $this->pdo->exec('COMMIT');

                return [
                    'allowed' => false,
                    'retry_after' => max(1, $expiresAt - $now),
                    'remaining' => 0,
                ];
            }

            $update = $this->pdo->prepare(
                'UPDATE mini_app_rate_limits
                 SET
                    attempts = attempts + 1,
                    updated_at = :updated_at
                 WHERE key_hash = :key_hash'
            );
            $update->execute([
                'updated_at' => date(DATE_ATOM),
                'key_hash' => $keyHash,
            ]);

            $this->pdo->exec('COMMIT');

            return [
                'allowed' => true,
                'retry_after' => 0,
                'remaining' => max(
                    0,
                    $maxAttempts - $attempts - 1
                ),
            ];
        } catch (Throwable $exception) {
            try {
                $this->pdo->exec('ROLLBACK');
            } catch (Throwable) {
            }

            throw $exception;
        }
    }
}
