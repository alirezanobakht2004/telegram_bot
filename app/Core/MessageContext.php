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
        private TelegramClient $telegram,
        public ?UpdateContext $updateContext = null,
        public ?int $messageId = null
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
        if (
            $this->messageId !== null
            && !array_key_exists(
                'reply_parameters',
                $options
            )
        ) {
            $options['reply_parameters'] = [
                'message_id' => $this->messageId,
                'allow_sending_without_reply' => true,
            ];
        }

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

        if (
            $this->messageId !== null
            && !array_key_exists(
                'reply_parameters',
                $options
            )
        ) {
            $options['reply_parameters'] = [
                'message_id' => $this->messageId,
                'allow_sending_without_reply' => true,
            ];
        }

        return $this->telegram->sendPhoto(
            $this->chatId,
            $photo,
            $options
        );
    }

    public function telegram(): TelegramClient
    {
        return $this->telegram;
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
