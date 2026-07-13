<?php

declare(strict_types=1);

namespace SmartToolbox\Modules\GroupManagement;

use SmartToolbox\Core\EventDispatcher;
use SmartToolbox\Core\TelegramClient;
use SmartToolbox\Core\UpdateContext;
use Throwable;

final class GroupAutomationListener
{
    public function __construct(
        private readonly GroupRepository $repository,
        private readonly GroupAuthorization $authorization,
        private readonly GroupModerationService $moderation,
        private readonly GroupTemplateRenderer $templates,
        private readonly TelegramClient $telegram,
        private readonly int $automodNoticeCooldownSeconds = 30
    ) {
    }

    public function register(
        EventDispatcher $events
    ): void {
        $events->listen(
            'update.persisted.message',
            function (
                UpdateContext $context
            ): void {
                $this->inspectMessage($context);
            },
            100
        );

        $events->listen(
            'update.persisted.edited_message',
            function (
                UpdateContext $context
            ): void {
                $this->inspectMessage($context);
            },
            100
        );

        $events->listen(
            'update.chat_member',
            function (
                UpdateContext $context
            ): void {
                $this->handleChatMember($context);
            }
        );

        $events->listen(
            'update.chat_join_request',
            function (
                UpdateContext $context
            ): void {
                $this->handleJoinRequest($context);
            }
        );
    }

    private function inspectMessage(
        UpdateContext $context
    ): void {
        $message = $context->payload();

        if (!is_array($message)) {
            return;
        }

        $chat = $message['chat'] ?? null;
        $user = $message['from'] ?? null;

        if (
            !is_array($chat)
            || !is_array($user)
            || !in_array(
                $chat['type'] ?? '',
                ['group', 'supergroup'],
                true
            )
            || ($user['is_bot'] ?? false) === true
        ) {
            return;
        }

        $chatId = $chat['id'] ?? null;
        $userId = $user['id'] ?? null;
        $messageId = $message['message_id']
            ?? null;

        if (
            !is_int($chatId)
            || !is_int($userId)
            || !is_int($messageId)
        ) {
            return;
        }

        if (
            $this->authorization
                ->isAdministrator(
                    $chatId,
                    $userId
                )
        ) {
            return;
        }

        $settings = $this->repository
            ->settings($chatId);

        $text = trim(
            (string) (
                $message['text']
                ?? $message['caption']
                ?? ''
            )
        );

        $normalized = $this->normalizeText(
            $text
        );

        $textHash = $normalized !== ''
            ? hash('sha256', $normalized)
            : '';

        $activity = $this->repository
            ->recordActivity(
                $chatId,
                $userId,
                $textHash,
                time(),
                $settings
            );

        $violation = null;

        if (
            (int) $settings[
                'anti_link_enabled'
            ] === 1
            && $this->containsForbiddenLink(
                $message,
                $text,
                $this->repository->domains(
                    $chatId
                )
            )
        ) {
            $violation = 'link';
        } elseif (
            (int) $settings[
                'bad_words_enabled'
            ] === 1
            && $this->containsBadWord(
                $normalized,
                $this->repository->badWords(
                    $chatId
                )
            )
        ) {
            $violation = 'bad_word';
        } elseif (
            (int) $settings[
                'anti_spam_enabled'
            ] === 1
            && $activity['flood']
        ) {
            $violation = 'flood';
        } elseif (
            (int) $settings[
                'anti_spam_enabled'
            ] === 1
            && $activity['duplicate']
        ) {
            $violation = 'duplicate';
        } elseif ($activity['slow_mode']) {
            $violation = 'slow_mode';
        }

        if ($violation === null) {
            return;
        }

        try {
            $this->moderation->deleteMessage(
                $chatId,
                $messageId
            );
        } catch (Throwable $exception) {
            $this->repository->audit(
                $chatId,
                null,
                $userId,
                'automod.delete_failed',
                [
                    'violation' => $violation,
                    'message_id' => $messageId,
                ],
                false,
                $exception->getMessage()
            );

            return;
        }

        $this->repository->audit(
            $chatId,
            null,
            $userId,
            'automod.' . $violation,
            [
                'message_id' => $messageId,
            ]
        );

        $context->stopPropagation();

        if (
            $this->shouldSendNotice(
                $chatId,
                $userId,
                $settings
            )
        ) {
            try {
                $this->telegram->sendMessage(
                    $chatId,
                    $this->violationMessage(
                        $violation,
                        $user
                    ),
                    [
                        'disable_notification' => true,
                    ]
                );
            } catch (Throwable) {
            }
        }
    }

