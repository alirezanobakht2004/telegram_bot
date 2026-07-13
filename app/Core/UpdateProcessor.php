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
        private readonly CommandRouter $router,
        private readonly ?EventDispatcher $events = null,
        private readonly ?CallbackRouter $callbackRouter = null,
        private readonly ?InlineQueryRouter $inlineRouter = null,
        private readonly ?UsageTracker $usageTracker = null
    ) {
    }

    /**
     * @param array<string, mixed> $update
     */
    public function process(array $update): void
    {
        $context = UpdateContext::fromArray($update);

        if (!$this->claimUpdate(
            $context->updateId,
            $context->type
        )) {
            return;
        }

        TelemetryContext::begin($context);

        $span = $this->usageTracker?->start(
            module: 'core',
            action: 'update.' . $context->type,
            inputKind: 'update',
            context: $context,
            metadata: [
                'message_id' => $context->messageId(),
            ]
        );

        try {
            $this->events?->dispatch(
                'update.received',
                $context
            );

            $this->events?->dispatch(
                'update.before',
                $context
            );

            $this->events?->dispatch(
                'update.before.' . $context->type,
                $context
            );

            $handled = false;

            if (!$context->isPropagationStopped()) {
                $handled = $this->route($context);
            }

            $context->setAttribute(
                'core.handled',
                $handled
            );

            $this->events?->dispatch(
                'update.' . $context->type,
                $context
            );

            $this->events?->dispatch(
                'update.after.' . $context->type,
                $context
            );

            $this->events?->dispatch(
                'update.after',
                $context
            );

            $this->markCompleted($context->updateId);

            $span?->success([
                'handled' => $handled,
                'propagation_stopped' =>
                    $context->isPropagationStopped(),
            ]);
        } catch (Throwable $exception) {
            $this->markFailed(
                $context->updateId,
                $exception->getMessage()
            );

            try {
                $this->events?->dispatch(
                    'update.failed',
                    $context
                );
            } catch (Throwable) {
            }

            $span?->failure($exception);

            throw $exception;
        } finally {
            TelemetryContext::clear();
        }
    }

    private function route(UpdateContext $context): bool
    {
        return match ($context->type) {
            'message' => $this->processMessage($context),
            'edited_message',
            'channel_post',
            'edited_channel_post' =>
                $this->storeMessageOnly($context),
            'callback_query' =>
                $this->processCallbackQuery($context),
            'inline_query' =>
                $this->processInlineQuery($context),
            'chosen_inline_result' =>
                $this->processChosenInlineResult($context),
            'my_chat_member',
            'chat_member' =>
                $this->processChatMemberUpdate($context),
            'chat_join_request' =>
                $this->processJoinRequest($context),
            'poll_answer' =>
                $this->storeUserOnly($context),
            default => false,
        };
    }

    private function processMessage(
        UpdateContext $context
    ): bool {
        $message = $context->payload();

        if (!is_array($message)) {
            return false;
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
                is_int($chat['id'] ?? null)
                    ? $chat['id']
                    : null
            );
        }

        if ($this->isBlocked(
            $chat,
            is_array($user) ? $user : null
        )) {
            return true;
        }

        $this->events?->dispatch(
            'update.persisted.message',
            $context
        );

        if ($context->isPropagationStopped()) {
            return true;
        }

        $text = $message['text'] ?? null;

        if (!is_string($text)) {
            return false;
        }

        $chatId = $chat['id'] ?? null;

        if (!is_int($chatId)) {
            return false;
        }

        $userId = is_array($user)
            && is_int($user['id'] ?? null)
            ? $user['id']
            : null;

        $messageContext = new MessageContext(
            chatId: $chatId,
            chatType: (string) ($chat['type'] ?? 'unknown'),
            userId: $userId,
            firstName: is_array($user)
                ? (string) ($user['first_name'] ?? '')
                : '',
            text: trim($text),
            telegram: $this->telegram,
            updateContext: $context,
            messageId: is_int($message['message_id'] ?? null)
                ? $message['message_id']
                : null
        );

        return $this->router->dispatch($messageContext);
    }

    private function storeMessageOnly(
        UpdateContext $context
    ): bool {
        $message = $context->payload();

        if (!is_array($message)) {
            return false;
        }

        $chat = $message['chat'] ?? null;
        $user = $message['from'] ?? null;

        if (is_array($chat)) {
            $this->saveChat($chat);
        }

        if (is_array($user)) {
            $this->saveUser(
                $user,
                is_array($chat)
                && is_int($chat['id'] ?? null)
                    ? $chat['id']
                    : null
            );
        }

        $this->events?->dispatch(
            'update.persisted.'
            . $context->type,
            $context
        );

        return $context->isPropagationStopped();
    }

    private function processCallbackQuery(
        UpdateContext $context
    ): bool {
        $query = $context->payload();

        if (!is_array($query)) {
            return false;
        }

        $user = $query['from'] ?? null;
        $chat = is_array($query['message'] ?? null)
            ? ($query['message']['chat'] ?? null)
            : null;

        if (is_array($chat)) {
            $this->saveChat($chat);
        }

        if (is_array($user)) {
            $this->saveUser(
                $user,
                is_array($chat)
                && is_int($chat['id'] ?? null)
                    ? $chat['id']
                    : null
            );
        }

        $callback = new CallbackQueryContext(
            query: $query,
            updateContext: $context,
            telegram: $this->telegram
        );

        if (
            is_array($user)
            && $this->isUserBlocked($user)
        ) {
            $callback->ensureAnswered();

            return true;
        }

        if ($this->callbackRouter === null) {
            $callback->ensureAnswered();

            return false;
        }

        return $this->callbackRouter->dispatch($callback);
    }

    private function processInlineQuery(
        UpdateContext $context
    ): bool {
        $query = $context->payload();

        if (!is_array($query)) {
            return false;
        }

        $user = $query['from'] ?? null;

        if (is_array($user)) {
            $this->saveUser($user, null);
        }

        $inline = new InlineQueryContext(
            query: $query,
            updateContext: $context,
            telegram: $this->telegram
        );

        if (
            is_array($user)
            && $this->isUserBlocked($user)
        ) {
            $inline->ensureAnswered([
                'cache_time' => 1,
                'is_personal' => true,
            ]);

            return true;
        }

        if ($this->inlineRouter === null) {
            $inline->ensureAnswered([
                'cache_time' => 1,
                'is_personal' => true,
            ]);

            return false;
        }

        return $this->inlineRouter->dispatch($inline);
    }

    private function processChosenInlineResult(
        UpdateContext $context
    ): bool {
        $payload = $context->payload();

        if (!is_array($payload)) {
            return false;
        }

        $user = $payload['from'] ?? null;

        if (is_array($user)) {
            $this->saveUser($user, null);
        }

        return false;
    }

    private function processChatMemberUpdate(
        UpdateContext $context
    ): bool {
        $payload = $context->payload();

        if (!is_array($payload)) {
            return false;
        }

        $chat = $payload['chat'] ?? null;
        $user = $payload['from'] ?? null;

        if (is_array($chat)) {
            $this->saveChat($chat);

            /*
             * فقط my_chat_member وضعیت عضویت خود ربات را نشان
             * می‌دهد. chat_member مربوط به اعضای عادی است و
             * نباید کل چت را غیرفعال کند.
             */
            if ($context->type === 'my_chat_member') {
                $status = $payload[
                    'new_chat_member'
                ]['status'] ?? null;

                if (is_string($status)) {
                    $active = !in_array(
                        $status,
                        ['left', 'kicked'],
                        true
                    );

                    $statement = $this->pdo->prepare(
                        'UPDATE chats
                         SET is_active = :is_active
                         WHERE telegram_id = :telegram_id'
                    );

                    $statement->execute([
                        'is_active' => $active ? 1 : 0,
                        'telegram_id' => $chat['id'] ?? 0,
                    ]);
                }
            }
        }

        $chatId = is_array($chat)
            && is_int($chat['id'] ?? null)
            ? $chat['id']
            : null;

        if (is_array($user)) {
            $this->saveUser(
                $user,
                $chatId
            );
        }

        $member = $payload[
            'new_chat_member'
        ]['user'] ?? null;

        if (is_array($member)) {
            $this->saveUser(
                $member,
                $chatId
            );
        }

        return false;
    }

    private function processJoinRequest(
        UpdateContext $context
    ): bool {
        $payload = $context->payload();

        if (!is_array($payload)) {
            return false;
        }

        $chat = $payload['chat'] ?? null;
        $user = $payload['from'] ?? null;

        if (is_array($chat)) {
            $this->saveChat($chat);
        }

        if (is_array($user)) {
            $this->saveUser(
                $user,
                is_array($chat)
                && is_int($chat['id'] ?? null)
                    ? $chat['id']
                    : null
            );
        }

        return false;
    }

    private function storeUserOnly(
        UpdateContext $context
    ): bool {
        $user = $context->user();

        if (is_array($user)) {
            $this->saveUser($user, null);
        }

        return false;
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

        if ($statusStatement->fetchColumn() !== 'failed') {
            return false;
        }

        $retry = $this->pdo->prepare(
            'UPDATE processed_updates
             SET
                status = :status,
                attempts = attempts + 1,
                error_message = NULL,
                received_at = :received_at,
                processed_at = NULL
             WHERE update_id = :update_id
               AND status = :failed_status'
        );

        $retry->execute([
            'status' => 'processing',
            'received_at' => date(DATE_ATOM),
            'update_id' => $updateId,
            'failed_status' => 'failed',
        ]);

        return $retry->rowCount() === 1;
    }

    /**
     * @param array<string, mixed> $user
     */
    private function saveUser(
        array $user,
        ?int $chatId
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
                last_chat_id = COALESCE(
                    excluded.last_chat_id,
                    users.last_chat_id
                ),
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
            'is_premium' => array_key_exists('is_premium', $user)
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
     * @param array<string, mixed> $chat
     * @param array<string, mixed>|null $user
     */
    private function isBlocked(
        array $chat,
        ?array $user
    ): bool {
        if (
            is_array($user)
            && $this->isUserBlocked($user)
        ) {
            return true;
        }

        $chatId = $chat['id'] ?? null;

        if (!is_int($chatId)) {
            return false;
        }

        $statement = $this->pdo->prepare(
            'SELECT admin_blocked
             FROM chats
             WHERE telegram_id = :telegram_id
             LIMIT 1'
        );

        $statement->execute([
            'telegram_id' => $chatId,
        ]);

        return (int) $statement->fetchColumn() === 1;
    }

    /**
     * @param array<string, mixed> $user
     */
    private function isUserBlocked(array $user): bool
    {
        $userId = $user['id'] ?? null;

        if (!is_int($userId)) {
            return false;
        }

        $statement = $this->pdo->prepare(
            'SELECT is_blocked
             FROM users
             WHERE telegram_id = :telegram_id
             LIMIT 1'
        );

        $statement->execute([
            'telegram_id' => $userId,
        ]);

        return (int) $statement->fetchColumn() === 1;
    }

    private function markCompleted(int $updateId): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE processed_updates
             SET
                status = :status,
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
             SET
                status = :status,
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
