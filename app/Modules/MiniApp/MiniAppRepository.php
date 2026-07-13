<?php

declare(strict_types=1);

namespace SmartToolbox\Modules\MiniApp;

use JsonException;
use PDO;
use Throwable;

final class MiniAppRepository
{
    public function __construct(
        private readonly PDO $pdo
    ) {
    }

    /**
     * @param array<string,mixed> $user
     */
    public function ensureUserAndPrivateChat(
        array $user
    ): void {
        $userId = $user['id'] ?? null;

        if (!is_int($userId) || $userId <= 0) {
            throw new MiniAppException(
                'شناسه کاربر Mini App معتبر نیست.',
                'user_invalid',
                500
            );
        }

        $now = date(DATE_ATOM);

        $this->pdo->exec('BEGIN IMMEDIATE');

        try {
            $userStatement = $this->pdo->prepare(
                'INSERT INTO users (
                    telegram_id,
                    is_bot,
                    first_name,
                    last_name,
                    username,
                    language_code,
                    is_premium,
                    first_seen_at,
                    last_seen_at,
                    last_chat_id,
                    request_count
                 ) VALUES (
                    :telegram_id,
                    0,
                    :first_name,
                    :last_name,
                    :username,
                    :language_code,
                    :is_premium,
                    :first_seen_at,
                    :last_seen_at,
                    :last_chat_id,
                    1
                 )
                 ON CONFLICT(telegram_id)
                 DO UPDATE SET
                    first_name = excluded.first_name,
                    last_name = excluded.last_name,
                    username = excluded.username,
                    language_code = excluded.language_code,
                    is_premium = excluded.is_premium,
                    last_seen_at = excluded.last_seen_at,
                    last_chat_id = excluded.last_chat_id,
                    request_count = users.request_count + 1'
            );

            $userStatement->execute([
                'telegram_id' => $userId,
                'first_name' => (string) (
                    $user['first_name'] ?? 'Telegram User'
                ),
                'last_name' => $user['last_name'] ?? null,
                'username' => $user['username'] ?? null,
                'language_code' => $user['language_code'] ?? null,
                'is_premium' => array_key_exists(
                    'is_premium',
                    $user
                ) && $user['is_premium'] !== null
                    ? ((bool) $user['is_premium'] ? 1 : 0)
                    : null,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
                'last_chat_id' => $userId,
            ]);

            $chatStatement = $this->pdo->prepare(
                'INSERT INTO chats (
                    telegram_id,
                    type,
                    title,
                    username,
                    first_name,
                    last_name,
                    first_seen_at,
                    last_seen_at,
                    request_count,
                    is_active
                 ) VALUES (
                    :telegram_id,
                    :type,
                    NULL,
                    :username,
                    :first_name,
                    :last_name,
                    :first_seen_at,
                    :last_seen_at,
                    1,
                    1
                 )
                 ON CONFLICT(telegram_id)
                 DO UPDATE SET
                    type = excluded.type,
                    username = excluded.username,
                    first_name = excluded.first_name,
                    last_name = excluded.last_name,
                    last_seen_at = excluded.last_seen_at,
                    request_count = chats.request_count + 1,
                    is_active = 1'
            );

            $chatStatement->execute([
                'telegram_id' => $userId,
                'type' => 'private',
                'username' => $user['username'] ?? null,
                'first_name' => (string) (
                    $user['first_name'] ?? 'Telegram User'
                ),
                'last_name' => $user['last_name'] ?? null,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
            ]);

