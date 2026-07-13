<?php

declare(strict_types=1);

namespace SmartToolbox\Modules\Quiz;

use SmartToolbox\Core\CallbackQueryContext;
use SmartToolbox\Core\CallbackRouter;
use SmartToolbox\Core\CommandRouter;
use SmartToolbox\Core\MessageContext;
use SmartToolbox\Core\ModuleInterface;
use SmartToolbox\Core\RateLimiter;
use Throwable;

final class QuizModule implements ModuleInterface
{
    /**
     * @param array<string, int> $defaultPoints
     * @param array<string, int> $defaultXp
     * @param array<string, int> $defaultTimeouts
     */
    public function __construct(
        private readonly QuizRepository $repository,
        private readonly MathQuestionGenerator $math,
        private readonly RateLimiter $rateLimiter,
        private readonly array $defaultPoints = [
            'easy' => 10,
            'medium' => 20,
            'hard' => 30,
        ],
        private readonly array $defaultXp = [
            'easy' => 10,
            'medium' => 18,
            'hard' => 26,
        ],
        private readonly array $defaultTimeouts = [
            'easy' => 30,
            'medium' => 25,
            'hard' => 20,
        ],
        private readonly int $leaderboardSize = 10,
        private readonly int $maxAttempts = 30,
        private readonly int $windowSeconds = 60
    ) {
    }

    public function register(
        CommandRouter $router
    ): void {
        $router->command(
            'quiz',
            function (
                MessageContext $context,
                string $arguments
            ): void {
                $this->startBankGame(
                    $context,
                    'quiz',
                    'trivia',
                    $arguments
                );
            },
            'quiz_games'
        );

        $router->command(
            'trivia',
            function (
                MessageContext $context,
                string $arguments
            ): void {
                $this->startBankGame(
                    $context,
                    'trivia',
                    'trivia',
                    $arguments
                );
            },
            'quiz_games'
        );

        $router->command(
            'mathgame',
            function (
                MessageContext $context,
                string $arguments
            ): void {
                $this->startMath(
                    $context,
                    $arguments
                );
            },
            'quiz_games'
        );

        $router->command(
            'wordgame',
            function (
                MessageContext $context,
                string $arguments
            ): void {
                $this->startBankGame(
                    $context,
                    'word',
                    'word',
                    $arguments,
                    'words'
                );
            },
            'quiz_games'
        );

        $router->command(
            'dailychallenge',
            function (
                MessageContext $context
            ): void {
                $this->startDaily($context);
            },
            'quiz_games'
        );

        $router->command(
            'leaderboard',
            function (
                MessageContext $context,
                string $arguments
            ): void {
                $this->leaderboard(
                    $context,
                    $arguments
                );
            },
            'quiz_games'
        );

        $router->command(
            'myscore',
            function (
                MessageContext $context
            ): void {
                $this->myScore($context);
            },
            'quiz_games'
        );

        $router->command(
            'achievements',
            function (
                MessageContext $context
            ): void {
                $this->achievements(
                    $context
                );
            },
            'quiz_games'
        );

        $router->command(
            'streak',
            function (
                MessageContext $context
            ): void {
                $this->streak($context);
            },
            'quiz_games'
        );

        $router->text(
            '🎯 مسابقه و آزمون',
            function (
                MessageContext $context
            ): void {
                $this->menu($context);
            },
            'quiz_games'
        );
    }

    public function registerCallbacks(
        CallbackRouter $router
    ): void {
        $router->on(
            'quiz:a:',
            function (
                CallbackQueryContext $context,
                string $suffix
            ): void {
                $this->answer(
                    $context,
                    $suffix
                );
            },
            'quiz_games'
        );
    }

