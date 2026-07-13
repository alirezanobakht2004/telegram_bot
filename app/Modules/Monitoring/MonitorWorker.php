<?php

declare(strict_types=1);

namespace SmartToolbox\Modules\Monitoring;

use SmartToolbox\Core\TelegramClient;
use SmartToolbox\Modules\Alerts\ScheduleCalculator;
use Throwable;

final class MonitorWorker
{
    public function __construct(
        private readonly MonitorRepository $repository,
        private readonly MonitorProbe $probe,
        private readonly ScheduleCalculator $schedule,
        private readonly TelegramClient $telegram,
        private readonly string $logFile
    ) {
    }

    /**
     * @return array{checked:int,up:int,down:int,notified:int,failed:int,pruned:int}
     */
    public function scan(
        int $limit,
        int $failureThreshold,
        int $recoveryThreshold,
        int $retentionDays
    ): array {
        $result = [
            'checked' => 0,
            'up' => 0,
            'down' => 0,
            'notified' => 0,
            'failed' => 0,
            'pruned' => 0,
        ];

        foreach ($this->repository->due($limit) as $monitor) {
            $result['checked']++;

            try {
                $probe = $this->probe->probe(
                    (string) $monitor['url']
                );
                $up = $probe['status_code'] >= 200
                    && $probe['status_code'] < 400;
                $result[$up ? 'up' : 'down']++;
                $state = $this->repository->recordCheck(
                    $monitor,
                    $probe,
                    $up,
                    $failureThreshold,
                    $recoveryThreshold
                );

                if ($state['transition'] !== null) {
                    $dedupKey = hash(
                        'sha256',
                        (int) $monitor['id']
                        . ':'
                        . $state['transition']
                        . ':'
                        . (string) ($monitor['last_changed_at'] ?? time())
                        . ':'
                        . intdiv(time(), 60)
                    );

                    try {
                        $this->telegram->sendMessage(
                            (int) $monitor['chat_id'],
                            $this->transitionMessage(
                                $monitor,
                                $probe,
                                $state['transition']
                            )
                        );

                        if ($this->repository->markNotification(
                            (int) $monitor['id'],
                            $state['transition'],
                            $dedupKey
                        )) {
                            $result['notified']++;
                        }
                    } catch (Throwable $notificationException) {
                        $this->log(
                            'monitor-notification:' . (int) $monitor['id'],
                            $notificationException
                        );
                    }
                }
            } catch (Throwable $exception) {
                $result['failed']++;
                $probe = [
                    'status_code' => null,
                    'response_ms' => null,
                    'final_url' => (string) $monitor['url'],
                    'primary_ip' => null,
                    'error_code' => $exception::class,
                    'error_message' => $exception->getMessage(),
                ];

                try {
                    $state = $this->repository->recordCheck(
                        $monitor,
                        $probe,
                        false,
                        $failureThreshold,
                        $recoveryThreshold
                    );

                    if ($state['transition'] === 'down') {
                        $dedupKey = hash(
                            'sha256',
                            (int) $monitor['id']
                            . ':down:'
                            . intdiv(time(), 60)
                        );

                        try {
                            $this->telegram->sendMessage(
                                (int) $monitor['chat_id'],
                                "❌ سایت DOWN شد\n\n"
                                . 'مانیتور #' . (int) $monitor['id'] . "\n"
                                . (string) $monitor['url'] . "\n"
                                . 'خطا: ' . mb_substr($exception->getMessage(), 0, 700)
                            );

                            if ($this->repository->markNotification(
                                (int) $monitor['id'],
                                'down',
                                $dedupKey
                            )) {
                                $result['notified']++;
                            }
                        } catch (Throwable $notificationException) {
                            $this->log(
                                'monitor-notification:' . (int) $monitor['id'],
                                $notificationException
                            );
                        }
                    }
                } catch (Throwable $recordException) {
                    $this->log(
                        'monitor-record:' . (int) $monitor['id'],
                        $recordException
                    );
                }

                $this->log(
                    'monitor:' . (int) $monitor['id'],
                    $exception
                );
            }
        }

        $result['pruned'] = $this->repository->prune(
            $retentionDays
        );

        return $result;
    }

    /**
     * @return array{checked:int,sent:int,failed:int}
     */
    public function dailyReports(int $limit): array
    {
        $result = [
            'checked' => 0,
            'sent' => 0,
            'failed' => 0,
        ];

        foreach ($this->repository->dueReports($limit) as $monitor) {
            $result['checked']++;

            try {
                $day = $this->repository->uptime((int) $monitor['id'], 1);
                $week = $this->repository->uptime((int) $monitor['id'], 7);
                $this->telegram->sendMessage(
                    (int) $monitor['chat_id'],
                    "📊 گزارش روزانه مانیتور\n\n"
                    . 'شناسه: #' . (int) $monitor['id'] . "\n"
                    . (string) $monitor['url'] . "\n"
                    . 'وضعیت فعلی: ' . (string) $monitor['last_state'] . "\n"
                    . 'Uptime 24h: ' . $day['uptime'] . "%\n"
                    . 'میانگین پاسخ: ' . $day['average_response_ms'] . " ms\n"
                    . 'رخدادهای 7d: ' . $week['incidents']
                );
                $next = $this->schedule->nextRun(
                    'daily',
                    (string) $monitor['daily_report_time'],
                    (string) $monitor['timezone']
                );
                $this->repository->markReportSent(
                    (int) $monitor['id'],
                    $next
                );
                $result['sent']++;
            } catch (Throwable $exception) {
                $result['failed']++;
                $this->repository->markReportSent(
                    (int) $monitor['id'],
                    time() + 3600
                );
                $this->log(
                    'monitor-report:' . (int) $monitor['id'],
                    $exception
                );
            }
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $monitor
     * @param array<string,mixed> $probe
     */
    private function transitionMessage(
        array $monitor,
        array $probe,
        string $state
    ): string {
        if ($state === 'up') {
            return "✅ سایت دوباره UP شد\n\n"
                . 'مانیتور #' . (int) $monitor['id'] . "\n"
                . (string) $monitor['url'] . "\n"
                . 'HTTP: ' . (string) ($probe['status_code'] ?? '—') . "\n"
                . 'زمان پاسخ: ' . (string) ($probe['response_ms'] ?? '—') . ' ms';
        }

        return "❌ سایت DOWN شد\n\n"
            . 'مانیتور #' . (int) $monitor['id'] . "\n"
            . (string) $monitor['url'] . "\n"
            . 'HTTP: ' . (string) ($probe['status_code'] ?? '—') . "\n"
            . 'زمان پاسخ: ' . (string) ($probe['response_ms'] ?? '—') . ' ms';
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
