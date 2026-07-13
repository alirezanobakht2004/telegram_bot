<?php

declare(strict_types=1);

namespace SmartToolbox\Modules\GroupManagement;

use InvalidArgumentException;
use RuntimeException;
use SmartToolbox\Core\CallbackQueryContext;
use SmartToolbox\Core\CallbackRouter;
use SmartToolbox\Core\CommandRouter;
use SmartToolbox\Core\MessageContext;
use SmartToolbox\Core\ModuleInterface;
use SmartToolbox\Core\RateLimiter;
use Throwable;

final class GroupManagementModule implements ModuleInterface
{
    public function __construct(
        private readonly GroupRepository $repository,
        private readonly GroupAuthorization $authorization,
        private readonly GroupModerationService $moderation,
        private readonly GroupDurationParser $duration,
        private readonly RateLimiter $rateLimiter,
        private readonly int $maxAttempts = 40,
        private readonly int $windowSeconds = 60,
        private readonly int $maxPurgeMessages = 100,
        private readonly int $maxRulesLength = 3000,
        private readonly int $maxTemplateLength = 2000,
        private readonly int $inviteMaximumDays = 365
    ) {
    }

    public function register(CommandRouter $router): void
    {
        $commands = [
            'warn' => 'warn',
            'warnings' => 'warnings',
            'unwarn' => 'unwarn',
            'clearwarnings' => 'clearWarnings',
            'mute' => 'mute',
            'unmute' => 'unmute',
            'ban' => 'ban',
            'unban' => 'unban',
            'kick' => 'kick',
            'purge' => 'purge',
            'slowmode' => 'slowMode',
            'rules' => 'rules',
            'setrules' => 'setRules',
            'setwelcome' => 'setWelcome',
            'setgoodbye' => 'setGoodbye',
            'welcome' => 'welcome',
            'goodbye' => 'goodbye',
            'antispam' => 'antiSpam',
            'antilink' => 'antiLink',
            'linkwhitelist' => 'linkWhitelist',
            'badwords' => 'badWords',
            'captcha' => 'captcha',
            'invitelink' => 'inviteLink',
            'invitelinks' => 'inviteLinks',
            'revokelink' => 'revokeLink',
            'joinrequests' => 'joinRequests',
            'groupadmin' => 'groupAdmin',
        ];

        foreach ($commands as $command => $method) {
            $router->command(
                $command,
                function (
                    MessageContext $context,
                    string $arguments
                ) use ($method): void {
                    $this->execute(
                        $context,
                        $method,
                        $arguments
                    );
                },
                'group_management'
            );
        }

        $router->text(
            '🛡 مدیریت گروه',
            function (
                MessageContext $context
            ): void {
                $this->execute(
                    $context,
                    'groupAdmin',
                    ''
                );
            },
            'group_management'
        );
    }

    public function registerCallbacks(
        CallbackRouter $router
    ): void {
        $router->on(
            'groupcaptcha:',
            function (
                CallbackQueryContext $context,
                string $suffix
            ): void {
                $this->handleCaptchaCallback(
                    $context,
                    $suffix
                );
            },
            'group_management'
        );

        $router->on(
            'groupjoin:',
            function (
                CallbackQueryContext $context,
                string $suffix
            ): void {
                $this->handleJoinCallback(
                    $context,
                    $suffix
                );
            },
            'group_management'
        );
    }

    private function execute(
        MessageContext $context,
        string $method,
        string $arguments
    ): void {
        if (!$this->allow($context)) {
            return;
        }

        try {
            $this->{$method}(
                $context,
                trim($arguments)
            );
        } catch (
            InvalidArgumentException
            | RuntimeException $exception
        ) {
            $context->reply(
                "عملیات انجام نشد. ⚠️\n\n"
                . $exception->getMessage()
            );
        } catch (Throwable $exception) {
            try {
                $this->repository->audit(
                    $context->chatId,
                    $context->userId,
                    null,
                    $method,
                    [],
                    false,
                    $exception->getMessage()
                );
            } catch (Throwable) {
            }

            $context->reply(
                "عملیات مدیریتی با خطا مواجه شد. ⚠️\n\n"
                . 'چند لحظه بعد دوباره تلاش کن.'
            );
        }
    }

    private function warn(
        MessageContext $context,
        string $arguments
    ): void {
        $this->authorization->requireActorAdmin(
            $context,
            'can_restrict_members'
        );

        [$targetId, $reason] =
            $this->targetAndRest(
                $context,
                $arguments
            );

        $this->authorization
            ->requireTargetManageable(
                $context,
                $targetId
            );

        $warningId =
            $this->repository->addWarning(
                $context->chatId,
                $targetId,
                (int) $context->userId,
                $reason !== ''
                    ? $reason
                    : null
            );

        $count =
            $this->repository
                ->activeWarningCount(
                    $context->chatId,
                    $targetId
                );

        $settings =
            $this->repository->settings(
                $context->chatId
            );

        $threshold = max(
            1,
            (int) $settings[
                'warnings_threshold'
            ]
        );

        $message = "اخطار #{$warningId} ثبت شد. ⚠️\n\n"
            . "کاربر: "
            . $this->repository->userLabel(
                $targetId
            )
            . "\n"
            . "اخطارهای فعال: {$count}/{$threshold}";

        if ($reason !== '') {
            $message .= "\nدلیل: {$reason}";
        }

        if ($count >= $threshold) {
            $action = (string) $settings[
                'warning_action'
            ];

            $duration = max(
                30,
                (int) $settings[
                    'warning_action_duration_seconds'
                ]
            );

            $untilAt = time() + $duration;

            if ($action === 'mute') {
                if ($context->chatType !== 'supergroup') {
                    $message .=
                        "\n\nعمل خودکار Mute فقط در سوپرگروه قابل اجرا است.";
                } else {
                    $this->authorization
                        ->requireBotRight(
                            $context->chatId,
                            'can_restrict_members'
                        );

                    $this->moderation->mute(
                        $context->chatId,
                        $targetId,
                        $untilAt,
                        $context->userId,
                        'رسیدن به سقف اخطار',
                        'mute'
                    );

                    $message .=
                        "\n\n🔇 کاربر به‌صورت خودکار محدود شد.";
                }
            } elseif ($action === 'ban') {
                $this->authorization
                    ->requireBotRight(
                        $context->chatId,
                        'can_restrict_members'
                    );

                $this->moderation->ban(
                    $context->chatId,
                    $targetId,
                    $untilAt,
                    $context->userId,
                    'رسیدن به سقف اخطار',
                    'ban'
                );

                $message .=
                    "\n\n⛔ کاربر به‌صورت خودکار مسدود شد.";
            }
        }

        $this->repository->audit(
            $context->chatId,
            $context->userId,
            $targetId,
            'warning.add',
            [
                'warning_id' => $warningId,
                'reason' => $reason,
                'active_count' => $count,
            ]
        );

        $context->reply($message);
    }