    private function menu(
        MessageContext $context
    ): void {
        $context->reply(
            "🎯 مسابقه و آزمون\n\n"
            . "/quiz — سؤال تصادفی\n"
            . "/quiz science hard — علوم سخت\n"
            . "/trivia geography — دانستنی جغرافیا\n"
            . "/mathgame easy — بازی ریاضی\n"
            . "/wordgame — بازی واژگان\n"
            . "/dailychallenge — چالش روز\n"
            . "/leaderboard — جدول برترین‌ها\n"
            . "/myscore — امتیاز من\n"
            . "/achievements — نشان‌ها\n"
            . "/streak — Streakها\n\n"
            . "دسته‌ها: "
            . implode(
                '، ',
                array_map(
                    static fn (
                        array $category
                    ): string =>
                        (string) $category[
                            'slug'
                        ],
                    $this->repository
                        ->categories()
                )
            )
        );
    }

    private function startBankGame(
        MessageContext $context,
        string $mode,
        string $questionType,
        string $arguments,
        ?string $forcedCategory = null
    ): void {
        $userId = $this->user(
            $context
        );

        if ($userId === null) {
            return;
        }

        if (!$this->allow($context)) {
            return;
        }

        try {
            [
                $category,
                $difficulty,
            ] = $this->filters(
                $arguments,
                $forcedCategory
            );

            $question =
                $this->repository
                    ->randomQuestion(
                        $userId,
                        $questionType,
                        $category,
                        $difficulty
                    );

            $this->sendQuestion(
                $context,
                $mode,
                $question
            );
        } catch (QuizException $exception) {
            $context->reply(
                $exception->errorCode === 'category_list'
                    ? $exception->getMessage()
                    : "شروع مسابقه ممکن نشد. ⚠️\n\n"
                        . $exception->getMessage()
            );
        }
    }

    private function startMath(
        MessageContext $context,
        string $arguments
    ): void {
        $userId = $this->user(
            $context
        );

        if ($userId === null) {
            return;
        }

        if (!$this->allow($context)) {
            return;
        }

        $difficulty = $this
            ->normalizeDifficulty(
                trim($arguments)
            ) ?? 'medium';

        $question = $this->math
            ->generate($difficulty);

        $question['points'] =
            $this->difficultyValue(
                $this->defaultPoints,
                $difficulty,
                20
            );

        $question['xp_reward'] =
            $this->difficultyValue(
                $this->defaultXp,
                $difficulty,
                18
            );

        $question[
            'answer_timeout_seconds'
        ] = $this->difficultyValue(
            $this->defaultTimeouts,
            $difficulty,
            25
        );

        try {
            $this->sendQuestion(
                $context,
                'math',
                $question
            );
        } catch (QuizException $exception) {
            $context->reply(
                "شروع بازی ریاضی ممکن نشد. ⚠️\n\n"
                . $exception->getMessage()
            );
        }
    }

    private function startDaily(
        MessageContext $context
    ): void {
        $userId = $this->user(
            $context
        );

        if ($userId === null) {
            return;
        }

        if (!$this->allow($context)) {
            return;
        }

        $today = date('Y-m-d');
        $attempt =
            $this->repository->dailyAttempt(
                $userId,
                $today
            );

        if ($attempt !== null) {
            $options = $attempt['options'];
            $correct = (int)
                $attempt['correct_option'];
            $selected = (int)
                $attempt['selected_option'];

            $context->reply(
                "📅 چالش امروز را قبلاً پاسخ داده‌ای.\n\n"
                . (
                    (int) $attempt[
                        'is_correct'
                    ] === 1
                        ? '✅ پاسخ درست'
                        : '❌ پاسخ نادرست'
                )
                . "\nپاسخ درست: "
                . (
                    $options[$correct]
                    ?? '—'
                )
                . (
                    $selected !== $correct
                        ? "\nانتخاب تو: "
                            . (
                                $options[
                                    $selected
                                ] ?? '—'
                            )
                        : ''
                )
                . "\nامتیاز: +"
                . (int) $attempt[
                    'score_awarded'
                ]
                . "\nXP: +"
                . (int) $attempt[
                    'xp_awarded'
                ]
            );

            return;
        }

        try {
            $question =
                $this->repository
                    ->dailyQuestion($today);

            $this->sendQuestion(
                $context,
                'daily',
                $question,
                $today
            );
        } catch (QuizException $exception) {
            $context->reply(
                "چالش روز آماده نشد. ⚠️\n\n"
                . $exception->getMessage()
            );
        }
    }

