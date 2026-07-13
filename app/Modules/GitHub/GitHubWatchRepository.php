<?php

declare(strict_types=1);

namespace SmartToolbox\Modules\GitHub;

use PDO;
use RuntimeException;

final class GitHubWatchRepository
{
    public function __construct(
        private readonly PDO $pdo
    ) {
    }

    public function watch(
        int $userId,
        int $chatId,
        string $owner,
        string $repository,
        ?int $releaseId,
        ?string $tagName
    ): int {
        if ($userId <= 0 || $chatId === 0) {
            throw new RuntimeException('Watch owner or chat is invalid.');
        }

        $now = date(DATE_ATOM);
        $statement = $this->pdo->prepare(
            'INSERT INTO github_release_watches (
                user_id,
                chat_id,
                owner,
                repository,
                last_release_id,
                last_tag_name,
                last_checked_at,
                status,
                created_at,
                updated_at
             ) VALUES (
                :user_id,
                :chat_id,
                :owner,
                :repository,
                :last_release_id,
                :last_tag_name,
                :last_checked_at,
                :status,
                :created_at,
                :updated_at
             )
             ON CONFLICT(user_id, owner, repository)
             DO UPDATE SET
                chat_id = excluded.chat_id,
                status = excluded.status,
                updated_at = excluded.updated_at'
        );

        $statement->execute([
            'user_id' => $userId,
            'chat_id' => $chatId,
            'owner' => $owner,
            'repository' => $repository,
            'last_release_id' => $releaseId,
            'last_tag_name' => $tagName,
            'last_checked_at' => $now,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $find = $this->pdo->prepare(
            'SELECT id
             FROM github_release_watches
             WHERE user_id = :user_id
               AND owner = :owner
               AND repository = :repository
             LIMIT 1'
        );
        $find->execute([
            'user_id' => $userId,
            'owner' => $owner,
            'repository' => $repository,
        ]);

        return (int) $find->fetchColumn();
    }

    public function unwatch(
        int $userId,
        string $owner,
        string $repository
    ): bool {
        $statement = $this->pdo->prepare(
            'DELETE FROM github_release_watches
             WHERE user_id = :user_id
               AND owner = :owner
               AND repository = :repository'
        );
        $statement->execute([
            'user_id' => $userId,
            'owner' => $owner,
            'repository' => $repository,
        ]);

        return $statement->rowCount() === 1;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forUser(
        int $userId,
        int $limit = 50
    ): array {
        $statement = $this->pdo->prepare(
            'SELECT *
             FROM github_release_watches
             WHERE user_id = :user_id
             ORDER BY id DESC
             LIMIT :limit'
        );
        $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $statement->bindValue(':limit', max(1, min(200, $limit)), PDO::PARAM_INT);
        $statement->execute();
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function active(int $limit = 50): array
    {
        $statement = $this->pdo->prepare(
            "SELECT *
             FROM github_release_watches
             WHERE status = 'active'
             ORDER BY
                COALESCE(last_checked_at, created_at) ASC,
                id ASC
             LIMIT :limit"
        );
        $statement->bindValue(':limit', max(1, min(500, $limit)), PDO::PARAM_INT);
        $statement->execute();
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    }

    public function checked(
        int $id,
        ?int $releaseId,
        ?string $tagName,
        bool $notified
    ): void {
        $now = date(DATE_ATOM);
        $statement = $this->pdo->prepare(
            'UPDATE github_release_watches
             SET
                last_release_id = :last_release_id,
                last_tag_name = :last_tag_name,
                last_checked_at = :last_checked_at,
                last_notified_at = CASE
                    WHEN :notified = 1
                    THEN :last_notified_at
                    ELSE last_notified_at
                END,
                updated_at = :updated_at
             WHERE id = :id'
        );
        $statement->execute([
            'last_release_id' => $releaseId,
            'last_tag_name' => $tagName,
            'last_checked_at' => $now,
            'notified' => $notified ? 1 : 0,
            'last_notified_at' => $now,
            'updated_at' => $now,
            'id' => $id,
        ]);
    }
}
