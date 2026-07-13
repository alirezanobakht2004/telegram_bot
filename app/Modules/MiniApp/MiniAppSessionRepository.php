<?php

declare(strict_types=1);

namespace SmartToolbox\Modules\MiniApp;

use PDO;
use Throwable;

final class MiniAppSessionRepository
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly int $idleTtlSeconds = 1200,
        private readonly int $absoluteTtlSeconds = 21600,
        private readonly int $maxActivePerUser = 5
    ) {
    }

    /**
     * @return array{
     *     token:string,
     *     csrf_token:string,
     *     session:array<string,mixed>
     * }
     */
    public function create(
        int $userId,
        string $initDataHash,
        string $ipAddress,
        string $userAgent
    ): array {
        if ($userId <= 0) {
            throw new MiniAppException(
                'شناسه کاربر Session معتبر نیست.',
                'session_user_invalid',
                500
            );
        }

        $token = bin2hex(random_bytes(32));
        $csrfToken = bin2hex(random_bytes(32));
        $sessionHash = $this->hashToken($token);
        $csrfHash = $this->hashToken($csrfToken);
        $now = time();
        $idleTtl = max(
            300,
            min(3600, $this->idleTtlSeconds)
        );
        $absoluteTtl = max(
            $idleTtl,
            min(86400, $this->absoluteTtlSeconds)
        );

        $this->pdo->exec('BEGIN IMMEDIATE');

        try {
            $insert = $this->pdo->prepare(
                'INSERT INTO mini_app_sessions (
                    session_hash,
                    user_id,
                    csrf_hash,
                    init_data_hash,
                    ip_hash,
                    user_agent_hash,
                    created_at,
                    last_seen_at,
                    expires_at,
                    absolute_expires_at
                 ) VALUES (
                    :session_hash,
                    :user_id,
                    :csrf_hash,
                    :init_data_hash,
                    :ip_hash,
                    :user_agent_hash,
                    :created_at,
                    :last_seen_at,
                    :expires_at,
                    :absolute_expires_at
                 )'
            );

            $insert->execute([
                'session_hash' => $sessionHash,
                'user_id' => $userId,
                'csrf_hash' => $csrfHash,
                'init_data_hash' => mb_substr(
                    $initDataHash,
                    0,
                    128
                ),
                'ip_hash' => $this->hashContext(
                    $ipAddress
                ),
                'user_agent_hash' => $this->hashContext(
                    $userAgent
                ),
                'created_at' => $now,
                'last_seen_at' => $now,
                'expires_at' => $now + $idleTtl,
                'absolute_expires_at' =>
                    $now + $absoluteTtl,
            ]);

            $sessionId = (int) $this->pdo
                ->lastInsertId();

            $maxActive = max(
                1,
                min(20, $this->maxActivePerUser)
            );

            $revokeOld = $this->pdo->prepare(
                "UPDATE mini_app_sessions
                 SET
                    revoked_at = :revoked_at,
                    revocation_reason = :reason
                 WHERE user_id = :user_id
                   AND revoked_at IS NULL
                   AND id NOT IN (
                        SELECT id
                        FROM mini_app_sessions
                        WHERE user_id = :user_id_inner
                          AND revoked_at IS NULL
                        ORDER BY id DESC
                        LIMIT :keep_limit
                   )"
            );

            $revokeOld->bindValue(
                ':revoked_at',
                $now,
                PDO::PARAM_INT
            );
            $revokeOld->bindValue(
                ':reason',
                'active_session_limit',
                PDO::PARAM_STR
            );
            $revokeOld->bindValue(
                ':user_id',
                $userId,
                PDO::PARAM_INT
            );
            $revokeOld->bindValue(
                ':user_id_inner',
                $userId,
                PDO::PARAM_INT
            );
            $revokeOld->bindValue(
                ':keep_limit',
                $maxActive,
                PDO::PARAM_INT
            );
            $revokeOld->execute();

            $this->pdo->exec('COMMIT');
        } catch (Throwable $exception) {
            try {
                $this->pdo->exec('ROLLBACK');
            } catch (Throwable) {
            }

            throw $exception;
        }

        return [
            'token' => $token,
            'csrf_token' => $csrfToken,
            'session' => [
                'id' => $sessionId,
                'user_id' => $userId,
                'session_hash' => $sessionHash,
                'csrf_hash' => $csrfHash,
                'created_at' => $now,
                'last_seen_at' => $now,
                'expires_at' => $now + $idleTtl,
                'absolute_expires_at' =>
                    $now + $absoluteTtl,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function authenticate(
        string $token,
        string $userAgent,
        bool $touch = true
    ): array {
        if (
            preg_match('/^[a-f0-9]{64}$/', $token) !== 1
        ) {
            throw new MiniAppException(
                'Session Mini App معتبر نیست.',
                'session_invalid',
                401
            );
        }

        $statement = $this->pdo->prepare(
            'SELECT *
             FROM mini_app_sessions
             WHERE session_hash = :session_hash
             LIMIT 1'
        );

        $statement->execute([
            'session_hash' => $this->hashToken($token),
        ]);

        $session = $statement->fetch(
            PDO::FETCH_ASSOC
        );

        if (!is_array($session)) {
            throw new MiniAppException(
                'Session Mini App پیدا نشد.',
                'session_not_found',
                401
            );
        }

        if ($session['revoked_at'] !== null) {
            throw new MiniAppException(
                'Session Mini App لغو شده است.',
                'session_revoked',
                401
            );
        }

        $now = time();

        if (
            (int) $session['expires_at'] < $now
            || (int) $session['absolute_expires_at'] < $now
        ) {
            $this->revokeSession(
                (int) $session['id'],
                'expired'
            );

            throw new MiniAppException(
                'Session Mini App منقضی شده است؛ برنامه را دوباره باز کن.',
                'session_expired',
                401
            );
        }

        if (!hash_equals(
            (string) $session['user_agent_hash'],
            $this->hashContext($userAgent)
        )) {
            $this->revokeSession(
                (int) $session['id'],
                'user_agent_changed'
            );

            throw new MiniAppException(
                'مشخصات Session تغییر کرده است؛ برنامه را دوباره باز کن.',
                'session_context_mismatch',
                401
            );
        }

        if ($touch) {
            $newExpiry = min(
                (int) $session['absolute_expires_at'],
                $now + max(
                    300,
                    min(3600, $this->idleTtlSeconds)
                )
            );

            if (
                $now - (int) $session['last_seen_at']
                >= 30
            ) {
                $update = $this->pdo->prepare(
                    'UPDATE mini_app_sessions
                     SET
                        last_seen_at = :last_seen_at,
                        expires_at = :expires_at
                     WHERE id = :id
                       AND revoked_at IS NULL'
                );

                $update->execute([
                    'last_seen_at' => $now,
                    'expires_at' => $newExpiry,
                    'id' => (int) $session['id'],
                ]);

                $session['last_seen_at'] = $now;
                $session['expires_at'] = $newExpiry;
            }
        }

        return $session;
    }

    /**
     * @param array<string,mixed> $session
     */
    public function verifyCsrf(
        array $session,
        string $token
    ): void {
        if (
            preg_match('/^[a-f0-9]{64}$/', $token) !== 1
            || !hash_equals(
                (string) ($session['csrf_hash'] ?? ''),
                $this->hashToken($token)
            )
        ) {
            throw new MiniAppException(
                'توکن CSRF معتبر نیست.',
                'csrf_invalid',
                403
            );
        }
    }

    public function revokeToken(
        string $token,
        string $reason = 'logout'
    ): void {
        if (
            preg_match('/^[a-f0-9]{64}$/', $token) !== 1
        ) {
            return;
        }

        $statement = $this->pdo->prepare(
            'UPDATE mini_app_sessions
             SET
                revoked_at = :revoked_at,
                revocation_reason = :reason
             WHERE session_hash = :session_hash
               AND revoked_at IS NULL'
        );

        $statement->execute([
            'revoked_at' => time(),
            'reason' => mb_substr($reason, 0, 100),
            'session_hash' => $this->hashToken($token),
        ]);
    }

    public function revokeSession(
        int $sessionId,
        string $reason = 'revoked'
    ): bool {
        $statement = $this->pdo->prepare(
            'UPDATE mini_app_sessions
             SET
                revoked_at = :revoked_at,
                revocation_reason = :reason
             WHERE id = :id
               AND revoked_at IS NULL'
        );

        $statement->execute([
            'revoked_at' => time(),
            'reason' => mb_substr($reason, 0, 100),
            'id' => $sessionId,
        ]);

        return $statement->rowCount() === 1;
    }

    public function revokeUserSessions(
        int $userId,
        string $reason = 'admin_revoke'
    ): int {
        $statement = $this->pdo->prepare(
            'UPDATE mini_app_sessions
             SET
                revoked_at = :revoked_at,
                revocation_reason = :reason
             WHERE user_id = :user_id
               AND revoked_at IS NULL'
        );

        $statement->execute([
            'revoked_at' => time(),
            'reason' => mb_substr($reason, 0, 100),
            'user_id' => $userId,
        ]);

        return $statement->rowCount();
    }

    /**
     * @return array{sessions:int,rate_limits:int,audit:int}
     */
    public function cleanup(
        int $sessionRetentionDays,
        int $auditRetentionDays
    ): array {
        $now = time();
        $sessionCutoff = $now - max(
            1,
            $sessionRetentionDays
        ) * 86400;
        $auditCutoff = $now - max(
            1,
            $auditRetentionDays
        ) * 86400;

        $sessions = $this->pdo->prepare(
            'DELETE FROM mini_app_sessions
             WHERE (
                absolute_expires_at < :session_cutoff
                OR (
                    revoked_at IS NOT NULL
                    AND revoked_at < :session_cutoff
                )
             )'
        );
        $sessions->execute([
            'session_cutoff' => $sessionCutoff,
        ]);

        $limits = $this->pdo->prepare(
            'DELETE FROM mini_app_rate_limits
             WHERE expires_at < :now'
        );
        $limits->execute(['now' => $now]);

        $audit = $this->pdo->prepare(
            'DELETE FROM mini_app_audit_logs
             WHERE occurred_at < :audit_cutoff'
        );
        $audit->execute([
            'audit_cutoff' => $auditCutoff,
        ]);

        return [
            'sessions' => $sessions->rowCount(),
            'rate_limits' => $limits->rowCount(),
            'audit' => $audit->rowCount(),
        ];
    }

    public function hashContext(string $value): string
    {
        return hash(
            'sha256',
            mb_substr($value, 0, 4096)
        );
    }

    private function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