    /**
     * @param array<string, mixed> $question
     */
    private function sendQuestion(
        MessageContext $context,
        string $mode,
        array $question,
        ?string $dailyDate = null
    ): void {
        $userId = $this->user(
            $context
        );

        if ($userId === null) {
            return;
        }

        $session =
            $this->repository->createSession(
                userId: $userId,
                chatId: $context->chatId,
                chatType: $context->chatType,
                mode: $mode,
                question: $question,
                dailyDate: $dailyDate
            );

        $buttons = [];

        foreach (
            $session['options']
            as $index => $option
        ) {
            $buttons[] = [
                'text' => $this->optionLabel(
                    $index
                ) . ' ' . mb_substr(
                    (string) $option,
                    0,
                    55
                ),
                'callback_data' =>
                    'quiz:a:'
                    . $session['token']
                    . ':'
                    . $index,
            ];
        }

        $keyboard = [];

        foreach (
            array_chunk($buttons, 2)
            as $row
        ) {
            $keyboard[] = $row;
        }

        $title = match ($mode) {
            'math' => '🧮 بازی ریاضی',
            'word' => '📝 بازی واژگان',
            'daily' => '📅 چالش روز',
            'trivia' => '🧠 دانستنی',
            default => '🎯 آزمون',
        };

        $messageText = $title
            . "\n\n"
            . "دسته: "
            . (
                trim(
                    (string) $session[
                        'category_name'
                    ]
                ) !== ''
                    ? $session[
                        'category_name'
                    ]
                    : 'عمومی'
            )
            . "\nسختی: "
            . $this->difficultyLabel(
                (string) $session[
                    'difficulty'
                ]
            )
            . "\n⏱ "
            . (int) $session[
                'answer_timeout_seconds'
            ]
            . " ثانیه"
            . " · امتیاز پایه "
            . (int) $session['points']
            . "\n\n"
            . (string) $session[
                'question_text'
            ];

        try {
            $sent = $context->reply(
                $messageText,
                [
                    'reply_markup' => [
                        'inline_keyboard' =>
                            $keyboard,
                    ],
                ]
            );

            $messageId = $sent[
                'message_id'
            ] ?? null;

            if (is_int($messageId)) {
                $this->repository
                    ->attachMessage(
                        (string) $session[
                            'token'
                        ],
                        $messageId
                    );
            }
        } catch (Throwable $exception) {
            $this->repository->cancelSession(
                (string) $session['token'],
                $userId
            );

            throw $exception;
        }
    }

    private function answer(
        CallbackQueryContext $context,
        string $suffix
    ): void {
        $parts = explode(':', $suffix);

        if (
            count($parts) !== 2
            || preg_match(
                '/^[a-f0-9]{24}$/',
                $parts[0]
            ) !== 1
            || preg_match(
                '/^\d$/',
                $parts[1]
            ) !== 1
        ) {
            $context->answer(
                'داده پاسخ معتبر نیست.',
                true
            );

            return;
        }

        $userId = $context->userId();

        if ($userId === null) {
            $context->answer(
                'شناسه کاربر در دسترس نیست.',
                true
            );

            return;
        }

        try {
            $result = $this->repository
                ->answer(
                    token: $parts[0],
                    userId: $userId,
                    chatId: $context->chatId(),
                    messageId:
                        $context->messageId(),
                    selectedOption:
                        (int) $parts[1],
                    today: date('Y-m-d')
                );

            $status = $result['status'];

            if ($status === 'expired') {
                $context->answer(
                    'زمان پاسخ تمام شده است. ⏱',
                    true
                );

                $this->finishExpiredMessage(
                    $context,
                    $result
                );

                return;
            }

            if ($status === 'already_answered') {
                $context->answer(
                    'پاسخ قبلاً ثبت شده است.',
                    true
                );

                return;
            }

            if ($status !== 'answered') {
                $context->answer(
                    'این آزمون دیگر فعال نیست.',
                    true
                );

                return;
            }

            $correct = (bool)
                $result['correct'];

            $context->answer(
                $correct
                    ? 'پاسخ درست بود! ✅'
                    : 'پاسخ نادرست بود. ❌',
                !$correct
            );

            $text = $this->resultText(
                $result
            );

            try {
                $context->editMessageText(
                    $text,
                    [
                        'reply_markup' => [
                            'inline_keyboard' => [],
                        ],
                    ]
                );
            } catch (Throwable) {
                $context->reply($text);
            }
        } catch (QuizException $exception) {
            $context->answer(
                $exception->getMessage(),
                true
            );
        } catch (Throwable) {
            $context->answer(
                'ثبت پاسخ با خطا مواجه شد.',
                true
            );
        }
    }

