<?php

declare(strict_types=1);

namespace SmartToolbox\Modules\GitHub;

use SmartToolbox\Core\TelegramClient;
use Throwable;

final class GitHubReleaseWatchService
{
    public function __construct(
        private readonly GitHubClient $client,
        private readonly GitHubWatchRepository $repository,
        private readonly TelegramClient $telegram,
        private readonly string $logFile
    ) {
    }

    /**
     * @return array{checked: int, notified: int, failed: int}
     */
    public function scan(int $limit = 20): array
    {
        $result = [
            'checked' => 0,
            'notified' => 0,
            'failed' => 0,
        ];

        foreach ($this->repository->active($limit) as $watch) {
            $result['checked']++;

            try {
                $fullName = $watch['owner'] . '/' . $watch['repository'];
                $release = $this->client->latestRelease($fullName);
                $currentId = $release !== null && is_int($release['id'] ?? null)
                    ? $release['id']
                    : null;
                $currentTag = $release !== null
                    ? trim((string) ($release['tag_name'] ?? ''))
                    : null;
                $previousId = is_numeric($watch['last_release_id'] ?? null)
                    ? (int) $watch['last_release_id']
                    : null;
                $notified = false;

                if (
                    $release !== null
                    && $currentId !== null
                    && $currentId !== $previousId
                ) {
                    $this->telegram->sendMessage(
                        (int) $watch['chat_id'],
                        $this->releaseMessage($release)
                    );
                    $result['notified']++;
                    $notified = true;
                }

                $this->repository->checked(
                    (int) $watch['id'],
                    $currentId,
                    $currentTag,
                    $notified
                );
            } catch (Throwable $exception) {
                $result['failed']++;
                $this->log(
                    (string) ($watch['owner'] ?? '')
                    . '/'
                    . (string) ($watch['repository'] ?? ''),
                    $exception
                );
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $release
     */
    private function releaseMessage(array $release): string
    {
        $body = trim((string) ($release['body'] ?? ''));
        if (mb_strlen($body) > 1800) {
            $body = mb_substr($body, 0, 1790) . '…';
        }

        $text = "🚀 انتشار جدید GitHub\n\n"
            . "مخزن: {$release['repository']}\n"
            . "نسخه: "
            . (($release['name'] ?? '') !== ''
                ? $release['name']
                : $release['tag_name'])
            . "\nTag: {$release['tag_name']}\n"
            . "زمان: {$release['published_at']}\n";

        if ($body !== '') {
            $text .= "\n{$body}\n";
        }

        if (($release['url'] ?? '') !== '') {
            $text .= "\n🔗 {$release['url']}";
        }

        return trim($text);
    }

    private function log(
        string $repository,
        Throwable $exception
    ): void {
        $directory = dirname($this->logFile);
        if (!is_dir($directory)) {
            @mkdir($directory, 0700, true);
        }

        @file_put_contents(
            $this->logFile,
            sprintf(
                "[%s] [repository:%s] %s\n",
                date(DATE_ATOM),
                str_replace(["\r", "\n"], ' ', mb_substr($repository, 0, 150)),
                $exception->getMessage()
            ),
            FILE_APPEND | LOCK_EX
        );
    }
}