    private function handleChatMember(
        UpdateContext $context
    ): void {
        $payload = $context->payload();

        if (!is_array($payload)) {
            return;
        }

        $this->authorization
            ->rememberFromChatMemberUpdate(
                $payload
            );

        $chat = $payload['chat'] ?? null;
        $old = $payload[
            'old_chat_member'
        ] ?? null;
        $new = $payload[
            'new_chat_member'
        ] ?? null;

        if (
            !is_array($chat)
            || !is_array($old)
            || !is_array($new)
            || !in_array(
                $chat['type'] ?? '',
                ['group', 'supergroup'],
                true
            )
        ) {
            return;
        }

        $chatId = $chat['id'] ?? null;
        $user = $new['user'] ?? null;

        if (
            !is_int($chatId)
            || !is_array($user)
            || ($user['is_bot'] ?? false)
                === true
        ) {
            return;
        }

        $userId = $user['id'] ?? null;

        if (!is_int($userId)) {
            return;
        }

        $oldStatus = (string) (
            $old['status'] ?? ''
        );

        $newStatus = (string) (
            $new['status'] ?? ''
        );

        $joined = in_array(
            $oldStatus,
            ['left', 'kicked'],
            true
        ) && in_array(
            $newStatus,
            [
                'member',
                'restricted',
                'administrator',
            ],
            true
        );

        $left = in_array(
            $oldStatus,
            [
                'member',
                'restricted',
                'administrator',
            ],
            true
        ) && in_array(
            $newStatus,
            ['left', 'kicked'],
            true
        );

        $settings = $this->repository
            ->settings($chatId);

        if ($joined) {
            if (
                (int) $settings[
                    'captcha_enabled'
                ] === 1
                && $chat['type']
                    === 'supergroup'
                && $newStatus
                    !== 'administrator'
            ) {
                $this->createCaptcha(
                    $chat,
                    $user,
                    $settings
                );
            }

            if (
                (int) $settings[
                    'welcome_enabled'
                ] === 1
            ) {
                $template = trim(
                    (string) (
                        $settings[
                            'welcome_message'
                        ] ?? ''
                    )
                );

                if ($template === '') {
                    $template =
                        'خوش آمدی {first_name} به {chat_title} 👋';
                }

                $this->sendRendered(
                    $chatId,
                    $template,
                    $user,
                    $chat
                );
            }

            $this->repository->audit(
                $chatId,
                null,
                $userId,
                'member.joined'
            );
        }

        if ($left) {
            if (
                (int) $settings[
                    'goodbye_enabled'
                ] === 1
            ) {
                $template = trim(
                    (string) (
                        $settings[
                            'goodbye_message'
                        ] ?? ''
                    )
                );

                if ($template === '') {
                    $template =
                        '{first_name} از گروه خارج شد.';
                }

                $this->sendRendered(
                    $chatId,
                    $template,
                    $user,
                    $chat
                );
            }

            $this->repository->audit(
                $chatId,
                null,
                $userId,
                'member.left',
                [
                    'new_status' => $newStatus,
                ]
            );
        }
    }

    private function handleJoinRequest(
        UpdateContext $context
    ): void {
        $payload = $context->payload();

        if (!is_array($payload)) {
            return;
        }

        $chatId = $payload[
            'chat'
        ]['id'] ?? null;
        $userId = $payload[
            'from'
        ]['id'] ?? null;

        if (
            !is_int($chatId)
            || !is_int($userId)
        ) {
            return;
        }

        $requestId = $this->repository
            ->storeJoinRequest($payload);

        $settings = $this->repository
            ->settings($chatId);

        $mode = (string) $settings[
            'join_request_mode'
        ];

        if ($mode === 'approve') {
            try {
                $this->moderation
                    ->approveJoinRequest(
                        $chatId,
                        $userId
                    );

                $this->repository
                    ->resolveJoinRequest(
                        $chatId,
                        $requestId,
                        'approved',
                        null
                    );

                $this->repository->audit(
                    $chatId,
                    null,
                    $userId,
                    'join_request.auto_approved',
                    [
                        'request_id' =>
                            $requestId,
                    ]
                );
            } catch (Throwable $exception) {
                $this->repository->audit(
                    $chatId,
                    null,
                    $userId,
                    'join_request.auto_approve_failed',
                    [
                        'request_id' =>
                            $requestId,
                    ],
                    false,
                    $exception->getMessage()
                );
            }

            return;
        }

        if ($mode === 'decline') {
            try {
                $this->moderation
                    ->declineJoinRequest(
                        $chatId,
                        $userId
                    );

                $this->repository
                    ->resolveJoinRequest(
                        $chatId,
                        $requestId,
                        'declined',
                        null
                    );

                $this->repository->audit(
                    $chatId,
                    null,
                    $userId,
                    'join_request.auto_declined',
                    [
                        'request_id' =>
                            $requestId,
                    ]
                );
            } catch (Throwable $exception) {
                $this->repository->audit(
                    $chatId,
                    null,
                    $userId,
                    'join_request.auto_decline_failed',
                    [
                        'request_id' =>
                            $requestId,
                    ],
                    false,
                    $exception->getMessage()
                );
            }

            return;
        }

        $user = $payload['from'];

        $name = trim(
            (string) (
                $user['first_name'] ?? ''
            )
            . ' '
            . (string) (
                $user['last_name'] ?? ''
            )
        );

        try {
            $this->telegram->sendMessage(
                $chatId,
                "درخواست عضویت جدید #{$requestId}\n\n"
                . 'کاربر: '
                . (
                    $name !== ''
                        ? $name
                        : $userId
                )
                . "\nشناسه: {$userId}"
                . (
                    is_string(
                        $payload['bio'] ?? null
                    )
                    && trim($payload['bio'])
                        !== ''
                        ? "\nBio: "
                            . mb_substr(
                                $payload['bio'],
                                0,
                                500
                            )
                        : ''
                ),
                [
                    'reply_markup' => [
                        'inline_keyboard' => [
                            [
                                [
                                    'text' => '✅ تأیید',
                                    'callback_data' =>
                                        'groupjoin:approve:'
                                        . $requestId,
                                ],
                                [
                                    'text' => '❌ رد',
                                    'callback_data' =>
                                        'groupjoin:decline:'
                                        . $requestId,
                                ],
                            ],
                        ],
                    ],
                ]
            );
        } catch (Throwable) {
        }
    }