    /**
     * @param array<string, mixed> $result
     */
    private function finishExpiredMessage(
        CallbackQueryContext $context,
        array $result
    ): void {
        $session = $result['session']
            ?? [];

        if (!is_array($session)) {
            return;
        }

        $text = "⏱ زمان پاسخ تمام شد.\n\n"
            . (string) (
                $session['question_text']
                ?? ''
            );

        try {
            $context->editMessageText(
                $text,
                [
                    'reply_markup' => [
                        'inline_keyboard' => [],
                    ],
                ]
            );
        } catch (Throwable) {
        }
    }

    /**
     * @param array<string, mixed> $result
     */
    private function resultText(
        array $result
    ): string {
        $session = $result['session'];
        $correct = (bool) $result[
            'correct'
        ];

        $text = ($correct
            ? '✅ پاسخ درست'
            : '❌ پاسخ نادرست')
            . "\n\n"
            . (string) $session[
                'question_text'
            ]
            . "\n\n"
            . "پاسخ درست: "
            . (string) $result[
                'correct_text'
            ];

        if (!$correct) {
            $text .= "\nانتخاب تو: "
                . (string) $result[
                    'selected_text'
                ];
        }

        $explanation = trim(
            (string) (
                $session['explanation']
                ?? ''
            )
        );

        if ($explanation !== '') {
            $text .= "\n\n💡 {$explanation}";
        }

        $text .= "\n\n"
            . "امتیاز: +"
            . (int) $result[
                'score_awarded'
            ]
            . " · XP: +"
            . (int) $result[
                'xp_awarded'
            ]
            . "\nسطح: "
            . (int) $result['level']
            . " · Streak صحیح: "
            . (int) $result[
                'correct_streak'
            ]
            . " · Streak روزانه: "
            . (int) $result[
                'daily_streak'
            ];

        $unlocked = $result['unlocked']
            ?? [];

        if (is_array($unlocked) && $unlocked !== []) {
            $text .= "\n\n🏆 نشان جدید:\n";

            foreach ($unlocked as $achievement) {
                if (!is_array($achievement)) {
                    continue;
                }

                $text .= (
                    (string) (
                        $achievement['icon']
                        ?? '🏆'
                    )
                )
                    . ' '
                    . (string) (
                        $achievement['name']
                        ?? ''
                    )
                    . "\n";
            }
        }

        return mb_substr(
            rtrim($text),
            0,
            4000
        );
    }

    private function leaderboard(
        MessageContext $context,
        string $arguments
    ): void {
        if ($this->user($context) === null) {
            return;
        }

        $mode = mb_strtolower(
            trim($arguments)
        );

        if ($mode === '') {
            $mode = $context->isGroup()
                ? 'group'
                : 'global';
        }

        if (
            in_array(
                $mode,
                ['گروه', 'group'],
                true
            )
        ) {
            if (!$context->isGroup()) {
                $context->reply(
                    'جدول گروه فقط داخل گروه قابل مشاهده است.'
                );

                return;
            }

            $rows =
                $this->repository
                    ->groupLeaderboard(
                        $context->chatId,
                        $this->leaderboardSize
                    );

            $title =
                '🏅 جدول برترین‌های گروه';
        } elseif (
            in_array(
                $mode,
                ['جهانی', 'global'],
                true
            )
        ) {
            $rows =
                $this->repository
                    ->globalLeaderboard(
                        $this->leaderboardSize
                    );

            $title =
                '🌍 جدول برترین‌های جهانی';
        } else {
            $context->reply(
                'نمونه: /leaderboard group یا /leaderboard global'
            );

            return;
        }

        if ($rows === []) {
            $context->reply(
                "{$title}\n\nهنوز امتیازی ثبت نشده است."
            );

            return;
        }

        $lines = [$title, ''];

        foreach (
            $rows
            as $index => $row
        ) {
            $lines[] = $this->rankIcon(
                $index + 1
            )
                . ' '
                . $this->userLabel($row)
                . ' — '
                . number_format(
                    (int) $row['score']
                )
                . ' امتیاز'
                . ' · Level '
                . (
                    isset($row['level'])
                        ? (int) $row['level']
                        : '—'
                );
        }

        $context->reply(
            implode("\n", $lines)
        );
    }

