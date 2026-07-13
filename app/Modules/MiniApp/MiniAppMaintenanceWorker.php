<?php

declare(strict_types=1);

namespace SmartToolbox\Modules\MiniApp;

final class MiniAppMaintenanceWorker
{
    public function __construct(
        private readonly MiniAppSessionRepository $sessions
    ) {
    }

    /**
     * @return array{sessions:int,rate_limits:int,audit:int}
     */
    public function run(
        int $sessionRetentionDays,
        int $auditRetentionDays
    ): array {
        return $this->sessions->cleanup(
            sessionRetentionDays:
                $sessionRetentionDays,
            auditRetentionDays:
                $auditRetentionDays
        );
    }
}
