<?php

declare(strict_types=1);

namespace SmartToolbox\Modules\Admin;

use PDO;
use RuntimeException;
use SmartToolbox\Core\CommandRouter;
use SmartToolbox\Core\ConversationStateStore;
use SmartToolbox\Core\MessageContext;
use SmartToolbox\Core\ModuleInterface;
use SmartToolbox\Core\TelegramClient;
use Throwable;

final class AdminModule implements ModuleInterface
{
    private const STATE_AWAITING_BROADCAST =
        'admin.awaiting_broadcast';

    /**
     * @var list<int>
     */
    private array $adminUserIds;

    /**
     * @param list<int|string> $adminUserIds
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly TelegramClient $telegram,
        private readonly ConversationStateStore $states,
        array $adminUserIds,
        private readonly string $databasePath,
        private readonly string $logFile,
        private readonly int $stateTtl = 600,
        private readonly int $broadcastBatchSize = 5,
        private readonly int $maxBroadcastLength = 3000
    ) {
        $normalizedIds = [];

        foreach ($adminUserIds as $adminUserId) {
            if (
                !is_int($adminUserId)
                && !is_string($adminUserId)
            ) {
                continue;
            }

            $value = trim((string) $adminUserId);

            if (
                preg_match('/^\d+$/', $value) !== 1
                || (int) $value <= 0
            ) {
                continue;
            }

            $normalizedIds[] = (int) $value;
        }

        $this->adminUserIds = array_values(
            array_unique($normalizedIds)
        );
    }

    public function register(CommandRouter $router): void
    {
        $router->command(
            'admin',
            function (MessageContext $context): void {
                $this->showAdminMenu($context);
            }
        );

        $router->command(
            'stats',
            function (MessageContext $context): void {
                $this->showStats($context);
            }
        );

        $router->command(
            'users',
            function (
                MessageContext $context,
                string $arguments
            ): void {
                $this->showUsers(
                    $context,
                    $arguments
                );
            }
        );

        $router->command(
            'chats',
            function (
                MessageContext $context,
                string $arguments
            ): void {
                $this->showChats(
                    $context,
                    $arguments
                );
            }
        );

        $router->command(
            'health',
            function (MessageContext $context): void {
                $this->showHealth($context);
            }
        );

        $router->command(
            'broadcast',
            function (
                MessageContext $context,
                string $arguments
            ): void {
                $this->handleBroadcastCommand(
                    $context,
                    $arguments
                );
            }
        );

        $router->command(
            'broadcastnext',
            function (
                MessageContext $context,
                string $arguments
            ): void {
                $this->processBroadcastBatchCommand(
                    $context,
                    $arguments
                );
            }
        );

        $router->command(
            'broadcaststatus',
            function (
                MessageContext $context,
                string $arguments
            ): void {
                $this->showBroadcastStatus(
                    $context,
                    $arguments
                );
            }
        );

        $router->command(
            'broadcastcancel',
            function (
                MessageContext $context,
                string $arguments
            ): void {
                $this->cancelBroadcast(
                    $context,
                    $arguments
                );
            }
        );

        $router->command(
            'broadcastretry',
            function (
                MessageContext $context,
                string $arguments
            ): void {
                $this->retryBroadcast(
                    $context,
                    $arguments
                );
            }
        );

        $router->text(
            '🛡 مدیریت',
            function (MessageContext $context): void {
                $this->showAdminMenu($context);
            }
        );

        $router->text(
            '📊 آمار',
            function (MessageContext $context): void {
                $this->showStats($context);
            }
        );

        $router->text(
            '👥 کاربران',
            function (MessageContext $context): void {
                $this->showUsers($context, '');
            }
        );

        $router->text(
            '💬 چت‌ها',
            function (MessageContext $context): void {
                $this->showChats($context, '');
            }
        );

        $router->text(
            '🩺 سلامت',
            function (MessageContext $context): void {
                $this->showHealth($context);
            }
        );

        $router->text(
            '📣 پیام همگانی',
            function (MessageContext $context): void {
                $this->askForBroadcast($context);
            }
        );

        $router->text(
            '📨 ادامه ارسال',
            function (MessageContext $context): void {
                $this->processBroadcastBatchCommand(
                    $context,
                    ''
                );
            }
        );

        $router->text(
            '📋 وضعیت ارسال',
            function (MessageContext $context): void {
                $this->showBroadcastStatus(
                    $context,
                    ''
                );
            }
        );

        $router->fallbackText(
            function (
                MessageContext $context,
                string $text
            ): bool {
                return $this->handlePendingBroadcast(
                    $context,
                    $text
                );
            }
        );
    }

    private function showAdminMenu(
        MessageContext $context
    ): void {
        if (!$this->authorize($context)) {
            return;
        }

        $activeBroadcasts = $this->scalarInt(
            "SELECT COUNT(*)
             FROM admin_broadcasts
             WHERE status IN ('pending', 'running')"
        );

        $options = [
            'reply_markup' => $this->adminKeyboard(),
        ];

        $context->reply(
            "🛡 پنل مدیریت\n\n"
            . "📊 آمار کامل ربات\n"
            . "👥 مشاهده کاربران اخیر\n"
            . "💬 مشاهده چت‌های اخیر\n"
            . "🩺 بررسی سلامت سرور و دیتابیس\n"
            . "📣 ارسال پیام همگانی کنترل‌شده\n\n"
            . "ارسال‌های فعال: {$activeBroadcasts}\n\n"
            . "دستورها:\n"
            . "/stats\n"
            . "/users 10\n"
            . "/chats 10\n"
            . "/health\n"
            . "/broadcast متن پیام\n"
            . "/broadcastnext\n"
            . "/broadcaststatus\n"
            . "/broadcastcancel ID\n"
            . "/broadcastretry ID",
            $options
        );
    }

    private function showStats(
        MessageContext $context
    ): void {
        if (!$this->authorize($context)) {
            return;
        }

        try {
            $users = $this->scalarInt(
                'SELECT COUNT(*) FROM users'
            );

            $activeUsers24Hours = $this->scalarInt(
                "SELECT COUNT(*)
                 FROM users
                 WHERE julianday(last_seen_at)
                    >= julianday('now', '-1 day')"
            );

            $activeUsers7Days = $this->scalarInt(
                "SELECT COUNT(*)
                 FROM users
                 WHERE julianday(last_seen_at)
                    >= julianday('now', '-7 days')"
            );

            $blockedUsers = $this->scalarInt(
                'SELECT COUNT(*)
                 FROM users
                 WHERE is_blocked = 1'
            );

            $userRequests = $this->scalarInt(
                'SELECT COALESCE(SUM(request_count), 0)
                 FROM users'
            );

            $privateChats = $this->scalarInt(
                "SELECT COUNT(*)
                 FROM chats
                 WHERE type = 'private'"
            );

            $groupChats = $this->scalarInt(
                "SELECT COUNT(*)
                 FROM chats
                 WHERE type IN ('group', 'supergroup')"
            );

            $activeChats = $this->scalarInt(
                'SELECT COUNT(*)
                 FROM chats
                 WHERE is_active = 1'
            );

            $updates = $this->scalarInt(
                'SELECT COUNT(*)
                 FROM processed_updates'
            );

            $completedUpdates = $this->scalarInt(
                "SELECT COUNT(*)
                 FROM processed_updates
                 WHERE status = 'completed'"
            );

            $failedUpdates = $this->scalarInt(
                "SELECT COUNT(*)
                 FROM processed_updates
                 WHERE status = 'failed'"
            );

            $processingUpdates = $this->scalarInt(
                "SELECT COUNT(*)
                 FROM processed_updates
                 WHERE status = 'processing'"
            );

            $preferences = $this->scalarInt(
                'SELECT COUNT(*)
                 FROM user_preferences'
            );

            $activeStates = $this->scalarInt(
                'SELECT COUNT(*)
                 FROM conversation_states
                 WHERE expires_at > :now',
                [
                    'now' => time(),
                ]
            );

            $activeRateLimits = $this->scalarInt(
                'SELECT COUNT(*)
                 FROM rate_limits
                 WHERE expires_at > :now',
                [
                    'now' => time(),
                ]
            );

            $broadcasts = $this->scalarInt(
                'SELECT COUNT(*)
                 FROM admin_broadcasts'
            );

            $broadcastSent = $this->scalarInt(
                'SELECT COALESCE(SUM(sent_count), 0)
                 FROM admin_broadcasts'
            );

            $broadcastFailed = $this->scalarInt(
                'SELECT COALESCE(SUM(failed_count), 0)
                 FROM admin_broadcasts'
            );

            $context->reply(
                "📊 آمار ربات\n\n"
                . "👥 کاربران: "
                . number_format($users)
                . "\n"
                . "🟢 فعال ۲۴ ساعت: "
                . number_format($activeUsers24Hours)
                . "\n"
                . "📅 فعال ۷ روز: "
                . number_format($activeUsers7Days)
                . "\n"
                . "🚫 کاربران مسدود: "
                . number_format($blockedUsers)
                . "\n"
                . "📨 درخواست‌های کاربران: "
                . number_format($userRequests)
                . "\n\n"
                . "💬 چت خصوصی: "
                . number_format($privateChats)
                . "\n"
                . "👨‍👩‍👧‍👦 گروه و سوپرگروه: "
                . number_format($groupChats)
                . "\n"
                . "✅ چت فعال: "
                . number_format($activeChats)
                . "\n\n"
                . "🔄 کل Updateها: "
                . number_format($updates)
                . "\n"
                . "✅ تکمیل‌شده: "
                . number_format($completedUpdates)
                . "\n"
                . "⚠️ ناموفق: "
                . number_format($failedUpdates)
                . "\n"
                . "⏳ در حال پردازش: "
                . number_format($processingUpdates)
                . "\n\n"
                . "⚙️ تنظیمات ذخیره‌شده: "
                . number_format($preferences)
                . "\n"
                . "🧩 State فعال: "
                . number_format($activeStates)
                . "\n"
                . "⏱ Rate limit فعال: "
                . number_format($activeRateLimits)
                . "\n\n"
                . "📣 ارسال‌های همگانی: "
                . number_format($broadcasts)
                . "\n"
                . "📤 پیام‌های ارسال‌شده: "
                . number_format($broadcastSent)
                . "\n"
                . "❌ ارسال‌های ناموفق: "
                . number_format($broadcastFailed)
            );
        } catch (Throwable $exception) {
            $this->handleError(
                $context,
                'stats',
                $exception
            );
        }
    }

    private function showUsers(
        MessageContext $context,
        string $arguments
    ): void {
        if (!$this->authorize($context)) {
            return;
        }

        try {
            $limit = $this->parseLimit(
                $arguments,
                10
            );

            $statement = $this->pdo->prepare(
                'SELECT
                    telegram_id,
                    first_name,
                    last_name,
                    username,
                    language_code,
                    request_count,
                    last_seen_at,
                    is_blocked
                 FROM users
                 ORDER BY last_seen_at DESC
                 LIMIT :limit'
            );

            $statement->bindValue(
                ':limit',
                $limit,
                PDO::PARAM_INT
            );

            $statement->execute();

            $rows = $statement->fetchAll(
                PDO::FETCH_ASSOC
            );

            if (!is_array($rows) || $rows === []) {
                $context->reply(
                    'هنوز کاربری ثبت نشده است.'
                );

                return;
            }

            $message = "👥 آخرین کاربران\n\n";

            foreach ($rows as $index => $row) {
                $name = $this->displayName(
                    $row
                );

                $username = is_string(
                    $row['username'] ?? null
                ) && trim($row['username']) !== ''
                    ? '@' . trim($row['username'])
                    : '—';

                $language = is_string(
                    $row['language_code'] ?? null
                ) && trim($row['language_code']) !== ''
                    ? trim($row['language_code'])
                    : '—';

                $status = (int) (
                    $row['is_blocked'] ?? 0
                ) === 1
                    ? '🚫 مسدود'
                    : '✅ فعال';

                $message .= ($index + 1)
                    . ") {$name}\n"
                    . "ID: "
                    . (string) (
                        $row['telegram_id'] ?? ''
                    )
                    . "\n"
                    . "Username: {$username}\n"
                    . "Language: {$language}\n"
                    . "Requests: "
                    . number_format(
                        (int) (
                            $row['request_count'] ?? 0
                        )
                    )
                    . "\n"
                    . "Last seen: "
                    . (string) (
                        $row['last_seen_at'] ?? '—'
                    )
                    . "\n"
                    . "Status: {$status}\n\n";
            }

            $context->reply(trim($message));
        } catch (Throwable $exception) {
            $this->handleError(
                $context,
                'users',
                $exception
            );
        }
    }

    private function showChats(
        MessageContext $context,
        string $arguments
    ): void {
        if (!$this->authorize($context)) {
            return;
        }

        try {
            $limit = $this->parseLimit(
                $arguments,
                10
            );

            $statement = $this->pdo->prepare(
                'SELECT
                    telegram_id,
                    type,
                    title,
                    username,
                    first_name,
                    last_name,
                    request_count,
                    last_seen_at,
                    is_active
                 FROM chats
                 ORDER BY last_seen_at DESC
                 LIMIT :limit'
            );

            $statement->bindValue(
                ':limit',
                $limit,
                PDO::PARAM_INT
            );

            $statement->execute();

            $rows = $statement->fetchAll(
                PDO::FETCH_ASSOC
            );

            if (!is_array($rows) || $rows === []) {
                $context->reply(
                    'هنوز چتی ثبت نشده است.'
                );

                return;
            }

            $message = "💬 آخرین چت‌ها\n\n";

            foreach ($rows as $index => $row) {
                $name = $this->chatDisplayName(
                    $row
                );

                $username = is_string(
                    $row['username'] ?? null
                ) && trim($row['username']) !== ''
                    ? '@' . trim($row['username'])
                    : '—';

                $type = $this->chatTypeLabel(
                    (string) (
                        $row['type'] ?? 'unknown'
                    )
                );

                $status = (int) (
                    $row['is_active'] ?? 0
                ) === 1
                    ? '✅ فعال'
                    : '🚫 غیرفعال';

                $message .= ($index + 1)
                    . ") {$name}\n"
                    . "Chat ID: "
                    . (string) (
                        $row['telegram_id'] ?? ''
                    )
                    . "\n"
                    . "Type: {$type}\n"
                    . "Username: {$username}\n"
                    . "Requests: "
                    . number_format(
                        (int) (
                            $row['request_count'] ?? 0
                        )
                    )
                    . "\n"
                    . "Last seen: "
                    . (string) (
                        $row['last_seen_at'] ?? '—'
                    )
                    . "\n"
                    . "Status: {$status}\n\n";
            }

            $context->reply(trim($message));
        } catch (Throwable $exception) {
            $this->handleError(
                $context,
                'chats',
                $exception
            );
        }
    }

    private function showHealth(
        MessageContext $context
    ): void {
        if (!$this->authorize($context)) {
            return;
        }

        try {
            $quickCheck = $this->pdo
                ->query('PRAGMA quick_check')
                ?->fetchColumn();

            $databaseSize = is_file(
                $this->databasePath
            )
                ? filesize($this->databasePath)
                : false;

            $storagePath = dirname(
                $this->databasePath
            );

            $diskFree = @disk_free_space(
                $storagePath
            );

            $diskTotal = @disk_total_space(
                $storagePath
            );

            $pendingBroadcastRecipients =
                $this->scalarInt(
                    "SELECT COUNT(*)
                     FROM admin_broadcast_recipients
                     WHERE status IN ('pending', 'processing')"
                );

            $failedUpdates = $this->scalarInt(
                "SELECT COUNT(*)
                 FROM processed_updates
                 WHERE status = 'failed'"
            );

            $message = "🩺 سلامت سیستم\n\n"
                . "PHP: "
                . PHP_VERSION
                . "\n"
                . "SAPI: "
                . PHP_SAPI
                . "\n"
                . "Timezone: "
                . date_default_timezone_get()
                . "\n"
                . "Server time: "
                . date(DATE_ATOM)
                . "\n\n"
                . "SQLite quick_check: "
                . (
                    is_string($quickCheck)
                    ? $quickCheck
                    : 'unknown'
                )
                . "\n"
                . "Database size: "
                . $this->formatBytes(
                    is_int($databaseSize)
                        ? $databaseSize
                        : 0
                )
                . "\n"
                . "Disk free: "
                . $this->formatBytes(
                    is_float($diskFree)
                    || is_int($diskFree)
                        ? (int) $diskFree
                        : 0
                )
                . "\n"
                . "Disk total: "
                . $this->formatBytes(
                    is_float($diskTotal)
                    || is_int($diskTotal)
                        ? (int) $diskTotal
                        : 0
                )
                . "\n\n"
                . "Memory now: "
                . $this->formatBytes(
                    memory_get_usage(true)
                )
                . "\n"
                . "Memory peak: "
                . $this->formatBytes(
                    memory_get_peak_usage(true)
                )
                . "\n"
                . "Memory limit: "
                . (string) ini_get('memory_limit')
                . "\n\n"
                . "Failed updates: "
                . number_format($failedUpdates)
                . "\n"
                . "Broadcast recipients pending: "
                . number_format(
                    $pendingBroadcastRecipients
                );

            $context->reply($message);
        } catch (Throwable $exception) {
            $this->handleError(
                $context,
                'health',
                $exception
            );
        }
    }

    private function handleBroadcastCommand(
        MessageContext $context,
        string $arguments
    ): void {
        if (!$this->authorize($context)) {
            return;
        }

        $message = trim($arguments);

        if ($message === '') {
            $this->askForBroadcast($context);

            return;
        }

        $this->states->clear(
            $context->actorKey()
        );

        $this->createBroadcast(
            $context,
            $message
        );
    }

    private function askForBroadcast(
        MessageContext $context
    ): void {
        if (!$this->authorize($context)) {
            return;
        }

        $this->states->set(
            $context->actorKey(),
            self::STATE_AWAITING_BROADCAST,
            ttlSeconds: $this->stateTtl
        );

        $context->reply(
            "متن پیام همگانی را بفرست. 📣\n\n"
            . "حداکثر طول: "
            . number_format(
                $this->safeMaxBroadcastLength()
            )
            . " کاراکتر\n\n"
            . "پیام فقط برای چت‌های خصوصی فعال "
            . "در صف قرار می‌گیرد.\n"
            . "هر بار حداکثر "
            . $this->safeBroadcastBatchSize()
            . " گیرنده پردازش می‌شود.\n\n"
            . "برای لغو: /cancel",
            [
                'reply_markup' => [
                    'force_reply' => true,
                    'selective' => true,
                    'input_field_placeholder' =>
                        'متن پیام مدیریت',
                ],
            ]
        );
    }

    private function handlePendingBroadcast(
        MessageContext $context,
        string $text
    ): bool {
        if (!$this->isAdmin($context)) {
            return false;
        }

        if (!$context->isPrivate()) {
            return false;
        }

        $state = $this->states->get(
            $context->actorKey()
        );

        if (
            $state === null
            || $state['state']
                !== self::STATE_AWAITING_BROADCAST
        ) {
            return false;
        }

        $message = trim($text);

        if ($message === '') {
            return true;
        }

        $this->states->clear(
            $context->actorKey()
        );

        $this->createBroadcast(
            $context,
            $message
        );

        return true;
    }

    private function createBroadcast(
        MessageContext $context,
        string $message
    ): void {
        try {
            $message = trim($message);

            if ($message === '') {
                throw new RuntimeException(
                    'Broadcast message cannot be empty.'
                );
            }

            if (
                mb_strlen($message)
                > $this->safeMaxBroadcastLength()
            ) {
                $context->reply(
                    "متن پیام بیش از حد طولانی است.\n\n"
                    . "حداکثر طول مجاز: "
                    . number_format(
                        $this->safeMaxBroadcastLength()
                    )
                    . ' کاراکتر'
                );

                return;
            }

            $adminUserId = $context->userId;

            if ($adminUserId === null) {
                throw new RuntimeException(
                    'Admin user ID is unavailable.'
                );
            }

            $this->pdo->beginTransaction();

            $insertBroadcast = $this->pdo->prepare(
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

            $insertBroadcast->execute([
                'admin_user_id' => $adminUserId,
                'message_text' => $message,
                'status' => 'pending',
                'created_at' => date(DATE_ATOM),
            ]);

            $broadcastId = (int) $this->pdo
                ->lastInsertId();

            $insertRecipients = $this->pdo->prepare(
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
                   AND telegram_id > 0"
            );

            $insertRecipients->execute([
                'broadcast_id' => $broadcastId,
            ]);

            $total = $this->scalarInt(
                'SELECT COUNT(*)
                 FROM admin_broadcast_recipients
                 WHERE broadcast_id = :broadcast_id',
                [
                    'broadcast_id' => $broadcastId,
                ]
            );

            $status = $total > 0
                ? 'pending'
                : 'completed';

            $updateBroadcast = $this->pdo->prepare(
                'UPDATE admin_broadcasts
                 SET
                    total_recipients = :total,
                    status = :status,
                    completed_at = CASE
                        WHEN :completed = 1
                        THEN :completed_at
                        ELSE completed_at
                    END
                 WHERE id = :broadcast_id'
            );

            $updateBroadcast->execute([
                'total' => $total,
                'status' => $status,
                'completed' => $total === 0 ? 1 : 0,
                'completed_at' => date(DATE_ATOM),
                'broadcast_id' => $broadcastId,
            ]);

            $this->pdo->commit();

            $context->reply(
                "صف پیام همگانی ساخته شد. ✅\n\n"
                . "شناسه: {$broadcastId}\n"
                . "گیرندگان: "
                . number_format($total)
                . "\n"
                . "Batch size: "
                . $this->safeBroadcastBatchSize()
            );

            if ($total > 0) {
                $this->processBroadcastBatch(
                    $context,
                    $broadcastId
                );
            }
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            $this->handleError(
                $context,
                'broadcast-create',
                $exception
            );
        }
    }

    private function processBroadcastBatchCommand(
        MessageContext $context,
        string $arguments
    ): void {
        if (!$this->authorize($context)) {
            return;
        }

        try {
            $broadcastId = $this->resolveBroadcastId(
                $arguments,
                [
                    'pending',
                    'running',
                ]
            );

            if ($broadcastId === null) {
                $context->reply(
                    "ارسال همگانی فعالی پیدا نشد.\n\n"
                    . "برای ساخت صف جدید:\n"
                    . "/broadcast متن پیام"
                );

                return;
            }

            $this->processBroadcastBatch(
                $context,
                $broadcastId
            );
        } catch (Throwable $exception) {
            $this->handleError(
                $context,
                'broadcast-next',
                $exception
            );
        }
    }

    private function processBroadcastBatch(
        MessageContext $context,
        int $broadcastId
    ): void {
        $broadcast = $this->getBroadcast(
            $broadcastId
        );

        if ($broadcast === null) {
            $context->reply(
                "ارسال همگانی با شناسه {$broadcastId} پیدا نشد."
            );

            return;
        }

        $status = (string) (
            $broadcast['status'] ?? ''
        );

        if ($status === 'completed') {
            $context->reply(
                "ارسال همگانی {$broadcastId} قبلاً تکمیل شده است."
            );

            return;
        }

        if ($status === 'cancelled') {
            $context->reply(
                "ارسال همگانی {$broadcastId} لغو شده است."
            );

            return;
        }

        $chatIds = $this->claimBroadcastRecipients(
            $broadcastId,
            $this->safeBroadcastBatchSize()
        );

        if ($chatIds === []) {
            $this->refreshBroadcastCounts(
                $broadcastId
            );

            $this->showBroadcastStatusById(
                $context,
                $broadcastId
            );

            return;
        }

        $messageText = (string) (
            $broadcast['message_text'] ?? ''
        );

        $sentInBatch = 0;
        $failedInBatch = 0;

        foreach ($chatIds as $chatId) {
            try {
                $this->telegram->sendMessage(
                    $chatId,
                    "📣 پیام مدیریت\n\n"
                    . $messageText
                );

                $this->markRecipientSent(
                    $broadcastId,
                    $chatId
                );

                $sentInBatch++;
            } catch (Throwable $exception) {
                $this->markRecipientFailed(
                    $broadcastId,
                    $chatId,
                    $exception
                );

                $failedInBatch++;
            }
        }

        $summary = $this->refreshBroadcastCounts(
            $broadcastId
        );

        $context->reply(
            "📨 Batch ارسال شد\n\n"
            . "شناسه: {$broadcastId}\n"
            . "موفق در این Batch: "
            . number_format($sentInBatch)
            . "\n"
            . "ناموفق در این Batch: "
            . number_format($failedInBatch)
            . "\n\n"
            . "کل گیرندگان: "
            . number_format($summary['total'])
            . "\n"
            . "کل موفق: "
            . number_format($summary['sent'])
            . "\n"
            . "کل ناموفق: "
            . number_format($summary['failed'])
            . "\n"
            . "باقی‌مانده: "
            . number_format($summary['pending'])
            . "\n"
            . "وضعیت: "
            . $this->broadcastStatusLabel(
                $summary['status']
            )
            . (
                $summary['pending'] > 0
                    ? "\n\nادامه: /broadcastnext {$broadcastId}"
                    : ''
            )
        );
    }

    /**
     * @return list<int>
     */
    private function claimBroadcastRecipients(
        int $broadcastId,
        int $limit
    ): array {
        $this->pdo->exec('BEGIN IMMEDIATE');

        try {
            /*
             * پردازش‌هایی که به‌دلیل قطع ناگهانی بیش از ده دقیقه
             * در وضعیت processing مانده‌اند، دوباره قابل دریافت می‌شوند.
             */
            $recover = $this->pdo->prepare(
                "UPDATE admin_broadcast_recipients
                 SET status = 'pending'
                 WHERE broadcast_id = :broadcast_id
                   AND status = 'processing'
                   AND (
                        attempted_at IS NULL
                        OR julianday(attempted_at)
                            < julianday('now', '-10 minutes')
                   )"
            );

            $recover->execute([
                'broadcast_id' => $broadcastId,
            ]);

            $select = $this->pdo->prepare(
                "SELECT chat_id
                 FROM admin_broadcast_recipients
                 WHERE broadcast_id = :broadcast_id
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
                        $chatIds[] = (int) $row;
                    }
                }
            }

