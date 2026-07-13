<?php

declare(strict_types=1);

namespace SmartToolbox\Modules\Profile;

use JsonException;
use PDO;
use RuntimeException;

final class ProfileRepository
{
    public function __construct(
        private readonly PDO $pdo
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function addFavorite(
        int $userId,
        string $type,
        string $commandText,
        string $label,
        array $payload = []
    ): int {
        $type = $this->normalizeType($type);
        $commandText = trim($commandText);
        $label = trim($label);

        if ($userId <= 0 || $commandText === '' || $label === '') {
            throw new RuntimeException('Favorite data is invalid.');
        }

        try {
            $payloadJson = json_encode(
                $payload,
                JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
            );
        } catch (JsonException $exception) {
            throw new RuntimeException(
                'Favorite payload could not be encoded.',
                previous: $exception
            );
        }

        $now = date(DATE_ATOM);
        $statement = $this->pdo->prepare(
            'INSERT INTO user_favorites (
                user_id,
                favorite_type,
                command_text,
                label,
                payload_json,
                is_pinned,
                sort_order,
                created_at,
                updated_at
             ) VALUES (
                :user_id,
                :favorite_type,
                :command_text,
                :label,
                :payload_json,
                0,
                0,
                :created_at,
                :updated_at
             )
             ON CONFLICT(
                user_id,
                favorite_type,
                command_text
             ) DO UPDATE SET
                label = excluded.label,
                payload_json = excluded.payload_json,
                updated_at = excluded.updated_at'
        );

        $statement->execute([
            'user_id' => $userId,
            'favorite_type' => $type,
            'command_text' => mb_substr($commandText, 0, 1000),
            'label' => mb_substr($label, 0, 200),
            'payload_json' => $payloadJson,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $find = $this->pdo->prepare(
            'SELECT id
             FROM user_favorites
             WHERE user_id = :user_id
               AND favorite_type = :favorite_type
               AND command_text = :command_text
             LIMIT 1'
        );

        $find->execute([
            'user_id' => $userId,
            'favorite_type' => $type,
            'command_text' => mb_substr($commandText, 0, 1000),
        ]);

        return (int) $find->fetchColumn();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function favorites(
        int $userId,
        int $limit = 30
    ): array {
        $statement = $this->pdo->prepare(
            'SELECT
                id,
                favorite_type,
                command_text,
                label,
                payload_json,
                is_pinned,
                sort_order,
                created_at,
                updated_at
             FROM user_favorites
             WHERE user_id = :user_id
             ORDER BY
                is_pinned DESC,
                sort_order ASC,
                id DESC
             LIMIT :limit'
        );

        $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $statement->bindValue(
            ':limit',
            max(1, min(100, $limit)),
            PDO::PARAM_INT
        );
        $statement->execute();

        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function favorite(
        int $userId,
        int $favoriteId
    ): ?array {
        $statement = $this->pdo->prepare(
            'SELECT *
             FROM user_favorites
             WHERE id = :id
               AND user_id = :user_id
             LIMIT 1'
        );

        $statement->execute([
            'id' => $favoriteId,
            'user_id' => $userId,
        ]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public function setFavoritePinned(
        int $userId,
        int $favoriteId,
        bool $pinned
    ): bool {
        $statement = $this->pdo->prepare(
            'UPDATE user_favorites
             SET
                is_pinned = :is_pinned,
                updated_at = :updated_at
             WHERE id = :id
               AND user_id = :user_id'
        );

        $statement->execute([
            'is_pinned' => $pinned ? 1 : 0,
            'updated_at' => date(DATE_ATOM),
            'id' => $favoriteId,
            'user_id' => $userId,
        ]);

        return $statement->rowCount() === 1;
    }

    public function deleteFavorite(
        int $userId,
        int $favoriteId
    ): bool {
        $statement = $this->pdo->prepare(
            'DELETE FROM user_favorites
             WHERE id = :id
               AND user_id = :user_id'
        );

        $statement->execute([
            'id' => $favoriteId,
            'user_id' => $userId,
        ]);

        return $statement->rowCount() === 1;
    }

    public function saveShortcut(
        int $userId,
        string $name,
        string $commandText
    ): int {
        $name = $this->normalizeShortcutName($name);
        $commandText = trim($commandText);

        if ($userId <= 0 || $commandText === '') {
            throw new RuntimeException('Shortcut data is invalid.');
        }

        $now = date(DATE_ATOM);
        $statement = $this->pdo->prepare(
            'INSERT INTO user_shortcuts (
                user_id,
                shortcut_name,
                command_text,
                created_at,
                updated_at
             ) VALUES (
                :user_id,
                :shortcut_name,
                :command_text,
                :created_at,
                :updated_at
             )
             ON CONFLICT(user_id, shortcut_name)
             DO UPDATE SET
                command_text = excluded.command_text,
                updated_at = excluded.updated_at'
        );

        $statement->execute([
            'user_id' => $userId,
            'shortcut_name' => $name,
            'command_text' => mb_substr($commandText, 0, 1000),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $find = $this->pdo->prepare(
            'SELECT id
             FROM user_shortcuts
             WHERE user_id = :user_id
               AND shortcut_name = :shortcut_name
             LIMIT 1'
        );

        $find->execute([
            'user_id' => $userId,
            'shortcut_name' => $name,
        ]);

        return (int) $find->fetchColumn();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function shortcut(
        int $userId,
        string $name
    ): ?array {
        $name = $this->normalizeShortcutName($name);

        $statement = $this->pdo->prepare(
            'SELECT
                id,
                shortcut_name,
                command_text,
                created_at,
                updated_at
             FROM user_shortcuts
             WHERE user_id = :user_id
               AND shortcut_name = :shortcut_name
             LIMIT 1'
        );

        $statement->execute([
            'user_id' => $userId,
            'shortcut_name' => $name,
        ]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function shortcuts(
        int $userId,
        int $limit = 50
    ): array {
        $statement = $this->pdo->prepare(
            'SELECT
                id,
                shortcut_name,
                command_text,
                created_at,
                updated_at
             FROM user_shortcuts
             WHERE user_id = :user_id
             ORDER BY shortcut_name ASC
             LIMIT :limit'
        );

        $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $statement->bindValue(
            ':limit',
            max(1, min(200, $limit)),
            PDO::PARAM_INT
        );
        $statement->execute();

        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    }

    public function deleteShortcut(
        int $userId,
        string $name
    ): bool {
        $statement = $this->pdo->prepare(
            'DELETE FROM user_shortcuts
             WHERE user_id = :user_id
               AND shortcut_name = :shortcut_name'
        );

        $statement->execute([
            'user_id' => $userId,
            'shortcut_name' => $this->normalizeShortcutName($name),
        ]);

        return $statement->rowCount() === 1;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function history(
        int $userId,
        int $limit = 20
    ): array {
        $statement = $this->pdo->prepare(
            'SELECT
                id,
                module,
                command,
                source,
                arguments_preview,
                success,
                duration_ms,
                created_at
             FROM command_history
             WHERE user_id = :user_id
             ORDER BY id DESC
             LIMIT :limit'
        );

        $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $statement->bindValue(
            ':limit',
            max(1, min(100, $limit)),
            PDO::PARAM_INT
        );
        $statement->execute();

        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    }

    public function clearHistory(int $userId): int
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM command_history
             WHERE user_id = :user_id'
        );

        $statement->execute(['user_id' => $userId]);

        return $statement->rowCount();
    }

    /**
     * @return array<string, int|string|null>
     */
    public function profile(int $userId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT
                telegram_id,
                first_name,
                last_name,
                username,
                language_code,
                first_seen_at,
                last_seen_at,
                request_count
             FROM users
             WHERE telegram_id = :user_id
             LIMIT 1'
        );

        $statement->execute(['user_id' => $userId]);
        $user = $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($user)) {
            throw new RuntimeException('User profile was not found.');
        }

        return $user + [
            'favorites_count' => $this->count(
                'SELECT COUNT(*) FROM user_favorites WHERE user_id = :user_id',
                ['user_id' => $userId]
            ),
            'shortcuts_count' => $this->count(
                'SELECT COUNT(*) FROM user_shortcuts WHERE user_id = :user_id',
                ['user_id' => $userId]
            ),
            'history_count' => $this->count(
                'SELECT COUNT(*) FROM command_history WHERE user_id = :user_id',
                ['user_id' => $userId]
            ),
            'reminders_count' => $this->tableExists('reminders')
                ? $this->count(
                    "SELECT COUNT(*) FROM reminders WHERE user_id = :user_id AND status IN ('pending', 'processing')",
                    ['user_id' => $userId]
                )
                : 0,
        ];
    }

    private function normalizeType(string $type): string
    {
        $type = mb_strtolower(trim($type));

        if (preg_match('/^[a-z][a-z0-9_]{1,30}$/', $type) !== 1) {
            throw new RuntimeException('Favorite type is invalid.');
        }

        return $type;
    }

    private function normalizeShortcutName(string $name): string
    {
        $name = mb_strtolower(ltrim(trim($name), '/'));

        if (preg_match('/^[a-z][a-z0-9_]{2,31}$/', $name) !== 1) {
            throw new RuntimeException(
                'Shortcut name must contain 3-32 English letters, digits, or underscores.'
            );
        }

        return $name;
    }

    /**
     * @param array<string, int|string> $parameters
     */
    private function count(
        string $sql,
        array $parameters = []
    ): int {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);

        return (int) $statement->fetchColumn();
    }

    private function tableExists(string $table): bool
    {
        $statement = $this->pdo->prepare(
            "SELECT COUNT(*)
             FROM sqlite_master
             WHERE type = 'table'
               AND name = :name"
        );
        $statement->execute(['name' => $table]);

        return (int) $statement->fetchColumn() > 0;
    }
}