    private function warnings(
        MessageContext $context,
        string $arguments
    ): void {
        $this->authorization
            ->requireGroup($context);

        $targetId = $this->targetOnly(
            $context,
            $arguments,
            true
        );

        $rows = $this->repository->warnings(
            $context->chatId,
            $targetId,
            20
        );

        if ($rows === []) {
            $context->reply(
                'هیچ اخطاری برای این کاربر ثبت نشده است.'
            );

            return;
        }

        $active = 0;
        $lines = [
            '⚠️ اخطارهای '
            . $this->repository->userLabel(
                $targetId
            ),
            '',
        ];

        foreach ($rows as $row) {
            $isActive =
                (int) $row['active'] === 1;

            if ($isActive) {
                $active++;
            }

            $lines[] = '#'
                . (int) $row['id']
                . ' · '
                . ($isActive
                    ? 'فعال'
                    : 'لغوشده')
                . ' · '
                . (string) $row['created_at'];

            if (
                is_string($row['reason'])
                && trim($row['reason']) !== ''
            ) {
                $lines[] =
                    'دلیل: ' . $row['reason'];
            }

            $lines[] = '';
        }

        array_splice(
            $lines,
            1,
            0,
            ['اخطار فعال: ' . $active]
        );

        $context->reply(
            mb_substr(
                implode("\n", $lines),
                0,
                3900
            )
        );
    }

    private function unwarn(
        MessageContext $context,
        string $arguments
    ): void {
        $this->authorization->requireActorAdmin(
            $context,
            'can_restrict_members'
        );

        $replyTarget =
            $this->replyTargetId($context);

        $arguments = trim($arguments);
        $warningId = null;
        $targetId = null;

        if (
            $arguments !== ''
            && preg_match(
                '/^\d+$/',
                $this->normalizeDigits(
                    $arguments
                )
            ) === 1
        ) {
            $warningId = (int)
                $this->normalizeDigits(
                    $arguments
                );
        } elseif ($replyTarget !== null) {
            $targetId = $replyTarget;
        } elseif ($arguments !== '') {
            $targetId =
                $this->repository
                    ->resolveUserToken(
                        $arguments
                    );
        }

        if ($warningId !== null) {
            $success =
                $this->repository
                    ->revokeWarning(
                        $context->chatId,
                        $warningId,
                        (int) $context->userId
                    );

            if (!$success) {
                throw new RuntimeException(
                    'اخطار فعال با این شناسه پیدا نشد.'
                );
            }

            $this->repository->audit(
                $context->chatId,
                $context->userId,
                null,
                'warning.revoke',
                ['warning_id' => $warningId]
            );

            $context->reply(
                "اخطار #{$warningId} لغو شد. ✅"
            );

            return;
        }

        if ($targetId === null) {
            throw new RuntimeException(
                'روی پیام کاربر Reply کن یا شناسه اخطار/کاربر را وارد کن.'
            );
        }

        $revoked =
            $this->repository
                ->revokeLatestWarning(
                    $context->chatId,
                    $targetId,
                    (int) $context->userId
                );

        if ($revoked === null) {
            throw new RuntimeException(
                'اخطار فعالی برای این کاربر وجود ندارد.'
            );
        }

        $this->repository->audit(
            $context->chatId,
            $context->userId,
            $targetId,
            'warning.revoke_latest',
            ['warning_id' => $revoked]
        );

        $context->reply(
            "آخرین اخطار فعال (#{$revoked}) لغو شد. ✅"
        );
    }

    private function clearWarnings(
        MessageContext $context,
        string $arguments
    ): void {
        $this->authorization->requireActorAdmin(
            $context,
            'can_restrict_members'
        );

        $targetId = $this->targetOnly(
            $context,
            $arguments
        );

        $count = $this->repository
            ->clearWarnings(
                $context->chatId,
                $targetId,
                (int) $context->userId
            );

        $this->repository->audit(
            $context->chatId,
            $context->userId,
            $targetId,
            'warning.clear',
            ['count' => $count]
        );

        $context->reply(
            "{$count} اخطار فعال پاک شد. ✅"
        );
    }

    private function mute(
        MessageContext $context,
        string $arguments
    ): void {
        $this->authorization->requireActorAdmin(
            $context,
            'can_restrict_members'
        );

        if ($context->chatType !== 'supergroup') {
            throw new RuntimeException(
                'Mute از Bot API فقط در سوپرگروه قابل اجرا است.'
            );
        }

        $this->authorization->requireBotRight(
            $context->chatId,
            'can_restrict_members'
        );

        [$targetId, $rest] =
            $this->targetAndRest(
                $context,
                $arguments
            );

        $this->authorization
            ->requireTargetManageable(
                $context,
                $targetId
            );

        [$seconds, $reason] =
            $this->durationAndReason(
                $rest,
                false
            );

        $untilAt = $seconds !== null
            ? time() + $seconds
            : null;

        $sanctionId = $this->moderation->mute(
            $context->chatId,
            $targetId,
            $untilAt,
            $context->userId,
            $reason !== ''
                ? $reason
                : null
        );

        $this->repository->audit(
            $context->chatId,
            $context->userId,
            $targetId,
            'member.mute',
            [
                'sanction_id' => $sanctionId,
                'until_at' => $untilAt,
                'reason' => $reason,
            ]
        );

        $context->reply(
            "کاربر محدود شد. 🔇\n\n"
            . "کاربر: "
            . $this->repository->userLabel(
                $targetId
            )
            . "\nمدت: "
            . ($seconds === null
                ? 'دائم'
                : $this->formatDuration(
                    $seconds
                ))
        );
    }

