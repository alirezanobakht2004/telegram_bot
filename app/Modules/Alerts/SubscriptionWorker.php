<?php

declare(strict_types=1);

namespace SmartToolbox\Modules\Alerts;

use SmartToolbox\Core\TelegramClient;
use Throwable;

final class SubscriptionWorker
{
    public function __construct(
        private readonly SubscriptionRepository $repository,
        private readonly AlertDataProvider $data,
        private readonly ScheduleCalculator $schedule,
        private readonly TelegramClient $telegram,
        private readonly string $logFile
    ) {
    }

    /**
     * @return array{checked:int,sent:int,failed:int}
     */
    public function scan(int $limit): array
    {
        $result = [
            'checked' => 0,
            'sent' => 0,
            'failed' => 0,
        ];

        foreach ($this->repository->due($limit) as $subscription) {
            $result['checked']++;

            try {
                $message = $this->data->subscriptionMessage(
                    $subscription
                );
                $this->telegram->sendMessage(
                    (int) $subscription['chat_id'],
                    $message
                    . "\n\n🆔 اشتراک #"
                    . (int) $subscription['id']
                );
                $nextRunAt = $this->nextRun($subscription);
                $this->repository->markSent(
                    (int) $subscription['id'],
                    $nextRunAt
                );
                $result['sent']++;
            } catch (Throwable $exception) {
                $result['failed']++;
                $failures = max(
                    1,
                    (int) ($subscription['failure_count'] ?? 0) + 1
                );
                $retry = time() + min(
                    21600,
                    300 * (2 ** min(6, $failures - 1))
                );
                $this->repository->markFailure(
                    (int) $subscription['id'],
                    $exception->getMessage(),
                    $retry
                );
                $this->log(
                    'subscription:' . (int) $subscription['id'],
                    $exception
                );
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $subscription
     */
    private function nextRun(array $subscription): int
    {
        return $this->schedule->nextRun(
            (string) $subscription['frequency'],
            (string) $subscription['schedule_time'],
            (string) $subscription['timezone'],
            $subscription['weekday'] !== null
                ? (int) $subscription['weekday']
                : null,
            $subscription['month_day'] !== null
                ? (int) $subscription['month_day']
                : null
        );
    }

    private function log(
        string $operation,
        Throwable $exception
    ): void {
        $directory = dirname($this->logFile);

        if (!is_dir($directory)) {
            @mkdir($directory, 0700, true);
        }

        @file_put_contents(
            $this->logFile,
            sprintf(
                "[%s] [%s] %s\n%s\n\n",
                date(DATE_ATOM),
                $operation,
                $exception->getMessage(),
                $exception->getTraceAsString()
            ),
            FILE_APPEND | LOCK_EX
        );
    }
}
