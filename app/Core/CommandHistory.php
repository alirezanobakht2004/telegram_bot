<?php

declare(strict_types=1);

namespace SmartToolbox\Core;

use PDO;
use Throwable;

final class CommandHistory
{
    /**
     * @var list<string>
     */
    private const SENSITIVE_COMMANDS = [
        'password',
        'hash',
        'base64',
        'base64decode',
        'jwtdecode',
        'regex',
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly bool $enabled = true,
        private readonly bool $storeArguments = false,
        private readonly int $maxArgumentCharacters = 200
    ) {
    }

    public function record(
        string $module,
        string $command,
        string $source,
        ?string $arguments,
        bool $success,
        float $durationMs,
        ?MessageContext $messageContext = null,
        ?UpdateContext $updateContext = null
    ): void {
        if (!$this->enabled) {
            return;
        }

        $updateContext ??= $messageContext?->updateContext
            ?? TelemetryContext::update();

        $preview = $this->argumentPreview(
            $command,
            $arguments
        );

        $now = time();

        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO command_history (
                    correlation_id,
                    update_id,
                    user_id,
                    chat_id,
                    chat_type,
                    module,
                    command,
                    source,
                    arguments_preview,
                    success,
                    duration_ms,
                    occurred_at,
                    created_at
                 ) VALUES (
                    :correlation_id,
                    :update_id,
                    :user_id,
                    :chat_id,
                    :chat_type,
                    :module,
                    :command,
                    :source,
                    :arguments_preview,
                    :success,
                    :duration_ms,
                    :occurred_at,
                    :created_at
                 )'
            );

            $statement->execute([
                'correlation_id' => $updateContext?->correlationId,
                'update_id' => $updateContext?->updateId,
                'user_id' => $messageContext?->userId
                    ?? $updateContext?->userId(),
                'chat_id' => $messageContext?->chatId
                    ?? $updateContext?->chatId(),
                'chat_type' => $messageContext?->chatType
                    ?? $updateContext?->chatType(),
                'module' => mb_substr(trim($module), 0, 120),
                'command' => mb_substr(trim($command), 0, 200),
                'source' => mb_substr(trim($source), 0, 50),
                'arguments_preview' => $preview,
                'success' => $success ? 1 : 0,
                'duration_ms' => round(max(0.0, $durationMs), 3),
                'occurred_at' => $now,
                'created_at' => date(DATE_ATOM, $now),
            ]);
        } catch (Throwable) {
            /* History must not break routing. */
        }
    }

    private function argumentPreview(
        string $command,
        ?string $arguments
    ): ?string {
        if (
            !$this->storeArguments
            || $arguments === null
            || trim($arguments) === ''
        ) {
            return null;
        }

        $normalizedCommand = mb_strtolower(
            ltrim(trim($command), '/')
        );

        if (in_array(
            $normalizedCommand,
            self::SENSITIVE_COMMANDS,
            true
        )) {
            return '[REDACTED]';
        }

        $value = preg_replace(
            '/[\x00-\x1F\x7F]+/u',
            ' ',
            trim($arguments)
        ) ?? trim($arguments);

        return mb_substr(
            $value,
            0,
            max(0, min(1000, $this->maxArgumentCharacters))
        );
    }
}
