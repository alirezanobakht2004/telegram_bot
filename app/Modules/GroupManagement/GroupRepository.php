<?php

declare(strict_types=1);

namespace SmartToolbox\Modules\GroupManagement;

use JsonException;
use PDO;
use RuntimeException;
use Throwable;

final class GroupRepository
{
    /**
     * @var array<int, array<string, mixed>>
     */
    private array $settingsCache = [];

    /**
     * @param array<string, int|string|null> $defaultSettings
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly array $defaultSettings = []
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function settings(int $chatId): array
    {
        if (isset($this->settingsCache[$chatId])) {
            return $this->settingsCache[$chatId];
        }

        $now = date(DATE_ATOM);

        $defaults = [
            'warnings_threshold' => 3,
            'warning_action' => 'mute',
            'warning_action_duration_seconds' => 3600,
            'anti_spam_enabled' => 0,
            'flood_max_messages' => 6,
            'flood_window_seconds' => 10,
            'duplicate_max_messages' => 3,
            'duplicate_window_seconds' => 30,
            'anti_link_enabled' => 0,
            'bad_words_enabled' => 0,
            'captcha_enabled' => 0,
            'captcha_timeout_seconds' => 120,
            'captcha_max_attempts' => 3,
            'captcha_failure_action' => 'kick',
            'welcome_enabled' => 0,
            'goodbye_enabled' => 0,
            'bot_slow_mode_seconds' => 0,
            'join_request_mode' => 'manual',
            ...$this->defaultSettings,
        ];

        $statement = $this->pdo->prepare(
            'INSERT OR IGNORE INTO group_settings (
                chat_id,
                warnings_threshold,
                warning_action,
                warning_action_duration_seconds,
                anti_spam_enabled,
                flood_max_messages,
                flood_window_seconds,
                duplicate_max_messages,
                duplicate_window_seconds,
                anti_link_enabled,
                bad_words_enabled,
                captcha_enabled,
                captcha_timeout_seconds,
                captcha_max_attempts,
                captcha_failure_action,
                welcome_enabled,
                goodbye_enabled,
                bot_slow_mode_seconds,
                join_request_mode,
                created_at,
                updated_at
             ) VALUES (
                :chat_id,
                :warnings_threshold,
                :warning_action,
                :warning_action_duration_seconds,
                :anti_spam_enabled,
                :flood_max_messages,
                :flood_window_seconds,
                :duplicate_max_messages,
                :duplicate_window_seconds,
                :anti_link_enabled,
                :bad_words_enabled,
                :captcha_enabled,
                :captcha_timeout_seconds,
                :captcha_max_attempts,
                :captcha_failure_action,
                :welcome_enabled,
                :goodbye_enabled,
                :bot_slow_mode_seconds,
                :join_request_mode,
                :created_at,
                :updated_at
             )'
        );

        $statement->execute([
            'chat_id' => $chatId,
            'warnings_threshold' => (int) $defaults['warnings_threshold'],
            'warning_action' => (string) $defaults['warning_action'],
            'warning_action_duration_seconds' => (int) $defaults['warning_action_duration_seconds'],
            'anti_spam_enabled' => (int) $defaults['anti_spam_enabled'],
            'flood_max_messages' => (int) $defaults['flood_max_messages'],
            'flood_window_seconds' => (int) $defaults['flood_window_seconds'],
            'duplicate_max_messages' => (int) $defaults['duplicate_max_messages'],
            'duplicate_window_seconds' => (int) $defaults['duplicate_window_seconds'],
            'anti_link_enabled' => (int) $defaults['anti_link_enabled'],
            'bad_words_enabled' => (int) $defaults['bad_words_enabled'],
            'captcha_enabled' => (int) $defaults['captcha_enabled'],
            'captcha_timeout_seconds' => (int) $defaults['captcha_timeout_seconds'],
            'captcha_max_attempts' => (int) $defaults['captcha_max_attempts'],
            'captcha_failure_action' => (string) $defaults['captcha_failure_action'],
            'welcome_enabled' => (int) $defaults['welcome_enabled'],
            'goodbye_enabled' => (int) $defaults['goodbye_enabled'],
            'bot_slow_mode_seconds' => (int) $defaults['bot_slow_mode_seconds'],
            'join_request_mode' => (string) $defaults['join_request_mode'],
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $select = $this->pdo->prepare(
            'SELECT *
             FROM group_settings
             WHERE chat_id = :chat_id
             LIMIT 1'
        );

        $select->execute([
            'chat_id' => $chatId,
        ]);

        $row = $select->fetch(
            PDO::FETCH_ASSOC
        );

        if (!is_array($row)) {
            throw new RuntimeException(
                'Group settings could not be loaded.'
            );
        }

        return $this->settingsCache[$chatId] =
            $row;
    }

    /**
     * @param array<string, int|string|null> $values
     */
    public function updateSettings(
        int $chatId,
        array $values
    ): void {
        $allowed = [
            'warnings_threshold',
            'warning_action',
            'warning_action_duration_seconds',
            'anti_spam_enabled',
            'flood_max_messages',
            'flood_window_seconds',
            'duplicate_max_messages',
            'duplicate_window_seconds',
            'anti_link_enabled',
            'bad_words_enabled',
            'captcha_enabled',
            'captcha_timeout_seconds',
            'captcha_max_attempts',
            'captcha_failure_action',
            'welcome_enabled',
            'welcome_message',
            'goodbye_enabled',
            'goodbye_message',
            'rules_text',
            'bot_slow_mode_seconds',
            'join_request_mode',
        ];

        $filtered = [];

        foreach ($values as $key => $value) {
            if (in_array($key, $allowed, true)) {
                $filtered[$key] = $value;
            }
        }

        if ($filtered === []) {
            return;
        }

        $this->settings($chatId);

        $assignments = [];
        $parameters = [
            'chat_id' => $chatId,
            'updated_at' => date(DATE_ATOM),
        ];

        foreach ($filtered as $key => $value) {
            $assignments[] = $key
                . ' = :' . $key;

            $parameters[$key] = $value;
        }

        $assignments[] =
            'updated_at = :updated_at';

        $statement = $this->pdo->prepare(
            'UPDATE group_settings
             SET ' . implode(', ', $assignments) . '
             WHERE chat_id = :chat_id'
        );

        $statement->execute($parameters);

        unset($this->settingsCache[$chatId]);
    }

