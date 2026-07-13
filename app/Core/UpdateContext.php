<?php

declare(strict_types=1);

namespace SmartToolbox\Core;

use RuntimeException;

final class UpdateContext
{
    /**
     * @var array<string, mixed>
     */
    private array $attributes = [];

    private bool $propagationStopped = false;

    /**
     * @param array<string, mixed> $raw
     */
    private function __construct(
        public readonly int $updateId,
        public readonly string $type,
        public readonly array $raw,
        public readonly string $correlationId,
        public readonly int $receivedAt,
        public readonly int $startedAtNanoseconds
    ) {
    }

    /**
     * @param array<string, mixed> $update
     */
    public static function fromArray(array $update): self
    {
        $updateId = $update['update_id'] ?? null;

        if (!is_int($updateId)) {
            throw new RuntimeException(
                'Telegram update_id is missing or invalid.'
            );
        }

        $type = 'unknown';

        foreach ($update as $key => $value) {
            if ($key !== 'update_id') {
                $type = (string) $key;
                break;
            }
        }

        return new self(
            updateId: $updateId,
            type: $type,
            raw: $update,
            correlationId: sprintf(
                'u%d-%s',
                $updateId,
                bin2hex(random_bytes(6))
            ),
            receivedAt: time(),
            startedAtNanoseconds: hrtime(true)
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function payload(): ?array
    {
        $payload = $this->raw[$this->type] ?? null;

        return is_array($payload)
            ? $payload
            : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function user(): ?array
    {
        $payload = $this->payload();

        if ($payload === null) {
            return null;
        }

        $user = match ($this->type) {
            'message',
            'edited_message',
            'channel_post',
            'edited_channel_post',
            'callback_query',
            'inline_query',
            'chosen_inline_result',
            'shipping_query',
            'pre_checkout_query',
            'chat_member',
            'my_chat_member',
            'chat_join_request' => $payload['from'] ?? null,

            'poll_answer' => $payload['user'] ?? null,
            default => null,
        };

        return is_array($user)
            ? $user
            : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function chat(): ?array
    {
        $payload = $this->payload();

        if ($payload === null) {
            return null;
        }

        $chat = match ($this->type) {
            'message',
            'edited_message',
            'channel_post',
            'edited_channel_post' => $payload['chat'] ?? null,

            'callback_query' => is_array(
                $payload['message'] ?? null
            )
                ? ($payload['message']['chat'] ?? null)
                : null,

            'chat_member',
            'my_chat_member',
            'chat_join_request' => $payload['chat'] ?? null,

            'poll_answer' => $payload['voter_chat'] ?? null,
            default => null,
        };

        return is_array($chat)
            ? $chat
            : null;
    }

    public function userId(): ?int
    {
        $id = $this->user()['id'] ?? null;

        return is_int($id)
            ? $id
            : null;
    }

    public function chatId(): ?int
    {
        $id = $this->chat()['id'] ?? null;

        return is_int($id)
            ? $id
            : null;
    }

    public function chatType(): ?string
    {
        $type = $this->chat()['type'] ?? null;

        return is_string($type)
            ? $type
            : null;
    }

    public function text(): ?string
    {
        $payload = $this->payload();

        if ($payload === null) {
            return null;
        }

        $text = match ($this->type) {
            'message',
            'edited_message',
            'channel_post',
            'edited_channel_post' => $payload['text'] ?? null,
            'inline_query' => $payload['query'] ?? null,
            'callback_query' => $payload['data'] ?? null,
            default => null,
        };

        return is_string($text)
            ? $text
            : null;
    }

    public function messageId(): ?int
    {
        $payload = $this->payload();

        if ($payload === null) {
            return null;
        }

        $message = $this->type === 'callback_query'
            ? ($payload['message'] ?? null)
            : $payload;

        if (!is_array($message)) {
            return null;
        }

        $messageId = $message['message_id'] ?? null;

        return is_int($messageId)
            ? $messageId
            : null;
    }

    public function durationMilliseconds(): float
    {
        return max(
            0.0,
            (hrtime(true) - $this->startedAtNanoseconds)
            / 1_000_000
        );
    }

    public function setAttribute(
        string $key,
        mixed $value
    ): void {
        $key = trim($key);

        if ($key === '') {
            throw new RuntimeException(
                'Update context attribute key cannot be empty.'
            );
        }

        $this->attributes[$key] = $value;
    }

    public function attribute(
        string $key,
        mixed $default = null
    ): mixed {
        return $this->attributes[$key]
            ?? $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function attributes(): array
    {
        return $this->attributes;
    }

    public function stopPropagation(): void
    {
        $this->propagationStopped = true;
    }

    public function isPropagationStopped(): bool
    {
        return $this->propagationStopped;
    }
}
