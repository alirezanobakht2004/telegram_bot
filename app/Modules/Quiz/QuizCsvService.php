<?php

declare(strict_types=1);

namespace SmartToolbox\Modules\Quiz;

use PDO;
use RuntimeException;
use Throwable;

final class QuizCsvService
{
    public function __construct(
        private readonly PDO $pdo
    ) {
    }

    /**
     * @param resource $stream
     */
    public function streamExport(
        mixed $stream
    ): void {
        if (!is_resource($stream)) {
            throw new RuntimeException(
                'CSV output stream is invalid.'
            );
        }

        fwrite($stream, "\xEF\xBB\xBF");

        fputcsv(
            $stream,
            [
                'id',
                'category_slug',
                'category_name',
                'category_enabled',
                'question_type',
                'difficulty',
                'question_text',
                'option_a',
                'option_b',
                'option_c',
                'option_d',
                'correct_option',
                'explanation',
                'points',
                'xp_reward',
                'answer_timeout_seconds',
                'enabled',
                'times_served',
                'correct_count',
                'incorrect_count',
                'timeout_count',
            ],
            ',',
            '"',
            '',
            "\n"
        );

        $questions = $this->pdo->query(
            'SELECT
                q.*,
                c.slug AS category_slug,
                c.name AS category_name,
                c.enabled AS category_enabled
             FROM quiz_questions AS q
             INNER JOIN quiz_categories AS c
                ON c.id = q.category_id
             ORDER BY
                c.sort_order ASC,
                c.name ASC,
                q.id ASC'
        )->fetchAll(PDO::FETCH_ASSOC);

        $optionStatement = $this->pdo->prepare(
            'SELECT
                option_text,
                is_correct
             FROM quiz_question_options
             WHERE question_id = :question_id
             ORDER BY sort_order ASC'
        );

        foreach (
            is_array($questions)
                ? $questions
                : []
            as $question
        ) {
            $optionStatement->execute([
                'question_id' =>
                    (int) $question['id'],
            ]);

            $options = $optionStatement
                ->fetchAll(PDO::FETCH_ASSOC);

            $texts = ['', '', '', ''];
            $correct = '';

            foreach (
                is_array($options)
                    ? array_slice($options, 0, 4)
                    : []
                as $index => $option
            ) {
                $texts[$index] =
                    (string) $option[
                        'option_text'
                    ];

                if (
                    (int) $option[
                        'is_correct'
                    ] === 1
                ) {
                    $correct = chr(
                        65 + $index
                    );
                }
            }

            fputcsv(
                $stream,
                [
                    (int) $question['id'],
                    (string) $question[
                        'category_slug'
                    ],
                    (string) $question[
                        'category_name'
                    ],
                    (int) $question[
                        'category_enabled'
                    ],
                    (string) $question[
                        'question_type'
                    ],
                    (string) $question[
                        'difficulty'
                    ],
                    (string) $question[
                        'question_text'
                    ],
                    $texts[0],
                    $texts[1],
                    $texts[2],
                    $texts[3],
                    $correct,
                    (string) (
                        $question[
                            'explanation'
                        ] ?? ''
                    ),
                    (int) $question['points'],
                    (int) $question[
                        'xp_reward'
                    ],
                    (int) $question[
                        'answer_timeout_seconds'
                    ],
                    (int) $question['enabled'],
                    (int) $question[
                        'times_served'
                    ],
                    (int) $question[
                        'correct_count'
                    ],
                    (int) $question[
                        'incorrect_count'
                    ],
                    (int) $question[
                        'timeout_count'
                    ],
                ],
                ',',
                '"',
                '',
                "\n"
            );
        }
    }