    private function myScore(
        MessageContext $context
    ): void {
        $userId = $this->user(
            $context
        );

        if ($userId === null) {
            return;
        }

        $score = $this->repository
            ->score($userId);

        $total = (int)
            $score['total_answers'];
        $correct = (int)
            $score['correct_answers'];

        $accuracy = $total > 0
            ? round(
                $correct / $total * 100,
                1
            )
            : 0.0;

        $context->reply(
            "🎯 امتیاز من\n\n"
            . "امتیاز: "
            . number_format(
                (int) $score['score']
            )
            . "\nXP: "
            . number_format(
                (int) $score['xp']
            )
            . "\nLevel: "
            . (int) $score['level']
            . "\nپاسخ‌ها: {$total}"
            . "\nدرست: {$correct}"
            . "\nدقت: {$accuracy}%"
            . "\nبهترین Streak صحیح: "
            . (int) $score[
                'longest_correct_streak'
            ]
            . "\nبهترین Streak روزانه: "
            . (int) $score[
                'longest_daily_streak'
            ]
        );
    }

    private function achievements(
        MessageContext $context
    ): void {
        $userId = $this->user(
            $context
        );

        if ($userId === null) {
            return;
        }

        $score = $this->repository
            ->score($userId);
        $rows = $this->repository
            ->achievements($userId);

        $unlocked = 0;
        $lines = [
            '🏆 نشان‌ها و دستاوردها',
            '',
        ];

        foreach ($rows as $row) {
            $isUnlocked = $row[
                'unlocked_at'
            ] !== null;

            if ($isUnlocked) {
                $unlocked++;
            }

            $metric = (string)
                $row['metric'];
            $progress = min(
                (int) $row['threshold'],
                (int) (
                    $score[$metric] ?? 0
                )
            );

            $lines[] = (
                $isUnlocked
                    ? '✅ '
                    : '🔒 '
            )
                . (string) $row['icon']
                . ' '
                . (string) $row['name']
                . ' — '
                . $progress
                . '/'
                . (int) $row[
                    'threshold'
                ];
        }

        array_splice(
            $lines,
            1,
            0,
            [
                "بازشده: {$unlocked}/"
                . count($rows),
            ]
        );

        $context->reply(
            mb_substr(
                implode("\n", $lines),
                0,
                3900
            )
        );
    }

    private function streak(
        MessageContext $context
    ): void {
        $userId = $this->user(
            $context
        );

        if ($userId === null) {
            return;
        }

        $score = $this->repository
            ->score($userId);

        $context->reply(
            "🔥 Streak من\n\n"
            . "پاسخ درست پیاپی: "
            . (int) $score[
                'current_correct_streak'
            ]
            . "\nرکورد پاسخ درست: "
            . (int) $score[
                'longest_correct_streak'
            ]
            . "\nروزهای فعال پیاپی: "
            . (int) $score[
                'daily_streak'
            ]
            . "\nرکورد روزانه: "
            . (int) $score[
                'longest_daily_streak'
            ]
            . "\nآخرین فعالیت: "
            . (
                $score[
                    'last_activity_date'
                ] ?? '—'
            )
        );
    }

