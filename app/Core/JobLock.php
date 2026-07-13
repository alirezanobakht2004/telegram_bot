<?php

declare(strict_types=1);

namespace SmartToolbox\Core;

use PDO;

final class JobLock
{
    public function __construct(
        private readonly PDO $pdo
    ) {
    }

    public function acquire(
        string $key,
        string $owner,
        int $ttlSeconds
    ): bool {
        $key = mb_substr(trim($key), 0, 200);
        $owner = mb_substr(trim($owner), 0, 200);
        $ttlSeconds = max(10, $ttlSeconds);
        $now = time();

        $statement = $this->pdo->prepare(
            'INSERT INTO job_locks (
                lock_key,
                owner,
                acquired_at,
                expires_at
             ) VALUES (
                :lock_key,
                :owner,
                :acquired_at,
                :expires_at
             )
             ON CONFLICT(lock_key) DO UPDATE SET
                owner = excluded.owner,
                acquired_at = excluded.acquired_at,
                expires_at = excluded.expires_at
             WHERE job_locks.expires_at <= :now
                OR job_locks.owner = :owner'
        );

        $statement->execute([
            'lock_key' => $key,
            'owner' => $owner,
            'acquired_at' => $now,
            'expires_at' => $now + $ttlSeconds,
            'now' => $now,
        ]);

        return $statement->rowCount() > 0;
    }

    public function refresh(
        string $key,
        string $owner,
        int $ttlSeconds
    ): bool {
        $statement = $this->pdo->prepare(
            'UPDATE job_locks
             SET expires_at = :expires_at
             WHERE lock_key = :lock_key
               AND owner = :owner'
        );

        $statement->execute([
            'expires_at' => time() + max(10, $ttlSeconds),
            'lock_key' => mb_substr(trim($key), 0, 200),
            'owner' => mb_substr(trim($owner), 0, 200),
        ]);

        return $statement->rowCount() > 0;
    }

    public function release(
        string $key,
        string $owner
    ): void {
        $statement = $this->pdo->prepare(
            'DELETE FROM job_locks
             WHERE lock_key = :lock_key
               AND owner = :owner'
        );

        $statement->execute([
            'lock_key' => mb_substr(trim($key), 0, 200),
            'owner' => mb_substr(trim($owner), 0, 200),
        ]);
    }

    public function pruneExpired(): int
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM job_locks
             WHERE expires_at <= :now'
        );

        $statement->execute([
            'now' => time(),
        ]);

        return $statement->rowCount();
    }
}