    public function addWarning(
        int $chatId,
        int $userId,
        int $adminId,
        ?string $reason
    ): int {
        $statement = $this->pdo->prepare(
            'INSERT INTO group_warnings (
                chat_id,
                user_id,
                admin_id,
                reason,
                active,
                created_at
             ) VALUES (
                :chat_id,
                :user_id,
                :admin_id,
                :reason,
                1,
                :created_at
             )'
        );

        $statement->execute([
            'chat_id' => $chatId,
            'user_id' => $userId,
            'admin_id' => $adminId,
            'reason' => $reason !== null
                ? mb_substr($reason, 0, 1000)
                : null,
            'created_at' => date(DATE_ATOM),
        ]);

        return (int) $this->pdo
            ->lastInsertId();
    }

    public function activeWarningCount(
        int $chatId,
        int $userId
    ): int {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM group_warnings
             WHERE chat_id = :chat_id
               AND user_id = :user_id
               AND active = 1'
        );

        $statement->execute([
            'chat_id' => $chatId,
            'user_id' => $userId,
        ]);

        return (int) $statement
            ->fetchColumn();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function warnings(
        int $chatId,
        int $userId,
        int $limit = 20
    ): array {
        $statement = $this->pdo->prepare(
            'SELECT
                w.id,
                w.reason,
                w.active,
                w.created_at,
                w.revoked_at,
                w.admin_id,
                u.first_name AS admin_first_name,
                u.username AS admin_username
             FROM group_warnings AS w
             LEFT JOIN users AS u
                ON u.telegram_id = w.admin_id
             WHERE w.chat_id = :chat_id
               AND w.user_id = :user_id
             ORDER BY w.id DESC
             LIMIT :limit'
        );

        $statement->bindValue(
            ':chat_id',
            $chatId,
            PDO::PARAM_INT
        );

        $statement->bindValue(
            ':user_id',
            $userId,
            PDO::PARAM_INT
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

    public function revokeWarning(
        int $chatId,
        int $warningId,
        int $adminId
    ): bool {
        $statement = $this->pdo->prepare(
            'UPDATE group_warnings
             SET
                active = 0,
                revoked_at = :revoked_at,
                revoked_by = :revoked_by
             WHERE id = :id
               AND chat_id = :chat_id
               AND active = 1'
        );

        $statement->execute([
            'revoked_at' => date(DATE_ATOM),
            'revoked_by' => $adminId,
            'id' => $warningId,
            'chat_id' => $chatId,
        ]);

        return $statement->rowCount() === 1;
    }

    public function revokeLatestWarning(
        int $chatId,
        int $userId,
        int $adminId
    ): ?int {
        $statement = $this->pdo->prepare(
            'SELECT id
             FROM group_warnings
             WHERE chat_id = :chat_id
               AND user_id = :user_id
               AND active = 1
             ORDER BY id DESC
             LIMIT 1'
        );

        $statement->execute([
            'chat_id' => $chatId,
            'user_id' => $userId,
        ]);

        $id = $statement->fetchColumn();

        if (!is_numeric($id)) {
            return null;
        }

        return $this->revokeWarning(
            $chatId,
            (int) $id,
            $adminId
        )
            ? (int) $id
            : null;
    }

    public function clearWarnings(
        int $chatId,
        int $userId,
        ?int $adminId
    ): int {
        $statement = $this->pdo->prepare(
            'UPDATE group_warnings
             SET
                active = 0,
                revoked_at = :revoked_at,
                revoked_by = :revoked_by
             WHERE chat_id = :chat_id
               AND user_id = :user_id
               AND active = 1'
        );

        $statement->execute([
            'revoked_at' => date(DATE_ATOM),
            'revoked_by' => $adminId,
            'chat_id' => $chatId,
            'user_id' => $userId,
        ]);

        return $statement->rowCount();
    }

    public function addSanction(
        int $chatId,
        int $userId,
        ?int $adminId,
        string $type,
        ?int $untilAt,
        ?string $reason,
        bool $telegramApplied = true
    ): int {
        $statement = $this->pdo->prepare(
            'INSERT INTO group_sanctions (
                chat_id,
                user_id,
                admin_id,
                sanction_type,
                status,
                reason,
                until_at,
                telegram_applied,
                created_at
             ) VALUES (
                :chat_id,
                :user_id,
                :admin_id,
                :sanction_type,
                :status,
                :reason,
                :until_at,
                :telegram_applied,
                :created_at
             )'
        );

        $statement->execute([
            'chat_id' => $chatId,
            'user_id' => $userId,
            'admin_id' => $adminId,
            'sanction_type' => $type,
            'status' => 'active',
            'reason' => $reason !== null
                ? mb_substr($reason, 0, 1000)
                : null,
            'until_at' => $untilAt,
            'telegram_applied' =>
                $telegramApplied ? 1 : 0,
            'created_at' => date(DATE_ATOM),
        ]);

        return (int) $this->pdo
            ->lastInsertId();
    }

    public function revokeActiveSanctions(
        int $chatId,
        int $userId,
        array $types
    ): int {
        if ($types === []) {
            return 0;
        }

        $placeholders = implode(
            ', ',
            array_fill(
                0,
                count($types),
                '?'
            )
        );

        $statement = $this->pdo->prepare(
            "UPDATE group_sanctions
             SET
                status = 'revoked',
                lifted_at = ?
             WHERE chat_id = ?
               AND user_id = ?
               AND status = 'active'
               AND sanction_type IN (
                    {$placeholders}
               )"
        );

        $statement->execute([
            date(DATE_ATOM),
            $chatId,
            $userId,
            ...$types,
        ]);

        return $statement->rowCount();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function dueSanctions(
        int $limit
    ): array {
        $statement = $this->pdo->prepare(
            "SELECT *
             FROM group_sanctions
             WHERE status = 'active'
               AND until_at IS NOT NULL
               AND until_at <= :now
               AND sanction_type IN (
                    'mute',
                    'ban',
                    'warning_action'
               )
             ORDER BY until_at ASC
             LIMIT :limit"
        );

        $statement->bindValue(
            ':now',
            time(),
            PDO::PARAM_INT
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

    public function completeSanction(
        int $sanctionId,
        bool $success,
        ?string $error = null
    ): void {
        $statement = $this->pdo->prepare(
            'UPDATE group_sanctions
             SET
                status = :status,
                lifted_at = :lifted_at,
                last_error = :last_error
             WHERE id = :id'
        );

        $statement->execute([
            'status' => $success
                ? 'expired'
                : 'failed',
            'lifted_at' => date(DATE_ATOM),
            'last_error' => $error !== null
                ? mb_substr($error, 0, 1000)
                : null,
            'id' => $sanctionId,
        ]);
    }

    public function addDomain(
        int $chatId,
        string $domain,
        int $adminId
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO group_domain_whitelist (
                chat_id,
                domain,
                created_by,
                created_at
             ) VALUES (
                :chat_id,
                :domain,
                :created_by,
                :created_at
             )
             ON CONFLICT(chat_id, domain)
             DO NOTHING'
        );

        $statement->execute([
            'chat_id' => $chatId,
            'domain' => $this->normalizeDomain(
                $domain
            ),
            'created_by' => $adminId,
            'created_at' => date(DATE_ATOM),
        ]);
    }

    public function removeDomain(
        int $chatId,
        string $domain
    ): bool {
        $statement = $this->pdo->prepare(
            'DELETE FROM group_domain_whitelist
             WHERE chat_id = :chat_id
               AND domain = :domain'
        );

        $statement->execute([
            'chat_id' => $chatId,
            'domain' => $this->normalizeDomain(
                $domain
            ),
        ]);

        return $statement->rowCount() === 1;
    }

    /**
     * @return list<string>
     */
    public function domains(int $chatId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT domain
             FROM group_domain_whitelist
             WHERE chat_id = :chat_id
             ORDER BY domain ASC'
        );

        $statement->execute([
            'chat_id' => $chatId,
        ]);

        $rows = $statement->fetchAll(
            PDO::FETCH_COLUMN
        );

        return is_array($rows)
            ? array_values(
                array_filter(
                    $rows,
                    'is_string'
                )
            )
            : [];
    }

    public function addBadWord(
        int $chatId,
        string $word,
        int $adminId
    ): void {
        $display = trim($word);
        $normalized = $this->normalizeText(
            $display
        );

        if ($normalized === '') {
            throw new RuntimeException(
                'Bad word cannot be empty.'
            );
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO group_bad_words (
                chat_id,
                normalized_word,
                display_word,
                created_by,
                created_at
             ) VALUES (
                :chat_id,
                :normalized_word,
                :display_word,
                :created_by,
                :created_at
             )
             ON CONFLICT(
                chat_id,
                normalized_word
             ) DO UPDATE SET
                display_word =
                    excluded.display_word'
        );

        $statement->execute([
            'chat_id' => $chatId,
            'normalized_word' => $normalized,
            'display_word' => mb_substr(
                $display,
                0,
                200
            ),
            'created_by' => $adminId,
            'created_at' => date(DATE_ATOM),
        ]);
    }

    public function removeBadWord(
        int $chatId,
        string $word
    ): bool {
        $statement = $this->pdo->prepare(
            'DELETE FROM group_bad_words
             WHERE chat_id = :chat_id
               AND normalized_word =
                    :normalized_word'
        );

        $statement->execute([
            'chat_id' => $chatId,
            'normalized_word' =>
                $this->normalizeText($word),
        ]);

        return $statement->rowCount() === 1;
    }

    public function clearBadWords(int $chatId): int
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM group_bad_words
             WHERE chat_id = :chat_id'
        );

        $statement->execute([
            'chat_id' => $chatId,
        ]);

        return $statement->rowCount();
    }

    /**
     * @return list<string>
     */
    public function badWords(int $chatId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT display_word
             FROM group_bad_words
             WHERE chat_id = :chat_id
             ORDER BY normalized_word ASC'
        );

        $statement->execute([
            'chat_id' => $chatId,
        ]);

        $rows = $statement->fetchAll(
            PDO::FETCH_COLUMN
        );

        return is_array($rows)
            ? array_values(
                array_filter(
                    $rows,
                    'is_string'
                )
            )
            : [];
    }

    /**
     * @param array<string, mixed> $settings
     * @return array{
     *     slow_mode: bool,
     *     flood: bool,
     *     duplicate: bool
     * }
     */
    public function recordActivity(
        int $chatId,
        int $userId,
        string $textHash,
        int $now,
        array $settings
    ): array {
        $this->pdo->exec('BEGIN IMMEDIATE');

        try {
            $statement = $this->pdo->prepare(
                'SELECT *
                 FROM group_member_activity
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

            $windowSeconds = max(
                1,
                (int) (
                    $settings[
                        'flood_window_seconds'
                    ] ?? 10
                )
            );

            $duplicateWindow = max(
                1,
                (int) (
                    $settings[
                        'duplicate_window_seconds'
                    ] ?? 30
                )
            );

            $windowStartedAt = is_array($row)
                ? (int) $row[
                    'window_started_at'
                ]
                : $now;

            $messageCount = is_array($row)
                ? (int) $row['message_count']
                : 0;

            if (
                $windowStartedAt <= 0
                || $now - $windowStartedAt
                    > $windowSeconds
            ) {
                $windowStartedAt = $now;
                $messageCount = 1;
            } else {
                $messageCount++;
            }

            $lastMessageAt = is_array($row)
                ? (int) $row[
                    'last_message_at'
                ]
                : 0;

            $lastHash = is_array($row)
                ? (string) (
                    $row['last_text_hash']
                    ?? ''
                )
                : '';

            $duplicateCount = is_array($row)
                ? (int) $row[
                    'duplicate_count'
                ]
                : 0;

            if (
                $textHash !== ''
                && $textHash === $lastHash
                && $lastMessageAt > 0
                && $now - $lastMessageAt
                    <= $duplicateWindow
            ) {
                $duplicateCount++;
            } else {
                $duplicateCount =
                    $textHash !== ''
                        ? 1
                        : 0;
            }

            $slowModeSeconds = max(
                0,
                (int) (
                    $settings[
                        'bot_slow_mode_seconds'
                    ] ?? 0
                )
            );

            $violations = [
                'slow_mode' =>
                    $slowModeSeconds > 0
                    && $lastMessageAt > 0
                    && $now - $lastMessageAt
                        < $slowModeSeconds,

                'flood' => $messageCount
                    > max(
                        2,
                        (int) (
                            $settings[
                                'flood_max_messages'
                            ] ?? 6
                        )
                    ),

                'duplicate' =>
                    $textHash !== ''
                    && $duplicateCount
                        > max(
                            1,
                            (int) (
                                $settings[
                                    'duplicate_max_messages'
                                ] ?? 3
                            )
                        ),
            ];

            $upsert = $this->pdo->prepare(
                'INSERT INTO group_member_activity (
                    chat_id,
                    user_id,
                    window_started_at,
                    message_count,
                    last_message_at,
                    last_text_hash,
                    duplicate_count,
                    last_violation_at,
                    updated_at
                 ) VALUES (
                    :chat_id,
                    :user_id,
                    :window_started_at,
                    :message_count,
                    :last_message_at,
                    :last_text_hash,
                    :duplicate_count,
                    :last_violation_at,
                    :updated_at
                 )
                 ON CONFLICT(chat_id, user_id)
                 DO UPDATE SET
                    window_started_at =
                        excluded.window_started_at,
                    message_count =
                        excluded.message_count,
                    last_message_at =
                        excluded.last_message_at,
                    last_text_hash =
                        excluded.last_text_hash,
                    duplicate_count =
                        excluded.duplicate_count,
                    last_violation_at =
                        excluded.last_violation_at,
                    updated_at =
                        excluded.updated_at'
            );

            $upsert->execute([
                'chat_id' => $chatId,
                'user_id' => $userId,
                'window_started_at' =>
                    $windowStartedAt,
                'message_count' => $messageCount,
                'last_message_at' => $now,
                'last_text_hash' => $textHash !== ''
                    ? $textHash
                    : null,
                'duplicate_count' =>
                    $duplicateCount,
                'last_violation_at' =>
                    in_array(
                        true,
                        $violations,
                        true
                    )
                        ? $now
                        : (
                            is_array($row)
                                ? $row[
                                    'last_violation_at'
                                ]
                                : null
                        ),
                'updated_at' => date(DATE_ATOM),
            ]);

            $this->pdo->exec('COMMIT');

            return $violations;
        } catch (Throwable $exception) {
            try {
                $this->pdo->exec('ROLLBACK');
            } catch (Throwable) {
            }

            throw $exception;
        }
    }

    public function createCaptcha(
        int $chatId,
        int $userId,
        string $question,
        string $answer,
        int $maxAttempts,
        int $expiresAt
    ): int {
        $cancel = $this->pdo->prepare(
            "UPDATE group_captcha_challenges
             SET
                status = 'cancelled',
                completed_at = :completed_at
             WHERE chat_id = :chat_id
               AND user_id = :user_id
               AND status = 'pending'"
        );

        $cancel->execute([
            'completed_at' => date(DATE_ATOM),
            'chat_id' => $chatId,
            'user_id' => $userId,
        ]);

        $statement = $this->pdo->prepare(
            'INSERT INTO group_captcha_challenges (
                chat_id,
                user_id,
                question,
                correct_answer,
                status,
                attempts,
                max_attempts,
                expires_at,
                created_at
             ) VALUES (
                :chat_id,
                :user_id,
                :question,
                :correct_answer,
                :status,
                0,
                :max_attempts,
                :expires_at,
                :created_at
             )'
        );

        $statement->execute([
            'chat_id' => $chatId,
            'user_id' => $userId,
            'question' => $question,
            'correct_answer' => $answer,
            'status' => 'pending',
            'max_attempts' => max(
                1,
                min(10, $maxAttempts)
            ),
            'expires_at' => $expiresAt,
            'created_at' => date(DATE_ATOM),
        ]);

        return (int) $this->pdo
            ->lastInsertId();
    }

    public function setCaptchaMessage(
        int $challengeId,
        int $messageId
    ): void {
        $statement = $this->pdo->prepare(
            'UPDATE group_captcha_challenges
             SET message_id = :message_id
             WHERE id = :id'
        );

        $statement->execute([
            'message_id' => $messageId,
            'id' => $challengeId,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function captcha(int $challengeId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT *
             FROM group_captcha_challenges
             WHERE id = :id
             LIMIT 1'
        );

        $statement->execute([
            'id' => $challengeId,
        ]);

        $row = $statement->fetch(
            PDO::FETCH_ASSOC
        );

        return is_array($row)
            ? $row
            : null;
    }

    /**
     * @return array{
     *     status: string,
     *     attempts: int,
     *     max_attempts: int
     * }
     */
    public function answerCaptcha(
        int $challengeId,
        int $userId,
        string $answer
    ): array {
        $this->pdo->exec('BEGIN IMMEDIATE');

        try {
            $challenge = $this->captcha(
                $challengeId
            );

            if (
                $challenge === null
                || (int) $challenge['user_id']
                    !== $userId
                || $challenge['status']
                    !== 'pending'
            ) {
                $this->pdo->exec('COMMIT');

                return [
                    'status' => 'invalid',
                    'attempts' => 0,
                    'max_attempts' => 0,
                ];
            }

            if (
                (int) $challenge['expires_at']
                < time()
            ) {
                $status = 'expired';
            } elseif (
                hash_equals(
                    (string) $challenge[
                        'correct_answer'
                    ],
                    trim($answer)
                )
            ) {
                $status = 'passed';
            } else {
                $attempts =
                    (int) $challenge[
                        'attempts'
                    ] + 1;

                $status = $attempts
                    >= (int) $challenge[
                        'max_attempts'
                    ]
                        ? 'failed'
                        : 'pending';

                $update = $this->pdo->prepare(
                    'UPDATE group_captcha_challenges
                     SET
                        attempts = :attempts,
                        status = :status,
                        completed_at =
                            :completed_at
                     WHERE id = :id'
                );

                $update->execute([
                    'attempts' => $attempts,
                    'status' => $status,
                    'completed_at' =>
                        $status === 'pending'
                            ? null
                            : date(DATE_ATOM),
                    'id' => $challengeId,
                ]);

                $this->pdo->exec('COMMIT');

                return [
                    'status' => $status,
                    'attempts' => $attempts,
                    'max_attempts' =>
                        (int) $challenge[
                            'max_attempts'
                        ],
                ];
            }

            $update = $this->pdo->prepare(
                'UPDATE group_captcha_challenges
                 SET
                    status = :status,
                    completed_at =
                        :completed_at
                 WHERE id = :id'
            );

            $update->execute([
                'status' => $status,
                'completed_at' => date(DATE_ATOM),
                'id' => $challengeId,
            ]);

            $this->pdo->exec('COMMIT');

            return [
                'status' => $status,
                'attempts' =>
                    (int) $challenge[
                        'attempts'
                    ],
                'max_attempts' =>
                    (int) $challenge[
                        'max_attempts'
                    ],
            ];
        } catch (Throwable $exception) {
            try {
                $this->pdo->exec('ROLLBACK');
            } catch (Throwable) {
            }

            throw $exception;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function expiredCaptchas(int $limit): array
    {
        $statement = $this->pdo->prepare(
            "SELECT *
             FROM group_captcha_challenges
             WHERE status = 'pending'
               AND expires_at <= :now
             ORDER BY expires_at ASC
             LIMIT :limit"
        );

        $statement->bindValue(
            ':now',
            time(),
            PDO::PARAM_INT
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

    public function finishCaptcha(
        int $challengeId,
        string $status
    ): void {
        $statement = $this->pdo->prepare(
            'UPDATE group_captcha_challenges
             SET
                status = :status,
                completed_at = :completed_at
             WHERE id = :id'
        );

        $statement->execute([
            'status' => $status,
            'completed_at' => date(DATE_ATOM),
            'id' => $challengeId,
        ]);
    }

    /**
     * @param array<string, mixed> $link
     */
    public function storeInviteLink(
        int $chatId,
        int $adminId,
        array $link
    ): int {
        $inviteLink = $link[
            'invite_link'
        ] ?? null;

        if (!is_string($inviteLink)) {
            throw new RuntimeException(
                'Telegram invite link is missing.'
            );
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO group_invite_links (
                chat_id,
                created_by,
                invite_link,
                link_name,
                expire_at,
                member_limit,
                creates_join_request,
                status,
                created_at
             ) VALUES (
                :chat_id,
                :created_by,
                :invite_link,
                :link_name,
                :expire_at,
                :member_limit,
                :creates_join_request,
                :status,
                :created_at
             )'
        );

        $statement->execute([
            'chat_id' => $chatId,
            'created_by' => $adminId,
            'invite_link' => $inviteLink,
            'link_name' => isset($link['name'])
                ? (string) $link['name']
                : null,
            'expire_at' => is_int(
                $link['expire_date'] ?? null
            )
                ? $link['expire_date']
                : null,
            'member_limit' => is_int(
                $link['member_limit'] ?? null
            )
                ? $link['member_limit']
                : null,
            'creates_join_request' =>
                ($link[
                    'creates_join_request'
                ] ?? false)
                    ? 1
                    : 0,
            'status' => 'active',
            'created_at' => date(DATE_ATOM),
        ]);

        return (int) $this->pdo
            ->lastInsertId();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function inviteLink(
        int $chatId,
        int $id
    ): ?array {
        $statement = $this->pdo->prepare(
            'SELECT *
             FROM group_invite_links
             WHERE id = :id
               AND chat_id = :chat_id
             LIMIT 1'
        );

        $statement->execute([
            'id' => $id,
            'chat_id' => $chatId,
        ]);

        $row = $statement->fetch(
            PDO::FETCH_ASSOC
        );

        return is_array($row)
            ? $row
            : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function inviteLinks(
        int $chatId,
        int $limit = 20
    ): array {
        $statement = $this->pdo->prepare(
            'SELECT *
             FROM group_invite_links
             WHERE chat_id = :chat_id
             ORDER BY id DESC
             LIMIT :limit'
        );

        $statement->bindValue(
            ':chat_id',
            $chatId,
            PDO::PARAM_INT
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

    public function markInviteRevoked(int $id): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE group_invite_links
             SET
                status = 'revoked',
                revoked_at = :revoked_at
             WHERE id = :id"
        );

        $statement->execute([
            'revoked_at' => date(DATE_ATOM),
            'id' => $id,
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    /**
     * @param array<string, mixed> $payload
     */
    public function storeJoinRequest(
        array $payload
    ): int {
        $chatId = $payload['chat']['id']
            ?? null;
        $userId = $payload['from']['id']
            ?? null;

        if (!is_int($chatId) || !is_int($userId)) {
            throw new RuntimeException(
                'Join request identifiers are invalid.'
            );
        }

        $userChatId = is_int(
            $payload['user_chat_id'] ?? null
        )
            ? $payload['user_chat_id']
            : null;

        $bio = is_string(
            $payload['bio'] ?? null
        )
            ? mb_substr(
                $payload['bio'],
                0,
                500
            )
            : null;

        $inviteLink = is_string(
            $payload[
                'invite_link'
            ]['invite_link'] ?? null
        )
            ? $payload[
                'invite_link'
            ]['invite_link']
            : null;

        $requestedAt = date(
            DATE_ATOM,
            is_int($payload['date'] ?? null)
                ? $payload['date']
                : time()
        );

        $this->pdo->exec('BEGIN IMMEDIATE');

        try {
            $insert = $this->pdo->prepare(
                "INSERT OR IGNORE INTO group_join_requests (
                    chat_id,
                    user_id,
                    user_chat_id,
                    bio,
                    invite_link,
                    status,
                    requested_at
                 ) VALUES (
                    :chat_id,
                    :user_id,
                    :user_chat_id,
                    :bio,
                    :invite_link,
                    'pending',
                    :requested_at
                 )"
            );

            $insert->execute([
                'chat_id' => $chatId,
                'user_id' => $userId,
                'user_chat_id' => $userChatId,
                'bio' => $bio,
                'invite_link' => $inviteLink,
                'requested_at' => $requestedAt,
            ]);

            $update = $this->pdo->prepare(
                "UPDATE group_join_requests
                 SET
                    user_chat_id = :user_chat_id,
                    bio = :bio,
                    invite_link = :invite_link,
                    requested_at = :requested_at
                 WHERE chat_id = :chat_id
                   AND user_id = :user_id
                   AND status = 'pending'"
            );

            $update->execute([
                'user_chat_id' => $userChatId,
                'bio' => $bio,
                'invite_link' => $inviteLink,
                'requested_at' => $requestedAt,
                'chat_id' => $chatId,
                'user_id' => $userId,
            ]);

            $select = $this->pdo->prepare(
                "SELECT id
                 FROM group_join_requests
                 WHERE chat_id = :chat_id
                   AND user_id = :user_id
                   AND status = 'pending'
                 LIMIT 1"
            );

            $select->execute([
                'chat_id' => $chatId,
                'user_id' => $userId,
            ]);

            $id = $select->fetchColumn();

            if (!is_numeric($id)) {
                throw new RuntimeException(
                    'Pending join request could not be stored.'
                );
            }

            $this->pdo->exec('COMMIT');

            return (int) $id;
        } catch (Throwable $exception) {
            try {
                $this->pdo->exec('ROLLBACK');
            } catch (Throwable) {
            }

            throw $exception;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function joinRequest(
        int $chatId,
        int $id
    ): ?array {
        $statement = $this->pdo->prepare(
            'SELECT
                r.*,
                u.first_name,
                u.last_name,
                u.username
             FROM group_join_requests AS r
             LEFT JOIN users AS u
                ON u.telegram_id = r.user_id
             WHERE r.id = :id
               AND r.chat_id = :chat_id
             LIMIT 1'
        );

        $statement->execute([
            'id' => $id,
            'chat_id' => $chatId,
        ]);

        $row = $statement->fetch(
            PDO::FETCH_ASSOC
        );

        return is_array($row)
            ? $row
            : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function pendingJoinRequests(
        int $chatId,
        int $limit = 20
    ): array {
        $statement = $this->pdo->prepare(
            "SELECT
                r.*,
                u.first_name,
                u.last_name,
                u.username
             FROM group_join_requests AS r
             LEFT JOIN users AS u
                ON u.telegram_id = r.user_id
             WHERE r.chat_id = :chat_id
               AND r.status = 'pending'
             ORDER BY r.id ASC
             LIMIT :limit"
        );

        $statement->bindValue(
            ':chat_id',
            $chatId,
            PDO::PARAM_INT
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

    public function resolveJoinRequest(
        int $chatId,
        int $id,
        string $status,
        ?int $adminId
    ): bool {
        $statement = $this->pdo->prepare(
            "UPDATE group_join_requests
             SET
                status = :status,
                resolved_at = :resolved_at,
                resolved_by = :resolved_by
             WHERE id = :id
               AND chat_id = :chat_id
               AND status = 'pending'"
        );

        $statement->execute([
            'status' => $status,
            'resolved_at' => date(DATE_ATOM),
            'resolved_by' => $adminId,
            'id' => $id,
            'chat_id' => $chatId,
        ]);

        return $statement->rowCount() === 1;
    }

    public function claimAutomodNotice(
        int $chatId,
        int $userId,
        int $cooldownSeconds
    ): bool {
        $cooldownSeconds = max(
            5,
            min(3600, $cooldownSeconds)
        );

        $cutoff = date(
            DATE_ATOM,
            time() - $cooldownSeconds
        );

        $statement = $this->pdo->prepare(
            "SELECT COUNT(*)
             FROM group_audit_logs
             WHERE chat_id = :chat_id
               AND target_user_id = :user_id
               AND action = 'automod.notice'
               AND julianday(created_at)
                    >= julianday(:cutoff)"
        );

        $statement->execute([
            'chat_id' => $chatId,
            'user_id' => $userId,
            'cutoff' => $cutoff,
        ]);

        if (
            (int) $statement->fetchColumn()
            > 0
        ) {
            return false;
        }

        $this->audit(
            $chatId,
            null,
            $userId,
            'automod.notice',
            [
                'cooldown_seconds' =>
                    $cooldownSeconds,
            ]
        );

        return true;
    }

    /**
     * @param array<string, mixed> $details
     */
    public function audit(
        int $chatId,
        ?int $actorId,
        ?int $targetUserId,
        string $action,
        array $details = [],
        bool $success = true,
        ?string $error = null
    ): void {
        try {
            $detailsJson = json_encode(
                $details,
                JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
            );
        } catch (JsonException) {
            $detailsJson = '{}';
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO group_audit_logs (
                chat_id,
                actor_id,
                target_user_id,
                action,
                details_json,
                success,
                error_message,
                created_at
             ) VALUES (
                :chat_id,
                :actor_id,
                :target_user_id,
                :action,
                :details_json,
                :success,
                :error_message,
                :created_at
             )'
        );

        $statement->execute([
            'chat_id' => $chatId,
            'actor_id' => $actorId,
            'target_user_id' => $targetUserId,
            'action' => mb_substr(
                $action,
                0,
                100
            ),
            'details_json' => $detailsJson,
            'success' => $success ? 1 : 0,
            'error_message' => $error !== null
                ? mb_substr($error, 0, 1000)
                : null,
            'created_at' => date(DATE_ATOM),
        ]);
    }

    public function prune(int $retentionDays): int
    {
        $cutoff = date(
            DATE_ATOM,
            time() - (
                max(1, $retentionDays)
                * 86400
            )
        );

        $total = 0;

        foreach ([
            'group_audit_logs',
            'group_warnings',
            'group_sanctions',
            'group_captcha_challenges',
        ] as $table) {
            $column = $table ===
                'group_captcha_challenges'
                    ? 'created_at'
                    : 'created_at';

            $statement = $this->pdo->prepare(
                "DELETE FROM {$table}
                 WHERE {$column} < :cutoff"
                . (
                    $table ===
                        'group_warnings'
                        ? ' AND active = 0'
                        : ''
                )
                . (
                    $table ===
                        'group_sanctions'
                        ? " AND status != 'active'"
                        : ''
                )
                . (
                    $table ===
                        'group_captcha_challenges'
                        ? " AND status != 'pending'"
                        : ''
                )
            );

            $statement->execute([
                'cutoff' => $cutoff,
            ]);

            $total += $statement->rowCount();
        }

        $activityCutoff = date(
            DATE_ATOM,
            time() - 7 * 86400
        );

        $activity = $this->pdo->prepare(
            'DELETE FROM group_member_activity
             WHERE updated_at < :cutoff'
        );

        $activity->execute([
            'cutoff' => $activityCutoff,
        ]);

        $roles = $this->pdo->prepare(
            'DELETE FROM group_member_roles
             WHERE checked_at < :cutoff'
        );

        $roles->execute([
            'cutoff' => time()
                - 30 * 86400,
        ]);

        return $total
            + $activity->rowCount()
            + $roles->rowCount();
    }

    public function resolveUserToken(
        string $token
    ): ?int {
        $token = trim($token);

        if (
            preg_match(
                '/^-?\d+$/',
                $token
            ) === 1
        ) {
            $id = (int) $token;

            return $id !== 0
                ? $id
                : null;
        }

        if (!str_starts_with($token, '@')) {
            return null;
        }

        $username = ltrim(
            mb_strtolower($token),
            '@'
        );

        if ($username === '') {
            return null;
        }

        $statement = $this->pdo->prepare(
            'SELECT telegram_id
             FROM users
             WHERE LOWER(username) = :username
             LIMIT 1'
        );

        $statement->execute([
            'username' => $username,
        ]);

        $id = $statement->fetchColumn();

        return is_numeric($id)
            ? (int) $id
            : null;
    }

    public function userLabel(int $userId): string
    {
        $statement = $this->pdo->prepare(
            'SELECT
                first_name,
                last_name,
                username
             FROM users
             WHERE telegram_id = :user_id
             LIMIT 1'
        );

        $statement->execute([
            'user_id' => $userId,
        ]);

        $row = $statement->fetch(
            PDO::FETCH_ASSOC
        );

        if (!is_array($row)) {
            return (string) $userId;
        }

        $name = trim(
            (string) (
                $row['first_name'] ?? ''
            )
            . ' '
            . (string) (
                $row['last_name'] ?? ''
            )
        );

        $username = trim(
            (string) (
                $row['username'] ?? ''
            )
        );

        if ($name !== '') {
            return $name
                . (
                    $username !== ''
                        ? ' (@' . $username . ')'
                        : ''
                );
        }

        return $username !== ''
            ? '@' . $username
            : (string) $userId;
    }

    private function normalizeDomain(
        string $domain
    ): string {
        $domain = mb_strtolower(
            trim($domain)
        );

        $domain = preg_replace(
            '#^https?://#i',
            '',
            $domain
        ) ?? $domain;

        $domain = explode('/', $domain)[0];
        $domain = rtrim($domain, '.');

        if (
            $domain === ''
            || preg_match(
                '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*'
                . '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/i',
                $domain
            ) !== 1
        ) {
            throw new RuntimeException(
                'Domain is invalid.'
            );
        }

        return $domain;
    }

    private function normalizeText(
        string $text
    ): string {
        $text = strtr(
            mb_strtolower(trim($text)),
            [
                'ي' => 'ی',
                'ك' => 'ک',
                "\u{200C}" => ' ',
            ]
        );

        return preg_replace(
            '/\s+/u',
            ' ',
            $text
        ) ?? $text;
    }
}
