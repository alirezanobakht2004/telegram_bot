<?php

declare(strict_types=1);

namespace SmartToolbox\Modules\GroupManagement;

final class GroupTemplateRenderer
{
    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $chat
     */
    public function render(
        string $template,
        array $user,
        array $chat
    ): string {
        $firstName = trim(
            (string) (
                $user['first_name'] ?? ''
            )
        );

        $lastName = trim(
            (string) (
                $user['last_name'] ?? ''
            )
        );

        $username = trim(
            (string) (
                $user['username'] ?? ''
            )
        );

        $userId = $user['id'] ?? '';
        $chatTitle = trim(
            (string) (
                $chat['title'] ?? ''
            )
        );

        return strtr(
            $template,
            [
                '{first_name}' => $firstName,
                '{last_name}' => $lastName,
                '{username}' => $username !== ''
                    ? '@' . $username
                    : 'بدون نام کاربری',
                '{user_id}' => (string) $userId,
                '{chat_title}' => $chatTitle,
                '{full_name}' => trim(
                    $firstName . ' ' . $lastName
                ),
            ]
        );
    }
}
