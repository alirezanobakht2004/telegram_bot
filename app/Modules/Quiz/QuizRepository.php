<?php

declare(strict_types=1);

namespace SmartToolbox\Modules\Quiz;

use DateTimeImmutable;
use JsonException;
use PDO;
use Throwable;

final class QuizRepository
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly QuizScoring $scoring
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function categories(
        bool $enabledOnly = true
    ): array {
        $sql = 'SELECT *
                FROM quiz_categories';

        if ($enabledOnly) {
            $sql .= ' WHERE enabled = 1';
        }

        $sql .= ' ORDER BY
                    sort_order ASC,
                    name ASC';

        $rows = $this->pdo
            ->query($sql)
            ->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows)
            ? $rows
            : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function categoryBySlug(
        string $slug
    ): ?array {
        $statement = $this->pdo->prepare(
            'SELECT *
             FROM quiz_categories
             WHERE LOWER(slug) = LOWER(:slug)
             LIMIT 1'
        );

        $statement->execute([
            'slug' => trim($slug),
        ]);

        $row = $statement->fetch(
            PDO::FETCH_ASSOC
        );

        return is_array($row)
            ? $row
            : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function randomQuestion(
        int $userId,
        string $questionType,
        ?string $categorySlug,
        ?string $difficulty
    ): array {
        $questionType = in_array(
            $questionType,
            ['trivia', 'word'],
            true
        )
            ? $questionType
            : 'trivia';

        $difficulty = in_array(
            $difficulty,
            ['easy', 'medium', 'hard'],
            true
        )
            ? $difficulty
            : null;

        $categorySlug = $categorySlug !== null
            ? trim($categorySlug)
            : null;

        $parameters = [
            'user_id' => $userId,
            'question_type' => $questionType,
        ];

        $conditions = [
            'q.enabled = 1',
            'c.enabled = 1',
            'q.question_type = :question_type',
        ];

        if (
            $categorySlug !== null
            && $categorySlug !== ''
        ) {
            $conditions[] =
                'LOWER(c.slug) = LOWER(:category_slug)';
            $parameters['category_slug'] =
                $categorySlug;
        }

        if ($difficulty !== null) {
            $conditions[] =
                'q.difficulty = :difficulty';
            $parameters['difficulty'] =
                $difficulty;
        }

        $base = 'SELECT
                    q.*,
                    c.slug AS category_slug,
                    c.name AS category_name
                 FROM quiz_questions AS q
                 INNER JOIN quiz_categories AS c
                    ON c.id = q.category_id
                 WHERE '
            . implode(' AND ', $conditions);

        $sql = $base
            . ' AND q.id NOT IN (
                    SELECT recent.question_id
                    FROM (
                        SELECT question_id
                        FROM quiz_sessions
                        WHERE user_id = :user_id
                          AND question_id IS NOT NULL
                        ORDER BY id DESC
                        LIMIT 8
                    ) AS recent
                    WHERE recent.question_id IS NOT NULL
                )
                ORDER BY RANDOM()
                LIMIT 1';

        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        $row = $statement->fetch(
            PDO::FETCH_ASSOC
        );

        if (!is_array($row)) {
            $fallbackParameters = $parameters;
            unset($fallbackParameters['user_id']);

            $statement = $this->pdo->prepare(
                $base
                . ' ORDER BY RANDOM()
                    LIMIT 1'
            );

            $statement->execute(
                $fallbackParameters
            );

            $row = $statement->fetch(
                PDO::FETCH_ASSOC
            );
        }

        if (!is_array($row)) {
            throw new QuizException(
                'برای این دسته و سختی سؤال فعالی پیدا نشد.',
                'question_not_found'
            );
        }

        return $this->hydrateQuestion($row);
    }

    /**
     * @return array<string, mixed>
     */
    public function dailyQuestion(
        string $date
    ): array {
        $date = $this->validDate($date);

        $this->pdo->exec('BEGIN IMMEDIATE');

        try {
            $statement = $this->pdo->prepare(
                'SELECT question_id
                 FROM quiz_daily_challenges
                 WHERE challenge_date = :date
                 LIMIT 1'
            );

            $statement->execute([
                'date' => $date,
            ]);

            $questionId =
                $statement->fetchColumn();

            if (!is_numeric($questionId)) {
                $ids = $this->pdo->query(
                    "SELECT q.id
                     FROM quiz_questions AS q
                     INNER JOIN quiz_categories AS c
                        ON c.id = q.category_id
                     WHERE q.enabled = 1
                       AND c.enabled = 1
                     ORDER BY q.id ASC"
                )->fetchAll(PDO::FETCH_COLUMN);

                $ids = is_array($ids)
                    ? array_values(
                        array_filter(
                            $ids,
                            'is_numeric'
                        )
                    )
                    : [];

                if ($ids === []) {
                    throw new QuizException(
                        'بانک سؤال فعال خالی است.',
                        'question_bank_empty'
                    );
                }

                $hash = (int) sprintf(
                    '%u',
                    crc32($date)
                );

                $questionId = (int) $ids[
                    $hash % count($ids)
                ];

                $insert = $this->pdo->prepare(
                    'INSERT INTO quiz_daily_challenges (
                        challenge_date,
                        question_id,
                        created_at
                     ) VALUES (
                        :date,
                        :question_id,
                        :created_at
                     )'
                );

                $insert->execute([
                    'date' => $date,
                    'question_id' => $questionId,
                    'created_at' => date(DATE_ATOM),
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

        return $this->questionById(
            (int) $questionId
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function questionById(
        int $questionId
    ): array {
        $statement = $this->pdo->prepare(
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

        $statement->execute([
            'id' => $questionId,
        ]);

        $row = $statement->fetch(
            PDO::FETCH_ASSOC
        );

        if (!is_array($row)) {
            throw new QuizException(
                'سؤال پیدا نشد.',
                'question_not_found'
            );
        }

        return $this->hydrateQuestion($row);
    }

    /**
     * @param array<string, mixed> $question
     * @return array<string, mixed>
     */
    public function createSession(
        int $userId,
        int $chatId,
        string $chatType,
        string $mode,
        array $question,
        ?string $dailyDate = null
    ): array {
        $mode = in_array(
            $mode,
            [
                'quiz',
                'trivia',
                'math',
                'word',
                'daily',
            ],
            true
        )
            ? $mode
            : 'quiz';

        if ($dailyDate !== null) {
            $dailyDate =
                $this->validDate($dailyDate);

            if (
                $this->dailyAttempt(
                    $userId,
                    $dailyDate
                ) !== null
            ) {
                throw new QuizException(
                    'چالش امروز را قبلاً پاسخ داده‌ای.',
                    'daily_already_answered'
                );
            }
        }

        $options = $question['options']
            ?? null;
        $correctOption =
            $question['correct_option']
            ?? null;

        if (
            !is_array($options)
            || count($options) < 2
            || count($options) > 10
            || !is_int($correctOption)
            || !array_key_exists(
                $correctOption,
                $options
            )
        ) {
            throw new QuizException(
                'ساختار گزینه‌های سؤال معتبر نیست.',
                'question_options_invalid'
            );
        }

        $normalizedOptions = [];

        foreach ($options as $option) {
            if (
                !is_string($option)
                || trim($option) === ''
            ) {
                throw new QuizException(
                    'متن گزینه سؤال معتبر نیست.',
                    'question_option_invalid'
                );
            }

            $normalizedOptions[] =
                mb_substr(
                    trim($option),
                    0,
                    500
                );
        }

        try {
            $optionsJson = json_encode(
                $normalizedOptions,
                JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
            );
        } catch (JsonException $exception) {
            throw new QuizException(
                'گزینه‌های سؤال قابل ذخیره نیستند.',
                'question_options_encoding_failed'
            );
        }

        $token = bin2hex(
            random_bytes(12)
        );

        $startedAt = time();
        $timeout = max(
            5,
            min(
                300,
                (int) (
                    $question[
                        'answer_timeout_seconds'
                    ] ?? 30
                )
            )
        );

        $this->pdo->exec('BEGIN IMMEDIATE');

        try {
            $cancel = $this->pdo->prepare(
                "UPDATE quiz_sessions
                 SET
                    status = 'cancelled',
                    updated_at = :updated_at
                 WHERE user_id = :user_id
                   AND status = 'active'"
            );

            $cancel->execute([
                'updated_at' => date(DATE_ATOM),
                'user_id' => $userId,
            ]);

            if ($dailyDate !== null) {
                $check = $this->pdo->prepare(
                    'SELECT COUNT(*)
                     FROM quiz_daily_attempts
                     WHERE challenge_date = :date
                       AND user_id = :user_id'
                );

                $check->execute([
                    'date' => $dailyDate,
                    'user_id' => $userId,
                ]);

                if (
                    (int) $check->fetchColumn()
                    > 0
                ) {
                    throw new QuizException(
                        'چالش امروز را قبلاً پاسخ داده‌ای.',
                        'daily_already_answered'
                    );
                }
            }

            $statement = $this->pdo->prepare(
                'INSERT INTO quiz_sessions (
                    token,
                    user_id,
                    chat_id,
                    chat_type,
                    mode,
                    question_id,
                    category_slug,
                    category_name,
                    difficulty,
                    question_text,
                    options_json,
                    correct_option,
                    explanation,
                    points,
                    xp_reward,
                    answer_timeout_seconds,
                    status,
                    started_at,
                    expires_at,
                    daily_date,
                    created_at,
                    updated_at
                 ) VALUES (
                    :token,
                    :user_id,
                    :chat_id,
                    :chat_type,
                    :mode,
                    :question_id,
                    :category_slug,
                    :category_name,
                    :difficulty,
                    :question_text,
                    :options_json,
                    :correct_option,
                    :explanation,
                    :points,
                    :xp_reward,
                    :answer_timeout_seconds,
                    :status,
                    :started_at,
                    :expires_at,
                    :daily_date,
                    :created_at,
                    :updated_at
                 )'
            );

            $now = date(DATE_ATOM);

            $statement->execute([
                'token' => $token,
                'user_id' => $userId,
                'chat_id' => $chatId,
                'chat_type' => $chatType,
                'mode' => $mode,
                'question_id' => isset(
                    $question['id']
                )
                && is_numeric($question['id'])
                    ? (int) $question['id']
                    : null,
                'category_slug' => (string) (
                    $question[
                        'category_slug'
                    ] ?? ''
                ),
                'category_name' => (string) (
                    $question[
                        'category_name'
                    ] ?? ''
                ),
                'difficulty' => (string) (
                    $question['difficulty']
                    ?? 'medium'
                ),
                'question_text' => mb_substr(
                    (string) $question[
                        'question_text'
                    ],
                    0,
                    3000
                ),
                'options_json' => $optionsJson,
                'correct_option' =>
                    $correctOption,
                'explanation' => isset(
                    $question['explanation']
                )
                    ? mb_substr(
                        (string) $question[
                            'explanation'
                        ],
                        0,
                        2000
                    )
                    : null,
                'points' => max(
                    1,
                    (int) (
                        $question['points']
                        ?? 10
                    )
                ),
                'xp_reward' => max(
                    1,
                    (int) (
                        $question['xp_reward']
                        ?? 10
                    )
                ),
                'answer_timeout_seconds' =>
                    $timeout,
                'status' => 'active',
                'started_at' => $startedAt,
                'expires_at' =>
                    $startedAt + $timeout,
                'daily_date' => $dailyDate,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $sessionId = (int) $this->pdo
                ->lastInsertId();

            if (
                isset($question['id'])
                && is_numeric($question['id'])
            ) {
                $served = $this->pdo->prepare(
                    'UPDATE quiz_questions
                     SET
                        times_served =
                            times_served + 1,
                        last_served_at =
                            :last_served_at
                     WHERE id = :id'
                );

                $served->execute([
                    'last_served_at' => $now,
                    'id' => (int) $question['id'],
                ]);
            }

            $this->pdo->exec('COMMIT');

            return [
                'id' => $sessionId,
                'token' => $token,
                'user_id' => $userId,
                'chat_id' => $chatId,
                'chat_type' => $chatType,
                'mode' => $mode,
                'question_id' => $question[
                    'id'
                ] ?? null,
                'category_slug' => $question[
                    'category_slug'
                ] ?? '',
                'category_name' => $question[
                    'category_name'
                ] ?? '',
                'difficulty' => $question[
                    'difficulty'
                ] ?? 'medium',
                'question_text' => $question[
                    'question_text'
                ],
                'options' => $normalizedOptions,
                'correct_option' =>
                    $correctOption,
                'explanation' => $question[
                    'explanation'
                ] ?? null,
                'points' => (int) (
                    $question['points'] ?? 10
                ),
                'xp_reward' => (int) (
                    $question['xp_reward'] ?? 10
                ),
                'answer_timeout_seconds' =>
                    $timeout,
                'started_at' => $startedAt,
                'expires_at' =>
                    $startedAt + $timeout,
                'daily_date' => $dailyDate,
            ];
        } catch (Throwable $exception) {
            try {
                $this->pdo->exec('ROLLBACK');
            } catch (Throwable) {
            }

            throw $exception;
        }
    }

    public function attachMessage(
        string $token,
        int $messageId
    ): void {
        $statement = $this->pdo->prepare(
            'UPDATE quiz_sessions
             SET
                message_id = :message_id,
                updated_at = :updated_at
             WHERE token = :token
               AND status = :status'
        );

        $statement->execute([
            'message_id' => $messageId,
            'updated_at' => date(DATE_ATOM),
            'token' => $token,
            'status' => 'active',
        ]);
    }

    public function cancelSession(
        string $token,
        int $userId
    ): void {
        $statement = $this->pdo->prepare(
            "UPDATE quiz_sessions
             SET
                status = 'cancelled',
                updated_at = :updated_at
             WHERE token = :token
               AND user_id = :user_id
               AND status = 'active'"
        );

        $statement->execute([
            'updated_at' => date(DATE_ATOM),
            'token' => $token,
            'user_id' => $userId,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function answer(
        string $token,
        int $userId,
        ?int $chatId,
        ?int $messageId,
        int $selectedOption,
        string $today
    ): array {
        $today = $this->validDate($today);
        $answeredAt = time();

        $this->pdo->exec('BEGIN IMMEDIATE');

        try {
            $statement = $this->pdo->prepare(
                'SELECT *
                 FROM quiz_sessions
                 WHERE token = :token
                 LIMIT 1'
            );

            $statement->execute([
                'token' => $token,
            ]);

            $session = $statement->fetch(
                PDO::FETCH_ASSOC
            );

            if (!is_array($session)) {
                throw new QuizException(
                    'جلسه آزمون پیدا نشد.',
                    'session_not_found'
                );
            }

            if (
                (int) $session['user_id']
                !== $userId
            ) {
                throw new QuizException(
                    'این سؤال متعلق به حساب شما نیست.',
                    'session_owner_mismatch'
                );
            }

            if (
                $chatId !== null
                && (int) $session['chat_id']
                    !== $chatId
            ) {
                throw new QuizException(
                    'چت جلسه آزمون معتبر نیست.',
                    'session_chat_mismatch'
                );
            }

            if (
                $messageId !== null
                && $session['message_id'] !== null
                && (int) $session['message_id']
                    !== $messageId
            ) {
                throw new QuizException(
                    'پیام آزمون با جلسه مطابقت ندارد.',
                    'session_message_mismatch'
                );
            }

            if ($session['status'] === 'answered') {
                $this->pdo->exec('COMMIT');

                return [
                    'status' => 'already_answered',
                ];
            }

            if ($session['status'] !== 'active') {
                $this->pdo->exec('COMMIT');

                return [
                    'status' =>
                        (string) $session['status'],
                ];
            }

            if (
                $answeredAt
                > (int) $session['expires_at']
            ) {
                $this->markSessionExpired(
                    $session,
                    $answeredAt
                );

                $this->pdo->exec('COMMIT');

                return [
                    'status' => 'expired',
                    'session' => $this
                        ->decodeSession($session),
                ];
            }

            $options = $this->decodeOptions(
                (string) $session[
                    'options_json'
                ]
            );

            if (
                !array_key_exists(
                    $selectedOption,
                    $options
                )
            ) {
                throw new QuizException(
                    'گزینه انتخاب‌شده معتبر نیست.',
                    'answer_option_invalid'
                );
            }

            $scoreRow = $this->scoreRow(
                $userId,
                true
            );

            $correct = $selectedOption
                === (int) $session[
                    'correct_option'
                ];

            $calculated =
                $this->scoring->calculate(
                    correct: $correct,
                    basePoints: (int)
                        $session['points'],
                    baseXp: (int)
                        $session['xp_reward'],
                    startedAt: (int)
                        $session['started_at'],
                    answeredAt: $answeredAt,
                    timeoutSeconds: (int)
                        $session[
                            'answer_timeout_seconds'
                        ],
                    previousCorrectStreak:
                        (int) $scoreRow[
                            'current_correct_streak'
                        ],
                    existingXp: (int)
                        $scoreRow['xp']
                );

            $newScore =
                (int) $scoreRow['score']
                + $calculated['score'];

            $newXp =
                (int) $scoreRow['xp']
                + $calculated['xp'];

            $newCorrectStreak = $correct
                ? (int) $scoreRow[
                    'current_correct_streak'
                ] + 1
                : 0;

            $newLongestCorrect = max(
                (int) $scoreRow[
                    'longest_correct_streak'
                ],
                $newCorrectStreak
            );

            [
                $newDailyStreak,
                $newLongestDaily,
            ] = $this->dailyStreak(
                (string) (
                    $scoreRow[
                        'last_activity_date'
                    ] ?? ''
                ),
                (int) $scoreRow[
                    'daily_streak'
                ],
                (int) $scoreRow[
                    'longest_daily_streak'
                ],
                $today
            );

            $isDaily =
                $session['daily_date'] !== null;

            $dailyChallengeIncrement =
                $isDaily ? 1 : 0;

            $dailyCorrectIncrement =
                $isDaily && $correct
                    ? 1
                    : 0;

            $mathCorrectIncrement =
                $session['mode'] === 'math'
                && $correct
                    ? 1
                    : 0;

            $wordCorrectIncrement =
                $session['mode'] === 'word'
                && $correct
                    ? 1
                    : 0;

            $updateScore = $this->pdo->prepare(
                'UPDATE quiz_user_scores
                 SET
                    score = :score,
                    xp = :xp,
                    level = :level,
                    total_answers =
                        total_answers + 1,
                    correct_answers =
                        correct_answers
                        + :correct_increment,
                    wrong_answers =
                        wrong_answers
                        + :wrong_increment,
                    current_correct_streak =
                        :current_correct_streak,
                    longest_correct_streak =
                        :longest_correct_streak,
                    daily_streak =
                        :daily_streak,
                    longest_daily_streak =
                        :longest_daily_streak,
                    last_activity_date =
                        :last_activity_date,
                    last_daily_challenge_date =
                        CASE
                            WHEN :is_daily = 1
                            THEN :last_daily_date
                            ELSE last_daily_challenge_date
                        END,
                    daily_challenges =
                        daily_challenges
                        + :daily_challenge_increment,
                    daily_correct =
                        daily_correct
                        + :daily_correct_increment,
                    math_correct =
                        math_correct
                        + :math_correct_increment,
                    word_correct =
                        word_correct
                        + :word_correct_increment,
                    updated_at = :updated_at
                 WHERE user_id = :user_id'
            );

            $updateScore->execute([
                'score' => $newScore,
                'xp' => $newXp,
                'level' => $calculated['level'],
                'correct_increment' =>
                    $correct ? 1 : 0,
                'wrong_increment' =>
                    $correct ? 0 : 1,
                'current_correct_streak' =>
                    $newCorrectStreak,
                'longest_correct_streak' =>
                    $newLongestCorrect,
                'daily_streak' =>
                    $newDailyStreak,
                'longest_daily_streak' =>
                    $newLongestDaily,
                'last_activity_date' =>
                    $today,
                'is_daily' => $isDaily ? 1 : 0,
                'last_daily_date' =>
                    $isDaily
                        ? (string) $session[
                            'daily_date'
                        ]
                        : null,
                'daily_challenge_increment' =>
                    $dailyChallengeIncrement,
                'daily_correct_increment' =>
                    $dailyCorrectIncrement,
                'math_correct_increment' =>
                    $mathCorrectIncrement,
                'word_correct_increment' =>
                    $wordCorrectIncrement,
                'updated_at' => date(DATE_ATOM),
                'user_id' => $userId,
            ]);

            if (
                in_array(
                    $session['chat_type'],
                    ['group', 'supergroup'],
                    true
                )
            ) {
                $group = $this->pdo->prepare(
                    'INSERT INTO quiz_group_scores (
                        chat_id,
                        user_id,
                        score,
                        xp,
                        total_answers,
                        correct_answers,
                        updated_at
                     ) VALUES (
                        :chat_id,
                        :user_id,
                        :score,
                        :xp,
                        1,
                        :correct_increment,
                        :updated_at
                     )
                     ON CONFLICT(chat_id, user_id)
                     DO UPDATE SET
                        score = score
                            + excluded.score,
                        xp = xp
                            + excluded.xp,
                        total_answers =
                            total_answers + 1,
                        correct_answers =
                            correct_answers
                            + excluded.correct_answers,
                        updated_at =
                            excluded.updated_at'
                );

                $group->execute([
                    'chat_id' => (int)
                        $session['chat_id'],
                    'user_id' => $userId,
                    'score' =>
                        $calculated['score'],
                    'xp' => $calculated['xp'],
                    'correct_increment' =>
                        $correct ? 1 : 0,
                    'updated_at' =>
                        date(DATE_ATOM),
                ]);
            }

            $updateSession = $this->pdo->prepare(
                "UPDATE quiz_sessions
                 SET
                    status = 'answered',
                    selected_option =
                        :selected_option,
                    is_correct = :is_correct,
                    score_awarded =
                        :score_awarded,
                    xp_awarded =
                        :xp_awarded,
                    answered_at = :answered_at,
                    updated_at = :updated_at
                 WHERE id = :id
                   AND status = 'active'"
            );

            $updateSession->execute([
                'selected_option' =>
                    $selectedOption,
                'is_correct' =>
                    $correct ? 1 : 0,
                'score_awarded' =>
                    $calculated['score'],
                'xp_awarded' =>
                    $calculated['xp'],
                'answered_at' => $answeredAt,
                'updated_at' => date(DATE_ATOM),
                'id' => (int) $session['id'],
            ]);

            if (
                $updateSession->rowCount()
                !== 1
            ) {
                throw new QuizException(
                    'پاسخ قبلاً ثبت شده است.',
                    'answer_race_lost'
                );
            }

            if ($session['question_id'] !== null) {
                $questionStats =
                    $this->pdo->prepare(
                        'UPDATE quiz_questions
                         SET
                            correct_count =
                                correct_count
                                + :correct_increment,
                            incorrect_count =
                                incorrect_count
                                + :wrong_increment,
                            updated_at =
                                :updated_at
                         WHERE id = :id'
                    );

                $questionStats->execute([
                    'correct_increment' =>
                        $correct ? 1 : 0,
                    'wrong_increment' =>
                        $correct ? 0 : 1,
                    'updated_at' =>
                        date(DATE_ATOM),
                    'id' => (int)
                        $session['question_id'],
                ]);
            }

            if ($isDaily) {
                $daily = $this->pdo->prepare(
                    'INSERT INTO quiz_daily_attempts (
                        challenge_date,
                        user_id,
                        session_id,
                        is_correct,
                        score_awarded,
                        xp_awarded,
                        answered_at
                     ) VALUES (
                        :challenge_date,
                        :user_id,
                        :session_id,
                        :is_correct,
                        :score_awarded,
                        :xp_awarded,
                        :answered_at
                     )'
                );

                $daily->execute([
                    'challenge_date' =>
                        (string) $session[
                            'daily_date'
                        ],
                    'user_id' => $userId,
                    'session_id' =>
                        (int) $session['id'],
                    'is_correct' =>
                        $correct ? 1 : 0,
                    'score_awarded' =>
                        $calculated['score'],
                    'xp_awarded' =>
                        $calculated['xp'],
                    'answered_at' =>
                        date(DATE_ATOM),
                ]);
            }

            $freshScore = $this->scoreRow(
                $userId,
                false
            );

            $unlocked = $this
                ->unlockAchievements(
                    $userId,
                    $freshScore
                );

            $this->pdo->exec('COMMIT');

            return [
                'status' => 'answered',
                'correct' => $correct,
                'selected_option' =>
                    $selectedOption,
                'correct_option' => (int)
                    $session['correct_option'],
                'selected_text' =>
                    $options[$selectedOption],
                'correct_text' => $options[
                    (int) $session[
                        'correct_option'
                    ]
                ],
                'score_awarded' =>
                    $calculated['score'],
                'xp_awarded' =>
                    $calculated['xp'],
                'time_bonus' =>
                    $calculated['time_bonus'],
                'streak_bonus' =>
                    $calculated['streak_bonus'],
                'level' =>
                    $calculated['level'],
                'score_total' => $newScore,
                'xp_total' => $newXp,
                'correct_streak' =>
                    $newCorrectStreak,
                'daily_streak' =>
                    $newDailyStreak,
                'unlocked' => $unlocked,
                'session' => [
                    ...$this->decodeSession(
                        $session
                    ),
                    'options' => $options,
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
     * @return array<string, mixed>|null
     */
    public function dailyAttempt(
        int $userId,
        string $date
    ): ?array {
        $statement = $this->pdo->prepare(
            'SELECT
                a.*,
                s.question_text,
                s.options_json,
                s.correct_option,
                s.selected_option,
                s.explanation
             FROM quiz_daily_attempts AS a
             INNER JOIN quiz_sessions AS s
                ON s.id = a.session_id
             WHERE a.challenge_date = :date
               AND a.user_id = :user_id
             LIMIT 1'
        );

        $statement->execute([
            'date' => $this->validDate($date),
            'user_id' => $userId,
        ]);

        $row = $statement->fetch(
            PDO::FETCH_ASSOC
        );

        if (!is_array($row)) {
            return null;
        }

        $row['options'] =
            $this->decodeOptions(
                (string) $row[
                    'options_json'
                ]
            );

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    public function score(
        int $userId
    ): array {
        return $this->scoreRow(
            $userId,
            true
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function globalLeaderboard(
        int $limit
    ): array {
        $statement = $this->pdo->prepare(
            'SELECT
                s.*,
                u.first_name,
                u.last_name,
                u.username
             FROM quiz_user_scores AS s
             INNER JOIN users AS u
                ON u.telegram_id = s.user_id
             WHERE s.total_answers > 0
             ORDER BY
                s.score DESC,
                s.xp DESC,
                s.correct_answers DESC,
                s.user_id ASC
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

    /**
     * @return list<array<string, mixed>>
     */
    public function groupLeaderboard(
        int $chatId,
        int $limit
    ): array {
        $statement = $this->pdo->prepare(
            'SELECT
                s.*,
                global.level,
                u.first_name,
                u.last_name,
                u.username
             FROM quiz_group_scores AS s
             INNER JOIN users AS u
                ON u.telegram_id = s.user_id
             LEFT JOIN quiz_user_scores AS global
                ON global.user_id = s.user_id
             WHERE s.chat_id = :chat_id
               AND s.total_answers > 0
             ORDER BY
                s.score DESC,
                s.xp DESC,
                s.correct_answers DESC,
                s.user_id ASC
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

    /**
     * @return list<array<string, mixed>>
     */
    public function achievements(
        int $userId
    ): array {
        $statement = $this->pdo->prepare(
            'SELECT
                a.*,
                ua.unlocked_at
             FROM quiz_achievements AS a
             LEFT JOIN quiz_user_achievements AS ua
                ON ua.achievement_id = a.id
               AND ua.user_id = :user_id
             WHERE a.enabled = 1
             ORDER BY
                CASE
                    WHEN ua.unlocked_at
                        IS NULL
                    THEN 1
                    ELSE 0
                END,
                a.sort_order ASC,
                a.threshold ASC'
        );

        $statement->execute([
            'user_id' => $userId,
        ]);

        $rows = $statement->fetchAll(
            PDO::FETCH_ASSOC
        );

        return is_array($rows)
            ? $rows
            : [];
    }

    /**
     * @return array{
     *     expired:int,
     *     pruned:int
     * }
     */
    public function maintain(
        int $batchSize,
        int $retentionDays
    ): array {
        $batchSize = max(
            1,
            min(1000, $batchSize)
        );

        $this->pdo->exec('BEGIN IMMEDIATE');

        try {
            $statement = $this->pdo->prepare(
                "SELECT *
                 FROM quiz_sessions
                 WHERE status = 'active'
                   AND expires_at < :now
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
                $batchSize,
                PDO::PARAM_INT
            );

            $statement->execute();

            $rows = $statement->fetchAll(
                PDO::FETCH_ASSOC
            );

            $expired = 0;

            foreach (
                is_array($rows)
                    ? $rows
                    : []
                as $row
            ) {
                $this->markSessionExpired(
                    $row,
                    time()
                );
                $expired++;
            }

            $this->pdo->exec('COMMIT');
        } catch (Throwable $exception) {
            try {
                $this->pdo->exec('ROLLBACK');
            } catch (Throwable) {
            }

            throw $exception;
        }

        $cutoff = date(
            DATE_ATOM,
            time() - max(
                1,
                $retentionDays
            ) * 86400
        );

        $delete = $this->pdo->prepare(
            "DELETE FROM quiz_sessions
             WHERE status IN (
                'answered',
                'expired',
                'cancelled'
             )
               AND updated_at < :cutoff"
        );

        $delete->execute([
            'cutoff' => $cutoff,
        ]);

        return [
            'expired' => $expired,
            'pruned' =>
                $delete->rowCount(),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrateQuestion(
        array $row
    ): array {
        $statement = $this->pdo->prepare(
            'SELECT
                option_text,
                is_correct
             FROM quiz_question_options
             WHERE question_id = :question_id
             ORDER BY sort_order ASC'
        );

        $statement->execute([
            'question_id' => (int) $row['id'],
        ]);

        $optionRows = $statement->fetchAll(
            PDO::FETCH_ASSOC
        );

        if (
            !is_array($optionRows)
            || count($optionRows) < 2
        ) {
            throw new QuizException(
                'گزینه‌های سؤال کامل نیستند.',
                'question_options_missing'
            );
        }

        $items = [];

        foreach ($optionRows as $option) {
            $items[] = [
                'text' => (string)
                    $option['option_text'],
                'correct' =>
                    (int) $option[
                        'is_correct'
                    ] === 1,
            ];
        }

        shuffle($items);

        $options = [];
        $correctOption = null;

        foreach (
            $items
            as $index => $item
        ) {
            $options[] = $item['text'];

            if ($item['correct']) {
                if ($correctOption !== null) {
                    throw new QuizException(
                        'سؤال بیش از یک پاسخ درست دارد.',
                        'multiple_correct_options'
                    );
                }

                $correctOption = $index;
            }
        }

        if ($correctOption === null) {
            throw new QuizException(
                'پاسخ درست سؤال مشخص نشده است.',
                'correct_option_missing'
            );
        }

        return [
            'id' => (int) $row['id'],
            'category_id' => (int)
                $row['category_id'],
            'category_slug' => (string)
                $row['category_slug'],
            'category_name' => (string)
                $row['category_name'],
            'question_type' => (string)
                $row['question_type'],
            'difficulty' => (string)
                $row['difficulty'],
            'question_text' => (string)
                $row['question_text'],
            'explanation' => $row[
                'explanation'
            ] !== null
                ? (string) $row[
                    'explanation'
                ]
                : null,
            'points' => (int) $row['points'],
            'xp_reward' => (int)
                $row['xp_reward'],
            'answer_timeout_seconds' =>
                (int) $row[
                    'answer_timeout_seconds'
                ],
            'options' => $options,
            'correct_option' =>
                $correctOption,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function scoreRow(
        int $userId,
        bool $create
    ): array {
        if ($create) {
            $insert = $this->pdo->prepare(
                'INSERT OR IGNORE INTO quiz_user_scores (
                    user_id,
                    updated_at
                 ) VALUES (
                    :user_id,
                    :updated_at
                 )'
            );

            $insert->execute([
                'user_id' => $userId,
                'updated_at' => date(DATE_ATOM),
            ]);
        }

        $statement = $this->pdo->prepare(
            'SELECT *
             FROM quiz_user_scores
             WHERE user_id = :user_id
             LIMIT 1'
        );

        $statement->execute([
            'user_id' => $userId,
        ]);

        $row = $statement->fetch(
            PDO::FETCH_ASSOC
        );

        if (!is_array($row)) {
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

        return $row;
    }

    /**
     * @param array<string, mixed> $score
     * @return list<array<string, mixed>>
     */
    private function unlockAchievements(
        int $userId,
        array $score
    ): array {
        $statement = $this->pdo->prepare(
            'SELECT a.*
             FROM quiz_achievements AS a
             LEFT JOIN quiz_user_achievements AS ua
                ON ua.achievement_id = a.id
               AND ua.user_id = :user_id
             WHERE a.enabled = 1
               AND ua.achievement_id IS NULL
             ORDER BY
                a.sort_order ASC,
                a.threshold ASC'
        );

        $statement->execute([
            'user_id' => $userId,
        ]);

        $rows = $statement->fetchAll(
            PDO::FETCH_ASSOC
        );

        $unlocked = [];

        foreach (
            is_array($rows)
                ? $rows
                : []
            as $achievement
        ) {
            $metric = (string)
                $achievement['metric'];

            $value = (int) (
                $score[$metric] ?? 0
            );

            if (
                $value < (int)
                    $achievement['threshold']
            ) {
                continue;
            }

            $insert = $this->pdo->prepare(
                'INSERT OR IGNORE INTO quiz_user_achievements (
                    user_id,
                    achievement_id,
                    unlocked_at
                 ) VALUES (
                    :user_id,
                    :achievement_id,
                    :unlocked_at
                 )'
            );

            $insert->execute([
                'user_id' => $userId,
                'achievement_id' => (int)
                    $achievement['id'],
                'unlocked_at' =>
                    date(DATE_ATOM),
            ]);

            if ($insert->rowCount() === 1) {
                $unlocked[] = $achievement;
            }
        }

        return $unlocked;
    }

    /**
     * @return array{0:int,1:int}
     */
    private function dailyStreak(
        string $lastDate,
        int $current,
        int $longest,
        string $today
    ): array {
        if ($lastDate === $today) {
            return [
                $current,
                max($longest, $current),
            ];
        }

        $yesterday = (
            new DateTimeImmutable($today)
        )->modify('-1 day')->format('Y-m-d');

        $next = $lastDate === $yesterday
            ? max(1, $current + 1)
            : 1;

        return [
            $next,
            max($longest, $next),
        ];
    }

    /**
     * @param array<string, mixed> $session
     */
    private function markSessionExpired(
        array $session,
        int $expiredAt
    ): void {
        $statement = $this->pdo->prepare(
            "UPDATE quiz_sessions
             SET
                status = 'expired',
                answered_at = :answered_at,
                updated_at = :updated_at
             WHERE id = :id
               AND status = 'active'"
        );

        $statement->execute([
            'answered_at' => $expiredAt,
            'updated_at' => date(DATE_ATOM),
            'id' => (int) $session['id'],
        ]);

        if (
            $statement->rowCount() === 1
            && $session['question_id']
                !== null
        ) {
            $question = $this->pdo->prepare(
                'UPDATE quiz_questions
                 SET
                    timeout_count =
                        timeout_count + 1,
                    updated_at = :updated_at
                 WHERE id = :id'
            );

            $question->execute([
                'updated_at' => date(DATE_ATOM),
                'id' => (int)
                    $session['question_id'],
            ]);
        }
    }

    /**
     * @return list<string>
     */
    private function decodeOptions(
        string $json
    ): array {
        try {
            $decoded = json_decode(
                $json,
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException $exception) {
            throw new QuizException(
                'گزینه‌های ذخیره‌شده آزمون خراب هستند.',
                'session_options_invalid'
            );
        }

        if (!is_array($decoded)) {
            throw new QuizException(
                'گزینه‌های ذخیره‌شده آزمون معتبر نیستند.',
                'session_options_invalid'
            );
        }

        return array_values(
            array_map(
                static fn (mixed $value): string =>
                    (string) $value,
                $decoded
            )
        );
    }

    /**
     * @param array<string, mixed> $session
     * @return array<string, mixed>
     */
    private function decodeSession(
        array $session
    ): array {
        return [
            'id' => (int) $session['id'],
            'token' => (string)
                $session['token'],
            'user_id' => (int)
                $session['user_id'],
            'chat_id' => (int)
                $session['chat_id'],
            'chat_type' => (string)
                $session['chat_type'],
            'mode' => (string)
                $session['mode'],
            'question_id' =>
                $session['question_id']
                    !== null
                    ? (int) $session[
                        'question_id'
                    ]
                    : null,
            'category_slug' => (string) (
                $session['category_slug']
                ?? ''
            ),
            'category_name' => (string) (
                $session['category_name']
                ?? ''
            ),
            'difficulty' => (string)
                $session['difficulty'],
            'question_text' => (string)
                $session['question_text'],
            'explanation' =>
                $session['explanation']
                    !== null
                    ? (string) $session[
                        'explanation'
                    ]
                    : null,
            'points' => (int)
                $session['points'],
            'xp_reward' => (int)
                $session['xp_reward'],
            'answer_timeout_seconds' =>
                (int) $session[
                    'answer_timeout_seconds'
                ],
            'started_at' => (int)
                $session['started_at'],
            'expires_at' => (int)
                $session['expires_at'],
            'message_id' =>
                $session['message_id']
                    !== null
                    ? (int) $session[
                        'message_id'
                    ]
                    : null,
            'daily_date' =>
                $session['daily_date']
                    !== null
                    ? (string) $session[
                        'daily_date'
                    ]
                    : null,
        ];
    }

    private function validDate(
        string $date
    ): string {
        $date = trim($date);

        $parsed = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $date
        );

        if (
            $parsed === false
            || $parsed->format('Y-m-d')
                !== $date
        ) {
            throw new QuizException(
                'تاریخ چالش معتبر نیست.',
                'date_invalid'
            );
        }

        return $date;
    }
}
