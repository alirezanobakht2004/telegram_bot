<?php

declare(strict_types=1);

namespace SmartToolbox\Core;

final readonly class MessageContext
{
    public function __construct(
        public int $chatId,
        public string $chatType,
        public ?int $userId,
        public string $firstName,
        public string $text,
        private TelegramClient $telegram
    ) {
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
        return $this->telegram->sendMessage(
            $this->chatId,
            $text,
            $options
        );
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function replyWithPhoto(
        string $photo,
        string $caption = '',
        array $options = []
    ): array {
        if (
            $caption !== ''
            && !array_key_exists('caption', $options)
        ) {
            $options['caption'] = $caption;
        }

        return $this->telegram->sendPhoto(
            $this->chatId,
            $photo,
            $options
        );
    }

    public function isPrivate(): bool
    {
        return $this->chatType === 'private';
    }

    public function isGroup(): bool
    {
        return in_array(
            $this->chatType,
            ['group', 'supergroup'],
            true
        );
    }

    public function actorKey(): string
    {
        if ($this->userId !== null) {
            return 'user:' . $this->userId;
        }

        return 'chat:' . $this->chatId;
    }
}
