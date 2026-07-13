<?php

declare(strict_types=1);

namespace SmartToolbox\Modules\FileTools;

use RuntimeException;

final class ProcessRunner
{
    /**
     * @param list<string> $command
     * @return array{exit_code: int, stdout: string, stderr: string}
     */
    public function run(
        array $command,
        int $timeoutSeconds,
        int $maxOutputBytes = 1048576
    ): array {
        if (!function_exists('proc_open')) {
            throw new FileToolException(
                'اجرای Process خارجی روی سرور غیرفعال است.',
                'proc_open_unavailable'
            );
        }

        if ($command === []) {
            throw new RuntimeException('Process command is empty.');
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(
            $command,
            $descriptors,
            $pipes,
            null,
            null,
            ['bypass_shell' => true]
        );

        if (!is_resource($process)) {
            throw new FileToolException(
                'Process خارجی شروع نشد.',
                'process_start_failed',
                true
            );
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $startedAt = microtime(true);
        $exitCode = -1;

        try {
            while (true) {
                $status = proc_get_status($process);
                $stdout .= (string) stream_get_contents($pipes[1]);
                $stderr .= (string) stream_get_contents($pipes[2]);

                if (
                    strlen($stdout) + strlen($stderr)
                    > max(1024, $maxOutputBytes)
                ) {
                    proc_terminate($process, 9);

                    throw new FileToolException(
                        'خروجی Process بیش از حد مجاز بود.',
                        'process_output_too_large'
                    );
                }

                if (!($status['running'] ?? false)) {
                    $exitCode = (int) ($status['exitcode'] ?? -1);
                    break;
                }

                if (
                    microtime(true) - $startedAt
                    > max(1, $timeoutSeconds)
                ) {
                    proc_terminate($process, 15);
                    usleep(200000);
                    $status = proc_get_status($process);

                    if ($status['running'] ?? false) {
                        proc_terminate($process, 9);
                    }

                    throw new FileToolException(
                        'زمان اجرای پردازش تمام شد.',
                        'job_timeout'
                    );
                }

                usleep(50000);
            }

            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);
        } finally {
            fclose($pipes[1]);
            fclose($pipes[2]);
            $closedCode = proc_close($process);

            if ($exitCode < 0 && $closedCode >= 0) {
                $exitCode = $closedCode;
            }
        }

        return [
            'exit_code' => $exitCode,
            'stdout' => $stdout,
            'stderr' => $stderr,
        ];
    }
}