    private function unmute(
        MessageContext $context,
        string $arguments
    ): void {
        $this->authorization->requireActorAdmin(
            $context,
            'can_restrict_members'
        );

        if ($context->chatType !== 'supergroup') {
            throw new RuntimeException(
                'Unmute فقط در سوپرگروه قابل اجرا است.'
            );
        }

        $this->authorization->requireBotRight(
            $context->chatId,
            'can_restrict_members'
        );

        $targetId = $this->targetOnly(
            $context,
            $arguments
        );

        $this->authorization
            ->requireTargetManageable(
                $context,
                $targetId
            );

        $this->moderation->unmute(
            $context->chatId,
            $targetId
        );

        $this->repository->audit(
            $context->chatId,
            $context->userId,
            $targetId,
            'member.unmute'
        );

        $context->reply(
            'محدودیت کاربر برداشته شد. ✅'
        );
    }

    private function ban(
        MessageContext $context,
        string $arguments
    ): void {
        $this->authorization->requireActorAdmin(
            $context,
            'can_restrict_members'
        );

        $this->authorization->requireBotRight(
            $context->chatId,
            'can_restrict_members'
        );

        [$targetId, $rest] =
            $this->targetAndRest(
                $context,
                $arguments
            );

        $this->authorization
            ->requireTargetManageable(
                $context,
                $targetId
            );

        [$seconds, $reason] =
            $this->durationAndReason(
                $rest,
                true
            );

        $untilAt = $seconds !== null
            ? time() + $seconds
            : null;

        $sanctionId = $this->moderation->ban(
            $context->chatId,
            $targetId,
            $untilAt,
            $context->userId,
            $reason !== ''
                ? $reason
                : null
        );

        $this->repository->audit(
            $context->chatId,
            $context->userId,
            $targetId,
            'member.ban',
            [
                'sanction_id' => $sanctionId,
                'until_at' => $untilAt,
                'reason' => $reason,
            ]
        );

        $context->reply(
            "کاربر مسدود شد. ⛔\n\n"
            . "مدت: "
            . ($seconds === null
                ? 'دائم'
                : $this->formatDuration(
                    $seconds
                ))
        );
    }

    private function unban(
        MessageContext $context,
        string $arguments
    ): void {
        $this->authorization->requireActorAdmin(
            $context,
            'can_restrict_members'
        );

        $this->authorization->requireBotRight(
            $context->chatId,
            'can_restrict_members'
        );

        $targetId = $this->targetOnly(
            $context,
            $arguments
        );

        $this->moderation->unban(
            $context->chatId,
            $targetId
        );

        $this->repository->audit(
            $context->chatId,
            $context->userId,
            $targetId,
            'member.unban'
        );

        $context->reply(
            'مسدودیت کاربر برداشته شد. ✅'
        );
    }

    private function kick(
        MessageContext $context,
        string $arguments
    ): void {
        $this->authorization->requireActorAdmin(
            $context,
            'can_restrict_members'
        );

        $this->authorization->requireBotRight(
            $context->chatId,
            'can_restrict_members'
        );

        [$targetId, $reason] =
            $this->targetAndRest(
                $context,
                $arguments
            );

        $this->authorization
            ->requireTargetManageable(
                $context,
                $targetId
            );

        $this->moderation->kick(
            $context->chatId,
            $targetId,
            $context->userId,
            $reason !== ''
                ? $reason
                : null
        );

        $this->repository->audit(
            $context->chatId,
            $context->userId,
            $targetId,
            'member.kick',
            ['reason' => $reason]
        );

        $context->reply(
            'کاربر از گروه خارج شد و امکان پیوستن مجدد دارد. 👢'
        );
    }

    private function purge(
        MessageContext $context,
        string $arguments
    ): void {
        $this->authorization->requireActorAdmin(
            $context,
            'can_delete_messages'
        );

        $this->authorization->requireBotRight(
            $context->chatId,
            'can_delete_messages'
        );

        if ($context->messageId === null) {
            throw new RuntimeException(
                'شناسه پیام دستور در دسترس نیست.'
            );
        }

        $maximum = max(
            1,
            min(100, $this->maxPurgeMessages)
        );

        $count = $arguments !== ''
            ? (int) $this->normalizeDigits(
                $arguments
            )
            : 10;

        $replyMessageId =
            $this->replyMessageId($context);

        if (
            $replyMessageId !== null
            && $arguments === ''
        ) {
            $start = min(
                $replyMessageId,
                $context->messageId
            );

            $end = max(
                $replyMessageId,
                $context->messageId
            );

            $messageIds = range(
                $start,
                min(
                    $end,
                    $start + $maximum - 1
                )
            );
        } else {
            $count = max(
                1,
                min($maximum, $count)
            );

            $start = max(
                1,
                $context->messageId
                - $count + 1
            );

            $messageIds = range(
                $start,
                $context->messageId
            );
        }

        $this->moderation->deleteMessages(
            $context->chatId,
            $messageIds
        );

        $this->repository->audit(
            $context->chatId,
            $context->userId,
            null,
            'messages.purge',
            ['message_ids' => $messageIds]
        );

        $context->telegram()->sendMessage(
            $context->chatId,
            count($messageIds)
            . ' پیام برای حذف ارسال شد. 🧹',
            [
                'disable_notification' => true,
            ]
        );
    }

    private function slowMode(
        MessageContext $context,
        string $arguments
    ): void {
        $this->authorization->requireActorAdmin(
            $context,
            'can_delete_messages'
        );

        $value = mb_strtolower(
            trim($arguments)
        );

        if (
            $value === ''
            || $value === 'status'
        ) {
            $settings =
                $this->repository->settings(
                    $context->chatId
                );

            $seconds = (int) $settings[
                'bot_slow_mode_seconds'
            ];

            $context->reply(
                "Slow Mode ربات: "
                . ($seconds > 0
                    ? $seconds . ' ثانیه'
                    : 'خاموش')
                . "\n\nاین قابلیت توسط ربات و حذف پیام‌های سریع اجرا می‌شود؛ Bot API متد مستقیمی برای تغییر Slow Mode بومی گروه ارائه نمی‌کند."
            );

            return;
        }

        if (
            in_array(
                $value,
                ['off', '0', 'خاموش'],
                true
            )
        ) {
            $seconds = 0;
        } elseif (
            preg_match(
                '/^\d+$/',
                $this->normalizeDigits(
                    $value
                )
            ) === 1
        ) {
            $seconds = max(
                1,
                min(
                    3600,
                    (int) $this->normalizeDigits(
                        $value
                    )
                )
            );
        } else {
            $seconds = $this->duration->parse(
                $value,
                1,
                3600,
                false
            ) ?? 0;
        }

        if ($seconds > 0) {
            $this->authorization->requireBotRight(
                $context->chatId,
                'can_delete_messages'
            );
        }

        $this->repository->updateSettings(
            $context->chatId,
            [
                'bot_slow_mode_seconds' =>
                    $seconds,
            ]
        );

        $this->repository->audit(
            $context->chatId,
            $context->userId,
            null,
            'settings.slow_mode',
            ['seconds' => $seconds]
        );

        $context->reply(
            $seconds > 0
                ? "Slow Mode ربات روی {$seconds} ثانیه تنظیم شد. ✅"
                : 'Slow Mode ربات خاموش شد. ✅'
        );
    }

