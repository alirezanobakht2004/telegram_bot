<?php

declare(strict_types=1);

namespace SmartToolbox\Modules\GroupManagement;

use JsonException;
use PDO;
use RuntimeException;
use SmartToolbox\Core\MessageContext;
use SmartToolbox\Core\TelegramClient;
use Throwable;

final class GroupAuthorization
{
    private ?int $botId = null;

    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly PDO $pdo,
        private readonly int $roleCacheTtl = 120
    ) {
    }

    public function requireGroup(
        MessageContext $context
    ): void {
        if (!$context->isGroup()) {
            throw new RuntimeException(
                'این دستور فقط داخل گروه یا سوپرگروه قابل استفاده است.'
            );
        }

        if ($context->userId === null) {
            throw new RuntimeException(
                'دستورهای مدیریتی در حالت مدیر ناشناس پشتیبانی نمی‌شوند.'
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function requireActorAdmin(
        MessageContext $context,
        ?string $requiredRight = null
    ): array {
        $this->requireGroup($context);

        $member = $this->member(
            $context->chatId,
            (int) $context->userId,
            true
        );

        $status = (string) (
            $member['status'] ?? ''
        );

        if (
            !in_array(
                $status,
                ['creator', 'administrator'],
                true
            )
        ) {
            throw new RuntimeException(
                'فقط مدیران گروه می‌توانند این دستور را اجرا کنند.'
            );
        }

        if (
            $status !== 'creator'
            && $requiredRight !== null
            && ($member[$requiredRight] ?? false)
                !== true
        ) {
            throw new RuntimeException(
                "دسترسی مدیریتی {$requiredRight} برای این عملیات لازم است."
            );
        }

        return $member;
    }

    /**
     * @return array<string, mixed>
     */
    public function requireBotRight(
        int $chatId,
        string $requiredRight
    ): array {
        $member = $this->member(
            $chatId,
            $this->botId(),
            true
        );

        if (
            ($member['status'] ?? '')
            !== 'administrator'
            && ($member['status'] ?? '')
                !== 'creator'
        ) {
            throw new RuntimeException(
                'ربات باید در گروه Administrator باشد.'
            );
        }

        if (
            ($member['status'] ?? '')
                !== 'creator'
            && ($member[$requiredRight] ?? false)
                !== true
        ) {
            throw new RuntimeException(
                "دسترسی {$requiredRight} برای ربات فعال نیست."
            );
        }

        return $member;
    }

    /**
     * @return array<string, mixed>
     */
    public function requireTargetManageable(
        MessageContext $context,
        int $targetUserId
    ): array {
        if (
            $context->userId !== null
            && $targetUserId === $context->userId
        ) {
            throw new RuntimeException(
                'نمی‌توانی این عملیات را روی خودت انجام بدهی.'
            );
        }

        if ($targetUserId === $this->botId()) {
            throw new RuntimeException(
                'نمی‌توانی ربات را هدف عملیات مدیریتی قرار بدهی.'
            );
        }

        $target = $this->member(
            $context->chatId,
            $targetUserId,
            true
        );

        $status = (string) (
            $target['status'] ?? ''
        );

        if (
            in_array(
                $status,
                ['creator', 'administrator'],
                true
            )
        ) {
            throw new RuntimeException(
                'مدیر یا مالک گروه از طریق ربات قابل محدودکردن نیست.'
            );
        }

        return $target;
    }

    /**
     * @return array<string, mixed>
     */
    public function member(
        int $chatId,
        int $userId,
        bool $forceRefresh = false
    ): array {
        if (!$forceRefresh) {
            $cached = $this->cachedMember(
                $chatId,
                $userId
            );

            if ($cached !== null) {
                return $cached;
            }
        }

        $result = $this->telegram->call(
            'getChatMember',
            [
                'chat_id' => $chatId,
                'user_id' => $userId,
            ]
        );

        if (!is_array($result)) {
            throw new RuntimeException(
                'اطلاعات عضو از Telegram دریافت نشد.'
            );
        }

        $this->storeMember(
            $chatId,
            $userId,
            $result
        );

        return $result;
    }

    public function isAdministrator(
        int $chatId,
        int $userId
    ): bool {
        try {
            $member = $this->member(
                $chatId,
                $userId
            );

            return in_array(
                $member['status'] ?? '',
                ['creator', 'administrator'],
                true
            );
        } catch (Throwable) {
            /*
             * AutoMod در خطای موقت Telegram به‌صورت Fail-open
             * عمل می‌کند تا پیام مدیر به‌اشتباه حذف یا محدود
             * نشود. دستورهای مدیریتی صریح همچنان خطا می‌دهند.
             */
            return true;
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function rememberFromChatMemberUpdate(
        array $payload
    ): void {
        $chatId = $payload['chat']['id']
            ?? null;

        $member = $payload[
            'new_chat_member'
        ] ?? null;

        $userId = is_array($member)
            ? ($member['user']['id'] ?? null)
            : null;

        if (
            !is_int($chatId)
            || !is_int($userId)
            || !is_array($member)
        ) {
            return;
        }

        $this->storeMember(
            $chatId,
            $userId,
            $member
        );
    }

    public function botId(): int
    {
        if ($this->botId !== null) {
            return $this->botId;
        }

        $me = $this->telegram->getMe();
        $id = $me['id'] ?? null;

        if (!is_int($id)) {
            throw new RuntimeException(
                'شناسه ربات از Telegram دریافت نشد.'
            );
        }

        return $this->botId = $id;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function cachedMember(
        int $chatId,
        int $userId
    ): ?array {
        $statement = $this->pdo->prepare(
            'SELECT
                status,
                permissions_json,
                checked_at
             FROM group_member_roles
             WHERE chat_id = :chat_id
               AND user_id = :user_id
             LIMIT 1'
        );

        $statement->execute([
            'chat_id' => $chatId,
            'user_id' => $userId,
        ]);

        $row = $statement->fetch(
            PDO::FETCH_ASSOC
        );

        if (
            !is_array($row)
            || (int) $row['checked_at']
                < time() - max(
                    10,
                    min(
                        3600,
                        $this->roleCacheTtl
                    )
                )
        ) {
            return null;
        }

        try {
            $permissions = json_decode(
                (string) $row[
                    'permissions_json'
                ],
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException) {
            return null;
        }

        if (!is_array($permissions)) {
            return null;
        }

        $permissions['status'] =
            (string) $row['status'];

        return $permissions;
    }

    /**
     * @param array<string, mixed> $member
     */
    private function storeMember(
        int $chatId,
        int $userId,
        array $member
    ): void {
        $status = (string) (
            $member['status'] ?? 'left'
        );

        $permissions = $member;
        unset(
            $permissions['user'],
            $permissions['status']
        );

        try {
            $json = json_encode(
                $permissions,
                JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
            );
        } catch (JsonException) {
            $json = '{}';
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO group_member_roles (
                chat_id,
                user_id,
                status,
                is_admin,
                permissions_json,
                checked_at,
                updated_at
             ) VALUES (
                :chat_id,
                :user_id,
                :status,
                :is_admin,
                :permissions_json,
                :checked_at,
                :updated_at
             )
             ON CONFLICT(chat_id, user_id)
             DO UPDATE SET
                status = excluded.status,
                is_admin = excluded.is_admin,
                permissions_json =
                    excluded.permissions_json,
                checked_at =
                    excluded.checked_at,
                updated_at =
                    excluded.updated_at'
        );

        $statement->execute([
            'chat_id' => $chatId,
            'user_id' => $userId,
            'status' => $status,
            'is_admin' => in_array(
                $status,
                ['creator', 'administrator'],
                true
            ) ? 1 : 0,
            'permissions_json' => $json,
            'checked_at' => time(),
            'updated_at' => date(DATE_ATOM),
        ]);
    }
}
