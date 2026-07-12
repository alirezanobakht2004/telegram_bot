<?php

declare(strict_types=1);

namespace SmartToolbox\Web;

use JsonException;
use PDO;
use RuntimeException;
use SmartToolbox\Core\FileCache;
use SmartToolbox\Core\RuntimeSettings;
use SmartToolbox\Core\TelegramClient;
use Throwable;

final class AdminPanelService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly RuntimeSettings $runtime,
        private readonly AdminSettingRegistry $registry,
        private readonly TelegramClient $telegram,
        private readonly string $databasePath,
        private readonly string $cacheDirectory,
        private readonly string $logsDirectory,
        private readonly string $backupsDirectory
    ) {
    }

    /**
     * @return array<string, int|string>
     */
    public function dashboard(): array
    {
        $cache = new FileCache(
            $this->cacheDirectory
        );

        $cacheStats = $cache->stats();

        return [
            'users_total' => $this->count(
                'SELECT COUNT(*) FROM users'
            ),
            'users_active_24h' => $this->count(
                "SELECT COUNT(*)
                 FROM users
                 WHERE julianday(last_seen_at)
                    >= julianday('now', '-1 day')"
            ),
            'users_active_7d' => $this->count(
                "SELECT COUNT(*)
                 FROM users
                 WHERE julianday(last_seen_at)
                    >= julianday('now', '-7 days')"
            ),
            'users_new_24h' => $this->count(
                "SELECT COUNT(*)
                 FROM users
                 WHERE julianday(first_seen_at)
                    >= julianday('now', '-1 day')"
            ),
            'users_blocked' => $this->count(
                'SELECT COUNT(*)
                 FROM users
                 WHERE is_blocked = 1'
            ),
            'requests_total' => $this->count(
                'SELECT COALESCE(
                    SUM(request_count),
                    0
                 )
                 FROM users'
            ),
            'chats_private' => $this->count(
                "SELECT COUNT(*)
                 FROM chats
                 WHERE type = 'private'"
            ),
            'chats_groups' => $this->count(
                "SELECT COUNT(*)
                 FROM chats
                 WHERE type IN (
                    'group',
                    'supergroup'
                 )"
            ),
            'chats_active' => $this->count(
                'SELECT COUNT(*)
                 FROM chats
                 WHERE is_active = 1
                   AND admin_blocked = 0'
            ),
            'chats_blocked' => $this->count(
                'SELECT COUNT(*)
                 FROM chats
                 WHERE admin_blocked = 1'
            ),
            'updates_total' => $this->count(
                'SELECT COUNT(*)
                 FROM processed_updates'
            ),
            'updates_completed' => $this->count(
                "SELECT COUNT(*)
                 FROM processed_updates
                 WHERE status = 'completed'"
            ),
            'updates_failed' => $this->count(
                "SELECT COUNT(*)
                 FROM processed_updates
                 WHERE status = 'failed'"
            ),
            'updates_today' => $this->count(
                "SELECT COUNT(*)
                 FROM processed_updates
                 WHERE julianday(received_at)
                    >= julianday(
                        'now',
                        'start of day'
                    )"
            ),
            'preferences' => $this->count(
                'SELECT COUNT(*)
                 FROM user_preferences'
            ),
            'active_states' => $this->count(
                'SELECT COUNT(*)
                 FROM conversation_states
                 WHERE expires_at > :now',
                ['now' => time()]
            ),
            'active_rate_limits' => $this->count(
                'SELECT COUNT(*)
                 FROM rate_limits
                 WHERE expires_at > :now',
                ['now' => time()]
            ),
            'runtime_overrides' => count(
                $this->runtime->allOverrides()
            ),
            'broadcasts_total' => $this->count(
                'SELECT COUNT(*)
                 FROM admin_broadcasts'
            ),
            'broadcasts_active' => $this->count(
                "SELECT COUNT(*)
                 FROM admin_broadcasts
                 WHERE status IN (
                    'pending',
                    'running'
                 )"
            ),
            'broadcast_sent' => $this->count(
                'SELECT COALESCE(
                    SUM(sent_count),
                    0
                 )
                 FROM admin_broadcasts'
            ),
            'broadcast_failed' => $this->count(
                'SELECT COALESCE(
                    SUM(failed_count),
                    0
                 )
                 FROM admin_broadcasts'
            ),
            'cache_files' =>
                $cacheStats['files'],
            'cache_bytes' =>
                $cacheStats['bytes'],
            'database_bytes' =>
                $this->fileSize(
                    $this->databasePath
                ),
            'disk_free_bytes' =>
                $this->diskFree(),
            'php_version' => PHP_VERSION,
            'server_time' => date(
                DATE_ATOM
            ),
        ];
    }

    /**
     * @return list<array{
     *     date: string,
     *     users: int,
     *     updates: int
     * }>
     */
    public function activity(
        int $days = 14
    ): array {
        $days = max(7, min(60, $days));
        $start = date(
            'Y-m-d',
            strtotime(
                '-' . ($days - 1)
                . ' days'
            )
        );

        $users = $this->dailyMap(
            'users',
            'first_seen_at',
            $start
        );

        $updates = $this->dailyMap(
            'processed_updates',
            'received_at',
            $start
        );

        $result = [];

        for (
            $offset = 0;
            $offset < $days;
            $offset++
        ) {
            $date = date(
                'Y-m-d',
                strtotime(
                    $start
                    . " +{$offset} days"
                )
            );

            $result[] = [
                'date' => $date,
                'users' =>
                    $users[$date] ?? 0,
                'updates' =>
                    $updates[$date] ?? 0,
            ];
        }

        return $result;
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    public function settings(): array
    {
        $groups = [];

        foreach (
            $this->registry->definitions()
            as $key => $definition
        ) {
            $groups[
                $definition['group']
            ][] = [
                'key' => $key,
                'label' =>
                    $definition['label'],
                'type' =>
                    $definition['type'],
                'help' =>
                    $definition['help'],
                'min' =>
                    $definition['min']
                    ?? null,
                'max' =>
                    $definition['max']
                    ?? null,
                'base' =>
                    $this->runtime->base($key),
                'effective' =>
                    $this->runtime->get($key),
                'overridden' =>
                    $this->runtime
                        ->hasOverride($key),
            ];
        }

        return $groups;
    }

    public function saveSetting(
        string $key,
        mixed $rawValue,
        string $identity,
        string $ip,
        string $userAgent
    ): void {
        $value = $this->registry->validate(
            $key,
            $rawValue
        );
        $old = $this->runtime->get($key);

        $this->runtime->set(
            $key,
            $value,
            $identity
        );

        $this->audit(
            $identity,
            'setting.update',
            $key,
            [
                'old' => $old,
                'new' => $value,
            ],
            $ip,
            $userAgent
        );
    }

    public function resetSetting(
        string $key,
        string $identity,
        string $ip,
        string $userAgent
    ): bool {
        if (
            !array_key_exists(
                $key,
                $this->registry->definitions()
            )
        ) {
            throw new RuntimeException(
                'Unknown editable setting.'
            );
        }

        $old = $this->runtime->override(
            $key
        );
        $deleted =
            $this->runtime->delete($key);

        if ($deleted) {
            $this->audit(
                $identity,
                'setting.reset',
                $key,
                [
                    'old_override' => $old,
                    'base' =>
                        $this->runtime
                            ->base($key),
                ],
                $ip,
                $userAgent
            );
        }

        return $deleted;
    }

    public function resetAllSettings(
        string $identity,
        string $ip,
        string $userAgent
    ): int {
        $keys = array_keys(
            $this->runtime->allOverrides()
        );
        $deleted = $this->runtime->clear();

        $this->audit(
            $identity,
            'setting.reset_all',
            'runtime_settings',
            [
                'deleted' => $deleted,
                'keys' => $keys,
            ],
            $ip,
            $userAgent
        );

        return $deleted;
    }

    /**
     * @return array<string, array{
     *     label: string,
     *     prefix: ?string,
     *     files: int,
     *     bytes: int,
     *     expired: int,
     *     unidentified: int
     * }>
     */
    public function cacheStats(): array
    {
        $cache = new FileCache(
            $this->cacheDirectory
        );
        $result = [];

        foreach (
            $this->cacheScopes()
            as $scope => $definition
        ) {
            $stats = $cache->stats(
                $definition['prefix']
            );

            $result[$scope] = [
                ...$definition,
                ...$stats,
            ];
        }

        return $result;
    }

    public function clearCache(
        string $scope,
        string $identity,
        string $ip,
        string $userAgent
    ): int {
        $scopes = $this->cacheScopes();

        if (!isset($scopes[$scope])) {
            throw new RuntimeException(
                'Unknown cache scope.'
            );
        }

        $cache = new FileCache(
            $this->cacheDirectory
        );
        $deleted = $cache->clear(
            $scopes[$scope]['prefix']
        );

        $this->audit(
            $identity,
            'cache.clear',
            $scope,
            [
                'deleted' => $deleted,
            ],
            $ip,
            $userAgent
        );

        return $deleted;
    }

    public function pruneCache(
        string $identity,
        string $ip,
        string $userAgent
    ): int {
        $cache = new FileCache(
            $this->cacheDirectory
        );
        $deleted = $cache->prune();

        $this->audit(
            $identity,
            'cache.prune',
            'api-cache',
            [
                'deleted' => $deleted,
            ],
            $ip,
            $userAgent
        );

        return $deleted;
    }

    /**
     * @return array<string, array{
     *     label: string,
     *     prefix: ?string
     * }>
     */
    public function cacheScopes(): array
    {
        return [
            'all' => [
                'label' => 'کل کش',
                'prefix' => null,
            ],
            'animals' => [
                'label' => 'حیوانات',
                'prefix' => 'animals.',
            ],
            'weather' => [
                'label' => 'آب‌وهوا',
                'prefix' => 'weather.',
            ],
            'currency' => [
                'label' => 'ارز',
                'prefix' => 'currency.',
            ],
            'countries' => [
                'label' => 'کشورها',
                'prefix' => 'countries.',
            ],
        ];
    }

    /**
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     total: int,
     *     page: int,
     *     pages: int
     * }
     */
    public function users(
        string $search,
        int $page,
        int $perPage = 20
    ): array {
        return $this->pagedEntities(
            table: 'users',
            fields: [
                'telegram_id',
                'first_name',
                'last_name',
                'username',
                'language_code',
                'is_premium',
                'first_seen_at',
                'last_seen_at',
                'last_chat_id',
                'request_count',
                'is_blocked',
            ],
            searchFields: [
                'first_name',
                'last_name',
                'username',
            ],
            search: $search,
            page: $page,
            perPage: $perPage
        );
    }

    public function setUserBlocked(
        int $telegramId,
        bool $blocked,
        string $identity,
        string $ip,
        string $userAgent
    ): void {
        $this->updateEntityFlag(
            'users',
            'is_blocked',
            $telegramId,
            $blocked
        );

        $this->audit(
            $identity,
            $blocked
                ? 'user.block'
                : 'user.unblock',
            (string) $telegramId,
            [],
            $ip,
            $userAgent
        );
    }

    /**
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     total: int,
     *     page: int,
     *     pages: int
     * }
     */
    public function chats(
        string $search,
        int $page,
        int $perPage = 20
    ): array {
        return $this->pagedEntities(
            table: 'chats',
            fields: [
                'telegram_id',
                'type',
                'title',
                'username',
                'first_name',
                'last_name',
                'first_seen_at',
                'last_seen_at',
                'request_count',
                'is_active',
                'admin_blocked',
            ],
            searchFields: [
                'title',
                'username',
                'first_name',
                'last_name',
            ],
            search: $search,
            page: $page,
            perPage: $perPage
        );
    }

    public function setChatBlocked(
        int $telegramId,
        bool $blocked,
        string $identity,
        string $ip,
        string $userAgent
    ): void {
        $this->updateEntityFlag(
            'chats',
            'admin_blocked',
            $telegramId,
            $blocked
        );

        $this->audit(
            $identity,
            $blocked
                ? 'chat.block'
                : 'chat.unblock',
            (string) $telegramId,
            [],
            $ip,
            $userAgent
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function broadcasts(
        int $limit = 50
    ): array {
        $statement = $this->pdo->prepare(
            'SELECT
                id,
                admin_user_id,
                message_text,
                status,
                total_recipients,
                sent_count,
                failed_count,
                created_at,
                started_at,
                completed_at,
                cancelled_at
             FROM admin_broadcasts
             ORDER BY id DESC
             LIMIT :limit'
        );
        $statement->bindValue(
            ':limit',
            max(1, min(100, $limit)),
            PDO::PARAM_INT
        );
        $statement->execute();

        $rows = $statement->fetchAll(
            PDO::FETCH_ASSOC
        );

        return is_array($rows)
            ? $rows
            : [];
    }

    public function createBroadcast(
        string $message,
        int $adminUserId,
        string $identity,
        string $ip,
        string $userAgent
    ): int {
        $message = trim($message);
        $maximum = max(
            100,
            min(
                3500,
                (int) $this->runtime->get(
                    'modules.admin.max_broadcast_length',
                    3000
                )
            )
        );

        if ($message === '') {
            throw new RuntimeException(
                'متن پیام خالی است.'
            );
        }

        if (mb_strlen($message) > $maximum) {
            throw new RuntimeException(
                "طول پیام بیشتر از {$maximum} کاراکتر است."
            );
        }

        $this->pdo->beginTransaction();

        try {
            $insert = $this->pdo->prepare(
                'INSERT INTO admin_broadcasts (
                    admin_user_id,
                    message_text,
                    status,
                    total_recipients,
                    sent_count,
                    failed_count,
                    created_at
                 ) VALUES (
                    :admin_user_id,
                    :message_text,
                    :status,
                    0,
                    0,
                    0,
                    :created_at
                 )'
            );
            $insert->execute([
                'admin_user_id' =>
                    $adminUserId,
                'message_text' => $message,
                'status' => 'pending',
                'created_at' => date(
                    DATE_ATOM
                ),
            ]);

            $id = (int)
                $this->pdo->lastInsertId();

            $recipients =
                $this->pdo->prepare(
                    "INSERT OR IGNORE INTO
                        admin_broadcast_recipients (
                            broadcast_id,
                            chat_id,
                            status,
                            attempts
                        )
                     SELECT
                        :broadcast_id,
                        telegram_id,
                        'pending',
                        0
                     FROM chats
                     WHERE type = 'private'
                       AND is_active = 1
                       AND admin_blocked = 0
                       AND telegram_id > 0"
                );
            $recipients->execute([
                'broadcast_id' => $id,
            ]);

            $total = $this->count(
                'SELECT COUNT(*)
                 FROM admin_broadcast_recipients
                 WHERE broadcast_id =
                    :broadcast_id',
                ['broadcast_id' => $id]
            );

            $update = $this->pdo->prepare(
                'UPDATE admin_broadcasts
                 SET
                    total_recipients = :total,
                    status = :status,
                    completed_at =
                        :completed_at
                 WHERE id = :broadcast_id'
            );
            $update->execute([
                'total' => $total,
                'status' => $total > 0
                    ? 'pending'
                    : 'completed',
                'completed_at' => $total > 0
                    ? null
                    : date(DATE_ATOM),
                'broadcast_id' => $id,
            ]);

            $this->pdo->commit();

            $this->audit(
                $identity,
                'broadcast.create',
                (string) $id,
                [
                    'recipients' => $total,
                    'length' =>
                        mb_strlen($message),
                ],
                $ip,
                $userAgent
            );

            return $id;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * @return array<string, int|string>
     */
    public function processBroadcast(
        int $broadcastId,
        string $identity,
        string $ip,
        string $userAgent
    ): array {
        $broadcast =
            $this->findBroadcast($broadcastId);

        if ($broadcast === null) {
            throw new RuntimeException(
                'Broadcast پیدا نشد.'
            );
        }

        if (
            in_array(
                $broadcast['status'],
                [
                    'completed',
                    'cancelled',
                ],
                true
            )
        ) {
            return $this->refreshBroadcast(
                $broadcastId,
                0,
                0
            );
        }

        $batchSize = max(
            1,
            min(
                20,
                (int) $this->runtime->get(
                    'modules.admin.broadcast_batch_size',
                    5
                )
            )
        );

        $chatIds = $this->claimRecipients(
            $broadcastId,
            $batchSize
        );

        $sentBatch = 0;
        $failedBatch = 0;

        foreach ($chatIds as $chatId) {
            try {
                $this->telegram->sendMessage(
                    $chatId,
                    "📣 پیام مدیریت\n\n"
                    . $broadcast[
                        'message_text'
                    ]
                );

                $this->markRecipient(
                    $broadcastId,
                    $chatId,
                    'sent',
                    null
                );
                $sentBatch++;
            } catch (Throwable $exception) {
                $this->markRecipient(
                    $broadcastId,
                    $chatId,
                    'failed',
                    $exception->getMessage()
                );
                $this->deactivateOnFailure(
                    $chatId,
                    $exception->getMessage()
                );
                $failedBatch++;
            }
        }

        $summary = $this->refreshBroadcast(
            $broadcastId,
            $sentBatch,
            $failedBatch
        );

        $this->audit(
            $identity,
            'broadcast.process',
            (string) $broadcastId,
            $summary,
            $ip,
            $userAgent
        );

        return $summary;
    }

    public function cancelBroadcast(
        int $broadcastId,
        string $identity,
        string $ip,
        string $userAgent
    ): void {
        $statement = $this->pdo->prepare(
            "UPDATE admin_broadcasts
             SET
                status = 'cancelled',
                cancelled_at =
                    :cancelled_at
             WHERE id = :broadcast_id
               AND status IN (
                    'pending',
                    'running'
               )"
        );
        $statement->execute([
            'cancelled_at' => date(
                DATE_ATOM
            ),
            'broadcast_id' =>
                $broadcastId,
        ]);

        if ($statement->rowCount() === 0) {
            throw new RuntimeException(
                'Broadcast فعال پیدا نشد.'
            );
        }

        $this->audit(
            $identity,
            'broadcast.cancel',
            (string) $broadcastId,
            [],
            $ip,
            $userAgent
        );
    }

    public function retryBroadcast(
        int $broadcastId,
        string $identity,
        string $ip,
        string $userAgent
    ): int {
        $statement = $this->pdo->prepare(
            "UPDATE admin_broadcast_recipients
             SET
                status = 'pending',
                error_message = NULL
             WHERE broadcast_id =
                :broadcast_id
               AND status = 'failed'"
        );
        $statement->execute([
            'broadcast_id' =>
                $broadcastId,
        ]);

        $count = $statement->rowCount();

        if ($count > 0) {
            $update = $this->pdo->prepare(
                "UPDATE admin_broadcasts
                 SET
                    status = 'running',
                    completed_at = NULL,
                    cancelled_at = NULL
                 WHERE id = :broadcast_id"
            );
            $update->execute([
                'broadcast_id' =>
                    $broadcastId,
            ]);
        }

        $this->audit(
            $identity,
            'broadcast.retry',
            (string) $broadcastId,
            ['recipients' => $count],
            $ip,
            $userAgent
        );

        return $count;
    }

    /**
     * @return list<array{
     *     name: string,
     *     bytes: int,
     *     modified_at: string
     * }>
     */
    public function logs(): array
    {
        return $this->fileList(
            $this->logsDirectory,
            '*.log'
        );
    }

    public function readLog(
        string $name,
        int $maxBytes = 100000
    ): string {
        $path = $this->safePath(
            $this->logsDirectory,
            $name,
            '.log'
        );

        if (!is_file($path)) {
            return '';
        }

        $size = filesize($path);

        if (!is_int($size) || $size <= 0) {
            return '';
        }

        $maxBytes = max(
            1000,
            min(500000, $maxBytes)
        );

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException(
                'فایل Log باز نشد.'
            );
        }

        try {
            fseek(
                $handle,
                max(0, $size - $maxBytes)
            );
            $contents =
                stream_get_contents($handle);

            return is_string($contents)
                ? $contents
                : '';
        } finally {
            fclose($handle);
        }
    }

    public function clearLog(
        string $name,
        string $identity,
        string $ip,
        string $userAgent
    ): void {
        $path = $this->safePath(
            $this->logsDirectory,
            $name,
            '.log'
        );

        if (!is_file($path)) {
            throw new RuntimeException(
                'فایل Log پیدا نشد.'
            );
        }

        if (
            file_put_contents(
                $path,
                '',
                LOCK_EX
            ) === false
        ) {
            throw new RuntimeException(
                'فایل Log پاک نشد.'
            );
        }

        $this->audit(
            $identity,
            'log.clear',
            basename($path),
            [],
            $ip,
            $userAgent
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function auditLogs(
        int $limit = 100
    ): array {
        $statement = $this->pdo->prepare(
            'SELECT
                id,
                admin_identity,
                action,
                target,
                details_json,
                ip_address,
                user_agent,
                created_at
             FROM admin_audit_logs
             ORDER BY id DESC
             LIMIT :limit'
        );
        $statement->bindValue(
            ':limit',
            max(1, min(500, $limit)),
            PDO::PARAM_INT
        );
        $statement->execute();

        $rows = $statement->fetchAll(
            PDO::FETCH_ASSOC
        );

        return is_array($rows)
            ? $rows
            : [];
    }

    /**
     * @return array<string, int|string>
     */
    public function system(): array
    {
        $quick = $this->pdo
            ->query('PRAGMA quick_check')
            ?->fetchColumn();
        $journal = $this->pdo
            ->query('PRAGMA journal_mode')
            ?->fetchColumn();
        $pages = $this->pdo
            ->query('PRAGMA page_count')
            ?->fetchColumn();
        $pageSize = $this->pdo
            ->query('PRAGMA page_size')
            ?->fetchColumn();

        return [
            'sqlite_quick_check' =>
                is_string($quick)
                    ? $quick
                    : 'unknown',
            'sqlite_journal_mode' =>
                is_string($journal)
                    ? $journal
                    : 'unknown',
            'sqlite_page_count' =>
                is_numeric($pages)
                    ? (int) $pages
                    : 0,
            'sqlite_page_size' =>
                is_numeric($pageSize)
                    ? (int) $pageSize
                    : 0,
            'database_bytes' =>
                $this->fileSize(
                    $this->databasePath
                ),
            'disk_free_bytes' =>
                $this->diskFree(),
            'disk_total_bytes' =>
                $this->diskTotal(),
            'memory_usage_bytes' =>
                memory_get_usage(true),
            'memory_peak_bytes' =>
                memory_get_peak_usage(true),
            'memory_limit' =>
                (string) ini_get(
                    'memory_limit'
                ),
            'php_version' => PHP_VERSION,
            'php_sapi' => PHP_SAPI,
            'timezone' =>
                date_default_timezone_get(),
            'server_time' =>
                date(DATE_ATOM),
            'expired_states' => $this->count(
                'SELECT COUNT(*)
                 FROM conversation_states
                 WHERE expires_at <= :now',
                ['now' => time()]
            ),
            'expired_rate_limits' =>
                $this->count(
                    'SELECT COUNT(*)
                     FROM rate_limits
                     WHERE expires_at <= :now',
                    ['now' => time()]
                ),
            'login_attempt_rows' =>
                $this->count(
                    'SELECT COUNT(*)
                     FROM admin_login_attempts'
                ),
        ];
    }

    /**
     * @return array<string, int>
     */
    public function cleanupExpired(
        string $identity,
        string $ip,
        string $userAgent
    ): array {
        $now = time();

        $result = [
            'states' => $this->deleteWhere(
                'DELETE FROM conversation_states
                 WHERE expires_at <= :now',
                ['now' => $now]
            ),
            'rate_limits' =>
                $this->deleteWhere(
                    'DELETE FROM rate_limits
                     WHERE expires_at <= :now',
                    ['now' => $now]
                ),
            'login_attempts' =>
                $this->deleteWhere(
                    'DELETE FROM admin_login_attempts
                     WHERE blocked_until < :old
                       AND window_started_at <
                            :old',
                    ['old' => $now - 86400]
                ),
        ];

        $this->audit(
            $identity,
            'system.cleanup',
            'expired-records',
            $result,
            $ip,
            $userAgent
        );

        return $result;
    }

    public function optimizeDatabase(
        string $identity,
        string $ip,
        string $userAgent
    ): void {
        $this->pdo->exec(
            'PRAGMA wal_checkpoint(TRUNCATE)'
        );
        $this->pdo->exec('VACUUM');
        $this->pdo->exec('ANALYZE');

        $this->audit(
            $identity,
            'system.optimize',
            'database',
            [],
            $ip,
            $userAgent
        );
    }

    public function createDatabaseBackup(
        string $identity,
        string $ip,
        string $userAgent
    ): string {
        if (
            !is_dir($this->backupsDirectory)
            && !mkdir(
                $this->backupsDirectory,
                0700,
                true
            )
            && !is_dir(
                $this->backupsDirectory
            )
        ) {
            throw new RuntimeException(
                'پوشه Backup ساخته نشد.'
            );
        }

        $this->pdo->exec(
            'PRAGMA wal_checkpoint(FULL)'
        );

        $name = 'bot-'
            . date('Ymd-His')
            . '-'
            . bin2hex(random_bytes(3))
            . '.sqlite';

        $destination = rtrim(
            $this->backupsDirectory,
            '/\\'
        ) . DIRECTORY_SEPARATOR . $name;

        if (
            !copy(
                $this->databasePath,
                $destination
            )
        ) {
            throw new RuntimeException(
                'Backup دیتابیس ساخته نشد.'
            );
        }

        @chmod($destination, 0600);

        $this->audit(
            $identity,
            'system.backup',
            $name,
            [
                'bytes' =>
                    $this->fileSize(
                        $destination
                    ),
            ],
            $ip,
            $userAgent
        );

        return $name;
    }

    /**
     * @return list<array{
     *     name: string,
     *     bytes: int,
     *     modified_at: string
     * }>
     */
    public function backups(): array
    {
        return $this->fileList(
            $this->backupsDirectory,
            '*.sqlite'
        );
    }

    public function backupPath(
        string $name
    ): string {
        $path = $this->safePath(
            $this->backupsDirectory,
            $name,
            '.sqlite'
        );

        if (!is_file($path)) {
            throw new RuntimeException(
                'Backup پیدا نشد.'
            );
        }

        return $path;
    }

    public function deleteBackup(
        string $name,
        string $identity,
        string $ip,
        string $userAgent
    ): void {
        $path = $this->backupPath($name);

        if (!unlink($path)) {
            throw new RuntimeException(
                'Backup حذف نشد.'
            );
        }

        $this->audit(
            $identity,
            'system.backup_delete',
            basename($path),
            [],
            $ip,
            $userAgent
        );
    }

    /**
     * @param array<string, mixed> $details
     */
    public function audit(
        string $identity,
        string $action,
        string $target,
        array $details,
        string $ip,
        string $userAgent
    ): void {
        try {
            $json = json_encode(
                $details,
                JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
            );
        } catch (JsonException) {
            $json = '{}';
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO admin_audit_logs (
                admin_identity,
                action,
                target,
                details_json,
                ip_address,
                user_agent,
                created_at
            ) VALUES (
                :admin_identity,
                :action,
                :target,
                :details_json,
                :ip_address,
                :user_agent,
                :created_at
            )'
        );

        $statement->execute([
            'admin_identity' => mb_substr(
                $identity,
                0,
                200
            ),
            'action' => mb_substr(
                $action,
                0,
                100
            ),
            'target' => mb_substr(
                $target,
                0,
                300
            ),
            'details_json' => $json,
            'ip_address' => mb_substr(
                $ip,
                0,
                100
            ),
            'user_agent' => mb_substr(
                $userAgent,
                0,
                500
            ),
            'created_at' => date(
                DATE_ATOM
            ),
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function dailyMap(
        string $table,
        string $column,
        string $start
    ): array {
        $allowed = [
            'users' => ['first_seen_at'],
            'processed_updates' => [
                'received_at',
            ],
        ];

        if (
            !isset($allowed[$table])
            || !in_array(
                $column,
                $allowed[$table],
                true
            )
        ) {
            throw new RuntimeException(
                'Invalid daily statistics query.'
            );
        }

        $statement = $this->pdo->prepare(
            "SELECT
                substr({$column}, 1, 10)
                    AS day,
                COUNT(*) AS total
             FROM {$table}
             WHERE substr(
                {$column},
                1,
                10
             ) >= :start
             GROUP BY day"
        );
        $statement->execute([
            'start' => $start,
        ]);

        $result = [];

        while (
            $row = $statement->fetch(
                PDO::FETCH_ASSOC
            )
        ) {
            $day = (string) (
                $row['day'] ?? ''
            );

            if ($day !== '') {
                $result[$day] = (int) (
                    $row['total'] ?? 0
                );
            }
        }

        return $result;
    }

    /**
     * @param list<string> $fields
     * @param list<string> $searchFields
     *
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     total: int,
     *     page: int,
     *     pages: int
     * }
     */
    private function pagedEntities(
        string $table,
        array $fields,
        array $searchFields,
        string $search,
        int $page,
        int $perPage
    ): array {
        if (
            !in_array(
                $table,
                ['users', 'chats'],
                true
            )
        ) {
            throw new RuntimeException(
                'Invalid entity table.'
            );
        }

        $page = max(1, $page);
        $perPage = max(
            5,
            min(100, $perPage)
        );
        $offset =
            ($page - 1) * $perPage;
        $search = trim($search);

        $where = '';
        $parameters = [];

        if ($search !== '') {
            $parts = [
                'CAST(telegram_id AS TEXT) LIKE :search',
            ];

            foreach ($searchFields as $field) {
                $parts[] =
                    "{$field} LIKE :search";
            }

            $where = 'WHERE '
                . implode(' OR ', $parts);
            $parameters['search'] =
                '%' . $search . '%';
        }

        $countStatement =
            $this->pdo->prepare(
                "SELECT COUNT(*)
                 FROM {$table}
                 {$where}"
            );
        $countStatement->execute(
            $parameters
        );
        $total = (int)
            $countStatement->fetchColumn();

        $statement = $this->pdo->prepare(
            'SELECT '
            . implode(', ', $fields)
            . "
             FROM {$table}
             {$where}
             ORDER BY last_seen_at DESC
             LIMIT :limit
             OFFSET :offset"
        );

        foreach ($parameters as $key => $value) {
            $statement->bindValue(
                ':' . $key,
                $value,
                PDO::PARAM_STR
            );
        }

        $statement->bindValue(
            ':limit',
            $perPage,
            PDO::PARAM_INT
        );
        $statement->bindValue(
            ':offset',
            $offset,
            PDO::PARAM_INT
        );
        $statement->execute();

        $rows = $statement->fetchAll(
            PDO::FETCH_ASSOC
        );

        return [
            'rows' => is_array($rows)
                ? $rows
                : [],
            'total' => $total,
            'page' => $page,
            'pages' => max(
                1,
                (int) ceil(
                    $total / $perPage
                )
            ),
        ];
    }

    private function updateEntityFlag(
        string $table,
        string $field,
        int $telegramId,
        bool $value
    ): void {
        $allowed = [
            'users.is_blocked',
            'chats.admin_blocked',
        ];

        if (
            !in_array(
                $table . '.' . $field,
                $allowed,
                true
            )
        ) {
            throw new RuntimeException(
                'Invalid entity flag.'
            );
        }

        $statement = $this->pdo->prepare(
            "UPDATE {$table}
             SET {$field} = :value
             WHERE telegram_id =
                :telegram_id"
        );
        $statement->execute([
            'value' => $value ? 1 : 0,
            'telegram_id' => $telegramId,
        ]);

        if ($statement->rowCount() === 0) {
            throw new RuntimeException(
                'رکورد پیدا نشد یا وضعیت تغییری نکرد.'
            );
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findBroadcast(
        int $broadcastId
    ): ?array {
        $statement = $this->pdo->prepare(
            'SELECT *
             FROM admin_broadcasts
             WHERE id = :id
             LIMIT 1'
        );
        $statement->execute([
            'id' => $broadcastId,
        ]);

        $row = $statement->fetch(
            PDO::FETCH_ASSOC
        );

        return is_array($row)
            ? $row
            : null;
    }

    /**
     * @return list<int>
     */
    private function claimRecipients(
        int $broadcastId,
        int $limit
    ): array {
        $this->pdo->exec(
            'BEGIN IMMEDIATE'
        );

        try {
            $recover = $this->pdo->prepare(
                "UPDATE admin_broadcast_recipients
                 SET status = 'pending'
                 WHERE broadcast_id =
                    :broadcast_id
                   AND status = 'processing'
                   AND (
                        attempted_at IS NULL
                        OR julianday(attempted_at)
                            < julianday(
                                'now',
                                '-10 minutes'
                            )
                   )"
            );
            $recover->execute([
                'broadcast_id' =>
                    $broadcastId,
            ]);

            $select = $this->pdo->prepare(
                "SELECT chat_id
                 FROM admin_broadcast_recipients
                 WHERE broadcast_id =
                    :broadcast_id
                   AND status = 'pending'
                 ORDER BY chat_id ASC
                 LIMIT :limit"
            );
            $select->bindValue(
                ':broadcast_id',
                $broadcastId,
                PDO::PARAM_INT
            );
            $select->bindValue(
                ':limit',
                $limit,
                PDO::PARAM_INT
            );
            $select->execute();

            $rows = $select->fetchAll(
                PDO::FETCH_COLUMN
            );
            $chatIds = [];

            if (is_array($rows)) {
                foreach ($rows as $row) {
                    if (is_numeric($row)) {
                        $chatIds[] =
                            (int) $row;
                    }
                }
            }

            $claim = $this->pdo->prepare(
                "UPDATE admin_broadcast_recipients
                 SET
                    status = 'processing',
                    attempts = attempts + 1,
                    attempted_at =
                        :attempted_at
                 WHERE broadcast_id =
                    :broadcast_id
                   AND chat_id = :chat_id
                   AND status = 'pending'"
            );

            foreach ($chatIds as $chatId) {
                $claim->execute([
                    'attempted_at' =>
                        date(DATE_ATOM),
                    'broadcast_id' =>
                        $broadcastId,
                    'chat_id' => $chatId,
                ]);
            }

            if ($chatIds !== []) {
                $start = $this->pdo->prepare(
                    "UPDATE admin_broadcasts
                     SET
                        status = 'running',
                        started_at = COALESCE(
                            started_at,
                            :started_at
                        )
                     WHERE id = :id"
                );
                $start->execute([
                    'started_at' =>
                        date(DATE_ATOM),
                    'id' => $broadcastId,
                ]);
            }

            $this->pdo->exec('COMMIT');

            return $chatIds;
        } catch (Throwable $exception) {
            try {
                $this->pdo->exec(
                    'ROLLBACK'
                );
            } catch (Throwable) {
            }

            throw $exception;
        }
    }

    private function markRecipient(
        int $broadcastId,
        int $chatId,
        string $status,
        ?string $error
    ): void {
        $statement = $this->pdo->prepare(
            'UPDATE admin_broadcast_recipients
             SET
                status = :status,
                error_message =
                    :error_message,
                attempted_at =
                    :attempted_at
             WHERE broadcast_id =
                    :broadcast_id
               AND chat_id = :chat_id'
        );
        $statement->execute([
            'status' => $status,
            'error_message' => $error !== null
                ? mb_substr(
                    $error,
                    0,
                    1000
                )
                : null,
            'attempted_at' =>
                date(DATE_ATOM),
            'broadcast_id' =>
                $broadcastId,
            'chat_id' => $chatId,
        ]);
    }

    private function deactivateOnFailure(
        int $chatId,
        string $error
    ): void {
        $error = mb_strtolower($error);

        $permanent = str_contains(
            $error,
            'bot was blocked'
        ) || str_contains(
            $error,
            'chat not found'
        ) || str_contains(
            $error,
            'user is deactivated'
        ) || str_contains(
            $error,
            'forbidden'
        );

        if (!$permanent) {
            return;
        }

        $statement = $this->pdo->prepare(
            'UPDATE chats
             SET is_active = 0
             WHERE telegram_id = :chat_id'
        );
        $statement->execute([
            'chat_id' => $chatId,
        ]);
    }

    /**
     * @return array<string, int|string>
     */
    private function refreshBroadcast(
        int $broadcastId,
        int $sentBatch,
        int $failedBatch
    ): array {
        $total = $this->count(
            'SELECT COUNT(*)
             FROM admin_broadcast_recipients
             WHERE broadcast_id = :id',
            ['id' => $broadcastId]
        );
        $sent = $this->count(
            "SELECT COUNT(*)
             FROM admin_broadcast_recipients
             WHERE broadcast_id = :id
               AND status = 'sent'",
            ['id' => $broadcastId]
        );
        $failed = $this->count(
            "SELECT COUNT(*)
             FROM admin_broadcast_recipients
             WHERE broadcast_id = :id
               AND status = 'failed'",
            ['id' => $broadcastId]
        );
        $pending = $this->count(
            "SELECT COUNT(*)
             FROM admin_broadcast_recipients
             WHERE broadcast_id = :id
               AND status IN (
                    'pending',
                    'processing'
               )",
            ['id' => $broadcastId]
        );

        $current = (string) (
            $this->scalar(
                'SELECT status
                 FROM admin_broadcasts
                 WHERE id = :id',
                ['id' => $broadcastId]
            ) ?? ''
        );

        $status = $current === 'cancelled'
            ? 'cancelled'
            : (
                $pending === 0
                    ? 'completed'
                    : 'running'
            );

        $update = $this->pdo->prepare(
            'UPDATE admin_broadcasts
             SET
                total_recipients = :total,
                sent_count = :sent,
                failed_count = :failed,
                status = :status,
                completed_at = CASE
                    WHEN :completed = 1
                    THEN COALESCE(
                        completed_at,
                        :completed_at
                    )
                    ELSE NULL
                END
             WHERE id = :id'
        );
        $update->execute([
            'total' => $total,
            'sent' => $sent,
            'failed' => $failed,
            'status' => $status,
            'completed' =>
                $status === 'completed'
                    ? 1
                    : 0,
            'completed_at' =>
                date(DATE_ATOM),
            'id' => $broadcastId,
        ]);

        return [
            'id' => $broadcastId,
            'sent_batch' => $sentBatch,
            'failed_batch' =>
                $failedBatch,
            'total' => $total,
            'sent' => $sent,
            'failed' => $failed,
            'pending' => $pending,
            'status' => $status,
        ];
    }

    /**
     * @return list<array{
     *     name: string,
     *     bytes: int,
     *     modified_at: string
     * }>
     */
    private function fileList(
        string $directory,
        string $pattern
    ): array {
        $files = glob(
            rtrim($directory, '/\\')
            . DIRECTORY_SEPARATOR
            . $pattern
        );

        if (!is_array($files)) {
            return [];
        }

        $result = [];

        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }

            $result[] = [
                'name' => basename($file),
                'bytes' =>
                    $this->fileSize($file),
                'modified_at' => date(
                    DATE_ATOM,
                    (int) filemtime($file)
                ),
            ];
        }

        usort(
            $result,
            static fn (
                array $left,
                array $right
            ): int => strcmp(
                $right['modified_at'],
                $left['modified_at']
            )
        );

        return $result;
    }

    private function safePath(
        string $directory,
        string $name,
        string $extension
    ): string {
        $name = basename(trim($name));

        if (
            $name === ''
            || !str_ends_with(
                $name,
                $extension
            )
            || preg_match(
                '/^[A-Za-z0-9._-]+$/',
                $name
            ) !== 1
        ) {
            throw new RuntimeException(
                'نام فایل معتبر نیست.'
            );
        }

        return rtrim(
            $directory,
            '/\\'
        ) . DIRECTORY_SEPARATOR . $name;
    }

    /**
     * @param array<string, int|string> $parameters
     */
    private function count(
        string $sql,
        array $parameters = []
    ): int {
        $value = $this->scalar(
            $sql,
            $parameters
        );

        return is_numeric($value)
            ? (int) $value
            : 0;
    }

    /**
     * @param array<string, int|string> $parameters
     */
    private function scalar(
        string $sql,
        array $parameters = []
    ): mixed {
        $statement = $this->pdo->prepare(
            $sql
        );
        $statement->execute($parameters);

        return $statement->fetchColumn();
    }

    /**
     * @param array<string, int|string> $parameters
     */
    private function deleteWhere(
        string $sql,
        array $parameters
    ): int {
        $statement = $this->pdo->prepare(
            $sql
        );
        $statement->execute($parameters);

        return $statement->rowCount();
    }

    private function fileSize(
        string $path
    ): int {
        $size = is_file($path)
            ? filesize($path)
            : false;

        return is_int($size)
            ? $size
            : 0;
    }

    private function diskFree(): int
    {
        $value = @disk_free_space(
            dirname($this->databasePath)
        );

        return is_float($value)
            || is_int($value)
            ? (int) $value
            : 0;
    }

    private function diskTotal(): int
    {
        $value = @disk_total_space(
            dirname($this->databasePath)
        );

        return is_float($value)
            || is_int($value)
            ? (int) $value
            : 0;
    }
}
