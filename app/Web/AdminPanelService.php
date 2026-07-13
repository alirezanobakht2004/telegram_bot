<?php

declare(strict_types=1);

namespace SmartToolbox\Web;

use JsonException;
use PDO;
use RuntimeException;
use SmartToolbox\Core\AnalyticsMaintenance;
use SmartToolbox\Core\DeadLetterQueue;
use SmartToolbox\Core\FeatureRegistry;
use SmartToolbox\Core\FileCache;
use SmartToolbox\Core\JobQueue;
use SmartToolbox\Core\RuntimeSettings;
use SmartToolbox\Core\TelegramClient;
use SmartToolbox\Modules\Reminders\ReminderWorker;
use SmartToolbox\Modules\GroupManagement\GroupAuthorization;
use SmartToolbox\Modules\GroupManagement\GroupModerationService;
use SmartToolbox\Modules\GroupManagement\GroupRepository;
use SmartToolbox\Modules\GroupManagement\GroupWorker;
use SmartToolbox\Modules\MiniApp\MiniAppMaintenanceWorker;
use SmartToolbox\Modules\MiniApp\MiniAppSessionRepository;
use SmartToolbox\Modules\Quiz\QuizCsvService;
use SmartToolbox\Modules\Quiz\QuizMaintenanceWorker;
use SmartToolbox\Modules\Quiz\QuizRepository;
use SmartToolbox\Modules\Quiz\QuizScoring;
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
            'reminders_pending' => $this->count(
                "SELECT COUNT(*)
                 FROM reminders
                 WHERE status IN (
                    'pending',
                    'processing'
                 )"
            ),
            'reminders_due' => $this->count(
                "SELECT COUNT(*)
                 FROM reminders
                 WHERE status = 'pending'
                   AND scheduled_at <= :now
                   AND next_attempt_at <= :now",
                ['now' => time()]
            ),
            'reminders_sent' => $this->count(
                "SELECT COUNT(*)
                 FROM reminders
                 WHERE status = 'sent'"
            ),
            'reminders_failed' => $this->count(
                "SELECT COUNT(*)
                 FROM reminders
                 WHERE status = 'failed'"
            ),
            'alerts_active' => $this->count(
                "SELECT COUNT(*)
                 FROM smart_alerts
                 WHERE status = 'active'"
            ),
            'subscriptions_active' => $this->count(
                "SELECT COUNT(*)
                 FROM smart_subscriptions
                 WHERE status = 'active'"
            ),
            'monitors_active' => $this->count(
                "SELECT COUNT(*)
                 FROM site_monitors
                 WHERE status = 'active'"
            ),
            'monitors_down' => $this->count(
                "SELECT COUNT(*)
                 FROM site_monitors
                 WHERE status = 'active'
                   AND last_state = 'down'"
            ),
            'managed_groups' => $this->count(
                'SELECT COUNT(*)
                 FROM group_settings'
            ),
            'group_warnings_active' => $this->count(
                'SELECT COUNT(*)
                 FROM group_warnings
                 WHERE active = 1'
            ),
            'group_sanctions_active' => $this->count(
                "SELECT COUNT(*)
                 FROM group_sanctions
                 WHERE status = 'active'"
            ),
            'group_captchas_pending' => $this->count(
                "SELECT COUNT(*)
                 FROM group_captcha_challenges
                 WHERE status = 'pending'"
            ),
            'group_join_requests_pending' => $this->count(
                "SELECT COUNT(*)
                 FROM group_join_requests
                 WHERE status = 'pending'"
            ),
            'group_automod_today' => $this->count(
                "SELECT COUNT(*)
                 FROM group_audit_logs
                 WHERE action LIKE 'automod.%'
                   AND julianday(created_at)
                    >= julianday('now', 'start of day')"
            ),
            'quiz_questions_enabled' => $this->count(
                'SELECT COUNT(*)
                 FROM quiz_questions
                 WHERE enabled = 1'
            ),
            'quiz_players' => $this->count(
                'SELECT COUNT(*)
                 FROM quiz_user_scores
                 WHERE total_answers > 0'
            ),
            'quiz_answers_today' => $this->count(
                "SELECT COUNT(*)
                 FROM quiz_sessions
                 WHERE status = 'answered'
                   AND date(
                        answered_at,
                        'unixepoch',
                        'localtime'
                   ) = date('now', 'localtime')"
            ),
            'quiz_active_sessions' => $this->count(
                "SELECT COUNT(*)
                 FROM quiz_sessions
                 WHERE status = 'active'
                   AND expires_at >= :quiz_now",
                ['quiz_now' => time()]
            ),
            'mini_app_active_sessions' => $this->count(
                'SELECT COUNT(*)
                 FROM mini_app_sessions
                 WHERE revoked_at IS NULL
                   AND expires_at >= :now
                   AND absolute_expires_at >= :now',
                ['now' => time()]
            ),
            'mini_app_auth_today' => $this->count(
                "SELECT COUNT(*)
                 FROM mini_app_audit_logs
                 WHERE action = 'auth.login'
                   AND success = 1
                   AND occurred_at >= :today",
                [
                    'today' => strtotime('today'),
                ]
            ),
            'mini_app_failures_today' => $this->count(
                'SELECT COUNT(*)
                 FROM mini_app_audit_logs
                 WHERE success = 0
                   AND occurred_at >= :today',
                [
                    'today' => strtotime('today'),
                ]
            ),
            'usage_events_today' => $this->count(
                'SELECT COUNT(*)
                 FROM usage_events
                 WHERE occurred_at >= :today',
                [
                    'today' => strtotime('today'),
                ]
            ),
            'usage_failures_today' => $this->count(
                'SELECT COUNT(*)
                 FROM usage_events
                 WHERE success = 0
                   AND occurred_at >= :today',
                [
                    'today' => strtotime('today'),
                ]
            ),
            'jobs_queued' => $this->count(
                "SELECT COUNT(*)
                 FROM job_queue
                 WHERE status IN (
                    'queued',
                    'processing'
                 )"
            ),
            'dead_letters' => $this->count(
                'SELECT COUNT(*)
                 FROM dead_letter_jobs
                 WHERE replayed_at IS NULL'
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
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     total: int,
     *     page: int,
     *     pages: int
     * }
     */
    public function reminders(
        string $status,
        int $page,
        int $perPage = 30
    ): array {
        $allowedStatuses = [
            'all',
            'pending',
            'processing',
            'sent',
            'failed',
            'cancelled',
        ];

        if (
            !in_array(
                $status,
                $allowedStatuses,
                true
            )
        ) {
            $status = 'all';
        }

        $page = max(1, $page);
        $perPage = max(
            10,
            min(100, $perPage)
        );

        $offset = ($page - 1) * $perPage;
        $where = '';
        $parameters = [];

        if ($status !== 'all') {
            $where =
                'WHERE r.status = :status';

            $parameters['status'] =
                $status;
        }

        $countStatement =
            $this->pdo->prepare(
                "SELECT COUNT(*)
                 FROM reminders AS r
                 {$where}"
            );

        $countStatement->execute(
            $parameters
        );

        $total = (int)
            $countStatement->fetchColumn();

        $statement = $this->pdo->prepare(
            "SELECT
                r.id,
                r.user_id,
                r.chat_id,
                r.reminder_text,
                r.timezone,
                r.scheduled_at,
                r.next_attempt_at,
                r.status,
                r.attempts,
                r.last_error,
                r.created_at,
                r.updated_at,
                r.sent_at,
                r.cancelled_at,
                u.first_name,
                u.last_name,
                u.username
             FROM reminders AS r
             LEFT JOIN users AS u
                ON u.telegram_id = r.user_id
             {$where}
             ORDER BY r.id DESC
             LIMIT :limit
             OFFSET :offset"
        );

        foreach (
            $parameters
            as $key => $value
        ) {
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

    /**
     * @return array<string, mixed>|null
     */
    public function lastReminderWorkerRun(): ?array
    {
        $statement = $this->pdo->query(
            'SELECT
                id,
                status,
                claimed_count,
                sent_count,
                failed_count,
                retried_count,
                pruned_count,
                started_at,
                completed_at,
                error_message
             FROM reminder_worker_runs
             ORDER BY id DESC
             LIMIT 1'
        );

        $row = $statement->fetch(
            PDO::FETCH_ASSOC
        );

        return is_array($row)
            ? $row
            : null;
    }

    /**
     * @return array{
     *     claimed: int,
     *     sent: int,
     *     failed: int,
     *     retried: int,
     *     pruned: int
     * }
     */
    public function processDueReminders(
        string $identity,
        string $ip,
        string $userAgent
    ): array {
        $worker = new ReminderWorker(
            pdo: $this->pdo,
            sender: function (
                int|string $chatId,
                string $text
            ): void {
                $this->telegram->sendMessage(
                    $chatId,
                    $text
                );
            },
            logFile: rtrim(
                $this->logsDirectory,
                '/\\'
            ) . '/reminders.log'
        );

        $result = $worker->run(
            batchSize: (int) $this->runtime->get(
                'modules.reminders.worker.batch_size',
                10
            ),
            maxDeliveryAttempts: (int) $this->runtime->get(
                'modules.reminders.worker.max_delivery_attempts',
                3
            ),
            retryBaseSeconds: (int) $this->runtime->get(
                'modules.reminders.worker.retry_base_seconds',
                60
            ),
            staleLockSeconds: (int) $this->runtime->get(
                'modules.reminders.worker.stale_lock_seconds',
                600
            ),
            retentionDays: (int) $this->runtime->get(
                'modules.reminders.retention_days',
                90
            )
        );

        $this->audit(
            $identity,
            'reminder.worker',
            'due-reminders',
            $result,
            $ip,
            $userAgent
        );

        return $result;
    }

    public function cancelReminder(
        int $reminderId,
        string $identity,
        string $ip,
        string $userAgent
    ): void {
        $statement = $this->pdo->prepare(
            "UPDATE reminders
             SET
                status = 'cancelled',
                cancelled_at = :cancelled_at,
                locked_at = NULL,
                updated_at = :updated_at
             WHERE id = :id
               AND status IN (
                    'pending',
                    'failed'
               )"
        );

        $now = date(DATE_ATOM);

        $statement->execute([
            'cancelled_at' => $now,
            'updated_at' => $now,
            'id' => $reminderId,
        ]);

        if (
            $statement->rowCount()
            === 0
        ) {
            throw new RuntimeException(
                'یادآور قابل لغو پیدا نشد.'
            );
        }

        $this->audit(
            $identity,
            'reminder.cancel',
            (string) $reminderId,
            [],
            $ip,
            $userAgent
        );
    }

    public function retryReminder(
        int $reminderId,
        string $identity,
        string $ip,
        string $userAgent
    ): void {
        $statement = $this->pdo->prepare(
            "UPDATE reminders
             SET
                status = 'pending',
                next_attempt_at =
                    :next_attempt_at,
                last_error = NULL,
                locked_at = NULL,
                updated_at = :updated_at
             WHERE id = :id
               AND status = 'failed'"
        );

        $statement->execute([
            'next_attempt_at' => time(),
            'updated_at' => date(DATE_ATOM),
            'id' => $reminderId,
        ]);

        if (
            $statement->rowCount()
            === 0
        ) {
            throw new RuntimeException(
                'یادآور ناموفق برای Retry پیدا نشد.'
            );
        }

        $this->audit(
            $identity,
            'reminder.retry',
            (string) $reminderId,
            [],
            $ip,
            $userAgent
        );
    }



    /**
     * @return array{
     *     summary:array<string,int>,
     *     sessions:list<array<string,mixed>>,
     *     audit:list<array<string,mixed>>
     * }
     */
    public function miniAppOverview(
        int $limit = 100
    ): array {
        $limit = max(10, min(500, $limit));
        $now = time();

        $sessions = $this->pdo->prepare(
            'SELECT
                s.id,
                s.user_id,
                s.created_at,
                s.last_seen_at,
                s.expires_at,
                s.absolute_expires_at,
                s.revoked_at,
                s.revocation_reason,
                u.first_name,
                u.last_name,
                u.username
             FROM mini_app_sessions AS s
             LEFT JOIN users AS u
                ON u.telegram_id = s.user_id
             ORDER BY s.id DESC
             LIMIT :limit'
        );
        $sessions->bindValue(
            ':limit',
            $limit,
            PDO::PARAM_INT
        );
        $sessions->execute();

        $audit = $this->pdo->prepare(
            'SELECT
                a.id,
                a.user_id,
                a.session_id,
                a.action,
                a.resource_type,
                a.resource_id,
                a.success,
                a.error_code,
                a.details_json,
                a.occurred_at,
                u.first_name,
                u.last_name,
                u.username
             FROM mini_app_audit_logs AS a
             LEFT JOIN users AS u
                ON u.telegram_id = a.user_id
             ORDER BY a.id DESC
             LIMIT :limit'
        );
        $audit->bindValue(
            ':limit',
            $limit,
            PDO::PARAM_INT
        );
        $audit->execute();

        return [
            'summary' => [
                'active_sessions' => $this->count(
                    'SELECT COUNT(*)
                     FROM mini_app_sessions
                     WHERE revoked_at IS NULL
                       AND expires_at >= :now
                       AND absolute_expires_at >= :now',
                    ['now' => $now]
                ),
                'users_with_sessions' => $this->count(
                    'SELECT COUNT(DISTINCT user_id)
                     FROM mini_app_sessions'
                ),
                'auth_today' => $this->count(
                    "SELECT COUNT(*)
                     FROM mini_app_audit_logs
                     WHERE action = 'auth.login'
                       AND success = 1
                       AND occurred_at >= :today",
                    ['today' => strtotime('today')]
                ),
                'requests_today' => $this->count(
                    'SELECT COUNT(*)
                     FROM mini_app_audit_logs
                     WHERE occurred_at >= :today',
                    ['today' => strtotime('today')]
                ),
                'failures_today' => $this->count(
                    'SELECT COUNT(*)
                     FROM mini_app_audit_logs
                     WHERE success = 0
                       AND occurred_at >= :today',
                    ['today' => strtotime('today')]
                ),
                'rate_limit_keys' => $this->count(
                    'SELECT COUNT(*)
                     FROM mini_app_rate_limits
                     WHERE expires_at >= :now',
                    ['now' => $now]
                ),
            ],
            'sessions' => $sessions->fetchAll(
                PDO::FETCH_ASSOC
            ) ?: [],
            'audit' => $audit->fetchAll(
                PDO::FETCH_ASSOC
            ) ?: [],
        ];
    }

    public function revokeMiniAppSession(
        int $sessionId,
        string $identity,
        string $ip,
        string $userAgent
    ): void {
        if ($sessionId <= 0) {
            throw new RuntimeException(
                'شناسه Session معتبر نیست.'
            );
        }

        if (!$this->miniAppSessions()
            ->revokeSession(
                $sessionId,
                'web_admin'
            )) {
            throw new RuntimeException(
                'Session فعال پیدا نشد.'
            );
        }

        $this->audit(
            $identity,
            'mini_app.session_revoke',
            (string) $sessionId,
            [],
            $ip,
            $userAgent
        );
    }

    public function revokeMiniAppUserSessions(
        int $userId,
        string $identity,
        string $ip,
        string $userAgent
    ): int {
        if ($userId <= 0) {
            throw new RuntimeException(
                'شناسه کاربر معتبر نیست.'
            );
        }

        $count = $this->miniAppSessions()
            ->revokeUserSessions(
                $userId,
                'web_admin_user_revoke'
            );

        $this->audit(
            $identity,
            'mini_app.user_sessions_revoke',
            (string) $userId,
            ['count' => $count],
            $ip,
            $userAgent
        );

        return $count;
    }

    /**
     * @return array{sessions:int,rate_limits:int,audit:int}
     */
    public function cleanupMiniApp(
        string $identity,
        string $ip,
        string $userAgent
    ): array {
        $result = (
            new MiniAppMaintenanceWorker(
                $this->miniAppSessions()
            )
        )->run(
            sessionRetentionDays: (int)
                $this->runtime->get(
                    'modules.mini_app.retention_days',
                    30
                ),
            auditRetentionDays: (int)
                $this->runtime->get(
                    'modules.mini_app.audit_retention_days',
                    180
                )
        );

        $this->audit(
            $identity,
            'mini_app.cleanup',
            'maintenance',
            $result,
            $ip,
            $userAgent
        );

        return $result;
    }

    private function miniAppSessions(): MiniAppSessionRepository
    {
        return new MiniAppSessionRepository(
            pdo: $this->pdo,
            idleTtlSeconds: (int)
                $this->runtime->get(
                    'modules.mini_app.security.session_idle_ttl_seconds',
                    1200
                ),
            absoluteTtlSeconds: (int)
                $this->runtime->get(
                    'modules.mini_app.security.session_absolute_ttl_seconds',
                    21600
                ),
            maxActivePerUser: (int)
                $this->runtime->get(
                    'modules.mini_app.security.max_active_sessions_per_user',
                    5
                )
        );
    }

    /**
     * @return array{
     *     summary:array<string,int|float>,
     *     categories:list<array<string,mixed>>,
     *     questions:list<array<string,mixed>>,
     *     difficult:list<array<string,mixed>>,
     *     edit_question:?array<string,mixed>
     * }
     */
    public function quizOverview(
        int $editQuestionId = 0,
        string $categorySlug = '',
        string $difficulty = '',
        int $limit = 100
    ): array {
        $limit = max(10, min(500, $limit));

        $summary = [
            'categories' => $this->count(
                'SELECT COUNT(*)
                 FROM quiz_categories'
            ),
            'categories_enabled' => $this->count(
                'SELECT COUNT(*)
                 FROM quiz_categories
                 WHERE enabled = 1'
            ),
            'questions' => $this->count(
                'SELECT COUNT(*)
                 FROM quiz_questions'
            ),
            'questions_enabled' => $this->count(
                'SELECT COUNT(*)
                 FROM quiz_questions
                 WHERE enabled = 1'
            ),
            'players' => $this->count(
                'SELECT COUNT(*)
                 FROM quiz_user_scores
                 WHERE total_answers > 0'
            ),
            'answers' => $this->count(
                "SELECT COUNT(*)
                 FROM quiz_sessions
                 WHERE status = 'answered'"
            ),
            'correct_answers' => $this->count(
                "SELECT COUNT(*)
                 FROM quiz_sessions
                 WHERE status = 'answered'
                   AND is_correct = 1"
            ),
            'daily_attempts' => $this->count(
                'SELECT COUNT(*)
                 FROM quiz_daily_attempts'
            ),
            'active_sessions' => $this->count(
                "SELECT COUNT(*)
                 FROM quiz_sessions
                 WHERE status = 'active'
                   AND expires_at >= :now",
                ['now' => time()]
            ),
        ];

        $summary['accuracy'] =
            $summary['answers'] > 0
                ? round(
                    $summary['correct_answers']
                    / $summary['answers']
                    * 100,
                    2
                )
                : 0.0;

        $categories = $this->pdo->query(
            'SELECT
                c.*,
                COUNT(q.id) AS question_count,
                COALESCE(
                    SUM(q.times_served),
                    0
                ) AS served_count,
                COALESCE(
                    SUM(q.correct_count),
                    0
                ) AS correct_count,
                COALESCE(
                    SUM(q.incorrect_count),
                    0
                ) AS incorrect_count,
                COALESCE(
                    SUM(q.timeout_count),
                    0
                ) AS timeout_count
             FROM quiz_categories AS c
             LEFT JOIN quiz_questions AS q
                ON q.category_id = c.id
             GROUP BY c.id
             ORDER BY
                c.sort_order ASC,
                c.name ASC'
        )->fetchAll(PDO::FETCH_ASSOC);

        $conditions = ['1 = 1'];
        $parameters = [];

        $categorySlug = trim($categorySlug);

        if ($categorySlug !== '') {
            $conditions[] =
                'LOWER(c.slug) = LOWER(:category_slug)';
            $parameters['category_slug'] =
                $categorySlug;
        }

        if (
            in_array(
                $difficulty,
                ['easy', 'medium', 'hard'],
                true
            )
        ) {
            $conditions[] =
                'q.difficulty = :difficulty';
            $parameters['difficulty'] =
                $difficulty;
        }

        $statement = $this->pdo->prepare(
            'SELECT
                q.*,
                c.slug AS category_slug,
                c.name AS category_name,
                c.enabled AS category_enabled,
                (
                    q.correct_count
                    + q.incorrect_count
                    + q.timeout_count
                ) AS attempts,
                CASE
                    WHEN (
                        q.correct_count
                        + q.incorrect_count
                    ) > 0
                    THEN ROUND(
                        q.correct_count * 100.0
                        / (
                            q.correct_count
                            + q.incorrect_count
                        ),
                        2
                    )
                    ELSE 0
                END AS correct_percent
             FROM quiz_questions AS q
             INNER JOIN quiz_categories AS c
                ON c.id = q.category_id
             WHERE '
            . implode(' AND ', $conditions)
            . ' ORDER BY q.id DESC
                LIMIT :limit'
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
            $limit,
            PDO::PARAM_INT
        );

        $statement->execute();

        $questions = $statement->fetchAll(
            PDO::FETCH_ASSOC
        );

        $questions = is_array($questions)
            ? $questions
            : [];

        $optionStatement = $this->pdo->prepare(
            'SELECT
                id,
                option_text,
                is_correct,
                sort_order
             FROM quiz_question_options
             WHERE question_id = :question_id
             ORDER BY sort_order ASC'
        );

        foreach ($questions as &$question) {
            $optionStatement->execute([
                'question_id' =>
                    (int) $question['id'],
            ]);

            $question['options'] =
                $optionStatement->fetchAll(
                    PDO::FETCH_ASSOC
                ) ?: [];
        }
        unset($question);

        $difficult = $this->pdo->query(
            'SELECT
                q.id,
                q.question_text,
                q.difficulty,
                c.name AS category_name,
                q.times_served,
                q.correct_count,
                q.incorrect_count,
                q.timeout_count,
                (
                    q.correct_count
                    + q.incorrect_count
                    + q.timeout_count
                ) AS attempts,
                CASE
                    WHEN (
                        q.correct_count
                        + q.incorrect_count
                    ) > 0
                    THEN ROUND(
                        q.correct_count * 100.0
                        / (
                            q.correct_count
                            + q.incorrect_count
                        ),
                        2
                    )
                    ELSE 0
                END AS correct_percent
             FROM quiz_questions AS q
             INNER JOIN quiz_categories AS c
                ON c.id = q.category_id
             WHERE (
                q.correct_count
                + q.incorrect_count
                + q.timeout_count
             ) >= 3
             ORDER BY
                correct_percent ASC,
                attempts DESC
             LIMIT 20'
        )->fetchAll(PDO::FETCH_ASSOC);

        $editQuestion = null;

        if ($editQuestionId > 0) {
            $editStatement = $this->pdo->prepare(
                'SELECT
                    q.*,
                    c.slug AS category_slug,
                    c.name AS category_name
                 FROM quiz_questions AS q
                 INNER JOIN quiz_categories AS c
                    ON c.id = q.category_id
                 WHERE q.id = :id
                 LIMIT 1'
            );

            $editStatement->execute([
                'id' => $editQuestionId,
            ]);

            $editQuestion = $editStatement
                ->fetch(PDO::FETCH_ASSOC);

            if (is_array($editQuestion)) {
                $optionStatement->execute([
                    'question_id' =>
                        $editQuestionId,
                ]);

                $editQuestion['options'] =
                    $optionStatement->fetchAll(
                        PDO::FETCH_ASSOC
                    ) ?: [];
            } else {
                $editQuestion = null;
            }
        }

        return [
            'summary' => $summary,
            'categories' => is_array($categories)
                ? $categories
                : [],
            'questions' => $questions,
            'difficult' => is_array($difficult)
                ? $difficult
                : [],
            'edit_question' => $editQuestion,
        ];
    }

    public function saveQuizCategory(
        int $id,
        string $slug,
        string $name,
        string $description,
        bool $enabled,
        int $sortOrder,
        string $identity,
        string $ip,
        string $userAgent
    ): int {
        $slug = mb_strtolower(trim($slug));
        $name = trim($name);

        if (
            preg_match(
                '/^[a-z0-9][a-z0-9_-]{1,49}$/',
                $slug
            ) !== 1
        ) {
            throw new RuntimeException(
                'Slug دسته باید انگلیسی و شامل حروف، عدد، خط تیره یا زیرخط باشد.'
            );
        }

        if (
            $name === ''
            || mb_strlen($name) > 150
        ) {
            throw new RuntimeException(
                'نام دسته معتبر نیست.'
            );
        }

        $now = date(DATE_ATOM);

        if ($id > 0) {
            $statement = $this->pdo->prepare(
                'UPDATE quiz_categories
                 SET
                    slug = :slug,
                    name = :name,
                    description = :description,
                    enabled = :enabled,
                    sort_order = :sort_order,
                    updated_at = :updated_at
                 WHERE id = :id'
            );

            $statement->execute([
                'slug' => $slug,
                'name' => $name,
                'description' =>
                    trim($description) ?: null,
                'enabled' => $enabled ? 1 : 0,
                'sort_order' => $sortOrder,
                'updated_at' => $now,
                'id' => $id,
            ]);

            if ($statement->rowCount() === 0) {
                throw new RuntimeException(
                    'دسته برای ویرایش پیدا نشد.'
                );
            }

            $categoryId = $id;
        } else {
            $statement = $this->pdo->prepare(
                'INSERT INTO quiz_categories (
                    slug,
                    name,
                    description,
                    enabled,
                    sort_order,
                    created_at,
                    updated_at
                 ) VALUES (
                    :slug,
                    :name,
                    :description,
                    :enabled,
                    :sort_order,
                    :created_at,
                    :updated_at
                 )'
            );

            $statement->execute([
                'slug' => $slug,
                'name' => $name,
                'description' =>
                    trim($description) ?: null,
                'enabled' => $enabled ? 1 : 0,
                'sort_order' => $sortOrder,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $categoryId = (int)
                $this->pdo->lastInsertId();
        }

        $this->audit(
            $identity,
            'quiz.category.save',
            (string) $categoryId,
            [
                'slug' => $slug,
                'enabled' => $enabled,
                'sort_order' => $sortOrder,
            ],
            $ip,
            $userAgent
        );

        return $categoryId;
    }

    public function setQuizCategoryEnabled(
        int $categoryId,
        bool $enabled,
        string $identity,
        string $ip,
        string $userAgent
    ): void {
        $statement = $this->pdo->prepare(
            'UPDATE quiz_categories
             SET
                enabled = :enabled,
                updated_at = :updated_at
             WHERE id = :id'
        );

        $statement->execute([
            'enabled' => $enabled ? 1 : 0,
            'updated_at' => date(DATE_ATOM),
            'id' => $categoryId,
        ]);

        if ($statement->rowCount() === 0) {
            throw new RuntimeException(
                'دسته پیدا نشد.'
            );
        }

        $this->audit(
            $identity,
            'quiz.category.toggle',
            (string) $categoryId,
            ['enabled' => $enabled],
            $ip,
            $userAgent
        );
    }

    /**
     * @param list<string> $options
     */
    public function saveQuizQuestion(
        int $id,
        int $categoryId,
        string $questionType,
        string $difficulty,
        string $questionText,
        array $options,
        int $correctOption,
        string $explanation,
        int $points,
        int $xpReward,
        int $timeoutSeconds,
        bool $enabled,
        string $identity,
        string $ip,
        string $userAgent
    ): int {
        if (
            !in_array(
                $questionType,
                ['trivia', 'word'],
                true
            )
        ) {
            throw new RuntimeException(
                'نوع سؤال معتبر نیست.'
            );
        }

        if (
            !in_array(
                $difficulty,
                ['easy', 'medium', 'hard'],
                true
            )
        ) {
            throw new RuntimeException(
                'سختی سؤال معتبر نیست.'
            );
        }

        $questionText = trim($questionText);

        if (
            $questionText === ''
            || mb_strlen($questionText) > 3000
        ) {
            throw new RuntimeException(
                'متن سؤال معتبر نیست.'
            );
        }

        if (count($options) !== 4) {
            throw new RuntimeException(
                'سؤال باید دقیقاً چهار گزینه داشته باشد.'
            );
        }

        $options = array_values(
            array_map(
                static fn (mixed $value): string =>
                    trim((string) $value),
                $options
            )
        );

        foreach ($options as $option) {
            if (
                $option === ''
                || mb_strlen($option) > 500
            ) {
                throw new RuntimeException(
                    'تمام گزینه‌ها باید تکمیل شوند.'
                );
            }
        }

        if (count(array_unique($options)) !== 4) {
            throw new RuntimeException(
                'گزینه‌های سؤال باید متفاوت باشند.'
            );
        }

        if (
            $correctOption < 0
            || $correctOption > 3
        ) {
            throw new RuntimeException(
                'گزینه درست معتبر نیست.'
            );
        }

        if ($points < 1 || $points > 1000) {
            throw new RuntimeException(
                'امتیاز باید بین ۱ و ۱۰۰۰ باشد.'
            );
        }

        if ($xpReward < 1 || $xpReward > 1000) {
            throw new RuntimeException(
                'XP باید بین ۱ و ۱۰۰۰ باشد.'
            );
        }

        if (
            $timeoutSeconds < 5
            || $timeoutSeconds > 300
        ) {
            throw new RuntimeException(
                'زمان پاسخ باید بین ۵ و ۳۰۰ ثانیه باشد.'
            );
        }

        if (
            $this->count(
                'SELECT COUNT(*)
                 FROM quiz_categories
                 WHERE id = :id',
                ['id' => $categoryId]
            ) !== 1
        ) {
            throw new RuntimeException(
                'دسته سؤال پیدا نشد.'
            );
        }

        $this->pdo->exec('BEGIN IMMEDIATE');

        try {
            $now = date(DATE_ATOM);

            if ($id > 0) {
                $statement = $this->pdo->prepare(
                    'UPDATE quiz_questions
                     SET
                        category_id =
                            :category_id,
                        question_type =
                            :question_type,
                        difficulty =
                            :difficulty,
                        question_text =
                            :question_text,
                        explanation =
                            :explanation,
                        points = :points,
                        xp_reward = :xp_reward,
                        answer_timeout_seconds =
                            :timeout,
                        enabled = :enabled,
                        source = :source,
                        created_by =
                            :created_by,
                        updated_at =
                            :updated_at
                     WHERE id = :id'
                );

                $statement->execute([
                    'category_id' =>
                        $categoryId,
                    'question_type' =>
                        $questionType,
                    'difficulty' =>
                        $difficulty,
                    'question_text' =>
                        $questionText,
                    'explanation' =>
                        trim($explanation) ?: null,
                    'points' => $points,
                    'xp_reward' => $xpReward,
                    'timeout' => $timeoutSeconds,
                    'enabled' => $enabled ? 1 : 0,
                    'source' => 'admin',
                    'created_by' => $identity,
                    'updated_at' => $now,
                    'id' => $id,
                ]);

                if ($statement->rowCount() === 0) {
                    $exists = $this->count(
                        'SELECT COUNT(*)
                         FROM quiz_questions
                         WHERE id = :id',
                        ['id' => $id]
                    );

                    if ($exists !== 1) {
                        throw new RuntimeException(
                            'سؤال برای ویرایش پیدا نشد.'
                        );
                    }
                }

                $questionId = $id;
            } else {
                $statement = $this->pdo->prepare(
                    'INSERT INTO quiz_questions (
                        category_id,
                        question_type,
                        difficulty,
                        question_text,
                        explanation,
                        points,
                        xp_reward,
                        answer_timeout_seconds,
                        enabled,
                        source,
                        created_by,
                        created_at,
                        updated_at
                     ) VALUES (
                        :category_id,
                        :question_type,
                        :difficulty,
                        :question_text,
                        :explanation,
                        :points,
                        :xp_reward,
                        :timeout,
                        :enabled,
                        :source,
                        :created_by,
                        :created_at,
                        :updated_at
                     )'
                );

                $statement->execute([
                    'category_id' =>
                        $categoryId,
                    'question_type' =>
                        $questionType,
                    'difficulty' =>
                        $difficulty,
                    'question_text' =>
                        $questionText,
                    'explanation' =>
                        trim($explanation) ?: null,
                    'points' => $points,
                    'xp_reward' => $xpReward,
                    'timeout' => $timeoutSeconds,
                    'enabled' => $enabled ? 1 : 0,
                    'source' => 'admin',
                    'created_by' => $identity,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $questionId = (int)
                    $this->pdo->lastInsertId();
            }

            $delete = $this->pdo->prepare(
                'DELETE FROM quiz_question_options
                 WHERE question_id = :question_id'
            );

            $delete->execute([
                'question_id' => $questionId,
            ]);

            $insert = $this->pdo->prepare(
                'INSERT INTO quiz_question_options (
                    question_id,
                    option_text,
                    is_correct,
                    sort_order
                 ) VALUES (
                    :question_id,
                    :option_text,
                    :is_correct,
                    :sort_order
                 )'
            );

            foreach (
                $options
                as $index => $option
            ) {
                $insert->execute([
                    'question_id' =>
                        $questionId,
                    'option_text' => $option,
                    'is_correct' =>
                        $index === $correctOption
                            ? 1
                            : 0,
                    'sort_order' => $index,
                ]);
            }

            $this->pdo->exec('COMMIT');
        } catch (Throwable $exception) {
            try {
                $this->pdo->exec('ROLLBACK');
            } catch (Throwable) {
            }

            throw $exception;
        }

        $this->audit(
            $identity,
            'quiz.question.save',
            (string) $questionId,
            [
                'category_id' => $categoryId,
                'difficulty' => $difficulty,
                'enabled' => $enabled,
                'points' => $points,
                'xp_reward' => $xpReward,
                'timeout' => $timeoutSeconds,
            ],
            $ip,
            $userAgent
        );

        return $questionId;
    }

    public function setQuizQuestionEnabled(
        int $questionId,
        bool $enabled,
        string $identity,
        string $ip,
        string $userAgent
    ): void {
        $statement = $this->pdo->prepare(
            'UPDATE quiz_questions
             SET
                enabled = :enabled,
                updated_at = :updated_at
             WHERE id = :id'
        );

        $statement->execute([
            'enabled' => $enabled ? 1 : 0,
            'updated_at' => date(DATE_ATOM),
            'id' => $questionId,
        ]);

        if ($statement->rowCount() === 0) {
            throw new RuntimeException(
                'سؤال پیدا نشد.'
            );
        }

        $this->audit(
            $identity,
            'quiz.question.toggle',
            (string) $questionId,
            ['enabled' => $enabled],
            $ip,
            $userAgent
        );
    }

    /**
     * @return array{
     *     imported:int,
     *     updated:int,
     *     categories:int
     * }
     */
    public function importQuizCsv(
        string $temporaryPath,
        string $originalName,
        int $size,
        string $identity,
        string $ip,
        string $userAgent
    ): array {
        if (
            $size <= 0
            || $size > 2097152
        ) {
            throw new RuntimeException(
                'حجم CSV باید حداکثر ۲ مگابایت باشد.'
            );
        }

        $extension = mb_strtolower(
            pathinfo(
                $originalName,
                PATHINFO_EXTENSION
            )
        );

        if ($extension !== 'csv') {
            throw new RuntimeException(
                'فقط فایل CSV پذیرفته می‌شود.'
            );
        }

        $result = (
            new QuizCsvService($this->pdo)
        )->importFile(
            $temporaryPath,
            $identity,
            2097152,
            1000
        );

        $this->audit(
            $identity,
            'quiz.csv.import',
            $originalName,
            $result,
            $ip,
            $userAgent
        );

        return $result;
    }

    /**
     * @param resource $stream
     */
    public function streamQuizCsv(
        mixed $stream
    ): void {
        (new QuizCsvService($this->pdo))
            ->streamExport($stream);
    }

    /**
     * @return array{
     *     expired:int,
     *     pruned:int
     * }
     */
    public function processQuizMaintenance(
        string $identity,
        string $ip,
        string $userAgent
    ): array {
        $result = (
            new QuizMaintenanceWorker(
                $this->quizRepository()
            )
        )->run(
            (int) $this->runtime->get(
                'modules.quiz_games.worker.batch_size',
                200
            ),
            (int) $this->runtime->get(
                'modules.quiz_games.retention_days',
                180
            )
        );

        $this->audit(
            $identity,
            'quiz.maintenance',
            'manual',
            $result,
            $ip,
            $userAgent
        );

        return $result;
    }

    private function quizRepository(): QuizRepository
    {
        return new QuizRepository(
            pdo: $this->pdo,
            scoring: new QuizScoring(
                timeBonusMaxPercent: (int)
                    $this->runtime->get(
                        'modules.quiz_games.scoring.time_bonus_max_percent',
                        50
                    ),
                streakBonusPercent: (int)
                    $this->runtime->get(
                        'modules.quiz_games.scoring.streak_bonus_percent',
                        5
                    ),
                participationXp: (int)
                    $this->runtime->get(
                        'modules.quiz_games.scoring.participation_xp',
                        1
                    ),
                xpPerLevel: (int)
                    $this->runtime->get(
                        'modules.quiz_games.scoring.xp_per_level',
                        100
                    )
            )
        );
    }

    /**
     * @return array{
     *     groups:list<array<string,mixed>>,
     *     selected_group:?array<string,mixed>,
     *     settings:?array<string,mixed>,
     *     warnings:list<array<string,mixed>>,
     *     sanctions:list<array<string,mixed>>,
     *     captchas:list<array<string,mixed>>,
     *     join_requests:list<array<string,mixed>>,
     *     invite_links:list<array<string,mixed>>,
     *     domains:list<string>,
     *     bad_words:list<string>,
     *     audit:list<array<string,mixed>>
     * }
     */
    public function groupManagementOverview(
        int $chatId = 0,
        int $limit = 100
    ): array {
        $limit = max(10, min(500, $limit));

        $groupsStatement = $this->pdo->prepare(
            "SELECT
                c.telegram_id AS chat_id,
                c.title,
                c.username,
                c.type,
                c.is_active,
                c.admin_blocked,
                c.last_seen_at,
                s.anti_spam_enabled,
                s.anti_link_enabled,
                s.bad_words_enabled,
                s.captcha_enabled,
                s.welcome_enabled,
                s.goodbye_enabled,
                s.join_request_mode,
                s.bot_slow_mode_seconds,
                (
                    SELECT COUNT(*)
                    FROM group_warnings AS w
                    WHERE w.chat_id = c.telegram_id
                      AND w.active = 1
                ) AS active_warnings,
                (
                    SELECT COUNT(*)
                    FROM group_sanctions AS x
                    WHERE x.chat_id = c.telegram_id
                      AND x.status = 'active'
                ) AS active_sanctions,
                (
                    SELECT COUNT(*)
                    FROM group_captcha_challenges AS p
                    WHERE p.chat_id = c.telegram_id
                      AND p.status = 'pending'
                ) AS pending_captchas,
                (
                    SELECT COUNT(*)
                    FROM group_join_requests AS j
                    WHERE j.chat_id = c.telegram_id
                      AND j.status = 'pending'
                ) AS pending_join_requests,
                (
                    SELECT COUNT(*)
                    FROM group_audit_logs AS a
                    WHERE a.chat_id = c.telegram_id
                      AND a.action LIKE 'automod.%'
                      AND julianday(a.created_at)
                        >= julianday('now', '-1 day')
                ) AS automod_24h
             FROM chats AS c
             LEFT JOIN group_settings AS s
                ON s.chat_id = c.telegram_id
             WHERE c.type IN (
                'group',
                'supergroup'
             )
             ORDER BY
                CASE
                    WHEN s.chat_id IS NULL THEN 1
                    ELSE 0
                END,
                c.last_seen_at DESC
             LIMIT :limit"
        );

        $groupsStatement->bindValue(
            ':limit',
            $limit,
            PDO::PARAM_INT
        );

        $groupsStatement->execute();

        $groups = $groupsStatement->fetchAll(
            PDO::FETCH_ASSOC
        );

        $groups = is_array($groups)
            ? $groups
            : [];

        if ($chatId === 0 && $groups !== []) {
            $chatId = (int) $groups[0]['chat_id'];
        }

        if ($chatId === 0) {
            return [
                'groups' => $groups,
                'selected_group' => null,
                'settings' => null,
                'warnings' => [],
                'sanctions' => [],
                'captchas' => [],
                'join_requests' => [],
                'invite_links' => [],
                'domains' => [],
                'bad_words' => [],
                'audit' => [],
            ];
        }

        $selectedStatement = $this->pdo->prepare(
            "SELECT
                telegram_id AS chat_id,
                title,
                username,
                type,
                is_active,
                admin_blocked,
                first_seen_at,
                last_seen_at,
                request_count
             FROM chats
             WHERE telegram_id = :chat_id
               AND type IN (
                    'group',
                    'supergroup'
               )
             LIMIT 1"
        );

        $selectedStatement->execute([
            'chat_id' => $chatId,
        ]);

        $selectedGroup = $selectedStatement->fetch(
            PDO::FETCH_ASSOC
        );

        if (!is_array($selectedGroup)) {
            throw new RuntimeException(
                'گروه انتخاب‌شده پیدا نشد.'
            );
        }

        $repository = $this->groupRepository();
        $settings = $repository->settings($chatId);

        $warningsStatement = $this->pdo->prepare(
            "SELECT
                w.id,
                w.user_id,
                w.admin_id,
                w.reason,
                w.active,
                w.created_at,
                w.revoked_at,
                target.first_name AS target_first_name,
                target.last_name AS target_last_name,
                target.username AS target_username,
                admin.first_name AS admin_first_name,
                admin.username AS admin_username
             FROM group_warnings AS w
             LEFT JOIN users AS target
                ON target.telegram_id = w.user_id
             LEFT JOIN users AS admin
                ON admin.telegram_id = w.admin_id
             WHERE w.chat_id = :chat_id
             ORDER BY w.id DESC
             LIMIT :limit"
        );

        $warningsStatement->bindValue(
            ':chat_id',
            $chatId,
            PDO::PARAM_INT
        );

        $warningsStatement->bindValue(
            ':limit',
            $limit,
            PDO::PARAM_INT
        );

        $warningsStatement->execute();

        $sanctionsStatement = $this->pdo->prepare(
            "SELECT
                x.*,
                u.first_name,
                u.last_name,
                u.username
             FROM group_sanctions AS x
             LEFT JOIN users AS u
                ON u.telegram_id = x.user_id
             WHERE x.chat_id = :chat_id
             ORDER BY x.id DESC
             LIMIT :limit"
        );

        $sanctionsStatement->bindValue(
            ':chat_id',
            $chatId,
            PDO::PARAM_INT
        );

        $sanctionsStatement->bindValue(
            ':limit',
            $limit,
            PDO::PARAM_INT
        );

        $sanctionsStatement->execute();

        $captchasStatement = $this->pdo->prepare(
            "SELECT
                p.*,
                u.first_name,
                u.last_name,
                u.username
             FROM group_captcha_challenges AS p
             LEFT JOIN users AS u
                ON u.telegram_id = p.user_id
             WHERE p.chat_id = :chat_id
             ORDER BY p.id DESC
             LIMIT :limit"
        );

        $captchasStatement->bindValue(
            ':chat_id',
            $chatId,
            PDO::PARAM_INT
        );

        $captchasStatement->bindValue(
            ':limit',
            $limit,
            PDO::PARAM_INT
        );

        $captchasStatement->execute();

        $joinStatement = $this->pdo->prepare(
            "SELECT
                j.*,
                u.first_name,
                u.last_name,
                u.username
             FROM group_join_requests AS j
             LEFT JOIN users AS u
                ON u.telegram_id = j.user_id
             WHERE j.chat_id = :chat_id
             ORDER BY j.id DESC
             LIMIT :limit"
        );

        $joinStatement->bindValue(
            ':chat_id',
            $chatId,
            PDO::PARAM_INT
        );

        $joinStatement->bindValue(
            ':limit',
            $limit,
            PDO::PARAM_INT
        );

        $joinStatement->execute();

        $linksStatement = $this->pdo->prepare(
            "SELECT *
             FROM group_invite_links
             WHERE chat_id = :chat_id
             ORDER BY id DESC
             LIMIT :limit"
        );

        $linksStatement->bindValue(
            ':chat_id',
            $chatId,
            PDO::PARAM_INT
        );

        $linksStatement->bindValue(
            ':limit',
            $limit,
            PDO::PARAM_INT
        );

        $linksStatement->execute();

        $auditStatement = $this->pdo->prepare(
            "SELECT
                a.*,
                actor.first_name AS actor_first_name,
                actor.username AS actor_username,
                target.first_name AS target_first_name,
                target.username AS target_username
             FROM group_audit_logs AS a
             LEFT JOIN users AS actor
                ON actor.telegram_id = a.actor_id
             LEFT JOIN users AS target
                ON target.telegram_id = a.target_user_id
             WHERE a.chat_id = :chat_id
             ORDER BY a.id DESC
             LIMIT :limit"
        );

        $auditStatement->bindValue(
            ':chat_id',
            $chatId,
            PDO::PARAM_INT
        );

        $auditStatement->bindValue(
            ':limit',
            $limit,
            PDO::PARAM_INT
        );

        $auditStatement->execute();

        return [
            'groups' => $groups,
            'selected_group' => $selectedGroup,
            'settings' => $settings,
            'warnings' =>
                $warningsStatement->fetchAll(
                    PDO::FETCH_ASSOC
                ) ?: [],
            'sanctions' =>
                $sanctionsStatement->fetchAll(
                    PDO::FETCH_ASSOC
                ) ?: [],
            'captchas' =>
                $captchasStatement->fetchAll(
                    PDO::FETCH_ASSOC
                ) ?: [],
            'join_requests' =>
                $joinStatement->fetchAll(
                    PDO::FETCH_ASSOC
                ) ?: [],
            'invite_links' =>
                $linksStatement->fetchAll(
                    PDO::FETCH_ASSOC
                ) ?: [],
            'domains' => $repository->domains(
                $chatId
            ),
            'bad_words' => $repository->badWords(
                $chatId
            ),
            'audit' =>
                $auditStatement->fetchAll(
                    PDO::FETCH_ASSOC
                ) ?: [],
        ];
    }

    /**
     * @param array<string, mixed> $values
     */
    public function saveGroupSettings(
        int $chatId,
        array $values,
        string $identity,
        string $ip,
        string $userAgent
    ): void {
        if ($chatId === 0) {
            throw new RuntimeException(
                'شناسه گروه معتبر نیست.'
            );
        }

        $groupExists = $this->count(
            "SELECT COUNT(*)
             FROM chats
             WHERE telegram_id = :chat_id
               AND type IN (
                    'group',
                    'supergroup'
               )",
            ['chat_id' => $chatId]
        );

        if ($groupExists !== 1) {
            throw new RuntimeException(
                'گروه در دیتابیس ربات پیدا نشد.'
            );
        }

        $booleanFields = [
            'anti_spam_enabled',
            'anti_link_enabled',
            'bad_words_enabled',
            'captcha_enabled',
            'welcome_enabled',
            'goodbye_enabled',
        ];

        $integerRules = [
            'warnings_threshold' => [1, 20],
            'warning_action_duration_seconds' => [
                30,
                31622400,
            ],
            'flood_max_messages' => [2, 100],
            'flood_window_seconds' => [1, 300],
            'duplicate_max_messages' => [1, 20],
            'duplicate_window_seconds' => [
                1,
                600,
            ],
            'captcha_timeout_seconds' => [
                30,
                1800,
            ],
            'captcha_max_attempts' => [1, 10],
            'bot_slow_mode_seconds' => [0, 3600],
        ];

        $clean = [];

        foreach ($booleanFields as $field) {
            $clean[$field] = (
                ($values[$field] ?? '0') === '1'
                || ($values[$field] ?? false) === true
                || ($values[$field] ?? 0) === 1
            ) ? 1 : 0;
        }

        foreach (
            $integerRules
            as $field => [$minimum, $maximum]
        ) {
            $value = (int) (
                $values[$field] ?? 0
            );

            if (
                $value < $minimum
                || $value > $maximum
            ) {
                throw new RuntimeException(
                    "مقدار {$field} باید بین "
                    . "{$minimum} و {$maximum} باشد."
                );
            }

            $clean[$field] = $value;
        }

        $warningAction = (string) (
            $values['warning_action'] ?? 'mute'
        );

        if (
            !in_array(
                $warningAction,
                ['none', 'mute', 'ban'],
                true
            )
        ) {
            throw new RuntimeException(
                'Action اخطار معتبر نیست.'
            );
        }

        $clean['warning_action'] =
            $warningAction;

        $captchaFailure = (string) (
            $values[
                'captcha_failure_action'
            ] ?? 'kick'
        );

        if (
            !in_array(
                $captchaFailure,
                ['kick', 'ban'],
                true
            )
        ) {
            throw new RuntimeException(
                'Action ناموفق کپچا معتبر نیست.'
            );
        }

        $clean['captcha_failure_action'] =
            $captchaFailure;

        $joinMode = (string) (
            $values['join_request_mode']
            ?? 'manual'
        );

        if (
            !in_array(
                $joinMode,
                ['manual', 'approve', 'decline'],
                true
            )
        ) {
            throw new RuntimeException(
                'حالت درخواست عضویت معتبر نیست.'
            );
        }

        $clean['join_request_mode'] =
            $joinMode;

        foreach (
            [
                'rules_text' => 4000,
                'welcome_message' => 4000,
                'goodbye_message' => 4000,
            ]
            as $field => $maximumLength
        ) {
            $clean[$field] = mb_substr(
                trim(
                    (string) (
                        $values[$field] ?? ''
                    )
                ),
                0,
                $maximumLength
            );
        }

        $authorization =
            new GroupAuthorization(
                telegram: $this->telegram,
                pdo: $this->pdo,
                roleCacheTtl: (int)
                    $this->runtime->get(
                        'modules.group_management.member_role_cache_ttl',
                        120
                    )
            );

        if (
            $clean['anti_spam_enabled'] === 1
            || $clean['anti_link_enabled'] === 1
            || $clean['bad_words_enabled'] === 1
            || $clean['bot_slow_mode_seconds'] > 0
        ) {
            $authorization->requireBotRight(
                $chatId,
                'can_delete_messages'
            );
        }

        if (
            $clean['captcha_enabled'] === 1
            || $clean['warning_action']
                !== 'none'
        ) {
            $authorization->requireBotRight(
                $chatId,
                'can_restrict_members'
            );
        }

        if (
            $clean['join_request_mode']
            !== 'manual'
        ) {
            $authorization->requireBotRight(
                $chatId,
                'can_invite_users'
            );
        }

        $repository = $this->groupRepository();
        $repository->updateSettings(
            $chatId,
            $clean
        );

        $repository->audit(
            $chatId,
            null,
            null,
            'settings.web_admin',
            [
                'admin_identity' => $identity,
                'values' => $clean,
            ]
        );

        $this->audit(
            $identity,
            'group.settings',
            (string) $chatId,
            $clean,
            $ip,
            $userAgent
        );
    }

    /**
     * @return array{
     *     sanctions_lifted:int,
     *     sanctions_failed:int,
     *     captchas_expired:int,
     *     captchas_failed:int,
     *     pruned:int
     * }
     */
    public function processGroupWorker(
        string $identity,
        string $ip,
        string $userAgent
    ): array {
        $repository = $this->groupRepository();

        $worker = new GroupWorker(
            repository: $repository,
            moderation: new GroupModerationService(
                telegram: $this->telegram,
                repository: $repository
            ),
            logFile: rtrim(
                $this->logsDirectory,
                '/\\'
            ) . '/group_management.log'
        );

        $result = $worker->run(
            batchSize: (int) $this->runtime->get(
                'modules.group_management.worker.batch_size',
                20
            ),
            retentionDays: (int) $this->runtime->get(
                'modules.group_management.retention_days',
                180
            )
        );

        $this->audit(
            $identity,
            'group.worker',
            'manual',
            $result,
            $ip,
            $userAgent
        );

        return $result;
    }

    public function resolveGroupJoinRequest(
        int $chatId,
        int $requestId,
        string $decision,
        string $identity,
        string $ip,
        string $userAgent
    ): void {
        if (
            !in_array(
                $decision,
                ['approve', 'decline'],
                true
            )
        ) {
            throw new RuntimeException(
                'تصمیم درخواست عضویت معتبر نیست.'
            );
        }

        $repository = $this->groupRepository();
        $request = $repository->joinRequest(
            $chatId,
            $requestId
        );

        if (
            $request === null
            || $request['status'] !== 'pending'
        ) {
            throw new RuntimeException(
                'درخواست عضویت در انتظار پیدا نشد.'
            );
        }

        $moderation = new GroupModerationService(
            telegram: $this->telegram,
            repository: $repository
        );

        $userId = (int) $request['user_id'];

        if ($decision === 'approve') {
            $moderation->approveJoinRequest(
                $chatId,
                $userId
            );

            $status = 'approved';
        } else {
            $moderation->declineJoinRequest(
                $chatId,
                $userId
            );

            $status = 'declined';
        }

        $repository->resolveJoinRequest(
            $chatId,
            $requestId,
            $status,
            null
        );

        $repository->audit(
            $chatId,
            null,
            $userId,
            'join_request.web_' . $status,
            [
                'request_id' => $requestId,
                'admin_identity' => $identity,
            ]
        );

        $this->audit(
            $identity,
            'group.join_request',
            $chatId . ':' . $requestId,
            ['status' => $status],
            $ip,
            $userAgent
        );
    }

    public function createGroupInvite(
        int $chatId,
        string $name,
        int $expireMinutes,
        int $memberLimit,
        bool $createsJoinRequest,
        string $identity,
        string $ip,
        string $userAgent
    ): int {
        $maximumDays = max(
            1,
            (int) $this->runtime->get(
                'modules.group_management.invite_maximum_days',
                365
            )
        );

        if (
            $expireMinutes < 0
            || $expireMinutes
                > $maximumDays * 1440
        ) {
            throw new RuntimeException(
                'مدت اعتبار لینک خارج از محدوده است.'
            );
        }

        if (
            $memberLimit < 0
            || $memberLimit > 99999
        ) {
            throw new RuntimeException(
                'سقف عضو لینک معتبر نیست.'
            );
        }

        $options = [];

        $name = trim($name);

        if ($name !== '') {
            $options['name'] = mb_substr(
                $name,
                0,
                32
            );
        }

        if ($expireMinutes > 0) {
            $options['expire_date'] =
                time() + $expireMinutes * 60;
        }

        if ($createsJoinRequest) {
            $options[
                'creates_join_request'
            ] = true;
        } elseif ($memberLimit > 0) {
            $options['member_limit'] =
                $memberLimit;
        }

        $repository = $this->groupRepository();
        $moderation = new GroupModerationService(
            telegram: $this->telegram,
            repository: $repository
        );

        $link = $moderation->createInviteLink(
            $chatId,
            $options
        );

        $id = $repository->storeInviteLink(
            $chatId,
            $this->webActorUserId(),
            $link
        );

        $repository->audit(
            $chatId,
            null,
            null,
            'invite.web_created',
            [
                'invite_id' => $id,
                'admin_identity' => $identity,
                'options' => $options,
            ]
        );

        $this->audit(
            $identity,
            'group.invite_create',
            $chatId . ':' . $id,
            $options,
            $ip,
            $userAgent
        );

        return $id;
    }

    public function liftGroupSanction(
        int $chatId,
        int $sanctionId,
        string $identity,
        string $ip,
        string $userAgent
    ): void {
        $statement = $this->pdo->prepare(
            "SELECT *
             FROM group_sanctions
             WHERE id = :id
               AND chat_id = :chat_id
               AND status = 'active'
             LIMIT 1"
        );

        $statement->execute([
            'id' => $sanctionId,
            'chat_id' => $chatId,
        ]);

        $sanction = $statement->fetch(
            PDO::FETCH_ASSOC
        );

        if (!is_array($sanction)) {
            throw new RuntimeException(
                'محدودیت فعال پیدا نشد.'
            );
        }

        $repository = $this->groupRepository();
        $moderation = new GroupModerationService(
            telegram: $this->telegram,
            repository: $repository
        );

        $targetUserId = (int)
            $sanction['user_id'];

        if (
            $sanction['sanction_type']
            === 'ban'
        ) {
            $moderation->unban(
                $chatId,
                $targetUserId
            );
        } else {
            $moderation->unmute(
                $chatId,
                $targetUserId
            );
        }

        $repository->completeSanction(
            $sanctionId,
            true
        );

        $repository->audit(
            $chatId,
            null,
            $targetUserId,
            'sanction.web_lifted',
            [
                'sanction_id' => $sanctionId,
                'admin_identity' => $identity,
            ]
        );

        $this->audit(
            $identity,
            'group.sanction_lift',
            $chatId . ':' . $sanctionId,
            [],
            $ip,
            $userAgent
        );
    }

    public function cancelGroupCaptcha(
        int $chatId,
        int $captchaId,
        string $identity,
        string $ip,
        string $userAgent
    ): void {
        $repository = $this->groupRepository();
        $captcha = $repository->captcha(
            $captchaId
        );

        if (
            $captcha === null
            || (int) $captcha['chat_id']
                !== $chatId
            || $captcha['status']
                !== 'pending'
        ) {
            throw new RuntimeException(
                'کپچای در انتظار پیدا نشد.'
            );
        }

        $targetUserId = (int)
            $captcha['user_id'];

        $moderation = new GroupModerationService(
            telegram: $this->telegram,
            repository: $repository
        );

        $moderation->unmute(
            $chatId,
            $targetUserId
        );

        $repository->finishCaptcha(
            $captchaId,
            'cancelled'
        );

        $repository->revokeActiveSanctions(
            $chatId,
            $targetUserId,
            ['captcha']
        );

        $repository->audit(
            $chatId,
            null,
            $targetUserId,
            'captcha.web_cancelled',
            [
                'challenge_id' => $captchaId,
                'admin_identity' => $identity,
            ]
        );

        $this->audit(
            $identity,
            'group.captcha_cancel',
            $chatId . ':' . $captchaId,
            [],
            $ip,
            $userAgent
        );
    }

    public function revokeGroupInvite(
        int $chatId,
        int $inviteId,
        string $identity,
        string $ip,
        string $userAgent
    ): void {
        $repository = $this->groupRepository();
        $invite = $repository->inviteLink(
            $chatId,
            $inviteId
        );

        if (
            $invite === null
            || $invite['status'] !== 'active'
        ) {
            throw new RuntimeException(
                'لینک دعوت فعال پیدا نشد.'
            );
        }

        $moderation = new GroupModerationService(
            telegram: $this->telegram,
            repository: $repository
        );

        $moderation->revokeInviteLink(
            $chatId,
            (string) $invite['invite_link']
        );

        $repository->markInviteRevoked(
            $inviteId
        );

        $repository->audit(
            $chatId,
            null,
            null,
            'invite.web_revoked',
            [
                'invite_id' => $inviteId,
                'admin_identity' => $identity,
            ]
        );

        $this->audit(
            $identity,
            'group.invite_revoke',
            $chatId . ':' . $inviteId,
            [],
            $ip,
            $userAgent
        );
    }

    public function updateGroupList(
        int $chatId,
        string $listType,
        string $operation,
        string $value,
        string $identity,
        string $ip,
        string $userAgent
    ): int {
        if (
            !in_array(
                $listType,
                ['domain', 'bad_word'],
                true
            )
        ) {
            throw new RuntimeException(
                'نوع فهرست معتبر نیست.'
            );
        }

        if (
            !in_array(
                $operation,
                ['add', 'remove', 'clear'],
                true
            )
        ) {
            throw new RuntimeException(
                'عملیات فهرست معتبر نیست.'
            );
        }

        $repository = $this->groupRepository();
        $affected = 0;

        if ($listType === 'domain') {
            if ($operation === 'clear') {
                $statement = $this->pdo->prepare(
                    'DELETE FROM group_domain_whitelist
                     WHERE chat_id = :chat_id'
                );

                $statement->execute([
                    'chat_id' => $chatId,
                ]);

                $affected = $statement->rowCount();
            } elseif ($operation === 'add') {
                $repository->addDomain(
                    $chatId,
                    $value,
                    $this->webActorUserId()
                );

                $affected = 1;
            } else {
                $affected = $repository->removeDomain(
                    $chatId,
                    $value
                ) ? 1 : 0;
            }
        } else {
            if ($operation === 'clear') {
                $affected = $repository->clearBadWords(
                    $chatId
                );
            } elseif ($operation === 'add') {
                $repository->addBadWord(
                    $chatId,
                    $value,
                    $this->webActorUserId()
                );

                $affected = 1;
            } else {
                $affected = $repository->removeBadWord(
                    $chatId,
                    $value
                ) ? 1 : 0;
            }
        }

        $repository->audit(
            $chatId,
            null,
            null,
            'settings.web_list_'
                . $listType
                . '.'
                . $operation,
            [
                'value' => $value,
                'affected' => $affected,
                'admin_identity' => $identity,
            ]
        );

        $this->audit(
            $identity,
            'group.list',
            $chatId . ':' . $listType,
            [
                'operation' => $operation,
                'value' => $value,
                'affected' => $affected,
            ],
            $ip,
            $userAgent
        );

        return $affected;
    }

    public function clearGroupWarnings(
        int $chatId,
        int $targetUserId,
        string $identity,
        string $ip,
        string $userAgent
    ): int {
        if ($targetUserId <= 0) {
            throw new RuntimeException(
                'شناسه کاربر معتبر نیست.'
            );
        }

        $repository = $this->groupRepository();
        $count = $repository->clearWarnings(
            $chatId,
            $targetUserId,
            null
        );

        $repository->audit(
            $chatId,
            null,
            $targetUserId,
            'warning.web_clear',
            [
                'count' => $count,
                'admin_identity' => $identity,
            ]
        );

        $this->audit(
            $identity,
            'group.warning_clear',
            $chatId . ':' . $targetUserId,
            ['count' => $count],
            $ip,
            $userAgent
        );

        return $count;
    }

    private function webActorUserId(): int
    {
        $configured = (array) $this->runtime->get(
            'admins',
            []
        );

        foreach ($configured as $value) {
            $candidate = null;

            if (
                is_int($value)
                && $value > 0
            ) {
                $candidate = $value;
            } elseif (
                is_string($value)
                && preg_match(
                    '/^\d+$/',
                    $value
                ) === 1
            ) {
                $candidate = (int) $value;
            }

            if (
                $candidate !== null
                && $this->count(
                    'SELECT COUNT(*)
                     FROM users
                     WHERE telegram_id = :id',
                    ['id' => $candidate]
                ) === 1
            ) {
                return $candidate;
            }
        }

        $candidate = $this->scalar(
            'SELECT telegram_id
             FROM users
             ORDER BY id ASC
             LIMIT 1'
        );

        if (is_numeric($candidate)) {
            return (int) $candidate;
        }

        throw new RuntimeException(
            'برای ثبت سازنده فهرست، یک Admin Telegram در config لازم است.'
        );
    }

    private function groupRepository(): GroupRepository
    {
        return new GroupRepository(
            pdo: $this->pdo,
            defaultSettings: (array)
                $this->runtime->get(
                    'modules.group_management.defaults',
                    []
                )
        );
    }

    /**
     * @return array{
     *     alerts:list<array<string,mixed>>,
     *     subscriptions:list<array<string,mixed>>,
     *     monitors:list<array<string,mixed>>
     * }
     */
    public function automationOverview(
        int $limit = 100
    ): array {
        $limit = max(10, min(500, $limit));

        $alerts = $this->pdo->prepare(
            'SELECT
                a.*,
                u.first_name,
                u.last_name,
                u.username
             FROM smart_alerts AS a
             LEFT JOIN users AS u
                ON u.telegram_id = a.user_id
             ORDER BY a.id DESC
             LIMIT :limit'
        );
        $alerts->bindValue(':limit', $limit, PDO::PARAM_INT);
        $alerts->execute();

        $subscriptions = $this->pdo->prepare(
            'SELECT
                s.*,
                u.first_name,
                u.last_name,
                u.username
             FROM smart_subscriptions AS s
             LEFT JOIN users AS u
                ON u.telegram_id = s.user_id
             ORDER BY s.id DESC
             LIMIT :limit'
        );
        $subscriptions->bindValue(':limit', $limit, PDO::PARAM_INT);
        $subscriptions->execute();

        $monitors = $this->pdo->prepare(
            'SELECT
                m.*,
                u.first_name,
                u.last_name,
                u.username,
                (
                    SELECT COUNT(*)
                    FROM monitor_checks AS c
                    WHERE c.monitor_id = m.id
                ) AS checks_count
             FROM site_monitors AS m
             LEFT JOIN users AS u
                ON u.telegram_id = m.user_id
             ORDER BY m.id DESC
             LIMIT :limit'
        );
        $monitors->bindValue(':limit', $limit, PDO::PARAM_INT);
        $monitors->execute();

        return [
            'alerts' => $alerts->fetchAll(PDO::FETCH_ASSOC) ?: [],
            'subscriptions' => $subscriptions->fetchAll(PDO::FETCH_ASSOC) ?: [],
            'monitors' => $monitors->fetchAll(PDO::FETCH_ASSOC) ?: [],
        ];
    }

    public function setAutomationStatus(
        string $type,
        int $id,
        string $status,
        string $identity,
        string $ip,
        string $userAgent
    ): void {
        $tables = [
            'alert' => 'smart_alerts',
            'subscription' => 'smart_subscriptions',
            'monitor' => 'site_monitors',
        ];

        if (!isset($tables[$type])) {
            throw new RuntimeException(
                'نوع رکورد خودکار معتبر نیست.'
            );
        }

        if (!in_array($status, ['active', 'paused', 'cancelled'], true)) {
            throw new RuntimeException(
                'وضعیت درخواستی معتبر نیست.'
            );
        }

        $extra = $status === 'active'
            ? ', next_check_at = :next_time'
            : '';

        if ($type === 'subscription' && $status === 'active') {
            $extra = ', next_run_at = :next_time';
        }

        $statement = $this->pdo->prepare(
            'UPDATE ' . $tables[$type] . '
             SET
                status = :status,
                updated_at = :updated_at'
                . $extra . '
             WHERE id = :id'
        );
        $parameters = [
            'status' => $status,
            'updated_at' => date(DATE_ATOM),
            'id' => $id,
        ];

        if ($status === 'active') {
            $parameters['next_time'] = time();
        }

        $statement->execute($parameters);

        if ($statement->rowCount() === 0) {
            throw new RuntimeException(
                'رکورد موردنظر پیدا نشد.'
            );
        }

        $this->audit(
            $identity,
            'automation.status',
            $type . ':' . $id,
            ['status' => $status],
            $ip,
            $userAgent
        );
    }

    /**
     * @return list<array{
     *     date:string,
     *     checks:int,
     *     up:int,
     *     uptime:float,
     *     average_response_ms:float
     * }>
     */
    public function monitorDailyUptime(
        int $monitorId,
        int $days = 30
    ): array {
        $days = max(1, min(365, $days));
        $cutoff = time() - $days * 86400;
        $statement = $this->pdo->prepare(
            "SELECT
                date(checked_at, 'unixepoch', 'localtime') AS day,
                COUNT(*) AS checks,
                SUM(CASE WHEN state = 'up' THEN 1 ELSE 0 END) AS up_count,
                AVG(CASE WHEN state = 'up' THEN response_ms END) AS avg_response
             FROM monitor_checks
             WHERE monitor_id = :monitor_id
               AND checked_at >= :cutoff
             GROUP BY day
             ORDER BY day ASC"
        );
        $statement->execute([
            'monitor_id' => $monitorId,
            'cutoff' => $cutoff,
        ]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $result = [];

        if (is_array($rows)) {
            foreach ($rows as $row) {
                $checks = (int) $row['checks'];
                $up = (int) $row['up_count'];
                $result[] = [
                    'date' => (string) $row['day'],
                    'checks' => $checks,
                    'up' => $up,
                    'uptime' => $checks > 0
                        ? round(($up / $checks) * 100, 2)
                        : 0.0,
                    'average_response_ms' => round(
                        (float) ($row['avg_response'] ?? 0),
                        2
                    ),
                ];
            }
        }

        return $result;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function monitorChecks(
        int $monitorId,
        int $limit = 100
    ): array {
        $statement = $this->pdo->prepare(
            'SELECT *
             FROM monitor_checks
             WHERE monitor_id = :monitor_id
             ORDER BY checked_at DESC
             LIMIT :limit'
        );
        $statement->bindValue(':monitor_id', $monitorId, PDO::PARAM_INT);
        $statement->bindValue(
            ':limit',
            max(1, min(500, $limit)),
            PDO::PARAM_INT
        );
        $statement->execute();
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
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
     * @return array<string, mixed>
     */
    public function analytics(
        int $days = 30
    ): array {
        $days = max(1, min(365, $days));
        $cutoff = time() - ($days * 86400);

        $summaryStatement = $this->pdo->prepare(
            'SELECT
                COUNT(*) AS events,
                COUNT(DISTINCT user_id) AS unique_users,
                COALESCE(AVG(duration_ms), 0) AS avg_duration_ms,
                COALESCE(MAX(duration_ms), 0) AS max_duration_ms,
                COALESCE(AVG(success) * 100, 100) AS success_rate
             FROM usage_events
             WHERE occurred_at >= :cutoff'
        );

        $summaryStatement->execute([
            'cutoff' => $cutoff,
        ]);

        $summary = $summaryStatement->fetch(
            PDO::FETCH_ASSOC
        );

        if (!is_array($summary)) {
            $summary = [];
        }

        $apiStatement = $this->pdo->prepare(
            'SELECT
                COUNT(*) AS calls,
                COALESCE(AVG(duration_ms), 0) AS avg_duration_ms,
                COALESCE(SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END), 0) AS failures,
                COALESCE(SUM(response_bytes), 0) AS response_bytes
             FROM api_metrics
             WHERE occurred_at >= :cutoff'
        );

        $apiStatement->execute([
            'cutoff' => $cutoff,
        ]);

        $apiSummary = $apiStatement->fetch(
            PDO::FETCH_ASSOC
        );

        if (!is_array($apiSummary)) {
            $apiSummary = [];
        }

        $cacheStatement = $this->pdo->prepare(
            "SELECT
                COUNT(*) AS operations,
                COALESCE(SUM(CASE WHEN operation = 'get' AND hit = 1 THEN 1 ELSE 0 END), 0) AS hits,
                COALESCE(SUM(CASE WHEN operation = 'get' AND hit = 0 THEN 1 ELSE 0 END), 0) AS misses
             FROM cache_metrics
             WHERE occurred_at >= :cutoff"
        );

        $cacheStatement->execute([
            'cutoff' => $cutoff,
        ]);

        $cacheSummary = $cacheStatement->fetch(
            PDO::FETCH_ASSOC
        );

        if (!is_array($cacheSummary)) {
            $cacheSummary = [];
        }

        $hits = (int) ($cacheSummary['hits'] ?? 0);
        $misses = (int) ($cacheSummary['misses'] ?? 0);

        return [
            'days' => $days,
            'summary' => [
                'events' => (int) ($summary['events'] ?? 0),
                'unique_users' => (int) ($summary['unique_users'] ?? 0),
                'avg_duration_ms' => round(
                    (float) ($summary['avg_duration_ms'] ?? 0),
                    2
                ),
                'max_duration_ms' => round(
                    (float) ($summary['max_duration_ms'] ?? 0),
                    2
                ),
                'success_rate' => round(
                    (float) ($summary['success_rate'] ?? 100),
                    2
                ),
                'api_calls' => (int) ($apiSummary['calls'] ?? 0),
                'api_avg_duration_ms' => round(
                    (float) ($apiSummary['avg_duration_ms'] ?? 0),
                    2
                ),
                'api_failures' => (int) ($apiSummary['failures'] ?? 0),
                'api_response_bytes' => (int) ($apiSummary['response_bytes'] ?? 0),
                'cache_operations' => (int) ($cacheSummary['operations'] ?? 0),
                'cache_hits' => $hits,
                'cache_misses' => $misses,
                'cache_hit_rate' => ($hits + $misses) > 0
                    ? round($hits * 100 / ($hits + $misses), 2)
                    : 0.0,
            ],
            'daily' => $this->analyticsDaily($cutoff),
            'commands' => $this->analyticsCommands($cutoff),
            'modules' => $this->analyticsModules($cutoff),
            'errors' => $this->analyticsErrors($cutoff),
            'api' => $this->analyticsApi($cutoff),
            'cache' => $this->analyticsCache($cutoff),
            'hours' => $this->analyticsHours($cutoff),
            'retention' => $this->analyticsRetention(),
            'jobs' => $this->jobOverview(),
            'recent_jobs' => $this->recentJobs(30),
            'dead_letters' => $this->recentDeadLetters(30),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function featureFlags(): array
    {
        return $this->featureRegistry()->all();
    }

    public function saveFeatureFlag(
        string $key,
        bool $enabled,
        int $rolloutPercentage,
        string $description,
        string $identity,
        string $ip,
        string $userAgent
    ): void {
        $old = $this->featureRegistry()->get($key);

        $this->featureRegistry()->set(
            $key,
            $enabled,
            $rolloutPercentage,
            $description,
            $identity
        );

        $this->audit(
            $identity,
            'feature.update',
            $key,
            [
                'old' => $old,
                'enabled' => $enabled,
                'rollout_percentage' => $rolloutPercentage,
            ],
            $ip,
            $userAgent
        );
    }

    public function resetFeatureFlag(
        string $key,
        string $identity,
        string $ip,
        string $userAgent
    ): bool {
        $deleted = $this->featureRegistry()->reset($key);

        if ($deleted) {
            $this->audit(
                $identity,
                'feature.reset',
                $key,
                [],
                $ip,
                $userAgent
            );
        }

        return $deleted;
    }

    /**
     * @return array<string, int>
     */
    public function cleanupAnalytics(
        string $identity,
        string $ip,
        string $userAgent
    ): array {
        $maintenance = new AnalyticsMaintenance(
            $this->pdo
        );

        $result = $maintenance->cleanup(
            usageDays: (int) $this->runtime->get(
                'analytics.retention.usage_days',
                90
            ),
            commandDays: (int) $this->runtime->get(
                'analytics.retention.command_days',
                30
            ),
            apiDays: (int) $this->runtime->get(
                'analytics.retention.api_days',
                30
            ),
            cacheDays: (int) $this->runtime->get(
                'analytics.retention.cache_days',
                30
            ),
            jobRunDays: (int) $this->runtime->get(
                'analytics.retention.job_run_days',
                30
            ),
            deadLetterDays: (int) $this->runtime->get(
                'analytics.retention.dead_letter_days',
                90
            ),
            maxUsageRows: (int) $this->runtime->get(
                'analytics.retention.max_usage_rows',
                250000
            )
        );

        $this->audit(
            $identity,
            'analytics.cleanup',
            'analytics',
            $result,
            $ip,
            $userAgent
        );

        return $result;
    }

    public function replayDeadLetter(
        int $deadLetterId,
        string $identity,
        string $ip,
        string $userAgent
    ): int {
        $queue = new JobQueue($this->pdo);
        $deadLetters = new DeadLetterQueue(
            $this->pdo,
            $queue
        );

        $jobId = $deadLetters->replay(
            $deadLetterId
        );

        $this->audit(
            $identity,
            'job.dead_letter_replay',
            (string) $deadLetterId,
            [
                'new_job_id' => $jobId,
            ],
            $ip,
            $userAgent
        );

        return $jobId;
    }

    public function cancelJob(
        int $jobId,
        string $identity,
        string $ip,
        string $userAgent
    ): bool {
        $cancelled = (new JobQueue(
            $this->pdo
        ))->cancel($jobId);

        if ($cancelled) {
            $this->audit(
                $identity,
                'job.cancel',
                (string) $jobId,
                [],
                $ip,
                $userAgent
            );
        }

        return $cancelled;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function analyticsDaily(int $cutoff): array
    {
        $statement = $this->pdo->prepare(
            'SELECT
                substr(created_at, 1, 10) AS day,
                COUNT(*) AS events,
                COUNT(DISTINCT user_id) AS users,
                COALESCE(AVG(duration_ms), 0) AS avg_duration_ms,
                COALESCE(AVG(success) * 100, 100) AS success_rate
             FROM usage_events
             WHERE occurred_at >= :cutoff
             GROUP BY day
             ORDER BY day ASC'
        );

        $statement->execute(['cutoff' => $cutoff]);

        return $this->rows($statement);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function analyticsCommands(int $cutoff): array
    {
        $statement = $this->pdo->prepare(
            "SELECT
                module,
                command,
                source,
                COUNT(*) AS total,
                COALESCE(AVG(duration_ms), 0) AS avg_duration_ms,
                COALESCE(AVG(success) * 100, 100) AS success_rate
             FROM command_history
             WHERE occurred_at >= :cutoff
             GROUP BY module, command, source
             ORDER BY total DESC
             LIMIT 30"
        );

        $statement->execute(['cutoff' => $cutoff]);

        return $this->rows($statement);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function analyticsModules(int $cutoff): array
    {
        $statement = $this->pdo->prepare(
            'SELECT
                module,
                COUNT(*) AS events,
                COUNT(DISTINCT user_id) AS users,
                COALESCE(AVG(duration_ms), 0) AS avg_duration_ms,
                COALESCE(MAX(duration_ms), 0) AS max_duration_ms,
                COALESCE(AVG(success) * 100, 100) AS success_rate
             FROM usage_events
             WHERE occurred_at >= :cutoff
             GROUP BY module
             ORDER BY events DESC'
        );

        $statement->execute(['cutoff' => $cutoff]);

        return $this->rows($statement);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function analyticsErrors(int $cutoff): array
    {
        $statement = $this->pdo->prepare(
            "SELECT
                module,
                action,
                COALESCE(error_code, 'unknown') AS error_code,
                MAX(error_message) AS error_message,
                COUNT(*) AS total,
                MAX(created_at) AS last_seen_at
             FROM usage_events
             WHERE occurred_at >= :cutoff
               AND success = 0
             GROUP BY module, action, error_code
             ORDER BY total DESC, last_seen_at DESC
             LIMIT 30"
        );

        $statement->execute(['cutoff' => $cutoff]);

        return $this->rows($statement);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function analyticsApi(int $cutoff): array
    {
        $statement = $this->pdo->prepare(
            'SELECT
                provider,
                host,
                path,
                COUNT(*) AS calls,
                COALESCE(AVG(duration_ms), 0) AS avg_duration_ms,
                COALESCE(MAX(duration_ms), 0) AS max_duration_ms,
                COALESCE(SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END), 0) AS failures,
                COALESCE(SUM(response_bytes), 0) AS response_bytes
             FROM api_metrics
             WHERE occurred_at >= :cutoff
             GROUP BY provider, host, path
             ORDER BY calls DESC
             LIMIT 30'
        );

        $statement->execute(['cutoff' => $cutoff]);

        return $this->rows($statement);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function analyticsCache(int $cutoff): array
    {
        $statement = $this->pdo->prepare(
            "SELECT
                namespace,
                COUNT(*) AS operations,
                COALESCE(SUM(CASE WHEN operation = 'get' AND hit = 1 THEN 1 ELSE 0 END), 0) AS hits,
                COALESCE(SUM(CASE WHEN operation = 'get' AND hit = 0 THEN 1 ELSE 0 END), 0) AS misses,
                COALESCE(AVG(duration_ms), 0) AS avg_duration_ms,
                COALESCE(SUM(value_bytes), 0) AS value_bytes
             FROM cache_metrics
             WHERE occurred_at >= :cutoff
             GROUP BY namespace
             ORDER BY operations DESC"
        );

        $statement->execute(['cutoff' => $cutoff]);

        return $this->rows($statement);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function analyticsHours(int $cutoff): array
    {
        $statement = $this->pdo->prepare(
            "SELECT
                strftime('%H', created_at) AS hour,
                COUNT(*) AS events,
                COUNT(DISTINCT user_id) AS users
             FROM usage_events
             WHERE occurred_at >= :cutoff
             GROUP BY hour
             ORDER BY hour ASC"
        );

        $statement->execute(['cutoff' => $cutoff]);

        return $this->rows($statement);
    }

    /**
     * @return array<string, array{eligible: int, retained: int, rate: float}>
     */
    private function analyticsRetention(): array
    {
        $result = [];

        foreach ([1, 7, 30] as $days) {
            $statement = $this->pdo->prepare(
                'SELECT
                    COUNT(*) AS eligible,
                    COALESCE(SUM(
                        CASE
                            WHEN julianday(last_seen_at)
                                >= julianday(first_seen_at, :offset)
                            THEN 1
                            ELSE 0
                        END
                    ), 0) AS retained
                 FROM users
                 WHERE julianday(first_seen_at)
                    <= julianday(\'now\', :negative_offset)'
            );

            $statement->execute([
                'offset' => '+' . $days . ' days',
                'negative_offset' => '-' . $days . ' days',
            ]);

            $row = $statement->fetch(PDO::FETCH_ASSOC);
            $eligible = is_array($row)
                ? (int) ($row['eligible'] ?? 0)
                : 0;
            $retained = is_array($row)
                ? (int) ($row['retained'] ?? 0)
                : 0;

            $result['d' . $days] = [
                'eligible' => $eligible,
                'retained' => $retained,
                'rate' => $eligible > 0
                    ? round($retained * 100 / $eligible, 2)
                    : 0.0,
            ];
        }

        return $result;
    }

    /**
     * @return array<string, int>
     */
    private function jobOverview(): array
    {
        return [
            'queued' => $this->count(
                "SELECT COUNT(*) FROM job_queue WHERE status = 'queued'"
            ),
            'processing' => $this->count(
                "SELECT COUNT(*) FROM job_queue WHERE status = 'processing'"
            ),
            'completed' => $this->count(
                "SELECT COUNT(*) FROM job_queue WHERE status = 'completed'"
            ),
            'dead' => $this->count(
                "SELECT COUNT(*) FROM job_queue WHERE status = 'dead'"
            ),
            'dead_letters' => $this->count(
                'SELECT COUNT(*) FROM dead_letter_jobs WHERE replayed_at IS NULL'
            ),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentJobs(int $limit): array
    {
        $statement = $this->pdo->prepare(
            'SELECT
                id,
                job_type,
                unique_key,
                status,
                priority,
                available_at,
                attempts,
                max_attempts,
                locked_by,
                locked_at,
                last_error,
                created_at,
                updated_at,
                completed_at
             FROM job_queue
             ORDER BY id DESC
             LIMIT :limit'
        );

        $statement->bindValue(
            ':limit',
            max(1, min(100, $limit)),
            PDO::PARAM_INT
        );
        $statement->execute();

        return $this->rows($statement);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentDeadLetters(int $limit): array
    {
        $statement = $this->pdo->prepare(
            'SELECT
                id,
                original_job_id,
                job_type,
                unique_key,
                attempts,
                error_message,
                failed_at,
                replayed_at,
                replay_job_id
             FROM dead_letter_jobs
             ORDER BY id DESC
             LIMIT :limit'
        );

        $statement->bindValue(
            ':limit',
            max(1, min(100, $limit)),
            PDO::PARAM_INT
        );
        $statement->execute();

        return $this->rows($statement);
    }

    private function featureRegistry(): FeatureRegistry
    {
        return new FeatureRegistry(
            $this->pdo,
            (array) $this->runtime->base(
                'features.defaults',
                []
            )
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(\PDOStatement $statement): array
    {
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows)
            ? $rows
            : [];
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
