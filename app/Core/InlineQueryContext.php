<?php

declare(strict_types=1);

namespace SmartToolbox\Core;

use RuntimeException;

final class InlineQueryContext
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
                'Inline query ID is missing.'
            );
        }

        return $id;
    }

    public function queryText(): string
    {
        $query = $this->query['query'] ?? '';

        return is_string($query)
            ? trim($query)
            : '';
    }

    public function offset(): string
    {
        $offset = $this->query['offset'] ?? '';

        return is_string($offset)
            ? $offset
            : '';
    }

    public function userId(): ?int
    {
        $id = $this->query['from']['id'] ?? null;

        return is_int($id)
            ? $id
            : null;
    }

    public function chatType(): ?string
    {
        $type = $this->query['chat_type'] ?? null;

        return is_string($type)
            ? $type
            : null;
    }

    /**
     * @param list<array<string, mixed>> $results
     * @param array<string, mixed> $options
     */
    public function answer(
        array $results,
        array $options = []
    ): bool {
        $result = $this->telegram->answerInlineQuery(
            $this->id(),
            $results,
            $options
        );

        $this->answered = true;

        return $result;
    }

    /**
     * @param array<string, mixed> $options
     */
    public function ensureAnswered(
        array $options = []
    ): void {
        if (!$this->answered) {
            $this->answer([], $options);
        }
    }
}