    /**
     * @return array{
     *     imported:int,
     *     updated:int,
     *     categories:int
     * }
     */
    public function importFile(
        string $path,
        string $actor,
        int $maxBytes = 2097152,
        int $maxRows = 1000
    ): array {
        $realPath = realpath($path);

        if (
            $realPath === false
            || !is_file($realPath)
        ) {
            throw new QuizException(
                'فایل CSV پیدا نشد.',
                'csv_file_missing'
            );
        }

        $size = filesize($realPath);

        if (
            !is_int($size)
            || $size <= 0
            || $size > max(1024, $maxBytes)
        ) {
            throw new QuizException(
                'حجم CSV معتبر نیست یا از سقف مجاز بیشتر است.',
                'csv_size_invalid'
            );
        }

        $handle = fopen($realPath, 'rb');

        if ($handle === false) {
            throw new QuizException(
                'CSV قابل خواندن نیست.',
                'csv_open_failed'
            );
        }

        try {
            $header = fgetcsv(
                $handle,
                null,
                ',',
                '"',
                ''
            );

            if (!is_array($header)) {
                throw new QuizException(
                    'سطر Header در CSV وجود ندارد.',
                    'csv_header_missing'
                );
            }

            $header = array_map(
                static function (
                    mixed $value
                ): string {
                    $value = trim(
                        (string) $value
                    );

                    return ltrim(
                        $value,
                        "\xEF\xBB\xBF"
                    );
                },
                $header
            );

            $index = array_flip($header);

            foreach (
                [
                    'category_slug',
                    'question_text',
                    'option_a',
                    'option_b',
                    'option_c',
                    'option_d',
                    'correct_option',
                ]
                as $required
            ) {
                if (!isset($index[$required])) {
                    throw new QuizException(
                        "ستون {$required} در CSV وجود ندارد.",
                        'csv_column_missing'
                    );
                }
            }

            $imported = 0;
            $updated = 0;
            $categories = [];
            $rowNumber = 1;

            $this->pdo->exec(
                'BEGIN IMMEDIATE'
            );

            try {
                while (
                    ($row = fgetcsv(
                        $handle,
                        null,
                        ',',
                        '"',
                        ''
                    ))
                    !== false
                ) {
                    $rowNumber++;

                    if (
                        $row === [null]
                        || $this->emptyRow($row)
                    ) {
                        continue;
                    }

                    if (
                        $imported + $updated
                        >= max(1, $maxRows)
                    ) {
                        throw new QuizException(
                            "CSV بیش از {$maxRows} سؤال دارد.",
                            'csv_row_limit'
                        );
                    }

                    $record = [];

                    foreach (
                        $index
                        as $name => $position
                    ) {
                        $record[$name] =
                            isset($row[$position])
                                ? trim(
                                    (string) $row[
                                        $position
                                    ]
                                )
                                : '';
                    }

                    $categoryId =
                        $this->ensureCategory(
                            $record,
                            $actor
                        );

                    $categories[$categoryId] =
                        true;

                    $questionId = $this
                        ->saveQuestionRecord(
                            $record,
                            $categoryId,
                            $actor,
                            $rowNumber
                        );

                    if (
                        isset($record['id'])
                        && preg_match(
                            '/^\d+$/',
                            $record['id']
                        ) === 1
                        && (int) $record['id']
                            === $questionId
                    ) {
                        $updated++;
                    } else {
                        $imported++;
                    }
                }

                $this->pdo->exec('COMMIT');
            } catch (Throwable $exception) {
                try {
                    $this->pdo->exec(
                        'ROLLBACK'
                    );
                } catch (Throwable) {
                }

                throw $exception;
            }

            return [
                'imported' => $imported,
                'updated' => $updated,
                'categories' =>
                    count($categories),
            ];
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param array<string, string> $record
     */
    private function ensureCategory(
        array $record,
        string $actor
    ): int {
        $slug = mb_strtolower(
            trim(
                $record['category_slug']
                ?? ''
            )
        );

        if (
            preg_match(
                '/^[a-z0-9][a-z0-9_-]{1,49}$/',
                $slug
            ) !== 1
        ) {
            throw new QuizException(
                "Slug دسته «{$slug}» معتبر نیست.",
                'category_slug_invalid'
            );
        }

        $name = trim(
            $record['category_name']
            ?? ''
        );

        if ($name === '') {
            $name = $slug;
        }

        $enabled = $this->boolean(
            $record[
                'category_enabled'
            ] ?? '1'
        );

        $now = date(DATE_ATOM);

        $statement = $this->pdo->prepare(
            'INSERT INTO quiz_categories (
                slug,
                name,
                enabled,
                created_at,
                updated_at
             ) VALUES (
                :slug,
                :name,
                :enabled,
                :created_at,
                :updated_at
             )
             ON CONFLICT(slug)
             DO UPDATE SET
                name = excluded.name,
                enabled = excluded.enabled,
                updated_at =
                    excluded.updated_at'
        );

        $statement->execute([
            'slug' => $slug,
            'name' => mb_substr(
                $name,
                0,
                150
            ),
            'enabled' => $enabled,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $select = $this->pdo->prepare(
            'SELECT id
             FROM quiz_categories
             WHERE slug = :slug
             LIMIT 1'
        );

        $select->execute([
            'slug' => $slug,
        ]);

        $id = $select->fetchColumn();

        if (!is_numeric($id)) {
            throw new QuizException(
                'دسته CSV ذخیره نشد.',
                'category_save_failed'
            );
        }

        return (int) $id;
    }

    /**
     * @param array<string, string> $record
     */
    private function saveQuestionRecord(
        array $record,
        int $categoryId,
        string $actor,
        int $rowNumber
    ): int {
        $questionText = trim(
            $record['question_text']
            ?? ''
        );

        if (
            $questionText === ''
            || mb_strlen($questionText) > 3000
        ) {
            throw new QuizException(
                "متن سؤال در سطر {$rowNumber} معتبر نیست.",
                'question_text_invalid'
            );
        }

        $questionType = mb_strtolower(
            $record['question_type']
            ?? 'trivia'
        );

        if (
            !in_array(
                $questionType,
                ['trivia', 'word'],
                true
            )
        ) {
            throw new QuizException(
                "نوع سؤال سطر {$rowNumber} معتبر نیست.",
                'question_type_invalid'
            );
        }

        $difficulty = mb_strtolower(
            $record['difficulty']
            ?? 'medium'
        );

        if (
            !in_array(
                $difficulty,
                ['easy', 'medium', 'hard'],
                true
            )
        ) {
            throw new QuizException(
                "سختی سؤال سطر {$rowNumber} معتبر نیست.",
                'question_difficulty_invalid'
            );
        }

        $options = [
            trim($record['option_a'] ?? ''),
            trim($record['option_b'] ?? ''),
            trim($record['option_c'] ?? ''),
            trim($record['option_d'] ?? ''),
        ];

        foreach ($options as $option) {
            if (
                $option === ''
                || mb_strlen($option) > 500
            ) {
                throw new QuizException(
                    "گزینه‌های سطر {$rowNumber} کامل نیستند.",
                    'question_option_invalid'
                );
            }
        }

        if (
            count(array_unique($options))
            !== 4
        ) {
            throw new QuizException(
                "گزینه‌های سطر {$rowNumber} باید متفاوت باشند.",
                'question_options_duplicate'
            );
        }

        $correct = $this->correctIndex(
            $record['correct_option']
            ?? ''
        );

        $points = $this->boundedInt(
            $record['points'] ?? '10',
            1,
            1000,
            "امتیاز سطر {$rowNumber}"
        );

        $xp = $this->boundedInt(
            $record['xp_reward'] ?? '10',
            1,
            1000,
            "XP سطر {$rowNumber}"
        );

        $timeout = $this->boundedInt(
            $record[
                'answer_timeout_seconds'
            ] ?? '30',
            5,
            300,
            "زمان پاسخ سطر {$rowNumber}"
        );

        $enabled = $this->boolean(
            $record['enabled'] ?? '1'
        );

        $id = isset($record['id'])
            && preg_match(
                '/^\d+$/',
                $record['id']
            ) === 1
            ? (int) $record['id']
            : 0;

        $now = date(DATE_ATOM);

        if ($id > 0) {
            $exists = $this->pdo->prepare(
                'SELECT COUNT(*)
                 FROM quiz_questions
                 WHERE id = :id'
            );

            $exists->execute([
                'id' => $id,
            ]);

            if (
                (int) $exists->fetchColumn()
                === 1
            ) {
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
                        trim(
                            $record[
                                'explanation'
                            ] ?? ''
                        ) ?: null,
                    'points' => $points,
                    'xp_reward' => $xp,
                    'timeout' => $timeout,
                    'enabled' => $enabled,
                    'source' => 'csv',
                    'created_by' => $actor,
                    'updated_at' => $now,
                    'id' => $id,
                ]);
            } else {
                $id = 0;
            }
        }

        if ($id === 0) {
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
                'category_id' => $categoryId,
                'question_type' =>
                    $questionType,
                'difficulty' => $difficulty,
                'question_text' =>
                    $questionText,
                'explanation' => trim(
                    $record['explanation']
                    ?? ''
                ) ?: null,
                'points' => $points,
                'xp_reward' => $xp,
                'timeout' => $timeout,
                'enabled' => $enabled,
                'source' => 'csv',
                'created_by' => $actor,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $id = (int) $this->pdo
                ->lastInsertId();
        }

        $delete = $this->pdo->prepare(
            'DELETE FROM quiz_question_options
             WHERE question_id = :question_id'
        );

        $delete->execute([
            'question_id' => $id,
        ]);

        $insertOption = $this->pdo->prepare(
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
            $insertOption->execute([
                'question_id' => $id,
                'option_text' => $option,
                'is_correct' =>
                    $index === $correct
                        ? 1
                        : 0,
                'sort_order' => $index,
            ]);
        }

        return $id;
    }

    /**
     * @param list<mixed> $row
     */
    private function emptyRow(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    private function correctIndex(
        string $value
    ): int {
        $value = mb_strtoupper(
            trim($value)
        );

        if (
            in_array(
                $value,
                ['A', 'B', 'C', 'D'],
                true
            )
        ) {
            return ord($value) - 65;
        }

        if (
            preg_match(
                '/^[1-4]$/',
                $value
            ) === 1
        ) {
            return (int) $value - 1;
        }

        if ($value === '0') {
            return 0;
        }

        throw new QuizException(
            'ستون correct_option باید A تا D یا 1 تا 4 باشد.',
            'correct_option_invalid'
        );
    }

    private function boolean(string $value): int
    {
        return in_array(
            mb_strtolower(trim($value)),
            [
                '1',
                'true',
                'yes',
                'on',
                'enabled',
                'فعال',
                'بله',
            ],
            true
        )
            ? 1
            : 0;
    }

    private function boundedInt(
        string $value,
        int $minimum,
        int $maximum,
        string $label
    ): int {
        if (
            preg_match(
                '/^-?\d+$/',
                trim($value)
            ) !== 1
        ) {
            throw new QuizException(
                "{$label} عدد صحیح نیست.",
                'numeric_value_invalid'
            );
        }

        $number = (int) $value;

        if (
            $number < $minimum
            || $number > $maximum
        ) {
            throw new QuizException(
                "{$label} باید بین {$minimum} و {$maximum} باشد.",
                'numeric_value_out_of_range'
            );
        }

        return $number;
    }
}
