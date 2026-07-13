<?php

declare(strict_types=1);

use SmartToolbox\Modules\Quiz\MathQuestionGenerator;
use SmartToolbox\Modules\Quiz\QuizCsvService;
use SmartToolbox\Modules\Quiz\QuizException;
use SmartToolbox\Modules\Quiz\QuizRepository;
use SmartToolbox\Modules\Quiz\QuizScoring;

$rootPath = dirname(__DIR__);

require $rootPath
    . '/vendor/autoload.php';

$assert = static function (
    bool $condition,
    string $message
): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$scoring = new QuizScoring(
    timeBonusMaxPercent: 50,
    streakBonusPercent: 5,
    participationXp: 1,
    xpPerLevel: 100
);

$score = $scoring->calculate(
    correct: true,
    basePoints: 20,
    baseXp: 18,
    startedAt: 100,
    answeredAt: 110,
    timeoutSeconds: 30,
    previousCorrectStreak: 2,
    existingXp: 90
);

$assert(
    $score['score'] > 20,
    'Time or streak bonus was not applied.'
);

$assert(
    $score['level'] === 2,
    'Level calculation failed.'
);

$wrong = $scoring->calculate(
    correct: false,
    basePoints: 20,
    baseXp: 18,
    startedAt: 100,
    answeredAt: 110,
    timeoutSeconds: 30,
    previousCorrectStreak: 2,
    existingXp: 0
);

$assert(
    $wrong['score'] === 0
    && $wrong['xp'] === 1,
    'Wrong-answer scoring failed.'
);

$math = (new MathQuestionGenerator())
    ->generate('hard');

$assert(
    count($math['options']) === 4
    && array_key_exists(
        $math['correct_option'],
        $math['options']
    ),
    'Math question generation failed.'
);

$databaseStatus = 'skipped';

