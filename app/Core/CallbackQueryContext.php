<?php

declare(strict_types=1);

namespace SmartToolbox\Core;

use RuntimeException;

final class CallbackQueryContext
{
    private bool $answered = false;

    /**
     * @param array<string, mixed> $query
     */
    public function __construct(
        public readonly array $query,
        public readonly UpdateContext $updateContext,
        private readonly TelegramClient $telegram
    ) {
    }

    public function id(): string
    {
        $id = $this->query['id'] ?? null;

        if (!is_string($id) || $id === '') {
            throw new RuntimeException(
                'Callback query ID is missing.'
            );
        }

        return $id;
    }

    public function data(): string
    {
        $data = $this->query['data'] ?? '';

        return is_string($data)
            ? $data
            : '';
    }

    public function userId(): ?int
    {
        $id = $this->query['from']['id'] ?? null;

        return is_int($id)
            ? $id
            : null;
    }

    public function chatId(): ?int
    {
        $id = $this->query['message']['chat']['id'] ?? null;

        return is_int($id)
            ? $id
            : null;
    }

    public function chatType(): string
    {
        $type = $this->query[
            'message'
        ]['chat']['type'] ?? null;

        return is_string($type)
            ? $type
            : 'private';
    }

    public function firstName(): string
    {
        $name = $this->query[
            'from'
        ]['first_name'] ?? '';

        return is_string($name)
            ? $name
            : '';
    }

    public function telegram(): TelegramClient
    {
        return $this->telegram;
    }

    public function messageId(): ?int
    {
        $id = $this->query['message']['message_id'] ?? null;

        return is_int($id)
            ? $id
            : null;
    }

    public function inlineMessageId(): ?string
    {
        $id = $this->query['inline_message_id'] ?? null;

        return is_string($id)
            ? $id
            : null;
    }

    public function answer(
        string $text = '',
        bool $showAlert = false,
        int $cacheTime = 0,
        ?string $url = null
    ): bool {
        $options = [
            'show_alert' => $showAlert,
            'cache_time' => max(0, $cacheTime),
        ];

        if ($text !== '') {
            $options['text'] = mb_substr($text, 0, 200);
        }

        if ($url !== null && trim($url) !== '') {
            $options['url'] = trim($url);
        }

        $result = $this->telegram->answerCallbackQuery(
            $this->id(),
            $options
        );

        $this->answered = true;

        return $result;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function reply(
        string $text,
        array $options = []
    ): array {
        $chatId = $this->chatId();

        if ($chatId === null) {
            throw new RuntimeException(
                'Callback query does not contain a chat.'
            );
        }

        return $this->telegram->sendMessage(
            $chatId,
            $text,
            $options
        );
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>|bool
     */
    public function editMessageText(
        string $text,
        array $options = []
    ): array|bool {
        $inlineMessageId =
            $this->inlineMessageId();

        if ($inlineMessageId !== null) {
            $options['inline_message_id'] =
                $inlineMessageId;
        } else {
            $chatId = $this->chatId();
            $messageId = $this->messageId();

            if (
                $chatId === null
                || $messageId === null
            ) {
                throw new RuntimeException(
                    'Callback query message identifiers are missing.'
                );
            }

            $options['chat_id'] = $chatId;
            $options['message_id'] =
                $messageId;
        }

        return $this->telegram
            ->editMessageText(
                $text,
                $options
            );
    }

    public function ensureAnswered(): void
    {
        if (!$this->answered) {
            $this->answer();
        }
    }
}