            if ($chatIds !== []) {
                $claim = $this->pdo->prepare(
                    "UPDATE admin_broadcast_recipients
                     SET
                        status = 'processing',
                        attempts = attempts + 1,
                        attempted_at = :attempted_at
                     WHERE broadcast_id = :broadcast_id
                       AND chat_id = :chat_id
                       AND status = 'pending'"
                );

                foreach ($chatIds as $chatId) {
                    $claim->execute([
                        'attempted_at' => date(DATE_ATOM),
                        'broadcast_id' => $broadcastId,
                        'chat_id' => $chatId,
                    ]);
                }

                $start = $this->pdo->prepare(
                    "UPDATE admin_broadcasts
                     SET
                        status = 'running',
                        started_at = COALESCE(
                            started_at,
                            :started_at
                        )
                     WHERE id = :broadcast_id
                       AND status IN ('pending', 'running')"
                );

                $start->execute([
                    'started_at' => date(DATE_ATOM),
                    'broadcast_id' => $broadcastId,
                ]);
            }

            $this->pdo->commit();

            return $chatIds;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            } else {
                /*
                 * BEGIN IMMEDIATE از PDO::beginTransaction استفاده نکرده،
                 * بنابراین در برخی Driverها inTransaction ممکن است false باشد.
                 */
                try {
                    $this->pdo->exec('ROLLBACK');
                } catch (Throwable) {
                }
            }

            throw $exception;
        }
    }

    private function markRecipientSent(
        int $broadcastId,
        int $chatId
    ): void {
        $statement = $this->pdo->prepare(
            "UPDATE admin_broadcast_recipients
             SET
                status = 'sent',
                error_message = NULL,
                attempted_at = :attempted_at
             WHERE broadcast_id = :broadcast_id
               AND chat_id = :chat_id"
        );

        $statement->execute([
            'attempted_at' => date(DATE_ATOM),
            'broadcast_id' => $broadcastId,
            'chat_id' => $chatId,
        ]);

        $activateChat = $this->pdo->prepare(
            'UPDATE chats
             SET is_active = 1
             WHERE telegram_id = :chat_id'
        );

        $activateChat->execute([
            'chat_id' => $chatId,
        ]);
    }

    private function markRecipientFailed(
        int $broadcastId,
        int $chatId,
        Throwable $exception
    ): void {
        $errorMessage = mb_substr(
            $exception->getMessage(),
            0,
            1000
        );

        $statement = $this->pdo->prepare(
            "UPDATE admin_broadcast_recipients
             SET
                status = 'failed',
                error_message = :error_message,
                attempted_at = :attempted_at
             WHERE broadcast_id = :broadcast_id
               AND chat_id = :chat_id"
        );

        $statement->execute([
            'error_message' => $errorMessage,
            'attempted_at' => date(DATE_ATOM),
            'broadcast_id' => $broadcastId,
            'chat_id' => $chatId,
        ]);

        $normalizedError = mb_strtolower(
            $errorMessage
        );

        $permanentFailure = str_contains(
            $normalizedError,
            'bot was blocked'
        ) || str_contains(
            $normalizedError,
            'chat not found'
        ) || str_contains(
            $normalizedError,
            'user is deactivated'
        ) || str_contains(
            $normalizedError,
            'forbidden'
        );

        if ($permanentFailure) {
            $deactivateChat = $this->pdo->prepare(
                'UPDATE chats
                 SET is_active = 0
                 WHERE telegram_id = :chat_id'
            );

            $deactivateChat->execute([
                'chat_id' => $chatId,
            ]);

            $blockUser = $this->pdo->prepare(
                'UPDATE users
                 SET is_blocked = 1
                 WHERE last_chat_id = :chat_id'
            );

            $blockUser->execute([
                'chat_id' => $chatId,
            ]);
        }

        $this->log(
            'broadcast-send:' . $broadcastId
            . ':' . $chatId,
            $exception
        );
    }

    /**
     * @return array{
     *     total: int,
     *     sent: int,
     *     failed: int,
     *     pending: int,
     *     status: string
     * }
     */
    private function refreshBroadcastCounts(
        int $broadcastId
    ): array {
        $total = $this->scalarInt(
            'SELECT COUNT(*)
             FROM admin_broadcast_recipients
             WHERE broadcast_id = :broadcast_id',
            [
                'broadcast_id' => $broadcastId,
            ]
        );

        $sent = $this->scalarInt(
            "SELECT COUNT(*)
             FROM admin_broadcast_recipients
             WHERE broadcast_id = :broadcast_id
               AND status = 'sent'",
            [
                'broadcast_id' => $broadcastId,
            ]
        );

        $failed = $this->scalarInt(
            "SELECT COUNT(*)
             FROM admin_broadcast_recipients
             WHERE broadcast_id = :broadcast_id
               AND status = 'failed'",
            [
                'broadcast_id' => $broadcastId,
            ]
        );

        $pending = $this->scalarInt(
            "SELECT COUNT(*)
             FROM admin_broadcast_recipients
             WHERE broadcast_id = :broadcast_id
               AND status IN ('pending', 'processing')",
            [
                'broadcast_id' => $broadcastId,
            ]
        );

        $currentStatus = (string) (
            $this->scalar(
                'SELECT status
                 FROM admin_broadcasts
                 WHERE id = :broadcast_id',
                [
                    'broadcast_id' => $broadcastId,
                ]
            ) ?? ''
        );

        if ($currentStatus === 'cancelled') {
            $status = 'cancelled';
        } elseif ($pending === 0) {
            $status = 'completed';
        } else {
            $status = 'running';
        }

        $update = $this->pdo->prepare(
            'UPDATE admin_broadcasts
             SET
                total_recipients = :total,
                sent_count = :sent,
                failed_count = :failed,
                status = :status,
                completed_at = CASE
                    WHEN :is_completed = 1
                    THEN COALESCE(
                        completed_at,
                        :completed_at
                    )
                    ELSE NULL
                END
             WHERE id = :broadcast_id'
        );

        $update->execute([
            'total' => $total,
            'sent' => $sent,
            'failed' => $failed,
            'status' => $status,
            'is_completed' => $status === 'completed'
                ? 1
                : 0,
            'completed_at' => date(DATE_ATOM),
            'broadcast_id' => $broadcastId,
        ]);

        return [
            'total' => $total,
            'sent' => $sent,
            'failed' => $failed,
            'pending' => $pending,
            'status' => $status,
        ];
    }

    private function showBroadcastStatus(
        MessageContext $context,
        string $arguments
    ): void {
        if (!$this->authorize($context)) {
            return;
        }

        try {
            $broadcastId = $this->resolveBroadcastId(
                $arguments,
                [
                    'pending',
                    'running',
                    'completed',
                    'cancelled',
                ]
            );

            if ($broadcastId === null) {
                $context->reply(
                    'هنوز هیچ ارسال همگانی ثبت نشده است.'
                );

                return;
            }

            $this->showBroadcastStatusById(
                $context,
                $broadcastId
            );
        } catch (Throwable $exception) {
            $this->handleError(
                $context,
                'broadcast-status',
                $exception
            );
        }
    }

    private function showBroadcastStatusById(
        MessageContext $context,
        int $broadcastId
    ): void {
        $summary = $this->refreshBroadcastCounts(
            $broadcastId
        );

        $broadcast = $this->getBroadcast(
            $broadcastId
        );

        if ($broadcast === null) {
            $context->reply(
                "ارسال همگانی {$broadcastId} پیدا نشد."
            );

            return;
        }

        $preview = trim(
            (string) (
                $broadcast['message_text'] ?? ''
            )
        );

        if (mb_strlen($preview) > 300) {
            $preview = mb_substr(
                $preview,
                0,
                300
            ) . '…';
        }

        $context->reply(
            "📋 وضعیت ارسال همگانی\n\n"
            . "شناسه: {$broadcastId}\n"
            . "وضعیت: "
            . $this->broadcastStatusLabel(
                $summary['status']
            )
            . "\n"
            . "کل گیرندگان: "
            . number_format($summary['total'])
            . "\n"
            . "موفق: "
            . number_format($summary['sent'])
            . "\n"
            . "ناموفق: "
            . number_format($summary['failed'])
            . "\n"
            . "باقی‌مانده: "
            . number_format($summary['pending'])
            . "\n"
            . "ساخته‌شده: "
            . (string) (
                $broadcast['created_at'] ?? '—'
            )
            . "\n"
            . "شروع: "
            . (string) (
                $broadcast['started_at'] ?? '—'
            )
            . "\n"
            . "پایان: "
            . (string) (
                $broadcast['completed_at'] ?? '—'
            )
            . "\n\n"
            . "متن:\n"
            . $preview
            . (
                $summary['pending'] > 0
                    ? "\n\nادامه: /broadcastnext {$broadcastId}"
                    : ''
            )
            . (
                $summary['failed'] > 0
                    ? "\nRetry خطاها: /broadcastretry {$broadcastId}"
                    : ''
            )
        );
    }

    private function cancelBroadcast(
        MessageContext $context,
        string $arguments
    ): void {
        if (!$this->authorize($context)) {
            return;
        }

        try {
            $broadcastId = $this->resolveBroadcastId(
                $arguments,
                [
                    'pending',
                    'running',
                ]
            );

            if ($broadcastId === null) {
                $context->reply(
                    'ارسال فعال برای لغو پیدا نشد.'
                );

                return;
            }

            $statement = $this->pdo->prepare(
                "UPDATE admin_broadcasts
                 SET
                    status = 'cancelled',
                    cancelled_at = :cancelled_at
                 WHERE id = :broadcast_id
                   AND status IN ('pending', 'running')"
            );

            $statement->execute([
                'cancelled_at' => date(DATE_ATOM),
                'broadcast_id' => $broadcastId,
            ]);

            $context->reply(
                "ارسال همگانی {$broadcastId} لغو شد. ✅"
            );
        } catch (Throwable $exception) {
            $this->handleError(
                $context,
                'broadcast-cancel',
                $exception
            );
        }
    }

    private function retryBroadcast(
        MessageContext $context,
        string $arguments
    ): void {
        if (!$this->authorize($context)) {
            return;
        }

        try {
            $broadcastId = $this->resolveBroadcastId(
                $arguments,
                [
                    'pending',
                    'running',
                    'completed',
                ]
            );

            if ($broadcastId === null) {
                $context->reply(
                    'ارسالی برای Retry پیدا نشد.'
                );

                return;
            }

            $reset = $this->pdo->prepare(
                "UPDATE admin_broadcast_recipients
                 SET
                    status = 'pending',
                    error_message = NULL
                 WHERE broadcast_id = :broadcast_id
                   AND status = 'failed'"
            );

            $reset->execute([
                'broadcast_id' => $broadcastId,
            ]);

            $count = $reset->rowCount();

            if ($count === 0) {
                $context->reply(
                    "ارسال همگانی {$broadcastId} "
                    . "گیرنده ناموفقی برای Retry ندارد."
                );

                return;
            }

            $update = $this->pdo->prepare(
                "UPDATE admin_broadcasts
                 SET
                    status = 'running',
                    completed_at = NULL,
                    cancelled_at = NULL
                 WHERE id = :broadcast_id"
            );

            $update->execute([
                'broadcast_id' => $broadcastId,
            ]);

            $context->reply(
                number_format($count)
                . " گیرنده برای Retry آماده شد. ✅"
            );

            $this->processBroadcastBatch(
                $context,
                $broadcastId
            );
        } catch (Throwable $exception) {
            $this->handleError(
                $context,
                'broadcast-retry',
                $exception
            );
        }
    }

    /**
     * @param list<string> $statuses
     */
    private function resolveBroadcastId(
        string $arguments,
        array $statuses
    ): ?int {
        $arguments = trim($arguments);

        if ($arguments !== '') {
            if (
                preg_match(
                    '/^\d+$/',
                    $arguments
                ) !== 1
            ) {
                throw new RuntimeException(
                    'Broadcast ID must be a positive integer.'
                );
            }

            $broadcastId = (int) $arguments;

            return $broadcastId > 0
                ? $broadcastId
                : null;
        }

        $placeholders = [];

        foreach ($statuses as $index => $status) {
            $placeholders[] = ':status_' . $index;
        }

        $statement = $this->pdo->prepare(
            'SELECT id
             FROM admin_broadcasts
             WHERE status IN ('
             . implode(', ', $placeholders)
             . ')
             ORDER BY id DESC
             LIMIT 1'
        );

        foreach ($statuses as $index => $status) {
            $statement->bindValue(
                ':status_' . $index,
                $status,
                PDO::PARAM_STR
            );
        }

        $statement->execute();

        $result = $statement->fetchColumn();

        return is_numeric($result)
            ? (int) $result
            : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getBroadcast(
        int $broadcastId
    ): ?array {
        $statement = $this->pdo->prepare(
            'SELECT *
             FROM admin_broadcasts
             WHERE id = :broadcast_id
             LIMIT 1'
        );

        $statement->execute([
            'broadcast_id' => $broadcastId,
        ]);

        $row = $statement->fetch(
            PDO::FETCH_ASSOC
        );

        return is_array($row)
            ? $row
            : null;
    }

    private function authorize(
        MessageContext $context
    ): bool {
        if (!$this->isAdmin($context)) {
            $context->reply(
                '⛔️ دسترسی به این بخش مجاز نیست.'
            );

            return false;
        }

        if (!$context->isPrivate()) {
            $context->reply(
                "برای امنیت، پنل مدیریت فقط در چت خصوصی "
                . "ربات قابل استفاده است."
            );

            return false;
        }

        return true;
    }

    private function isAdmin(
        MessageContext $context
    ): bool {
        return $context->userId !== null
            && in_array(
                $context->userId,
                $this->adminUserIds,
                true
            );
    }

    private function parseLimit(
        string $arguments,
        int $default
    ): int {
        $arguments = trim($arguments);

        if ($arguments === '') {
            return $default;
        }

        if (
            preg_match('/^\d+$/', $arguments) !== 1
        ) {
            throw new RuntimeException(
                'Limit must be an integer.'
            );
        }

        return max(
            1,
            min(20, (int) $arguments)
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function displayName(array $row): string
    {
        $parts = [];

        foreach (
            [
                $row['first_name'] ?? null,
                $row['last_name'] ?? null,
            ] as $part
        ) {
            if (
                is_string($part)
                && trim($part) !== ''
            ) {
                $parts[] = trim($part);
            }
        }

        return $parts !== []
            ? implode(' ', $parts)
            : 'بدون نام';
    }

    /**
     * @param array<string, mixed> $row
     */
    private function chatDisplayName(
        array $row
    ): string {
        $title = $row['title'] ?? null;

        if (
            is_string($title)
            && trim($title) !== ''
        ) {
            return trim($title);
        }

        return $this->displayName($row);
    }

    private function chatTypeLabel(
        string $type
    ): string {
        return match ($type) {
            'private' => 'خصوصی',
            'group' => 'گروه',
            'supergroup' => 'سوپرگروه',
            'channel' => 'کانال',
            default => $type,
        };
    }

    private function broadcastStatusLabel(
        string $status
    ): string {
        return match ($status) {
            'pending' => '⏳ در صف',
            'running' => '📨 در حال ارسال',
            'completed' => '✅ تکمیل‌شده',
            'cancelled' => '🛑 لغوشده',
            default => $status,
        };
    }

    /**
     * @param array<string, int|string> $parameters
     */
    private function scalarInt(
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
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);

        return $statement->fetchColumn();
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }

        $units = [
            'B',
            'KB',
            'MB',
            'GB',
            'TB',
        ];

        $power = min(
            (int) floor(
                log($bytes, 1024)
            ),
            count($units) - 1
        );

        $value = $bytes
            / (1024 ** $power);

        return number_format(
            $value,
            $power === 0 ? 0 : 2
        ) . ' ' . $units[$power];
    }

    private function safeBroadcastBatchSize(): int
    {
        return max(
            1,
            min(20, $this->broadcastBatchSize)
        );
    }

    private function safeMaxBroadcastLength(): int
    {
        return max(
            100,
            min(3500, $this->maxBroadcastLength)
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function adminKeyboard(): array
    {
        return [
            'keyboard' => [
                [
                    [
                        'text' => '📊 آمار',
                    ],
                    [
                        'text' => '🩺 سلامت',
                    ],
                ],
                [
                    [
                        'text' => '👥 کاربران',
                    ],
                    [
                        'text' => '💬 چت‌ها',
                    ],
                ],
                [
                    [
                        'text' => '📣 پیام همگانی',
                    ],
                    [
                        'text' => '📨 ادامه ارسال',
                    ],
                ],
                [
                    [
                        'text' => '📋 وضعیت ارسال',
                    ],
                ],
                [
                    [
                        'text' => '🏠 منوی اصلی',
                    ],
                ],
            ],
            'resize_keyboard' => true,
            'one_time_keyboard' => false,
            'input_field_placeholder' =>
                'یکی از ابزارهای مدیریت را انتخاب کن',
        ];
    }

    private function handleError(
        MessageContext $context,
        string $operation,
        Throwable $exception
    ): void {
        $this->log(
            $operation,
            $exception
        );

        $context->reply(
            "اجرای عملیات مدیریت با خطا مواجه شد. ⚠️\n\n"
            . 'جزئیات در admin.log ثبت شد.'
        );
    }

    private function log(
        string $operation,
        Throwable $exception
    ): void {
        $directory = dirname(
            $this->logFile
        );

        if (!is_dir($directory)) {
            @mkdir(
                $directory,
                0700,
                true
            );
        }

        $entry = sprintf(
            "[%s] [operation:%s] %s\n%s\n\n",
            date(DATE_ATOM),
            str_replace(
                ["\r", "\n"],
                ' ',
                mb_substr($operation, 0, 150)
            ),
            $exception->getMessage(),
            $exception->getTraceAsString()
        );

        @file_put_contents(
            $this->logFile,
            $entry,
            FILE_APPEND | LOCK_EX
        );
    }
}
