<?php

declare(strict_types=1);

namespace SmartToolbox\Modules\Alerts;

use SmartToolbox\Core\TelegramClient;
use Throwable;

final class AlertWorker
{
    public function __construct(
        private readonly AlertRepository $repository,
        private readonly AlertDataProvider $data,
        private readonly ConditionEvaluator $evaluator,
        private readonly TelegramClient $telegram,
        private readonly string $logFile
    ) {
    }

    /**
     * @return array{checked:int,triggered:int,failed:int,pruned:int}
     */
    public function scan(
        int $limit,
        int $retentionDays = 90
    ): array {
        $result = [
            'checked' => 0,
            'triggered' => 0,
            'failed' => 0,
            'pruned' => 0,
        ];

        foreach ($this->repository->due($limit) as $alert) {
            $result['checked']++;
            $interval = max(
                60,
                (int) ($alert['check_interval_seconds'] ?? 300)
            );
            $nextCheckAt = time() + $interval;

            try {
                $observation = $this->data->observation($alert);
                $target = $alert['threshold_value'] !== null
                    ? (float) $alert['threshold_value']
                    : ($alert['comparison_value'] ?? null);
                $previous = $alert['last_observed_value'] ?? null;
                $previousCondition = $alert['last_condition_state'] === null
                    ? null
                    : ((int) $alert['last_condition_state'] === 1);
                $evaluation = $this->evaluator->evaluate(
                    (string) $alert['operator'],
                    $observation['value'],
                    $target,
                    $previous,
                    $previousCondition,
                    (float) ($alert['hysteresis'] ?? 0.0)
                );

                if (
                    $evaluation['trigger']
                    && $this->repository->canNotify($alert, time())
                ) {
                    $dedupKey = $this->dedupKey(
                        $alert,
                        $evaluation['normalized_value']
                    );

                    $this->telegram->sendMessage(
                        (int) $alert['chat_id'],
                        $this->notification(
                            $alert,
                            $observation
                        )
                    );

                    if ($this->repository->recordNotification(
                        (int) $alert['id'],
                        $dedupKey,
                        $evaluation['normalized_value'],
                        time()
                    )) {
                        $result['triggered']++;
                    }
                }

                /*
                 * State is committed only after a due notification has been
                 * delivered. A transient Telegram error therefore leaves the
                 * previous condition state intact and the next worker run can
                 * retry without losing the transition.
                 */
                $this->repository->markChecked(
                    (int) $alert['id'],
                    $evaluation,
                    $nextCheckAt
                );
            } catch (Throwable $exception) {
                $result['failed']++;
                $failures = max(
                    1,
                    (int) ($alert['failure_count'] ?? 0) + 1
                );
                $delay = min(
                    3600,
                    $interval * min(8, $failures)
                );
                $this->repository->markFailure(
                    (int) $alert['id'],
                    $exception->getMessage(),
                    time() + $delay
                );
                $this->log(
                    'alert:' . (int) $alert['id'],
                    $exception
                );
            }
        }

        $result['pruned'] = $this->repository->pruneNotifications(
            $retentionDays
        );

        return $result;
    }

    /**
     * @param array<string, mixed> $alert
     * @param array<string, mixed> $observation
     */
    private function notification(
        array $alert,
        array $observation
    ): string {
        $type = (string) $alert['alert_type'];
        $subject = (string) $alert['subject'];
        $operator = (string) $alert['operator'];
        $target = $alert['threshold_value'] !== null
            ? (string) $alert['threshold_value']
            : (string) ($alert['comparison_value'] ?? '');

        if ($type === 'currency') {
            $subject .= '/'
                . (string) ($alert['secondary_subject'] ?? '');
        }

        return "🔔 هشدار هوشمند فعال شد\n\n"
            . 'شناسه: #' . (int) $alert['id'] . "\n"
            . "نوع: {$type}\n"
            . "موضوع: {$subject}\n"
            . "شرط: {$operator} {$target}\n"
            . 'مقدار فعلی: ' . (string) $observation['display'] . "\n"
            . 'جزئیات: ' . (string) $observation['details']
            . "\n\nمدیریت: /alerts";
    }

    /**
     * @param array<string, mixed> $alert
     */
    private function dedupKey(
        array $alert,
        string $value
    ): string {
        $cooldown = max(
            60,
            (int) ($alert['cooldown_seconds'] ?? 3600)
        );

        return hash(
            'sha256',
            implode(':', [
                (string) $alert['id'],
                (string) $alert['operator'],
                (string) ($alert['comparison_value'] ?? $alert['threshold_value'] ?? ''),
                $value,
                (string) intdiv(time(), $cooldown),
            ])
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
