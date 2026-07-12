<?php

declare(strict_types=1);

namespace SmartToolbox\Core;

use PDO;
use RuntimeException;
use Throwable;

final class UpdateProcessor
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly TelegramClient $telegram,
        private readonly CommandRouter $router
    ) {
    }

    /**
     * @param array<string, mixed> $update
     */
    public function process(array $update): void
    {
        $updateId = $update['update_id'] ?? null;

        if (!is_int($updateId)) {
            throw new RuntimeException(
                'Telegram update_id is missing or invalid.'
            );
        }

        $updateType = $this->detectUpdateType($update);

        if (!$this->claimUpdate($updateId, $updateType)) {
            return;
        }

        try {
            $message = $update['message'] ?? null;

            if (!is_array($message)) {
                $this->markCompleted($updateId);

                return;
            }

            $chat = $message['chat'] ?? null;
            $user = $message['from'] ?? null;

            if (!is_array($chat)) {
                throw new RuntimeException(
                    'Telegram message does not contain a valid chat.'
                );
            }

            $this->saveChat($chat);

            if (is_array($user)) {
                $this->saveUser(
                    $user,
                    (int) $chat['id']
                );
            }

            $text = $message['text'] ?? null;

            if (is_string($text)) {
                $this->handleTextMessage(
                    $chat,
                    is_array($user) ? $user : null,
                    trim($text)
                );
            }

            $this->markCompleted($updateId);
        } catch (Throwable $exception) {
            $this->markFailed(
                $updateId,
                $exception->getMessage()
            );

            throw $exception;
        }
    }

    private function claimUpdate(
        int $updateId,
        string $updateType
    ): bool {
        $statement = $this->pdo->prepare(
            'INSERT OR IGNORE INTO processed_updates (
                update_id,
                update_type,
                status,
                attempts,
                received_at
            ) VALUES (
                :update_id,
                :update_type,
                :status,
                1,
                :received_at
            )'
        );

        $statement->execute([
            'update_id' => $updateId,
            'update_type' => $updateType,
            'status' => 'processing',
            'received_at' => date(DATE_ATOM),
        ]);

        if ($statement->rowCount() === 1) {
            return true;
        }

        $statusStatement = $this->pdo->prepare(
            'SELECT status
             FROM processed_updates
             WHERE update_id = :update_id'
        );

        $statusStatement->execute([
            'update_id' => $updateId,
        ]);

        $status = $statusStatement->fetchColumn();

        if ($status !== 'failed') {
            return false;
        }

        $retryStatement = $this->pdo->prepare(
            'UPDATE processed_updates
             SET status = :status,
                 attempts = attempts + 1,
                 error_message = NULL,
                 received_at = :received_at,
                 processed_at = NULL
             WHERE update_id = :update_id
               AND status = :failed_status'
        );

        $retryStatement->execute([
            'status' => 'processing',
            'received_at' => date(DATE_ATOM),
            'update_id' => $updateId,
            'failed_status' => 'failed',
        ]);

        return $retryStatement->rowCount() === 1;
    }

    /**
     * @param array<string, mixed> $update
     */
    private function detectUpdateType(array $update): string
    {
        foreach ($update as $key => $value) {
            if ($key !== 'update_id') {
                return (string) $key;
            }
        }

        return 'unknown';
    }

    /**
     * @param array<string, mixed> $user
     */
    private function saveUser(
        array $user,
        int $chatId
    ): void {
        $telegramId = $user['id'] ?? null;

        if (!is_int($telegramId)) {
            return;
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO users (
                telegram_id,
                is_bot,
                first_name,
                last_name,
                username,
                language_code,
                is_premium,
                first_seen_at,
                last_seen_at,
                last_chat_id,
                request_count
            ) VALUES (
                :telegram_id,
                :is_bot,
                :first_name,
                :last_name,
                :username,
                :language_code,
                :is_premium,
                :first_seen_at,
                :last_seen_at,
                :last_chat_id,
                1
            )
            ON CONFLICT(telegram_id) DO UPDATE SET
                is_bot = excluded.is_bot,
                first_name = excluded.first_name,
                last_name = excluded.last_name,
                username = excluded.username,
                language_code = excluded.language_code,
                is_premium = excluded.is_premium,
                last_seen_at = excluded.last_seen_at,
                last_chat_id = excluded.last_chat_id,
                request_count = users.request_count + 1'
        );

        $now = date(DATE_ATOM);

        $statement->execute([
            'telegram_id' => $telegramId,
            'is_bot' => ($user['is_bot'] ?? false) ? 1 : 0,
            'first_name' => (string) ($user['first_name'] ?? ''),
            'last_name' => isset($user['last_name'])
                ? (string) $user['last_name']
                : null,
            'username' => isset($user['username'])
                ? (string) $user['username']
                : null,
            'language_code' => isset($user['language_code'])
                ? (string) $user['language_code']
                : null,
            'is_premium' => array_key_exists(
                'is_premium',
                $user
            )
                ? (($user['is_premium'] ?? false) ? 1 : 0)
                : null,
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'last_chat_id' => $chatId,
        ]);
    }

    /**
     * @param array<string, mixed> $chat
     */
    private function saveChat(array $chat): void
    {
        $telegramId = $chat['id'] ?? null;

        if (!is_int($telegramId)) {
            throw new RuntimeException(
                'Telegram chat ID is invalid.'
            );
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO chats (
                telegram_id,
                type,
                title,
                username,
                first_name,
                last_name,
                first_seen_at,
                last_seen_at,
                request_count,
                is_active
            ) VALUES (
                :telegram_id,
                :type,
                :title,
                :username,
                :first_name,
                :last_name,
                :first_seen_at,
                :last_seen_at,
                1,
                1
            )
            ON CONFLICT(telegram_id) DO UPDATE SET
                type = excluded.type,
                title = excluded.title,
                username = excluded.username,
                first_name = excluded.first_name,
                last_name = excluded.last_name,
                last_seen_at = excluded.last_seen_at,
                request_count = chats.request_count + 1,
                is_active = 1'
        );

        $now = date(DATE_ATOM);

        $statement->execute([
            'telegram_id' => $telegramId,
            'type' => (string) ($chat['type'] ?? 'unknown'),
            'title' => isset($chat['title'])
                ? (string) $chat['title']
                : null,
            'username' => isset($chat['username'])
                ? (string) $chat['username']
                : null,
            'first_name' => isset($chat['first_name'])
                ? (string) $chat['first_name']
                : null,
            'last_name' => isset($chat['last_name'])
                ? (string) $chat['last_name']
                : null,
            'first_seen_at' => $now,
            'last_seen_at' => $now,
        ]);
    }

    /**
     * @param array<string, mixed>      $chat
     * @param array<string, mixed>|null $user
     */
    private function handleTextMessage(
        array $chat,
        ?array $user,
        string $text
    ): void {
        $chatId = $chat['id'] ?? null;

        if (!is_int($chatId)) {
            return;
        }

        $userId = null;

        if (
            is_array($user)
            && is_int($user['id'] ?? null)
        ) {
            $userId = $user['id'];
        }

        $context = new MessageContext(
            chatId: $chatId,
            chatType: (string) ($chat['type'] ?? 'unknown'),
            userId: $userId,
            firstName: is_array($user)
                ? (string) ($user['first_name'] ?? '')
                : '',
            text: $text,
            telegram: $this->telegram
        );

        $this->router->dispatch($context);
    }

    private function markCompleted(int $updateId): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE processed_updates
             SET status = :status,
                 processed_at = :processed_at,
                 error_message = NULL
             WHERE update_id = :update_id'
        );

        $statement->execute([
            'status' => 'completed',
            'processed_at' => date(DATE_ATOM),
            'update_id' => $updateId,
        ]);
    }

    private function markFailed(
        int $updateId,
        string $errorMessage
    ): void {
        $statement = $this->pdo->prepare(
            'UPDATE processed_updates
             SET status = :status,
                 processed_at = :processed_at,
                 error_message = :error_message
             WHERE update_id = :update_id'
        );

        $statement->execute([
            'status' => 'failed',
            'processed_at' => date(DATE_ATOM),
            'error_message' => mb_substr(
                $errorMessage,
                0,
                1000
            ),
            'update_id' => $updateId,
        ]);
    }
}