<?php

declare(strict_types=1);

namespace SmartToolbox\Modules\GroupManagement;

use RuntimeException;
use SmartToolbox\Core\TelegramClient;

final class GroupModerationService
{
    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly GroupRepository $repository
    ) {
    }

    public function mute(
        int $chatId,
        int $userId,
        ?int $untilAt,
        ?int $adminId,
        ?string $reason,
        string $sanctionType = 'mute'
    ): int {
        $parameters = [
            'chat_id' => $chatId,
            'user_id' => $userId,
            'permissions' =>
                $this->restrictedPermissions(),
            'use_independent_chat_permissions' =>
                true,
        ];

        if ($untilAt !== null) {
            $parameters['until_date'] = $untilAt;
        }

        $this->telegram->call(
            'restrictChatMember',
            $parameters
        );

        return $this->repository->addSanction(
            $chatId,
            $userId,
            $adminId,
            $sanctionType,
            $untilAt,
            $reason
        );
    }

    public function unmute(
        int $chatId,
        int $userId
    ): void {
        $chat = $this->telegram->call(
            'getChat',
            ['chat_id' => $chatId]
        );

        $permissions = is_array($chat)
            && is_array(
                $chat['permissions'] ?? null
            )
                ? $chat['permissions']
                : $this->safeMemberPermissions();

        $this->telegram->call(
            'restrictChatMember',
            [
                'chat_id' => $chatId,
                'user_id' => $userId,
                'permissions' => $permissions,
                'use_independent_chat_permissions' =>
                    true,
            ]
        );

        $this->repository
            ->revokeActiveSanctions(
                $chatId,
                $userId,
                [
                    'mute',
                    'captcha',
                    'warning_action',
                ]
            );
    }

    public function ban(
        int $chatId,
        int $userId,
        ?int $untilAt,
        ?int $adminId,
        ?string $reason,
        string $sanctionType = 'ban'
    ): int {
        $parameters = [
            'chat_id' => $chatId,
            'user_id' => $userId,
            'revoke_messages' => true,
        ];

        if ($untilAt !== null) {
            $parameters['until_date'] = $untilAt;
        }

        $this->telegram->call(
            'banChatMember',
            $parameters
        );

        return $this->repository->addSanction(
            $chatId,
            $userId,
            $adminId,
            $sanctionType,
            $untilAt,
            $reason
        );
    }

    public function unban(
        int $chatId,
        int $userId
    ): void {
        $this->telegram->call(
            'unbanChatMember',
            [
                'chat_id' => $chatId,
                'user_id' => $userId,
                'only_if_banned' => true,
            ]
        );

        $this->repository
            ->revokeActiveSanctions(
                $chatId,
                $userId,
                [
                    'ban',
                    'warning_action',
                ]
            );
    }

    public function kick(
        int $chatId,
        int $userId,
        ?int $adminId,
        ?string $reason
    ): int {
        $this->telegram->call(
            'banChatMember',
            [
                'chat_id' => $chatId,
                'user_id' => $userId,
                'revoke_messages' => true,
            ]
        );

        $this->telegram->call(
            'unbanChatMember',
            [
                'chat_id' => $chatId,
                'user_id' => $userId,
                'only_if_banned' => true,
            ]
        );

        $sanctionId =
            $this->repository->addSanction(
                $chatId,
                $userId,
                $adminId,
                'kick',
                time(),
                $reason
            );

        $this->repository->completeSanction(
            $sanctionId,
            true
        );

        return $sanctionId;
    }

    /**
     * @param list<int> $messageIds
     */
    public function deleteMessages(
        int $chatId,
        array $messageIds
    ): void {
        $messageIds = array_values(
            array_unique(
                array_filter(
                    $messageIds,
                    static fn (mixed $id): bool =>
                        is_int($id)
                        && $id > 0
                )
            )
        );

        if ($messageIds === []) {
            return;
        }

        foreach (
            array_chunk($messageIds, 100)
            as $chunk
        ) {
            $this->telegram->call(
                'deleteMessages',
                [
                    'chat_id' => $chatId,
                    'message_ids' => $chunk,
                ]
            );
        }
    }

    public function deleteMessage(
        int $chatId,
        int $messageId
    ): void {
        $this->telegram->call(
            'deleteMessage',
            [
                'chat_id' => $chatId,
                'message_id' => $messageId,
            ]
        );
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function createInviteLink(
        int $chatId,
        array $options
    ): array {
        $result = $this->telegram->call(
            'createChatInviteLink',
            [
                'chat_id' => $chatId,
            ] + $options
        );

        if (!is_array($result)) {
            throw new RuntimeException(
                'Telegram invite link response is invalid.'
            );
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function revokeInviteLink(
        int $chatId,
        string $inviteLink
    ): array {
        $result = $this->telegram->call(
            'revokeChatInviteLink',
            [
                'chat_id' => $chatId,
                'invite_link' => $inviteLink,
            ]
        );

        if (!is_array($result)) {
            throw new RuntimeException(
                'Telegram revoke response is invalid.'
            );
        }

        return $result;
    }

    public function approveJoinRequest(
        int $chatId,
        int $userId
    ): void {
        $this->telegram->call(
            'approveChatJoinRequest',
            [
                'chat_id' => $chatId,
                'user_id' => $userId,
            ]
        );
    }

    public function declineJoinRequest(
        int $chatId,
        int $userId
    ): void {
        $this->telegram->call(
            'declineChatJoinRequest',
            [
                'chat_id' => $chatId,
                'user_id' => $userId,
            ]
        );
    }

    /**
     * @return array<string, bool>
     */
    private function restrictedPermissions(): array
    {
        return [
            'can_send_messages' => false,
            'can_send_audios' => false,
            'can_send_documents' => false,
            'can_send_photos' => false,
            'can_send_videos' => false,
            'can_send_video_notes' => false,
            'can_send_voice_notes' => false,
            'can_send_polls' => false,
            'can_send_other_messages' => false,
            'can_add_web_page_previews' => false,
            'can_react_to_messages' => false,
            'can_edit_tag' => false,
            'can_change_info' => false,
            'can_invite_users' => false,
            'can_pin_messages' => false,
            'can_manage_topics' => false,
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function safeMemberPermissions(): array
    {
        return [
            'can_send_messages' => true,
            'can_send_audios' => true,
            'can_send_documents' => true,
            'can_send_photos' => true,
            'can_send_videos' => true,
            'can_send_video_notes' => true,
            'can_send_voice_notes' => true,
            'can_send_polls' => true,
            'can_send_other_messages' => true,
            'can_add_web_page_previews' => true,
            'can_react_to_messages' => true,
            'can_edit_tag' => false,
            'can_change_info' => false,
            'can_invite_users' => false,
            'can_pin_messages' => false,
            'can_manage_topics' => false,
        ];
    }
}