            $this->pdo->exec('COMMIT');
        } catch (Throwable $exception) {
            try {
                $this->pdo->exec('ROLLBACK');
            } catch (Throwable) {
            }

            throw $exception;
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function dashboard(int $userId): array
    {
        $profile = $this->profile($userId);
        $score = $this->quizScore($userId);

        return [
            'profile' => $profile,
            'counts' => [
                'reminders' => $this->count(
                    "SELECT COUNT(*)
                     FROM reminders
                     WHERE user_id = :user_id
                       AND status IN ('pending', 'processing')",
                    ['user_id' => $userId]
                ),
                'alerts' => $this->count(
                    "SELECT COUNT(*)
                     FROM smart_alerts
                     WHERE user_id = :user_id
                       AND status IN ('active', 'paused')",
                    ['user_id' => $userId]
                ),
                'subscriptions' => $this->count(
                    "SELECT COUNT(*)
                     FROM smart_subscriptions
                     WHERE user_id = :user_id
                       AND status IN ('active', 'paused')",
                    ['user_id' => $userId]
                ),
                'monitors' => $this->count(
                    "SELECT COUNT(*)
                     FROM site_monitors
                     WHERE user_id = :user_id
                       AND status IN ('active', 'paused')",
                    ['user_id' => $userId]
                ),
                'monitors_down' => $this->count(
                    "SELECT COUNT(*)
                     FROM site_monitors
                     WHERE user_id = :user_id
                       AND status = 'active'
                       AND last_state = 'down'",
                    ['user_id' => $userId]
                ),
                'favorites' => $this->count(
                    'SELECT COUNT(*)
                     FROM user_favorites
                     WHERE user_id = :user_id',
                    ['user_id' => $userId]
                ),
                'shortcuts' => $this->count(
                    'SELECT COUNT(*)
                     FROM user_shortcuts
                     WHERE user_id = :user_id',
                    ['user_id' => $userId]
                ),
                'history' => $this->count(
                    'SELECT COUNT(*)
                     FROM command_history
                     WHERE user_id = :user_id',
                    ['user_id' => $userId]
                ),
            ],
            'next_reminders' => array_slice(
                $this->reminders($userId, 30),
                0,
                5
            ),
            'favorites' => array_slice(
                $this->favorites($userId),
                0,
                6
            ),
            'quiz' => $score,
        ];
    }

    /**
     * @return array<string,mixed>
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
                is_premium,
                first_seen_at,
                last_seen_at,
                request_count
             FROM users
             WHERE telegram_id = :user_id
             LIMIT 1'
        );
        $statement->execute(['user_id' => $userId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            throw new MiniAppException(
                'پروفایل کاربر پیدا نشد.',
                'profile_not_found',
                404
            );
        }

        return $row;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function reminders(
        int $userId,
        int $limit = 200
    ): array {
        $statement = $this->pdo->prepare(
            'SELECT
                id,
                reminder_text,
                timezone,
                scheduled_at,
                status,
                attempts,
                last_error,
                sent_at,
                cancelled_at,
                created_at,
                updated_at
             FROM reminders
             WHERE user_id = :user_id
             ORDER BY
                CASE
                    WHEN status IN (
                        \'pending\',
                        \'processing\'
                    ) THEN 0
                    ELSE 1
                END,
                CASE
                    WHEN status IN (
                        \'pending\',
                        \'processing\'
                    ) THEN scheduled_at
                    ELSE -id
                END ASC
             LIMIT :limit'
        );
        $statement->bindValue(
            ':user_id',
            $userId,
            PDO::PARAM_INT
        );
        $statement->bindValue(
            ':limit',
            max(1, min(500, $limit)),
            PDO::PARAM_INT
        );
        $statement->execute();

        return $this->rows($statement);
    }

    public function createReminder(
        int $userId,
        string $text,
        int $scheduledAt,
        string $timezone,
        int $maxFutureDays,
        int $maxPending,
        int $maxTextLength
    ): int {
        $text = trim($text);

        if (
            $text === ''
            || mb_strlen($text) > max(1, $maxTextLength)
        ) {
            throw new MiniAppException(
                'متن یادآور معتبر نیست.',
                'reminder_text_invalid'
            );
        }

        $now = time();

        if (
            $scheduledAt <= $now
            || $scheduledAt > $now + max(1, $maxFutureDays) * 86400
        ) {
            throw new MiniAppException(
                'زمان یادآور باید در آینده و داخل بازه مجاز باشد.',
                'reminder_time_invalid'
            );
        }

        if (
            $this->count(
                "SELECT COUNT(*)
                 FROM reminders
                 WHERE user_id = :user_id
                   AND status IN ('pending', 'processing')",
                ['user_id' => $userId]
            ) >= max(1, $maxPending)
        ) {
            throw new MiniAppException(
                'به سقف یادآورهای فعال رسیده‌ای.',
                'reminder_limit_reached',
                409
            );
        }

        $createdAt = date(DATE_ATOM);
        $statement = $this->pdo->prepare(
            'INSERT INTO reminders (
                user_id,
                chat_id,
                reminder_text,
                timezone,
                scheduled_at,
                next_attempt_at,
                status,
                attempts,
                created_at,
                updated_at
             ) VALUES (
                :user_id,
                :chat_id,
                :reminder_text,
                :timezone,
                :scheduled_at,
                :next_attempt_at,
                :status,
                0,
                :created_at,
                :updated_at
             )'
        );
        $statement->execute([
            'user_id' => $userId,
            'chat_id' => $userId,
            'reminder_text' => $text,
            'timezone' => $timezone,
            'scheduled_at' => $scheduledAt,
            'next_attempt_at' => $scheduledAt,
            'status' => 'pending',
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function cancelReminder(
        int $userId,
        int $reminderId
    ): bool {
        $statement = $this->pdo->prepare(
            "UPDATE reminders
             SET
                status = 'cancelled',
                cancelled_at = :cancelled_at,
                locked_at = NULL,
                updated_at = :updated_at
             WHERE id = :id
               AND user_id = :user_id
               AND status IN ('pending', 'failed')"
        );
        $now = date(DATE_ATOM);
        $statement->execute([
            'cancelled_at' => $now,
            'updated_at' => $now,
            'id' => $reminderId,
            'user_id' => $userId,
        ]);

        return $statement->rowCount() === 1;
    }

    public function deleteReminder(
        int $userId,
        int $reminderId
    ): bool {
        $statement = $this->pdo->prepare(
            "DELETE FROM reminders
             WHERE id = :id
               AND user_id = :user_id
               AND status IN ('sent', 'failed', 'cancelled')"
        );
        $statement->execute([
            'id' => $reminderId,
            'user_id' => $userId,
        ]);

        return $statement->rowCount() === 1;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function alerts(int $userId): array
    {
        $statement = $this->pdo->prepare(
            "SELECT *
             FROM smart_alerts
             WHERE user_id = :user_id
               AND status != 'cancelled'
             ORDER BY id DESC
             LIMIT 200"
        );
        $statement->execute(['user_id' => $userId]);

        return $this->rows($statement);
    }

    /**
     * @param array<string,mixed> $data
     */
    public function createAlert(
        int $userId,
        array $data,
        int $maxAlerts,
        int $defaultCooldown,
        float $defaultHysteresis,
        int $maxNotifications,
        int $checkInterval
    ): int {
        if (
            $this->count(
                "SELECT COUNT(*)
                 FROM smart_alerts
                 WHERE user_id = :user_id
                   AND status IN ('active', 'paused')",
                ['user_id' => $userId]
            ) >= max(1, $maxAlerts)
        ) {
            throw new MiniAppException(
                'به سقف هشدارهای فعال رسیده‌ای.',
                'alert_limit_reached',
                409
            );
        }

        $type = (string) ($data['alert_type'] ?? '');
        $operator = (string) ($data['operator'] ?? '');
        $subject = trim((string) ($data['subject'] ?? ''));
        $secondary = trim((string) ($data['secondary_subject'] ?? ''));

        if (!in_array(
            $type,
            ['weather_condition', 'temperature', 'wind', 'currency'],
            true
        )) {
            throw new MiniAppException(
                'نوع هشدار معتبر نیست.',
                'alert_type_invalid'
            );
        }

        if (!in_array(
            $operator,
            ['above', 'below', 'equals', 'changes', 'contains', 'starts', 'stops'],
            true
        )) {
            throw new MiniAppException(
                'عملگر هشدار معتبر نیست.',
                'alert_operator_invalid'
            );
        }

        if ($subject === '' || mb_strlen($subject) > 200) {
            throw new MiniAppException(
                'موضوع هشدار معتبر نیست.',
                'alert_subject_invalid'
            );
        }

        if ($type === 'currency' && $secondary === '') {
            throw new MiniAppException(
                'ارز مقصد را وارد کن.',
                'alert_secondary_subject_missing'
            );
        }

        $comparison = isset($data['comparison_value'])
            ? trim((string) $data['comparison_value'])
            : null;
        $threshold = isset($data['threshold_value'])
            && $data['threshold_value'] !== ''
            ? (float) $data['threshold_value']
            : null;

        if (
            in_array($operator, ['above', 'below', 'equals', 'changes'], true)
            && $threshold === null
            && !in_array($type, ['weather_condition'], true)
        ) {
            throw new MiniAppException(
                'مقدار عددی هشدار را وارد کن.',
                'alert_threshold_missing'
            );
        }

        if (
            in_array($operator, ['contains', 'starts', 'stops', 'equals'], true)
            && $type === 'weather_condition'
            && ($comparison === null || $comparison === '')
        ) {
            throw new MiniAppException(
                'شرط متنی هشدار را وارد کن.',
                'alert_comparison_missing'
            );
        }

        $now = date(DATE_ATOM);
        $statement = $this->pdo->prepare(
            'INSERT INTO smart_alerts (
                user_id,
                chat_id,
                alert_type,
                subject,
                secondary_subject,
                operator,
                comparison_value,
                threshold_value,
                cooldown_seconds,
                hysteresis,
                max_notifications_per_day,
                check_interval_seconds,
                next_check_at,
                status,
                created_at,
                updated_at
             ) VALUES (
                :user_id,
                :chat_id,
                :alert_type,
                :subject,
                :secondary_subject,
                :operator,
                :comparison_value,
                :threshold_value,
                :cooldown_seconds,
                :hysteresis,
                :max_notifications_per_day,
                :check_interval_seconds,
                :next_check_at,
                :status,
                :created_at,
                :updated_at
             )'
        );
        $statement->execute([
            'user_id' => $userId,
            'chat_id' => $userId,
            'alert_type' => $type,
            'subject' => $subject,
            'secondary_subject' => $secondary !== '' ? $secondary : null,
            'operator' => $operator,
            'comparison_value' => $comparison !== '' ? $comparison : null,
            'threshold_value' => $threshold,
            'cooldown_seconds' => max(0, $defaultCooldown),
            'hysteresis' => max(0.0, $defaultHysteresis),
            'max_notifications_per_day' => max(1, $maxNotifications),
            'check_interval_seconds' => max(60, $checkInterval),
            'next_check_at' => time(),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function setAlertStatus(
        int $userId,
        int $alertId,
        string $status
    ): bool {
        if (!in_array($status, ['active', 'paused', 'cancelled'], true)) {
            throw new MiniAppException(
                'وضعیت هشدار معتبر نیست.',
                'alert_status_invalid'
            );
        }

        $statement = $this->pdo->prepare(
            'UPDATE smart_alerts
             SET
                status = :status,
                next_check_at = CASE
                    WHEN :resume_status = \'active\'
                    THEN :next_check_at
                    ELSE next_check_at
                END,
                updated_at = :updated_at
             WHERE id = :id
               AND user_id = :user_id
               AND status != \'cancelled\''
        );
        $statement->execute([
            'status' => $status,
            'resume_status' => $status,
            'next_check_at' => time(),
            'updated_at' => date(DATE_ATOM),
            'id' => $alertId,
            'user_id' => $userId,
        ]);

        return $statement->rowCount() === 1;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function subscriptions(int $userId): array
    {
        $statement = $this->pdo->prepare(
            "SELECT *
             FROM smart_subscriptions
             WHERE user_id = :user_id
               AND status != 'cancelled'
             ORDER BY id DESC
             LIMIT 200"
        );
        $statement->execute(['user_id' => $userId]);

        return $this->rows($statement);
    }

    /**
     * @param array<string,mixed> $data
     */
    public function createSubscription(
        int $userId,
        array $data,
        int $nextRunAt,
        int $maxSubscriptions
    ): int {
        if (
            $this->count(
                "SELECT COUNT(*)
                 FROM smart_subscriptions
                 WHERE user_id = :user_id
                   AND status IN ('active', 'paused')",
                ['user_id' => $userId]
            ) >= max(1, $maxSubscriptions)
        ) {
            throw new MiniAppException(
                'به سقف اشتراک‌های فعال رسیده‌ای.',
                'subscription_limit_reached',
                409
            );
        }

        $type = (string) ($data['subscription_type'] ?? '');
        $frequency = (string) ($data['frequency'] ?? '');
        $subject = trim((string) ($data['subject'] ?? ''));
        $scheduleTime = (string) ($data['schedule_time'] ?? '');
        $timezone = trim((string) ($data['timezone'] ?? 'Asia/Tehran'));
        $weekday = isset($data['weekday']) && $data['weekday'] !== ''
            ? (int) $data['weekday']
            : null;
        $monthDay = isset($data['month_day']) && $data['month_day'] !== ''
            ? (int) $data['month_day']
            : null;

        if (!in_array($type, ['weather', 'country'], true)) {
            throw new MiniAppException(
                'نوع اشتراک معتبر نیست.',
                'subscription_type_invalid'
            );
        }

        if (!in_array($frequency, ['daily', 'weekly', 'monthly'], true)) {
            throw new MiniAppException(
                'تناوب اشتراک معتبر نیست.',
                'subscription_frequency_invalid'
            );
        }

        if ($subject === '' || mb_strlen($subject) > 200) {
            throw new MiniAppException(
                'موضوع اشتراک معتبر نیست.',
                'subscription_subject_invalid'
            );
        }

        $now = date(DATE_ATOM);
        $statement = $this->pdo->prepare(
            'INSERT INTO smart_subscriptions (
                user_id,
                chat_id,
                subscription_type,
                subject,
                frequency,
                schedule_time,
                weekday,
                month_day,
                timezone,
                next_run_at,
                status,
                created_at,
                updated_at
             ) VALUES (
                :user_id,
                :chat_id,
                :subscription_type,
                :subject,
                :frequency,
                :schedule_time,
                :weekday,
                :month_day,
                :timezone,
                :next_run_at,
                :status,
                :created_at,
                :updated_at
             )'
        );
        $statement->execute([
            'user_id' => $userId,
            'chat_id' => $userId,
            'subscription_type' => $type,
            'subject' => $subject,
            'frequency' => $frequency,
            'schedule_time' => $scheduleTime,
            'weekday' => $weekday,
            'month_day' => $monthDay,
            'timezone' => $timezone,
            'next_run_at' => $nextRunAt,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function setSubscriptionStatus(
        int $userId,
        int $subscriptionId,
        string $status,
        ?int $nextRunAt = null
    ): bool {
        if (!in_array($status, ['active', 'paused', 'cancelled'], true)) {
            throw new MiniAppException(
                'وضعیت اشتراک معتبر نیست.',
                'subscription_status_invalid'
            );
        }

        $statement = $this->pdo->prepare(
            'UPDATE smart_subscriptions
             SET
                status = :status,
                next_run_at = CASE
                    WHEN :resume_status = \'active\'
                    THEN COALESCE(:next_run_at, next_run_at)
                    ELSE next_run_at
                END,
                updated_at = :updated_at
             WHERE id = :id
               AND user_id = :user_id
               AND status != \'cancelled\''
        );
        $statement->execute([
            'status' => $status,
            'resume_status' => $status,
            'next_run_at' => $nextRunAt,
            'updated_at' => date(DATE_ATOM),
            'id' => $subscriptionId,
            'user_id' => $userId,
        ]);

        return $statement->rowCount() === 1;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function monitors(int $userId): array
    {
        $statement = $this->pdo->prepare(
            "SELECT
                m.*,
                (
                    SELECT COUNT(*)
                    FROM monitor_checks AS c
                    WHERE c.monitor_id = m.id
                      AND c.checked_at >= :cutoff
                ) AS checks_30d,
                (
                    SELECT COUNT(*)
                    FROM monitor_checks AS c
                    WHERE c.monitor_id = m.id
                      AND c.checked_at >= :cutoff2
                      AND c.state = 'up'
                ) AS up_checks_30d,
                (
                    SELECT ROUND(AVG(c.response_ms), 1)
                    FROM monitor_checks AS c
                    WHERE c.monitor_id = m.id
                      AND c.checked_at >= :cutoff3
                      AND c.response_ms IS NOT NULL
                ) AS average_response_ms_30d
             FROM site_monitors AS m
             WHERE m.user_id = :user_id
               AND m.status != 'cancelled'
             ORDER BY m.id DESC
             LIMIT 200"
        );
        $cutoff = time() - 30 * 86400;
        $statement->execute([
            'cutoff' => $cutoff,
            'cutoff2' => $cutoff,
            'cutoff3' => $cutoff,
            'user_id' => $userId,
        ]);
        $rows = $this->rows($statement);

        foreach ($rows as &$row) {
            $checks = (int) ($row['checks_30d'] ?? 0);
            $up = (int) ($row['up_checks_30d'] ?? 0);
            $row['uptime_percent_30d'] = $checks > 0
                ? round($up / $checks * 100, 2)
                : null;
        }
        unset($row);

        return $rows;
    }

    public function createMonitor(
        int $userId,
        string $url,
        string $normalizedUrl,
        int $intervalSeconds,
        string $timezone,
        int $maxMonitors
    ): int {
        if (
            $this->count(
                "SELECT COUNT(*)
                 FROM site_monitors
                 WHERE user_id = :user_id
                   AND status IN ('active', 'paused')",
                ['user_id' => $userId]
            ) >= max(1, $maxMonitors)
        ) {
            throw new MiniAppException(
                'به سقف مانیتورهای فعال رسیده‌ای.',
                'monitor_limit_reached',
                409
            );
        }

        $now = date(DATE_ATOM);
        $statement = $this->pdo->prepare(
            'INSERT INTO site_monitors (
                user_id,
                chat_id,
                url,
                normalized_url,
                interval_seconds,
                status,
                next_check_at,
                timezone,
                created_at,
                updated_at
             ) VALUES (
                :user_id,
                :chat_id,
                :url,
                :normalized_url,
                :interval_seconds,
                :status,
                :next_check_at,
                :timezone,
                :created_at,
                :updated_at
             )
             ON CONFLICT(user_id, normalized_url)
             DO UPDATE SET
                chat_id = excluded.chat_id,
                url = excluded.url,
                interval_seconds = excluded.interval_seconds,
                status = \'active\',
                next_check_at = excluded.next_check_at,
                timezone = excluded.timezone,
                updated_at = excluded.updated_at'
        );
        $statement->execute([
            'user_id' => $userId,
            'chat_id' => $userId,
            'url' => $url,
            'normalized_url' => $normalizedUrl,
            'interval_seconds' => $intervalSeconds,
            'status' => 'active',
            'next_check_at' => time(),
            'timezone' => $timezone,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $id = (int) $this->pdo->lastInsertId();

        if ($id > 0) {
            return $id;
        }

        $find = $this->pdo->prepare(
            'SELECT id
             FROM site_monitors
             WHERE user_id = :user_id
               AND normalized_url = :normalized_url
             LIMIT 1'
        );
        $find->execute([
            'user_id' => $userId,
            'normalized_url' => $normalizedUrl,
        ]);
        $existing = $find->fetchColumn();

        if (!is_numeric($existing)) {
            throw new MiniAppException(
                'مانیتور ذخیره نشد.',
                'monitor_create_failed',
                500
            );
        }

        return (int) $existing;
    }

    public function setMonitorStatus(
        int $userId,
        int $monitorId,
        string $status
    ): bool {
        if (!in_array($status, ['active', 'paused', 'cancelled'], true)) {
            throw new MiniAppException(
                'وضعیت مانیتور معتبر نیست.',
                'monitor_status_invalid'
            );
        }

        $statement = $this->pdo->prepare(
            'UPDATE site_monitors
             SET
                status = :status,
                next_check_at = CASE
                    WHEN :resume_status = \'active\'
                    THEN :next_check_at
                    ELSE next_check_at
                END,
                updated_at = :updated_at
             WHERE id = :id
               AND user_id = :user_id
               AND status != \'cancelled\''
        );
        $statement->execute([
            'status' => $status,
            'resume_status' => $status,
            'next_check_at' => time(),
            'updated_at' => date(DATE_ATOM),
            'id' => $monitorId,
            'user_id' => $userId,
        ]);

        return $statement->rowCount() === 1;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function favorites(
        int $userId,
        ?string $type = null,
        int $limit = 200
    ): array {
        $where = 'user_id = :user_id';
        $parameters = ['user_id' => $userId];

        if ($type !== null) {
            $where .= ' AND favorite_type = :favorite_type';
            $parameters['favorite_type'] = $type;
        }

        $statement = $this->pdo->prepare(
            "SELECT *
             FROM user_favorites
             WHERE {$where}
             ORDER BY
                is_pinned DESC,
                sort_order ASC,
                id DESC
             LIMIT :limit"
        );

        foreach ($parameters as $key => $value) {
            $statement->bindValue(
                ':' . $key,
                $value,
                $key === 'user_id'
                    ? PDO::PARAM_INT
                    : PDO::PARAM_STR
            );
        }

        $statement->bindValue(
            ':limit',
            max(1, min(500, $limit)),
            PDO::PARAM_INT
        );
        $statement->execute();
        $rows = $this->rows($statement);

        foreach ($rows as &$row) {
            $row['payload'] = $this->decodeJson(
                (string) ($row['payload_json'] ?? '{}')
            );
            unset($row['payload_json']);
        }
        unset($row);

        return $rows;
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function saveFavorite(
        int $userId,
        string $type,
        string $commandText,
        string $label,
        array $payload,
        int $maxFavorites
    ): int {
        $allowed = [
            'weather',
            'currency',
            'country',
            'wiki',
            'github',
            'calc',
        ];
        $type = mb_strtolower(trim($type));
        $commandText = trim($commandText);
        $label = trim($label);

        if (!in_array($type, $allowed, true)) {
            throw new MiniAppException(
                'نوع علاقه‌مندی معتبر نیست.',
                'favorite_type_invalid'
            );
        }

        if (
            $commandText === ''
            || mb_strlen($commandText) > 1000
            || $label === ''
            || mb_strlen($label) > 200
        ) {
            throw new MiniAppException(
                'اطلاعات علاقه‌مندی معتبر نیست.',
                'favorite_invalid'
            );
        }

        $existing = $this->count(
            'SELECT COUNT(*)
             FROM user_favorites
             WHERE user_id = :user_id
               AND favorite_type = :favorite_type
               AND command_text = :command_text',
            [
                'user_id' => $userId,
                'favorite_type' => $type,
                'command_text' => $commandText,
            ]
        );

        if (
            $existing === 0
            && $this->count(
                'SELECT COUNT(*)
                 FROM user_favorites
                 WHERE user_id = :user_id',
                ['user_id' => $userId]
            ) >= max(1, $maxFavorites)
        ) {
            throw new MiniAppException(
                'به سقف علاقه‌مندی‌ها رسیده‌ای.',
                'favorite_limit_reached',
                409
            );
        }

        try {
            $payloadJson = json_encode(
                $payload,
                JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
            );
        } catch (JsonException) {
            throw new MiniAppException(
                'اطلاعات علاقه‌مندی قابل ذخیره نیست.',
                'favorite_payload_invalid'
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
             ON CONFLICT(user_id, favorite_type, command_text)
             DO UPDATE SET
                label = excluded.label,
                payload_json = excluded.payload_json,
                updated_at = excluded.updated_at'
        );
        $statement->execute([
            'user_id' => $userId,
            'favorite_type' => $type,
            'command_text' => $commandText,
            'label' => $label,
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
            'command_text' => $commandText,
        ]);

        return (int) $find->fetchColumn();
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

    /**
     * @return list<array<string,mixed>>
     */
    public function shortcuts(int $userId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT *
             FROM user_shortcuts
             WHERE user_id = :user_id
             ORDER BY shortcut_name ASC
             LIMIT 200'
        );
        $statement->execute(['user_id' => $userId]);

        return $this->rows($statement);
    }

    public function saveShortcut(
        int $userId,
        string $name,
        string $commandText,
        int $maxShortcuts
    ): int {
        $name = mb_strtolower(
            ltrim(trim($name), '/')
        );
        $commandText = trim($commandText);

        if (
            preg_match('/^[a-z][a-z0-9_]{2,31}$/', $name) !== 1
        ) {
            throw new MiniAppException(
                'نام میان‌بر باید ۳ تا ۳۲ نویسه انگلیسی، عدد یا زیرخط باشد.',
                'shortcut_name_invalid'
            );
        }

        if (
            $commandText === ''
            || mb_strlen($commandText) > 1000
        ) {
            throw new MiniAppException(
                'دستور میان‌بر معتبر نیست.',
                'shortcut_command_invalid'
            );
        }

        $target = mb_strtolower(
            strtok($commandText, " \t\r\n") ?: ''
        );
        $allowedTargets = [
            'weather', 'currency', 'country', 'countrycode',
            'wiki', 'github', 'release', 'issues', 'calc',
            'convert', 'remind', 'json', 'jsonpath', 'base64',
            'base64decode', 'urlencode', 'urldecode', 'jwtdecode',
            'regex', 'uuid', 'ulid', 'hash', 'timestamp', 'cron',
            'color', 'ip', 'useragent',
        ];

        if (!in_array($target, $allowedTargets, true)) {
            throw new MiniAppException(
                'دستور هدف میان‌بر مجاز نیست.',
                'shortcut_target_invalid'
            );
        }

        $existing = $this->count(
            'SELECT COUNT(*)
             FROM user_shortcuts
             WHERE user_id = :user_id
               AND shortcut_name = :shortcut_name',
            [
                'user_id' => $userId,
                'shortcut_name' => $name,
            ]
        );

        if (
            $existing === 0
            && $this->count(
                'SELECT COUNT(*)
                 FROM user_shortcuts
                 WHERE user_id = :user_id',
                ['user_id' => $userId]
            ) >= max(1, $maxShortcuts)
        ) {
            throw new MiniAppException(
                'به سقف میان‌برها رسیده‌ای.',
                'shortcut_limit_reached',
                409
            );
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
            'command_text' => $commandText,
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
            'shortcut_name' => mb_strtolower(
                ltrim(trim($name), '/')
            ),
        ]);

        return $statement->rowCount() === 1;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function history(
        int $userId,
        int $limit = 100
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
            max(1, min(500, $limit)),
            PDO::PARAM_INT
        );
        $statement->execute();

        return $this->rows($statement);
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
     * @return array<string,mixed>
     */
    public function quiz(int $userId): array
    {
        $score = $this->quizScore($userId);

        $achievements = $this->pdo->prepare(
            'SELECT
                a.id,
                a.code,
                a.name,
                a.description,
                a.icon,
                a.metric,
                a.threshold,
                ua.unlocked_at
             FROM quiz_achievements AS a
             LEFT JOIN quiz_user_achievements AS ua
                ON ua.achievement_id = a.id
               AND ua.user_id = :user_id
             WHERE a.enabled = 1
             ORDER BY
                CASE WHEN ua.unlocked_at IS NULL THEN 1 ELSE 0 END,
                a.sort_order ASC,
                a.threshold ASC'
        );
        $achievements->execute(['user_id' => $userId]);

        $recent = $this->pdo->prepare(
            'SELECT
                id,
                mode,
                category_name,
                difficulty,
                question_text,
                status,
                is_correct,
                score_awarded,
                xp_awarded,
                started_at,
                answered_at,
                daily_date
             FROM quiz_sessions
             WHERE user_id = :user_id
             ORDER BY id DESC
             LIMIT 30'
        );
        $recent->execute(['user_id' => $userId]);

        $rank = $this->pdo->prepare(
            'SELECT 1 + COUNT(*)
             FROM quiz_user_scores
             WHERE score > :score
                OR (
                    score = :score_equal
                    AND xp > :xp
                )'
        );
        $rank->execute([
            'score' => (int) $score['score'],
            'score_equal' => (int) $score['score'],
            'xp' => (int) $score['xp'],
        ]);

        return [
            'score' => $score,
            'global_rank' => (int) $rank->fetchColumn(),
            'achievements' => $this->rows($achievements),
            'recent_sessions' => $this->rows($recent),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function quizScore(int $userId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT *
             FROM quiz_user_scores
             WHERE user_id = :user_id
             LIMIT 1'
        );
        $statement->execute(['user_id' => $userId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if (is_array($row)) {
            return $row;
        }

        return [
            'user_id' => $userId,
            'score' => 0,
            'xp' => 0,
            'level' => 1,
            'total_answers' => 0,
            'correct_answers' => 0,
            'wrong_answers' => 0,
            'current_correct_streak' => 0,
            'longest_correct_streak' => 0,
            'daily_streak' => 0,
            'longest_daily_streak' => 0,
            'last_activity_date' => null,
            'last_daily_challenge_date' => null,
            'daily_challenges' => 0,
            'daily_correct' => 0,
            'math_correct' => 0,
            'word_correct' => 0,
        ];
    }

    /**
     * @return array<string,string>
     */
    public function settings(int $userId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT preference_key, preference_value
             FROM user_preferences
             WHERE actor_key = :actor_key
             ORDER BY preference_key ASC'
        );
        $statement->execute([
            'actor_key' => 'user:' . $userId,
        ]);

        $settings = [
            'timezone' => 'Asia/Tehran',
            'output_language' => 'fa',
            'number_format' => 'latin',
            'date_format' => 'iso',
            'menu_order' => 'default',
        ];

        while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
            if (
                is_string($row['preference_key'] ?? null)
                && is_string($row['preference_value'] ?? null)
            ) {
                $settings[$row['preference_key']] =
                    $row['preference_value'];
            }
        }

        return $settings;
    }

    /**
     * @param array<string,string> $settings
     */
    public function updateSettings(
        int $userId,
        array $settings
    ): array {
        $allowed = [
            'timezone' => null,
            'output_language' => ['fa', 'en'],
            'number_format' => ['latin', 'persian'],
            'date_format' => ['iso', 'local'],
            'menu_order' => null,
        ];

        $statement = $this->pdo->prepare(
            'INSERT INTO user_preferences (
                actor_key,
                preference_key,
                preference_value,
                updated_at
             ) VALUES (
                :actor_key,
                :preference_key,
                :preference_value,
                :updated_at
             )
             ON CONFLICT(actor_key, preference_key)
             DO UPDATE SET
                preference_value = excluded.preference_value,
                updated_at = excluded.updated_at'
        );

        foreach ($settings as $key => $value) {
            if (!array_key_exists($key, $allowed)) {
                continue;
            }

            $value = trim($value);

            if ($key === 'timezone') {
                try {
                    new \DateTimeZone($value);
                } catch (Throwable) {
                    throw new MiniAppException(
                        'منطقه زمانی معتبر نیست.',
                        'timezone_invalid'
                    );
                }
            } elseif ($key === 'menu_order') {
                if (
                    $value !== 'default'
                    && preg_match(
                        '/^[a-z_,]{3,1000}$/',
                        $value
                    ) !== 1
                ) {
                    throw new MiniAppException(
                        'ترتیب منو معتبر نیست.',
                        'menu_order_invalid'
                    );
                }
            } elseif (
                is_array($allowed[$key])
                && !in_array($value, $allowed[$key], true)
            ) {
                throw new MiniAppException(
                    'مقدار تنظیمات معتبر نیست.',
                    'preference_value_invalid'
                );
            }

            if (strlen($value) > 1000) {
                throw new MiniAppException(
                    'مقدار تنظیمات بیش از حد طولانی است.',
                    'preference_value_too_long'
                );
            }

            $statement->execute([
                'actor_key' => 'user:' . $userId,
                'preference_key' => $key,
                'preference_value' => $value,
                'updated_at' => date(DATE_ATOM),
            ]);
        }

        return $this->settings($userId);
    }

    /**
     * @param array<string,int|float|string> $parameters
     */
    private function count(
        string $sql,
        array $parameters = []
    ): int {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);

        return (int) $statement->fetchColumn();
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function rows(\PDOStatement $statement): array
    {
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeJson(string $json): array
    {
        try {
            $decoded = json_decode(
                $json,
                true,
                64,
                JSON_THROW_ON_ERROR
            );

            return is_array($decoded) ? $decoded : [];
        } catch (JsonException) {
            return [];
        }
    }
}