    private function rules(
        MessageContext $context,
        string $arguments
    ): void {
        $this->authorization
            ->requireGroup($context);

        $settings =
            $this->repository->settings(
                $context->chatId
            );

        $rules = trim(
            (string) (
                $settings['rules_text']
                ?? ''
            )
        );

        $context->reply(
            $rules !== ''
                ? "📜 قوانین گروه\n\n{$rules}"
                : 'هنوز قانونی برای این گروه ثبت نشده است.'
        );
    }

    private function setRules(
        MessageContext $context,
        string $arguments
    ): void {
        $this->authorization->requireActorAdmin(
            $context,
            'can_change_info'
        );

        $content = $this->contentFromArgumentOrReply(
            $context,
            $arguments
        );

        if (mb_strlen($content) > $this->maxRulesLength) {
            throw new RuntimeException(
                'متن قوانین بیش از حد طولانی است.'
            );
        }

        $this->repository->updateSettings(
            $context->chatId,
            ['rules_text' => $content]
        );

        $this->repository->audit(
            $context->chatId,
            $context->userId,
            null,
            'settings.rules'
        );

        $context->reply(
            'قوانین گروه ذخیره شد. ✅'
        );
    }

    private function setWelcome(
        MessageContext $context,
        string $arguments
    ): void {
        $this->setTemplate(
            $context,
            $arguments,
            'welcome_message',
            'welcome_enabled',
            'پیام خوش‌آمدگویی'
        );
    }

    private function setGoodbye(
        MessageContext $context,
        string $arguments
    ): void {
        $this->setTemplate(
            $context,
            $arguments,
            'goodbye_message',
            'goodbye_enabled',
            'پیام خداحافظی'
        );
    }

    private function welcome(
        MessageContext $context,
        string $arguments
    ): void {
        $this->toggleSetting(
            $context,
            $arguments,
            'welcome_enabled',
            'خوش‌آمدگویی'
        );
    }

    private function goodbye(
        MessageContext $context,
        string $arguments
    ): void {
        $this->toggleSetting(
            $context,
            $arguments,
            'goodbye_enabled',
            'خداحافظی'
        );
    }

    private function antiSpam(
        MessageContext $context,
        string $arguments
    ): void {
        $this->toggleSetting(
            $context,
            $arguments,
            'anti_spam_enabled',
            'ضد اسپم'
        );
    }

    private function antiLink(
        MessageContext $context,
        string $arguments
    ): void {
        $this->toggleSetting(
            $context,
            $arguments,
            'anti_link_enabled',
            'ضد لینک'
        );
    }

    private function captcha(
        MessageContext $context,
        string $arguments
    ): void {
        $this->toggleSetting(
            $context,
            $arguments,
            'captcha_enabled',
            'کپچا'
        );
    }

    private function linkWhitelist(
        MessageContext $context,
        string $arguments
    ): void {
        $this->authorization->requireActorAdmin(
            $context,
            'can_delete_messages'
        );

        [$action, $value] =
            $this->actionAndValue(
                $arguments
            );

        if ($action === 'list') {
            $domains = $this->repository
                ->domains($context->chatId);

            $context->reply(
                $domains === []
                    ? 'Whitelist دامنه خالی است.'
                    : "دامنه‌های مجاز:\n\n"
                        . implode(
                            "\n",
                            array_map(
                                static fn (
                                    string $domain
                                ): string =>
                                    '• ' . $domain,
                                $domains
                            )
                        )
            );

            return;
        }

        if ($value === '') {
            throw new RuntimeException(
                'دامنه وارد نشده است.'
            );
        }

        if ($action === 'add') {
            $this->repository->addDomain(
                $context->chatId,
                $value,
                (int) $context->userId
            );

            $message = 'دامنه به Whitelist اضافه شد. ✅';
        } elseif ($action === 'remove') {
            $removed =
                $this->repository
                    ->removeDomain(
                        $context->chatId,
                        $value
                    );

            $message = $removed
                ? 'دامنه حذف شد. ✅'
                : 'دامنه در Whitelist نبود.';
        } else {
            throw new RuntimeException(
                'فرمت: /linkwhitelist add example.com | remove | list'
            );
        }

        $this->repository->audit(
            $context->chatId,
            $context->userId,
            null,
            'settings.link_whitelist.' . $action,
            ['domain' => $value]
        );

        $context->reply($message);
    }

    private function badWords(
        MessageContext $context,
        string $arguments
    ): void {
        $this->authorization->requireActorAdmin(
            $context,
            'can_delete_messages'
        );

        [$action, $value] =
            $this->actionAndValue(
                $arguments
            );

        if (
            in_array(
                $action,
                ['on', 'off', 'status'],
                true
            )
        ) {
            $this->toggleSetting(
                $context,
                $action,
                'bad_words_enabled',
                'فیلتر کلمات ممنوع'
            );

            return;
        }

        if ($action === 'list') {
            $words = $this->repository
                ->badWords($context->chatId);

            $context->reply(
                $words === []
                    ? 'فهرست کلمات ممنوع خالی است.'
                    : "کلمات ممنوع:\n\n"
                        . implode(
                            "\n",
                            array_map(
                                static fn (
                                    string $word
                                ): string =>
                                    '• ' . $word,
                                $words
                            )
                        )
            );

            return;
        }

        if ($action === 'clear') {
            $count = $this->repository
                ->clearBadWords(
                    $context->chatId
                );

            $context->reply(
                "{$count} کلمه حذف شد. ✅"
            );

            return;
        }

        if ($value === '') {
            throw new RuntimeException(
                'کلمه یا عبارت وارد نشده است.'
            );
        }

        if ($action === 'add') {
            $this->repository->addBadWord(
                $context->chatId,
                $value,
                (int) $context->userId
            );

            $message =
                'کلمه به فهرست ممنوع اضافه شد. ✅';
        } elseif ($action === 'remove') {
            $removed =
                $this->repository
                    ->removeBadWord(
                        $context->chatId,
                        $value
                    );

            $message = $removed
                ? 'کلمه حذف شد. ✅'
                : 'کلمه در فهرست نبود.';
        } else {
            throw new RuntimeException(
                'فرمت: /badwords add عبارت | remove | list | clear | on | off'
            );
        }

        $this->repository->audit(
            $context->chatId,
            $context->userId,
            null,
            'settings.bad_words.' . $action,
            ['value' => $value]
        );

        $context->reply($message);
    }

