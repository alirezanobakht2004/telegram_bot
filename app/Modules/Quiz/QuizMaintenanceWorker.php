<?php

declare(strict_types=1);

namespace SmartToolbox\Modules\Quiz;

final class QuizMaintenanceWorker
{
    public function __construct(
        private readonly QuizRepository $repository
    ) {
    }

    /**
     * @return array{
     *     expired:int,
     *     pruned:int
     * }
     */
    public function run(
        int $batchSize,
        int $retentionDays
    ): array {
        return $this->repository->maintain(
            $batchSize,
            $retentionDays
        );
    }
}