if (extension_loaded('pdo_sqlite')) {
    $temporaryDirectory =
        sys_get_temp_dir()
        . '/smart-toolbox-release-five-'
        . bin2hex(random_bytes(5));

    if (
        !mkdir(
            $temporaryDirectory,
            0700,
            true
        )
    ) {
        throw new RuntimeException(
            'Temporary directory could not be created.'
        );
    }

    $databasePath =
        $temporaryDirectory
        . '/test.sqlite';

    $pdo = new PDO(
        'sqlite:' . $databasePath,
        options: [
            PDO::ATTR_ERRMODE =>
                PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE =>
                PDO::FETCH_ASSOC,
        ]
    );

    $pdo->exec(
        'PRAGMA foreign_keys = ON'
    );

    $pdo->exec(
        'CREATE TABLE users (
            telegram_id INTEGER PRIMARY KEY,
            first_name TEXT,
            last_name TEXT,
            username TEXT
        )'
    );

    $pdo->exec(
        'CREATE TABLE chats (
            telegram_id INTEGER PRIMARY KEY,
            type TEXT,
            title TEXT
        )'
    );

    $pdo->exec(
        'CREATE TABLE feature_flags (
            flag_key TEXT PRIMARY KEY,
            enabled INTEGER NOT NULL,
            rollout_percentage INTEGER NOT NULL,
            description TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            updated_by TEXT NOT NULL
        )'
    );

    $pdo->exec(
        "INSERT INTO users (
            telegram_id,
            first_name,
            username
         ) VALUES
            (1001, 'Ali', 'ali'),
            (1002, 'Sara', 'sara'),
            (1003, 'Reza', 'reza')"
    );

    $pdo->exec(
        "INSERT INTO chats (
            telegram_id,
            type,
            title
         ) VALUES
            (2001, 'private', 'Private'),
            (-100300, 'supergroup', 'Quiz Group')"
    );

    $migration = file_get_contents(
        $rootPath
        . '/database/migrations/'
        . '013_release_five_quiz_games.sql'
    );

    if (!is_string($migration)) {
        throw new RuntimeException(
            'Release-five migration could not be read.'
        );
    }

    $pdo->exec($migration);

    $repository = new QuizRepository(
        pdo: $pdo,
        scoring: $scoring
    );

    $assert(
        count($repository->categories())
        === 6,
        'Seed categories are missing.'
    );

    $question = $repository->randomQuestion(
        1001,
        'trivia',
        'science',
        null
    );

    $assert(
        count($question['options']) === 4,
        'Seed question options are invalid.'
    );

    $session = $repository->createSession(
        userId: 1001,
        chatId: 2001,
        chatType: 'private',
        mode: 'quiz',
        question: $question
    );

    $repository->attachMessage(
        $session['token'],
        501
    );

    $assert(
        strlen(
            'quiz:a:'
            . $session['token']
            . ':'
            . $session['correct_option']
        ) <= 64,
        'Callback data exceeds Telegram limit.'
    );

    $answer = $repository->answer(
        token: $session['token'],
        userId: 1001,
        chatId: 2001,
        messageId: 501,
        selectedOption:
            $session['correct_option'],
        today: '2026-07-13'
    );

    $assert(
        $answer['status'] === 'answered'
        && $answer['correct'] === true
        && $answer['score_awarded'] > 0
        && $answer['xp_awarded'] > 0,
        'Correct answer registration failed.'
    );

    $replay = $repository->answer(
        token: $session['token'],
        userId: 1001,
        chatId: 2001,
        messageId: 501,
        selectedOption:
            $session['correct_option'],
        today: '2026-07-13'
    );

    $assert(
        $replay['status']
            === 'already_answered',
        'Replay protection failed.'
    );

    $ownerSession =
        $repository->createSession(
            userId: 1001,
            chatId: 2001,
            chatType: 'private',
            mode: 'quiz',
            question: $question
        );

    $ownerProtected = false;

    try {
        $repository->answer(
            token: $ownerSession['token'],
            userId: 1002,
            chatId: 2001,
            messageId: null,
            selectedOption: 0,
            today: '2026-07-13'
        );
    } catch (QuizException $exception) {
        $ownerProtected =
            $exception->errorCode
            === 'session_owner_mismatch';
    }

    $assert(
        $ownerProtected,
        'Session owner anti-cheat failed.'
    );

    $expiredSession =
        $repository->createSession(
            userId: 1002,
            chatId: 2001,
            chatType: 'private',
            mode: 'quiz',
            question: $question
        );

    $expire = $pdo->prepare(
        'UPDATE quiz_sessions
         SET expires_at = :expires_at
         WHERE token = :token'
    );

    $expire->execute([
        'expires_at' => time() - 1,
        'token' => $expiredSession[
            'token'
        ],
    ]);

    $expired = $repository->answer(
        token: $expiredSession['token'],
        userId: 1002,
        chatId: 2001,
        messageId: null,
        selectedOption: 0,
        today: '2026-07-13'
    );

    $assert(
        $expired['status'] === 'expired',
        'Answer timeout enforcement failed.'
    );

    $dailyQuestion =
        $repository->dailyQuestion(
            '2026-07-13'
        );

    $dailySession =
        $repository->createSession(
            userId: 1003,
            chatId: 2001,
            chatType: 'private',
            mode: 'daily',
            question: $dailyQuestion,
            dailyDate: '2026-07-13'
        );

    $repository->answer(
        token: $dailySession['token'],
        userId: 1003,
        chatId: 2001,
        messageId: null,
        selectedOption:
            $dailySession[
                'correct_option'
            ],
        today: '2026-07-13'
    );

    $dailyProtected = false;

    try {
        $repository->createSession(
            userId: 1003,
            chatId: 2001,
            chatType: 'private',
            mode: 'daily',
            question: $dailyQuestion,
            dailyDate: '2026-07-13'
        );
    } catch (QuizException $exception) {
        $dailyProtected =
            $exception->errorCode
            === 'daily_already_answered';
    }

    $assert(
        $dailyProtected,
        'Daily challenge one-attempt rule failed.'
    );

    $groupQuestion =
        $repository->randomQuestion(
            1001,
            'word',
            'words',
            'easy'
        );

    $groupSession =
        $repository->createSession(
            userId: 1001,
            chatId: -100300,
            chatType: 'supergroup',
            mode: 'word',
            question: $groupQuestion
        );

    $repository->answer(
        token: $groupSession['token'],
        userId: 1001,
        chatId: -100300,
        messageId: null,
        selectedOption:
            $groupSession[
                'correct_option'
            ],
        today: '2026-07-14'
    );

    $assert(
        count(
            $repository->groupLeaderboard(
                -100300,
                10
            )
        ) === 1,
        'Group leaderboard failed.'
    );

    $assert(
        count(
            $repository->globalLeaderboard(
                10
            )
        ) >= 2,
        'Global leaderboard failed.'
    );

    $assert(
        count(
            array_filter(
                $repository->achievements(
                    1001
                ),
                static fn (array $row): bool =>
                    $row['unlocked_at']
                    !== null
            )
        ) >= 2,
        'Achievement unlocking failed.'
    );

    $csvPath = $temporaryDirectory
        . '/import.csv';

    file_put_contents(
        $csvPath,
        implode(
            "\n",
            [
                'category_slug,category_name,question_type,difficulty,question_text,option_a,option_b,option_c,option_d,correct_option,explanation,points,xp_reward,answer_timeout_seconds,enabled',
                'animals,حیوانات,trivia,easy,کدام حیوان پستاندار است؟,دلفین,کوسه,اختاپوس,قزل‌آلا,A,دلفین پستاندار است.,10,10,30,1',
            ]
        )
    );

    $import = (
        new QuizCsvService($pdo)
    )->importFile(
        $csvPath,
        'test-admin'
    );

    $assert(
        $import['imported'] === 1
        && $import['categories'] === 1,
        'Quiz CSV import failed.'
    );

    $exportPath = $temporaryDirectory
        . '/export.csv';

    $stream = fopen(
        $exportPath,
        'wb'
    );

    if ($stream === false) {
        throw new RuntimeException(
            'CSV export file could not be opened.'
        );
    }

    (new QuizCsvService($pdo))
        ->streamExport($stream);

    fclose($stream);

    $assert(
        filesize($exportPath) > 100,
        'Quiz CSV export failed.'
    );

    $feature = $pdo->query(
        "SELECT
            enabled,
            rollout_percentage
         FROM feature_flags
         WHERE flag_key = 'quiz_games'"
    )->fetch(PDO::FETCH_NUM);

    $assert(
        is_array($feature)
        && (int) $feature[0] === 1
        && (int) $feature[1] === 100,
        'Quiz feature flag failed.'
    );

    $requiredTables = [
        'quiz_categories',
        'quiz_questions',
        'quiz_question_options',
        'quiz_sessions',
        'quiz_user_scores',
        'quiz_group_scores',
        'quiz_daily_challenges',
        'quiz_daily_attempts',
        'quiz_achievements',
        'quiz_user_achievements',
    ];

    foreach ($requiredTables as $table) {
        $statement = $pdo->prepare(
            "SELECT COUNT(*)
             FROM sqlite_master
             WHERE type = 'table'
               AND name = :name"
        );

        $statement->execute([
            'name' => $table,
        ]);

        $assert(
            (int) $statement->fetchColumn()
                === 1,
            "Missing table: {$table}"
        );
    }

    $databaseStatus = 'passed';

    unset(
        $repository,
        $statement,
        $expire,
        $pdo
    );

    gc_collect_cycles();

    $cleanupDirectory = static function (
        string $directory
    ): void {
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            if (!is_dir($directory)) {
                return;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(
                    $directory,
                    FilesystemIterator::SKIP_DOTS
                ),
                RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($iterator as $item) {
                $path = $item->getPathname();

                if ($item->isDir()) {
                    @rmdir($path);
                } else {
                    @unlink($path);
                }
            }

            if (@rmdir($directory)) {
                return;
            }

            gc_collect_cycles();
            usleep(100000);
        }

        throw new RuntimeException(
            'Temporary release-five directory could not be removed: '
            . $directory
        );
    };

    $cleanupDirectory(
        $temporaryDirectory
    );
}

echo json_encode(
    [
        'status' => 'passed',
        'tests' => [
            'scoring' => true,
            'math_generator' => true,
            'anti_cheat' => true,
            'answer_timeout' => true,
            'daily_challenge' => true,
            'leaderboards' => true,
            'achievements' => true,
            'csv_import_export' => true,
            'database' => $databaseStatus,
        ],
    ],
    JSON_PRETTY_PRINT
    | JSON_UNESCAPED_UNICODE
    | JSON_UNESCAPED_SLASHES
) . PHP_EOL;
