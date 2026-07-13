<?php

declare(strict_types=1);

namespace SmartToolbox\Modules\MiniApp;

use JsonException;
use PDO;

final class MiniAppAuditLogger
{
    public function __construct(
        private readonly PDO $pdo
    ) {
    }

    /**
     * @param array<string,mixed> $details
     */
    public function record(
        ?int $userId,
        ?int $sessionId,
        string $action,
        bool $success,
        string $ipAddress,
        string $userAgent,
        ?string $resourceType = null,
        ?string $resourceId = null,
        ?string $errorCode = null,
        array $details = []
    ): void {
        $safeDetails = $this->sanitize($details);

        try {
            $detailsJson = json_encode(
                $safeDetails,
                JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
            );
        } catch (JsonException) {
            $detailsJson = '{}';
        }

        $now = time();
        $statement = $this->pdo->prepare(
            'INSERT INTO mini_app_audit_logs (
                user_id,
                session_id,
                action,
                resource_type,
                resource_id,
                success,
                error_code,
                details_json,
                ip_hash,
                user_agent_hash,
                occurred_at,
                created_at
             ) VALUES (
                :user_id,
                :session_id,
                :action,
                :resource_type,
                :resource_id,
                :success,
                :error_code,
                :details_json,
                :ip_hash,
                :user_agent_hash,
                :occurred_at,
                :created_at
             )'
        );

        $statement->execute([
            'user_id' => $userId,
            'session_id' => $sessionId,
            'action' => mb_substr($action, 0, 100),
            'resource_type' => $resourceType !== null
                ? mb_substr($resourceType, 0, 100)
                : null,
            'resource_id' => $resourceId !== null
                ? mb_substr($resourceId, 0, 200)
                : null,
            'success' => $success ? 1 : 0,
            'error_code' => $errorCode !== null
                ? mb_substr($errorCode, 0, 100)
                : null,
            'details_json' => $detailsJson,
            'ip_hash' => hash(
                'sha256',
                mb_substr($ipAddress, 0, 512)
            ),
            'user_agent_hash' => hash(
                'sha256',
                mb_substr($userAgent, 0, 4096)
            ),
            'occurred_at' => $now,
            'created_at' => date(DATE_ATOM, $now),
        ]);
    }

    /**
     * @param array<string,mixed> $details
     * @return array<string,mixed>
     */
    private function sanitize(array $details): array
    {
        $blocked = [
            'token',
            'bot_token',
            'session_token',
            'csrf_token',
            'init_data',
            'hash',
            'authorization',
            'cookie',
        ];

        $walk = function (
            mixed $value,
            int $depth = 0
        ) use (&$walk, $blocked): mixed {
            if ($depth > 4) {
                return '[depth-limit]';
            }

            if (is_array($value)) {
                $result = [];

                foreach ($value as $key => $item) {
                    $keyString = mb_strtolower(
                        (string) $key
                    );

                    if (in_array($keyString, $blocked, true)) {
                        $result[$key] = '[redacted]';
                        continue;
                    }

                    $result[$key] = $walk(
                        $item,
                        $depth + 1
                    );
                }

                return $result;
            }

            if (is_string($value)) {
                return mb_substr($value, 0, 1000);
            }

            if (
                is_int($value)
                || is_float($value)
                || is_bool($value)
                || $value === null
            ) {
                return $value;
            }

            return (string) $value;
        };

        $sanitized = $walk($details);

        return is_array($sanitized)
            ? $sanitized
            : [];
    }
}
