<?php

declare(strict_types=1);

namespace SmartToolbox\Modules\Quiz;

final class QuizScoring
{
    public function __construct(
        private readonly int $timeBonusMaxPercent = 50,
        private readonly int $streakBonusPercent = 5,
        private readonly int $participationXp = 1,
        private readonly int $xpPerLevel = 100
    ) {
    }

    /**
     * @return array{
     *     score:int,
     *     xp:int,
     *     time_bonus:int,
     *     streak_bonus:int,
     *     level:int
     * }
     */
    public function calculate(
        bool $correct,
        int $basePoints,
        int $baseXp,
        int $startedAt,
        int $answeredAt,
        int $timeoutSeconds,
        int $previousCorrectStreak,
        int $existingXp
    ): array {
        $basePoints = max(1, $basePoints);
        $baseXp = max(1, $baseXp);
        $timeoutSeconds = max(
            1,
            $timeoutSeconds
        );

        if (!$correct) {
            $xp = max(
                0,
                $this->participationXp
            );

            return [
                'score' => 0,
                'xp' => $xp,
                'time_bonus' => 0,
                'streak_bonus' => 0,
                'level' => $this->level(
                    $existingXp + $xp
                ),
            ];
        }

        $elapsed = max(
            0,
            $answeredAt - $startedAt
        );

        $remainingRatio = max(
            0.0,
            min(
                1.0,
                (
                    $timeoutSeconds - $elapsed
                ) / $timeoutSeconds
            )
        );

        $timeBonus = (int) floor(
            $basePoints
            * max(
                0,
                min(
                    100,
                    $this->timeBonusMaxPercent
                )
            )
            / 100
            * $remainingRatio
        );

        $newStreak = max(
            1,
            $previousCorrectStreak + 1
        );

        $streakBonus = (int) floor(
            $basePoints
            * max(
                0,
                min(
                    50,
                    $this->streakBonusPercent
                )
            )
            / 100
            * min(10, $newStreak)
        );

        $score = $basePoints
            + $timeBonus
            + $streakBonus;

        return [
            'score' => $score,
            'xp' => $baseXp,
            'time_bonus' => $timeBonus,
            'streak_bonus' => $streakBonus,
            'level' => $this->level(
                $existingXp + $baseXp
            ),
        ];
    }

    public function level(int $xp): int
    {
        return 1 + intdiv(
            max(0, $xp),
            max(1, $this->xpPerLevel)
        );
    }
}
