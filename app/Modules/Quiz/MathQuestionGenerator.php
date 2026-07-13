<?php

declare(strict_types=1);

namespace SmartToolbox\Modules\Quiz;

final class MathQuestionGenerator
{
    /**
     * @return array{
     *     question_text:string,
     *     options:list<string>,
     *     correct_option:int,
     *     explanation:string,
     *     difficulty:string,
     *     category_slug:string,
     *     category_name:string
     * }
     */
    public function generate(
        string $difficulty
    ): array {
        $difficulty = in_array(
            $difficulty,
            ['easy', 'medium', 'hard'],
            true
        )
            ? $difficulty
            : 'medium';

        [$question, $answer, $explanation] =
            match ($difficulty) {
                'easy' => $this->easy(),
                'hard' => $this->hard(),
                default => $this->medium(),
            };

        $values = [$answer];

        $spread = match ($difficulty) {
            'easy' => 5,
            'hard' => 20,
            default => 10,
        };

        while (count($values) < 4) {
            $offset = random_int(
                -$spread,
                $spread
            );

            if ($offset === 0) {
                continue;
            }

            $candidate = $answer + $offset;

            if (
                $candidate < -9999
                || $candidate > 99999
                || in_array(
                    $candidate,
                    $values,
                    true
                )
            ) {
                continue;
            }

            $values[] = $candidate;
        }

        shuffle($values);

        $correctOption = array_search(
            $answer,
            $values,
            true
        );

        if (!is_int($correctOption)) {
            throw new QuizException(
                'گزینه درست سؤال ریاضی ساخته نشد.',
                'math_generation_failed'
            );
        }

        return [
            'question_text' => $question,
            'options' => array_map(
                static fn (int $value): string =>
                    (string) $value,
                $values
            ),
            'correct_option' => $correctOption,
            'explanation' => $explanation,
            'difficulty' => $difficulty,
            'category_slug' => 'math',
            'category_name' => 'ریاضی',
        ];
    }

    /**
     * @return array{0:string,1:int,2:string}
     */
    private function easy(): array
    {
        $left = random_int(4, 40);
        $right = random_int(2, 30);
        $operator = random_int(0, 1);

        if ($operator === 0) {
            $answer = $left + $right;

            return [
                "{$left} + {$right} = ؟",
                $answer,
                "{$left} + {$right} = {$answer}",
            ];
        }

        if ($right > $left) {
            [$left, $right] = [
                $right,
                $left,
            ];
        }

        $answer = $left - $right;

        return [
            "{$left} − {$right} = ؟",
            $answer,
            "{$left} − {$right} = {$answer}",
        ];
    }

    /**
     * @return array{0:string,1:int,2:string}
     */
    private function medium(): array
    {
        if (random_int(0, 1) === 0) {
            $left = random_int(3, 15);
            $right = random_int(3, 15);
            $answer = $left * $right;

            return [
                "{$left} × {$right} = ؟",
                $answer,
                "{$left} × {$right} = {$answer}",
            ];
        }

        $divisor = random_int(2, 12);
        $answer = random_int(2, 15);
        $dividend = $divisor * $answer;

        return [
            "{$dividend} ÷ {$divisor} = ؟",
            $answer,
            "{$dividend} ÷ {$divisor} = {$answer}",
        ];
    }

    /**
     * @return array{0:string,1:int,2:string}
     */
    private function hard(): array
    {
        $a = random_int(3, 12);
        $b = random_int(2, 10);
        $c = random_int(2, 9);

        if (random_int(0, 1) === 0) {
            $answer = ($a + $b) * $c;

            return [
                "({$a} + {$b}) × {$c} = ؟",
                $answer,
                "ابتدا پرانتز: {$a} + {$b} = "
                . ($a + $b)
                . "؛ سپس × {$c} = {$answer}",
            ];
        }

        $answer = $a * $b - $c;

        return [
            "{$a} × {$b} − {$c} = ؟",
            $answer,
            "ابتدا ضرب: {$a} × {$b} = "
            . ($a * $b)
            . "؛ سپس − {$c} = {$answer}",
        ];
    }
}