    /**
     * @param array<string, mixed> $chat
     * @param array<string, mixed> $user
     * @param array<string, mixed> $settings
     */
    private function createCaptcha(
        array $chat,
        array $user,
        array $settings
    ): void {
        $chatId = (int) $chat['id'];
        $userId = (int) $user['id'];

        try {
            $this->moderation->mute(
                $chatId,
                $userId,
                time() + max(
                    60,
                    (int) $settings[
                        'captcha_timeout_seconds'
                    ] + 60
                ),
                null,
                'Waiting for captcha',
                'captcha'
            );

            $left = random_int(2, 9);
            $right = random_int(1, 9);
            $answer = (string) (
                $left + $right
            );

            $challengeId =
                $this->repository
                    ->createCaptcha(
                        $chatId,
                        $userId,
                        "{$left} + {$right} = ؟",
                        $answer,
                        (int) $settings[
                            'captcha_max_attempts'
                        ],
                        time() + max(
                            30,
                            (int) $settings[
                                'captcha_timeout_seconds'
                            ]
                        )
                    );

            $answers = [
                (int) $answer,
                max(
                    0,
                    (int) $answer
                    + random_int(1, 3)
                ),
                max(
                    0,
                    (int) $answer
                    - random_int(1, 3)
                ),
                (int) $answer
                    + random_int(4, 7),
            ];

            $answers = array_values(
                array_unique($answers)
            );

            while (count($answers) < 4) {
                $answers[] = random_int(
                    1,
                    18
                );
                $answers = array_values(
                    array_unique($answers)
                );
            }

            shuffle($answers);

            $keyboard = [];

            foreach (
                array_chunk($answers, 2)
                as $row
            ) {
                $buttons = [];

                foreach ($row as $value) {
                    $buttons[] = [
                        'text' => (string) $value,
                        'callback_data' =>
                            'groupcaptcha:'
                            . $challengeId
                            . ':'
                            . $value,
                    ];
                }

                $keyboard[] = $buttons;
            }

            $message = $this->telegram
                ->sendMessage(
                    $chatId,
                    "🧩 تأیید عضو جدید\n\n"
                    . $this->templates->render(
                        '{first_name}، برای ارسال پیام پاسخ درست را انتخاب کن.',
                        $user,
                        $chat
                    )
                    . "\n\n"
                    . "{$left} + {$right} = ؟",
                    [
                        'reply_markup' => [
                            'inline_keyboard' =>
                                $keyboard,
                        ],
                    ]
                );

            if (
                is_int(
                    $message['message_id']
                    ?? null
                )
            ) {
                $this->repository
                    ->setCaptchaMessage(
                        $challengeId,
                        $message['message_id']
                    );
            }

            $this->repository->audit(
                $chatId,
                null,
                $userId,
                'captcha.created',
                [
                    'challenge_id' =>
                        $challengeId,
                ]
            );
        } catch (Throwable $exception) {
            $this->repository->audit(
                $chatId,
                null,
                $userId,
                'captcha.create_failed',
                [],
                false,
                $exception->getMessage()
            );
        }
    }