    private function inviteLink(
        MessageContext $context,
        string $arguments
    ): void {
        $this->authorization->requireActorAdmin(
            $context,
            'can_invite_users'
        );

        $this->authorization->requireBotRight(
            $context->chatId,
            'can_invite_users'
        );

        $parts = preg_split(
            '/\s+/u',
            trim($arguments),
            -1,
            PREG_SPLIT_NO_EMPTY
        );

        $parts = is_array($parts)
            ? $parts
            : [];

        $durationSeconds = null;
        $memberLimit = null;
        $createsRequest = false;
        $nameParts = [];

        foreach ($parts as $part) {
            if (
                mb_strtolower($part)
                === 'request'
            ) {
                $createsRequest = true;
                continue;
            }

            if (
                $durationSeconds === null
                && preg_match(
                    '/^\d+\s*(m|h|d|w)$/i',
                    $this->normalizeDigits(
                        $part
                    )
                ) === 1
            ) {
                $durationSeconds =
                    $this->duration->parse(
                        $part,
                        60,
                        $this->inviteMaximumDays
                        * 86400,
                        false
                    );
                continue;
            }

            if (
                $memberLimit === null
                && preg_match(
                    '/^\d+$/',
                    $this->normalizeDigits(
                        $part
                    )
                ) === 1
            ) {
                $memberLimit = max(
                    1,
                    min(
                        99999,
                        (int)
                        $this->normalizeDigits(
                            $part
                        )
                    )
                );
                continue;
            }

            $nameParts[] = $part;
        }

        if ($createsRequest) {
            $memberLimit = null;
        }

        $options = [];

        if ($durationSeconds !== null) {
            $options['expire_date'] =
                time() + $durationSeconds;
        }

        if ($memberLimit !== null) {
            $options['member_limit'] =
                $memberLimit;
        }

        if ($createsRequest) {
            $options[
                'creates_join_request'
            ] = true;
        }

        $name = trim(
            implode(' ', $nameParts)
        );

        if ($name !== '') {
            $options['name'] = mb_substr(
                $name,
                0,
                32
            );
        }

        $link = $this->moderation
            ->createInviteLink(
                $context->chatId,
                $options
            );

        $id = $this->repository
            ->storeInviteLink(
                $context->chatId,
                (int) $context->userId,
                $link
            );

        $this->repository->audit(
            $context->chatId,
            $context->userId,
            null,
            'invite.create',
            [
                'invite_id' => $id,
                'member_limit' =>
                    $memberLimit,
                'creates_join_request' =>
                    $createsRequest,
            ]
        );

        $context->reply(
            "🔗 لینک دعوت #{$id}\n\n"
            . (string) $link['invite_link']
            . "\n\nلغو: /revokelink {$id}"
        );
    }

    private function inviteLinks(
        MessageContext $context,
        string $arguments
    ): void {
        $this->authorization->requireActorAdmin(
            $context,
            'can_invite_users'
        );

        $rows = $this->repository
            ->inviteLinks(
                $context->chatId,
                20
            );

        if ($rows === []) {
            $context->reply(
                'هنوز لینک دعوتی توسط ربات ساخته نشده است.'
            );

            return;
        }

        $lines = ['🔗 لینک‌های دعوت', ''];

        foreach ($rows as $row) {
            $lines[] = '#'
                . (int) $row['id']
                . ' · '
                . (string) $row['status'];

            $lines[] =
                (string) $row['invite_link'];

            if ($row['status'] === 'active') {
                $lines[] =
                    '/revokelink '
                    . (int) $row['id'];
            }

            $lines[] = '';
        }

        $context->reply(
            mb_substr(
                implode("\n", $lines),
                0,
                3900
            )
        );
    }

    private function revokeLink(
        MessageContext $context,
        string $arguments
    ): void {
        $this->authorization->requireActorAdmin(
            $context,
            'can_invite_users'
        );

        $this->authorization->requireBotRight(
            $context->chatId,
            'can_invite_users'
        );

        $id = $this->positiveInt(
            $arguments
        );

        $row = $this->repository
            ->inviteLink(
                $context->chatId,
                $id
            );

        if (
            $row === null
            || $row['status'] !== 'active'
        ) {
            throw new RuntimeException(
                'لینک فعال با این شناسه پیدا نشد.'
            );
        }

        $this->moderation
            ->revokeInviteLink(
                $context->chatId,
                (string) $row[
                    'invite_link'
                ]
            );

        $this->repository
            ->markInviteRevoked($id);

        $this->repository->audit(
            $context->chatId,
            $context->userId,
            null,
            'invite.revoke',
            ['invite_id' => $id]
        );

        $context->reply(
            "لینک #{$id} لغو شد. ✅"
        );
    }

