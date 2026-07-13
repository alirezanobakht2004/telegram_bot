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

    public function ensureAnswered(): void
    {
        if (!$this->answered) {
            $this->answer();
        }
    }
}