    /**
     * @param array<string, mixed> $message
     * @param list<string> $allowedDomains
     */
    private function containsForbiddenLink(
        array $message,
        string $text,
        array $allowedDomains
    ): bool {
        $urls = [];

        foreach (
            [
                $message['entities'] ?? [],
                $message[
                    'caption_entities'
                ] ?? [],
            ]
            as $entities
        ) {
            if (!is_array($entities)) {
                continue;
            }

            foreach ($entities as $entity) {
                if (!is_array($entity)) {
                    continue;
                }

                $type = $entity['type'] ?? '';

                if (
                    $type === 'text_link'
                    && is_string(
                        $entity['url'] ?? null
                    )
                ) {
                    $urls[] = $entity['url'];
                } elseif (
                    in_array(
                        $type,
                        ['url'],
                        true
                    )
                ) {
                    $offset = $entity['offset']
                        ?? null;
                    $length = $entity['length']
                        ?? null;

                    if (
                        is_int($offset)
                        && is_int($length)
                    ) {
                        $urls[] = mb_substr(
                            $text,
                            $offset,
                            $length
                        );
                    }
                }
            }
        }

        if (
            preg_match_all(
                '#(?:https?://|www\.)[^\s<>()]+#iu',
                $text,
                $matches
            ) > 0
        ) {
            $urls = [
                ...$urls,
                ...$matches[0],
            ];
        }

        if (
            preg_match_all(
                '/(?:t\.me|telegram\.me)\/[A-Za-z0-9_+\/-]+/iu',
                $text,
                $matches
            ) > 0
        ) {
            $urls = [
                ...$urls,
                ...$matches[0],
            ];
        }

        foreach ($urls as $url) {
            $host = $this->hostFromUrl(
                $url
            );

            if ($host === '') {
                return true;
            }

            $allowed = false;

            foreach ($allowedDomains as $domain) {
                if (
                    $host === $domain
                    || str_ends_with(
                        $host,
                        '.' . $domain
                    )
                ) {
                    $allowed = true;
                    break;
                }
            }

            if (!$allowed) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $badWords
     */
    private function containsBadWord(
        string $normalizedText,
        array $badWords
    ): bool {
        if ($normalizedText === '') {
            return false;
        }

        foreach ($badWords as $word) {
            $needle = $this->normalizeText(
                $word
            );

            if (
                $needle !== ''
                && str_contains(
                    $normalizedText,
                    $needle
                )
            ) {
                return true;
            }
        }

        return false;
    }

    private function hostFromUrl(
        string $url
    ): string {
        $url = trim(
            rtrim(
                $url,
                ".,؛،!?)]}"
            )
        );

        if (
            !preg_match(
                '#^[a-z][a-z0-9+.-]*://#i',
                $url
            )
        ) {
            $url = 'https://' . $url;
        }

        $host = parse_url(
            $url,
            PHP_URL_HOST
        );

        return is_string($host)
            ? mb_strtolower(
                rtrim($host, '.')
            )
            : '';
    }

    /**
     * @param array<string, mixed> $user
     */
    private function violationMessage(
        string $violation,
        array $user
    ): string {
        $name = trim(
            (string) (
                $user['first_name'] ?? ''
            )
        );

        $reason = match ($violation) {
            'link' => 'ارسال لینک غیرمجاز',
            'bad_word' => 'استفاده از عبارت ممنوع',
            'flood' => 'ارسال پیام‌های زیاد در زمان کوتاه',
            'duplicate' => 'تکرار چندباره یک پیام',
            'slow_mode' => 'رعایت‌نکردن فاصله ارسال پیام',
            default => 'نقض تنظیمات گروه',
        };

        return ($name !== ''
            ? $name . '، '
            : '')
            . "پیام به‌دلیل {$reason} حذف شد. 🛡";
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function shouldSendNotice(
        int $chatId,
        int $userId,
        array $settings
    ): bool {
        $cooldown = max(
            5,
            $this->automodNoticeCooldownSeconds
        );

        return $this->repository
            ->claimAutomodNotice(
                $chatId,
                $userId,
                $cooldown
            );
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $chat
     */
    private function sendRendered(
        int $chatId,
        string $template,
        array $user,
        array $chat
    ): void {
        try {
            $this->telegram->sendMessage(
                $chatId,
                mb_substr(
                    $this->templates->render(
                        $template,
                        $user,
                        $chat
                    ),
                    0,
                    4000
                )
            );
        } catch (Throwable) {
        }
    }

    private function normalizeText(
        string $text
    ): string {
        $text = strtr(
            mb_strtolower(trim($text)),
            [
                'ي' => 'ی',
                'ك' => 'ک',
                "\u{200C}" => ' ',
            ]
        );

        return preg_replace(
            '/\s+/u',
            ' ',
            $text
        ) ?? $text;
    }
}
