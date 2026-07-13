<?php

declare(strict_types=1);

namespace SmartToolbox\Modules\GroupManagement;

use Throwable;

final class GroupWorker
{
    public function __construct(
        private readonly GroupRepository $repository,
        private readonly GroupModerationService $moderation,
        private readonly string $logFile
    ) {
    }

    /**
     * @return array{
     *     sanctions_lifted: int,
     *     sanctions_failed: int,
     *     captchas_expired: int,
     *     captchas_failed: int,
     *     pruned: int
     * }
     */
    public function run(
        int $batchSize = 20,
        int $retentionDays = 180
    ): array {
        $batchSize = max(
            1,
            min(100, $batchSize)
        );

        $result = [
            'sanctions_lifted' => 0,
            'sanctions_failed' => 0,
            'captchas_expired' => 0,
            'captchas_failed' => 0,
            'pruned' => 0,
        ];

        foreach (
            $this->repository->dueSanctions(
                $batchSize
            )
            as $sanction
        ) {
            $id = (int) $sanction['id'];
            $chatId = (int) $sanction[
                'chat_id'
            ];
            $userId = (int) $sanction[
                'user_id'
            ];
            $type = (string) $sanction[
                'sanction_type'
            ];

            try {
                if ($type === 'ban') {
                    $this->moderation->unban(
                        $chatId,
                        $userId
                    );
                } else {
                    $this->moderation->unmute(
                        $chatId,
                        $userId
                    );
                }

                $this->repository
                    ->completeSanction(
                        $id,
                        true
                    );

                $this->repository->audit(
                    $chatId,
                    null,
                    $userId,
                    'sanction.expired',
                    [
                        'sanction_id' => $id,
                        'type' => $type,
                    ]
                );

                $result[
                    'sanctions_lifted'
                ]++;
            } catch (Throwable $exception) {
                $this->repository
                    ->completeSanction(
                        $id,
                        false,
                        $exception->getMessage()
                    );

                $this->repository->audit(
                    $chatId,
                    null,
                    $userId,
                    'sanction.expire_failed',
                    [
                        'sanction_id' => $id,
                        'type' => $type,
                    ],
                    false,
                    $exception->getMessage()
                );

                $this->log(
                    'sanction:' . $id,
                    $exception
                );

                $result[
                    'sanctions_failed'
                ]++;
            }
        }

        foreach (
            $this->repository
                ->expiredCaptchas(
                    $batchSize
                )
            as $challenge
        ) {
            $id = (int) $challenge['id'];
            $chatId = (int) $challenge[
                'chat_id'
            ];
            $userId = (int) $challenge[
                'user_id'
            ];
            $settings =
                $this->repository->settings(
                    $chatId
                );

            try {
                if (
                    $settings[
                        'captcha_failure_action'
                    ] === 'ban'
                ) {
                    $this->moderation->ban(
                        $chatId,
                        $userId,
                        null,
                        null,
                        'Captcha expired',
                        'ban'
                    );
                } else {
                    $this->moderation->kick(
                        $chatId,
                        $userId,
                        null,
                        'Captcha expired'
                    );
                }

                $this->repository
                    ->revokeActiveSanctions(
                        $chatId,
                        $userId,
                        ['captcha']
                    );

                $this->repository
                    ->finishCaptcha(
                        $id,
                        'expired'
                    );

                $this->repository->audit(
                    $chatId,
                    null,
                    $userId,
                    'captcha.expired',
                    ['challenge_id' => $id]
                );

                $result[
                    'captchas_expired'
                ]++;
            } catch (Throwable $exception) {
                $this->repository->audit(
                    $chatId,
                    null,
                    $userId,
                    'captcha.expire_failed',
                    ['challenge_id' => $id],
                    false,
                    $exception->getMessage()
                );

                $this->log(
                    'captcha:' . $id,
                    $exception
                );

                $result[
                    'captchas_failed'
                ]++;
            }
        }

        $result['pruned'] =
            $this->repository->prune(
                max(1, $retentionDays)
            );

        return $result;
    }

    private function log(
        string $operation,
        Throwable $exception
    ): void {
        $directory = dirname(
            $this->logFile
        );

        if (!is_dir($directory)) {
            @mkdir(
                $directory,
                0700,
                true
            );
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