    private function joinRequests(
        MessageContext $context,
        string $arguments
    ): void {
        $this->authorization->requireActorAdmin(
            $context,
            'can_invite_users'
        );

        $this->authorization->requireBotRight(
            $context->chatId,
            'can_invite_users'
        );

        $parts = preg_split(
            '/\s+/u',
            trim($arguments),
            -1,
            PREG_SPLIT_NO_EMPTY
        );

        $parts = is_array($parts)
            ? $parts
            : [];

        $action = mb_strtolower(
            $parts[0] ?? 'list'
        );

        if ($action === 'mode') {
            $mode = mb_strtolower(
                $parts[1] ?? ''
            );

            if (
                !in_array(
                    $mode,
                    [
                        'manual',
                        'approve',
                        'decline',
                    ],
                    true
                )
            ) {
                throw new RuntimeException(
                    'Mode باید manual، approve یا decline باشد.'
                );
            }

            $this->repository->updateSettings(
                $context->chatId,
                ['join_request_mode' => $mode]
            );

            $context->reply(
                "حالت درخواست عضویت روی {$mode} تنظیم شد. ✅"
            );

            return;
        }

        if (
            in_array(
                $action,
                ['approve', 'decline'],
                true
            )
        ) {
            $id = $this->positiveInt(
                $parts[1] ?? ''
            );

            $this->resolveJoinRequest(
                $context->chatId,
                $id,
                $action,
                $context->userId
            );

            $context->reply(
                $action === 'approve'
                    ? 'درخواست عضویت تأیید شد. ✅'
                    : 'درخواست عضویت رد شد. ✅'
            );

            return;
        }

        if (
            in_array(
                $action,
                ['approveall', 'declineall'],
                true
            )
        ) {
            $rows = $this->repository
                ->pendingJoinRequests(
                    $context->chatId,
                    100
                );

            $resolved = 0;
            $mode = $action === 'approveall'
                ? 'approve'
                : 'decline';

            foreach ($rows as $row) {
                try {
                    $this->resolveJoinRequest(
                        $context->chatId,
                        (int) $row['id'],
                        $mode,
                        $context->userId
                    );

                    $resolved++;
                } catch (Throwable) {
                }
            }

            $context->reply(
                "{$resolved} درخواست پردازش شد. ✅"
            );

            return;
        }

        $rows = $this->repository
            ->pendingJoinRequests(
                $context->chatId,
                20
            );

        if ($rows === []) {
            $context->reply(
                'درخواست عضویت در انتظار وجود ندارد.'
            );

            return;
        }

        foreach ($rows as $row) {
            $name = trim(
                (string) (
                    $row['first_name'] ?? ''
                )
                . ' '
                . (string) (
                    $row['last_name'] ?? ''
                )
            );

            $name = $name !== ''
                ? $name
                : (string) $row['user_id'];

            $context->telegram()->sendMessage(
                $context->chatId,
                "درخواست عضویت #"
                . (int) $row['id']
                . "\n\n"
                . "کاربر: {$name}\n"
                . "شناسه: "
                . (int) $row['user_id']
                . (
                    trim(
                        (string) (
                            $row['bio'] ?? ''
                        )
                    ) !== ''
                        ? "\nBio: "
                            . $row['bio']
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
                                        . (int) $row['id'],
                                ],
                                [
                                    'text' => '❌ رد',
                                    'callback_data' =>
                                        'groupjoin:decline:'
                                        . (int) $row['id'],
                                ],
                            ],
                        ],
                    ],
                ]
            );
        }
    }

    private function groupAdmin(
        MessageContext $context,
        string $arguments
    ): void {
        $this->authorization->requireActorAdmin(
            $context
        );

        $settings =
            $this->repository->settings(
                $context->chatId
            );

        $context->reply(
            "🛡 مدیریت حرفه‌ای گروه\n\n"
            . "اخطار: /warn، /warnings، /unwarn\n"
            . "محدودیت: /mute، /unmute، /ban، /unban، /kick\n"
            . "پاک‌سازی: /purge 20\n"
            . "قوانین: /rules، /setrules\n"
            . "خوش‌آمد: /welcome، /setwelcome\n"
            . "ضداسپم: /antispam on|off\n"
            . "ضدلینک: /antilink on|off\n"
            . "Whitelist: /linkwhitelist\n"
            . "کلمات ممنوع: /badwords\n"
            . "کپچا: /captcha on|off\n"
            . "لینک دعوت: /invitelink، /invitelinks\n"
            . "درخواست عضویت: /joinrequests\n\n"
            . "وضعیت فعلی:\n"
            . "Anti-spam: "
            . $this->onOff(
                (int) $settings[
                    'anti_spam_enabled'
                ]
            )
            . "\nAnti-link: "
            . $this->onOff(
                (int) $settings[
                    'anti_link_enabled'
                ]
            )
            . "\nCaptcha: "
            . $this->onOff(
                (int) $settings[
                    'captcha_enabled'
                ]
            )
            . "\nSlow mode: "
            . (int) $settings[
                'bot_slow_mode_seconds'
            ]
            . 's'
        );
    }

    private function setTemplate(
        MessageContext $context,
        string $arguments,
        string $messageKey,
        string $enabledKey,
        string $label
    ): void {
        $this->authorization->requireActorAdmin(
            $context,
            'can_change_info'
        );

        $content = $this->contentFromArgumentOrReply(
            $context,
            $arguments
        );

        if (
            mb_strlen($content)
            > $this->maxTemplateLength
        ) {
            throw new RuntimeException(
                'متن قالب بیش از حد طولانی است.'
            );
        }

        $this->repository->updateSettings(
            $context->chatId,
            [
                $messageKey => $content,
                $enabledKey => 1,
            ]
        );

        $this->repository->audit(
            $context->chatId,
            $context->userId,
            null,
            'settings.' . $messageKey
        );

        $context->reply(
            "{$label} ذخیره و فعال شد. ✅\n\n"
            . "متغیرها: {first_name}، {last_name}، {full_name}، {username}، {user_id}، {chat_title}"
        );
    }

    private function toggleSetting(
        MessageContext $context,
        string $arguments,
        string $key,
        string $label
    ): void {
        $this->authorization->requireActorAdmin(
            $context,
            'can_manage_chat'
        );

        $value = mb_strtolower(
            trim($arguments)
        );

        $settings =
            $this->repository->settings(
                $context->chatId
            );

        if (
            $value === ''
            || $value === 'status'
        ) {
            $context->reply(
                "{$label}: "
                . $this->onOff(
                    (int) $settings[$key]
                )
            );

            return;
        }

        if (
            in_array(
                $value,
                ['on', 'enable', '1', 'روشن'],
                true
            )
        ) {
            $enabled = 1;
        } elseif (
            in_array(
                $value,
                ['off', 'disable', '0', 'خاموش'],
                true
            )
        ) {
            $enabled = 0;
        } else {
            throw new RuntimeException(
                "فرمت: on، off یا status"
            );
        }

        if (
            $enabled === 1
            && in_array(
                $key,
                [
                    'anti_spam_enabled',
                    'anti_link_enabled',
                    'bad_words_enabled',
                ],
                true
            )
        ) {
            $this->authorization
                ->requireBotRight(
                    $context->chatId,
                    'can_delete_messages'
                );
        }

        if (
            $enabled === 1
            && $key === 'captcha_enabled'
        ) {
            if ($context->chatType !== 'supergroup') {
                throw new RuntimeException(
                    'Captcha فقط در سوپرگروه قابل استفاده است.'
                );
            }

            $this->authorization
                ->requireBotRight(
                    $context->chatId,
                    'can_restrict_members'
                );
        }

        $this->repository->updateSettings(
            $context->chatId,
            [$key => $enabled]
        );

        $this->repository->audit(
            $context->chatId,
            $context->userId,
            null,
            'settings.' . $key,
            ['enabled' => $enabled]
        );

        $context->reply(
            "{$label} "
            . ($enabled === 1
                ? 'فعال'
                : 'غیرفعال')
            . ' شد. ✅'
        );
    }

    private function handleCaptchaCallback(
        CallbackQueryContext $context,
        string $suffix
    ): void {
        $parts = explode(':', $suffix, 2);

        if (count($parts) !== 2) {
            $context->answer(
                'داده کپچا معتبر نیست.',
                true
            );

            return;
        }

        $challengeId = (int) $parts[0];
        $answer = $parts[1];
        $userId = $context->userId();

        if ($userId === null) {
            $context->answer(
                'شناسه کاربر در دسترس نیست.',
                true
            );

            return;
        }

        $challenge =
            $this->repository->captcha(
                $challengeId
            );

        if (
            $challenge === null
            || (int) $challenge['user_id']
                !== $userId
        ) {
            $context->answer(
                'این کپچا متعلق به شما نیست.',
                true
            );

            return;
        }

        $result =
            $this->repository
                ->answerCaptcha(
                    $challengeId,
                    $userId,
                    $answer
                );

        $status = $result['status'];

        if ($status === 'passed') {
            try {
                $this->moderation->unmute(
                    (int) $challenge['chat_id'],
                    $userId
                );

                $this->repository->audit(
                    (int) $challenge['chat_id'],
                    $userId,
                    $userId,
                    'captcha.passed'
                );

                $context->answer(
                    'تأیید شد؛ محدودیت برداشته شد. ✅'
                );

                $context->reply(
                    'کاربر تأیید شد و می‌تواند پیام ارسال کند. ✅'
                );

                if (
                    $context->messageId()
                    !== null
                ) {
                    try {
                        $this->moderation
                            ->deleteMessage(
                                (int) $challenge[
                                    'chat_id'
                                ],
                                (int) $context
                                    ->messageId()
                            );
                    } catch (Throwable) {
                    }
                }
            } catch (Throwable $exception) {
                $context->answer(
                    'پاسخ درست بود، اما برداشتن محدودیت ناموفق شد.',
                    true
                );
            }

            return;
        }

        if (
            in_array(
                $status,
                ['failed', 'expired'],
                true
            )
        ) {
            $settings =
                $this->repository->settings(
                    (int) $challenge['chat_id']
                );

            try {
                if (
                    $settings[
                        'captcha_failure_action'
                    ] === 'ban'
                ) {
                    $this->moderation->ban(
                        (int) $challenge['chat_id'],
                        $userId,
                        null,
                        null,
                        'Captcha failed',
                        'ban'
                    );
                } else {
                    $this->moderation->kick(
                        (int) $challenge['chat_id'],
                        $userId,
                        null,
                        'Captcha failed'
                    );
                }
            } catch (Throwable) {
            }

            $this->repository
                ->revokeActiveSanctions(
                    (int) $challenge['chat_id'],
                    $userId,
                    ['captcha']
                );

            $context->answer(
                'کپچا ناموفق بود.',
                true
            );

            return;
        }

        if ($status === 'pending') {
            $remaining = max(
                0,
                $result['max_attempts']
                - $result['attempts']
            );

            $context->answer(
                "پاسخ نادرست است؛ {$remaining} تلاش باقی مانده.",
                true
            );

            return;
        }

        $context->answer(
            'کپچا منقضی یا پردازش‌شده است.',
            true
        );
    }

    private function handleJoinCallback(
        CallbackQueryContext $context,
        string $suffix
    ): void {
        $chatId = $context->chatId();
        $userId = $context->userId();

        if ($chatId === null || $userId === null) {
            $context->answer(
                'اطلاعات چت در دسترس نیست.',
                true
            );

            return;
        }

        if (
            !$this->authorization
                ->isAdministrator(
                    $chatId,
                    $userId
                )
        ) {
            $context->answer(
                'فقط مدیران می‌توانند تصمیم بگیرند.',
                true
            );

            return;
        }

        [$action, $idText] = array_pad(
            explode(':', $suffix, 2),
            2,
            ''
        );

        $id = (int) $idText;

        if (
            !in_array(
                $action,
                ['approve', 'decline'],
                true
            )
            || $id <= 0
        ) {
            $context->answer(
                'داده درخواست معتبر نیست.',
                true
            );

            return;
        }

        try {
            $this->resolveJoinRequest(
                $chatId,
                $id,
                $action,
                $userId
            );

            $context->answer(
                $action === 'approve'
                    ? 'تأیید شد. ✅'
                    : 'رد شد. ✅'
            );
        } catch (Throwable $exception) {
            $context->answer(
                $exception->getMessage(),
                true
            );
        }
    }

    private function resolveJoinRequest(
        int $chatId,
        int $id,
        string $action,
        ?int $adminId
    ): void {
        $row = $this->repository
            ->joinRequest(
                $chatId,
                $id
            );

        if (
            $row === null
            || $row['status'] !== 'pending'
        ) {
            throw new RuntimeException(
                'درخواست در انتظار پیدا نشد.'
            );
        }

        $userId = (int) $row['user_id'];

        if ($action === 'approve') {
            $this->moderation
                ->approveJoinRequest(
                    $chatId,
                    $userId
                );

            $status = 'approved';
        } else {
            $this->moderation
                ->declineJoinRequest(
                    $chatId,
                    $userId
                );

            $status = 'declined';
        }

        $this->repository
            ->resolveJoinRequest(
                $chatId,
                $id,
                $status,
                $adminId
            );

        $this->repository->audit(
            $chatId,
            $adminId,
            $userId,
            'join_request.' . $status,
            ['request_id' => $id]
        );
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function targetAndRest(
        MessageContext $context,
        string $arguments
    ): array {
        $replyTarget =
            $this->replyTargetId($context);

        if ($replyTarget !== null) {
            return [
                $replyTarget,
                trim($arguments),
            ];
        }

        $parts = preg_split(
            '/\s+/u',
            trim($arguments),
            2,
            PREG_SPLIT_NO_EMPTY
        );

        if (
            !is_array($parts)
            || $parts === []
        ) {
            throw new RuntimeException(
                'روی پیام کاربر Reply کن یا شناسه/@username او را وارد کن.'
            );
        }

        $targetId =
            $this->repository
                ->resolveUserToken(
                    $parts[0]
                );

        if ($targetId === null) {
            throw new RuntimeException(
                'کاربر در دیتابیس ربات پیدا نشد؛ روی پیام او Reply کن.'
            );
        }

        return [
            $targetId,
            trim($parts[1] ?? ''),
        ];
    }

    private function targetOnly(
        MessageContext $context,
        string $arguments,
        bool $defaultSelf = false
    ): int {
        $replyTarget =
            $this->replyTargetId($context);

        if ($replyTarget !== null) {
            return $replyTarget;
        }

        if (
            trim($arguments) === ''
            && $defaultSelf
            && $context->userId !== null
        ) {
            return $context->userId;
        }

        $target =
            $this->repository
                ->resolveUserToken(
                    trim($arguments)
                );

        if ($target === null) {
            throw new RuntimeException(
                'روی پیام کاربر Reply کن یا شناسه/@username او را وارد کن.'
            );
        }

        return $target;
    }

    /**
     * @return array{0: ?int, 1: string}
     */
    private function durationAndReason(
        string $value,
        bool $durationOptional
    ): array {
        $parts = preg_split(
            '/\s+/u',
            trim($value),
            2,
            PREG_SPLIT_NO_EMPTY
        );

        if (
            !is_array($parts)
            || $parts === []
        ) {
            if ($durationOptional) {
                return [null, ''];
            }

            throw new RuntimeException(
                'مدت زمان وارد نشده است؛ نمونه: 10m.'
            );
        }

        try {
            $seconds = $this->duration
                ->parse(
                    $parts[0],
                    30,
                    31622400,
                    true
                );

            return [
                $seconds,
                trim($parts[1] ?? ''),
            ];
        } catch (InvalidArgumentException) {
            if ($durationOptional) {
                return [
                    null,
                    trim($value),
                ];
            }

            throw new RuntimeException(
                'مدت زمان معتبر نیست؛ نمونه: 10m، 2h، 3d یا forever.'
            );
        }
    }

    private function contentFromArgumentOrReply(
        MessageContext $context,
        string $arguments
    ): string {
        $content = trim($arguments);

        if ($content !== '') {
            return $content;
        }

        $payload = $context->updateContext
            ?->payload();

        $reply = is_array($payload)
            ? ($payload[
                'reply_to_message'
            ] ?? null)
            : null;

        if (is_array($reply)) {
            $replyText = $reply['text']
                ?? $reply['caption']
                ?? null;

            if (is_string($replyText)) {
                $content = trim($replyText);
            }
        }

        if ($content === '') {
            throw new RuntimeException(
                'متن را بعد از دستور بنویس یا روی یک پیام متنی Reply کن.'
            );
        }

        return $content;
    }

    private function replyTargetId(
        MessageContext $context
    ): ?int {
        $payload = $context->updateContext
            ?->payload();

        $id = is_array($payload)
            ? ($payload[
                'reply_to_message'
            ]['from']['id'] ?? null)
            : null;

        return is_int($id)
            ? $id
            : null;
    }

    private function replyMessageId(
        MessageContext $context
    ): ?int {
        $payload = $context->updateContext
            ?->payload();

        $id = is_array($payload)
            ? ($payload[
                'reply_to_message'
            ]['message_id'] ?? null)
            : null;

        return is_int($id)
            ? $id
            : null;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function actionAndValue(
        string $arguments
    ): array {
        $parts = preg_split(
            '/\s+/u',
            trim($arguments),
            2,
            PREG_SPLIT_NO_EMPTY
        );

        if (
            !is_array($parts)
            || $parts === []
        ) {
            return ['list', ''];
        }

        return [
            mb_strtolower($parts[0]),
            trim($parts[1] ?? ''),
        ];
    }

    private function positiveInt(
        string $value
    ): int {
        $value = $this->normalizeDigits(
            trim($value)
        );

        if (
            preg_match(
                '/^\d+$/',
                $value
            ) !== 1
            || (int) $value <= 0
        ) {
            throw new RuntimeException(
                'شناسه عددی معتبر وارد نشده است.'
            );
        }

        return (int) $value;
    }

    private function allow(
        MessageContext $context
    ): bool {
        $result = $this->rateLimiter->attempt(
            'group-management:'
            . $context->actorKey(),
            max(1, $this->maxAttempts),
            max(1, $this->windowSeconds)
        );

        if ($result->allowed) {
            return true;
        }

        $context->reply(
            "درخواست‌های مدیریتی زیاد است؛ "
            . "{$result->retryAfter} ثانیه دیگر تلاش کن."
        );

        return false;
    }

    private function formatDuration(int $seconds): string
    {
        return match (true) {
            $seconds % 604800 === 0 =>
                ($seconds / 604800)
                . ' هفته',

            $seconds % 86400 === 0 =>
                ($seconds / 86400)
                . ' روز',

            $seconds % 3600 === 0 =>
                ($seconds / 3600)
                . ' ساعت',

            $seconds % 60 === 0 =>
                ($seconds / 60)
                . ' دقیقه',

            default => $seconds . ' ثانیه',
        };
    }

    private function onOff(int $value): string
    {
        return $value === 1
            ? 'روشن'
            : 'خاموش';
    }

    private function normalizeDigits(
        string $value
    ): string {
        return strtr(
            $value,
            [
                '۰' => '0',
                '۱' => '1',
                '۲' => '2',
                '۳' => '3',
                '۴' => '4',
                '۵' => '5',
                '۶' => '6',
                '۷' => '7',
                '۸' => '8',
                '۹' => '9',

                '٠' => '0',
                '١' => '1',
                '٢' => '2',
                '٣' => '3',
                '٤' => '4',
                '٥' => '5',
                '٦' => '6',
                '٧' => '7',
                '٨' => '8',
                '٩' => '9',
            ]
        );
    }
}