    /**
     * @return array{0:?string,1:?string}
     */
    private function filters(
        string $arguments,
        ?string $forcedCategory
    ): array {
        $category = $forcedCategory;
        $difficulty = null;

        $tokens = preg_split(
            '/\s+/u',
            mb_strtolower(
                trim($arguments)
            ),
            -1,
            PREG_SPLIT_NO_EMPTY
        );

        foreach (
            is_array($tokens)
                ? $tokens
                : []
            as $token
        ) {
            $normalizedDifficulty =
                $this->normalizeDifficulty(
                    $token
                );

            if (
                $normalizedDifficulty
                !== null
            ) {
                $difficulty =
                    $normalizedDifficulty;
                continue;
            }

            if ($forcedCategory === null) {
                $category = $token;
            }
        }

        if (
            $category !== null
            && $category !== ''
        ) {
            $resolved = $this
                ->resolveCategory(
                    $category
                );

            if ($resolved === null) {
                throw new QuizException(
                    'دسته سؤال شناخته نشد. برای فهرست از /quiz categories استفاده کن.',
                    'category_not_found'
                );
            }

            $category = $resolved;
        }

        return [
            $category,
            $difficulty,
        ];
    }

    private function resolveCategory(
        string $value
    ): ?string {
        if (
            in_array(
                $value,
                ['categories', 'category', 'دسته', 'دسته‌ها'],
                true
            )
        ) {
            throw new QuizException(
                'دسته‌ها: '
                . implode(
                    '، ',
                    array_map(
                        static fn (
                            array $row
                        ): string =>
                            (string) $row[
                                'slug'
                            ],
                        $this->repository
                            ->categories()
                    )
                ),
                'category_list'
            );
        }

        foreach (
            $this->repository->categories()
            as $category
        ) {
            if (
                mb_strtolower(
                    (string) $category['slug']
                ) === $value
                || mb_strtolower(
                    (string) $category['name']
                ) === $value
            ) {
                return (string)
                    $category['slug'];
            }
        }

        return null;
    }

    private function normalizeDifficulty(
        string $value
    ): ?string {
        return match (
            mb_strtolower(trim($value))
        ) {
            'easy', 'آسان', 'ساده' =>
                'easy',
            'medium', 'متوسط' =>
                'medium',
            'hard', 'سخت', 'دشوار' =>
                'hard',
            default => null,
        };
    }

    private function difficultyValue(
        array $values,
        string $difficulty,
        int $fallback
    ): int {
        return max(
            1,
            (int) (
                $values[$difficulty]
                ?? $fallback
            )
        );
    }

    private function difficultyLabel(
        string $difficulty
    ): string {
        return match ($difficulty) {
            'easy' => 'آسان',
            'hard' => 'سخت',
            default => 'متوسط',
        };
    }

    private function optionLabel(
        int $index
    ): string {
        return match ($index) {
            0 => 'A)',
            1 => 'B)',
            2 => 'C)',
            3 => 'D)',
            4 => 'E)',
            default => (string)
                ($index + 1) . ')',
        };
    }

    private function rankIcon(int $rank): string
    {
        return match ($rank) {
            1 => '🥇',
            2 => '🥈',
            3 => '🥉',
            default => $rank . '.',
        };
    }

    /**
     * @param array<string, mixed> $row
     */
    private function userLabel(
        array $row
    ): string {
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
            return $name;
        }

        if ($username !== '') {
            return '@' . $username;
        }

        return 'کاربر '
            . (int) $row['user_id'];
    }

    private function user(
        MessageContext $context
    ): ?int {
        if ($context->userId === null) {
            $context->reply(
                'شناسه کاربر برای مسابقه در دسترس نیست.'
            );

            return null;
        }

        return $context->userId;
    }

    private function allow(
        MessageContext $context
    ): bool {
        $result = $this->rateLimiter
            ->attempt(
                'quiz-games:'
                . $context->actorKey(),
                max(1, $this->maxAttempts),
                max(1, $this->windowSeconds)
            );

        if ($result->allowed) {
            return true;
        }

        $context->reply(
            "درخواست‌های مسابقه زیاد است؛ "
            . "{$result->retryAfter} ثانیه دیگر تلاش کن."
        );

        return false;
    }
}
