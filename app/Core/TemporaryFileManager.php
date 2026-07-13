<?php

declare(strict_types=1);

namespace SmartToolbox\Core;

use RuntimeException;

final class TemporaryFileManager
{
    public function __construct(
        private readonly string $rootDirectory
    ) {
        if (
            !is_dir($this->rootDirectory)
            && !mkdir($this->rootDirectory, 0700, true)
            && !is_dir($this->rootDirectory)
        ) {
            throw new RuntimeException(
                'Temporary file directory could not be created.'
            );
        }

        @chmod($this->rootDirectory, 0700);
    }

    public function createWorkspace(
        string $owner = 'system'
    ): string {
        $owner = preg_replace(
            '/[^A-Za-z0-9_.-]+/',
            '_',
            trim($owner)
        ) ?? 'system';

        $owner = $owner !== ''
            ? mb_substr($owner, 0, 80)
            : 'system';

        $path = rtrim($this->rootDirectory, '/\\')
            . DIRECTORY_SEPARATOR
            . $owner
            . '-'
            . date('YmdHis')
            . '-'
            . bin2hex(random_bytes(6));

        if (!mkdir($path, 0700, true)) {
            throw new RuntimeException(
                'Temporary workspace could not be created.'
            );
        }

        return $path;
    }

    public function createFile(
        string $workspace,
        string $extension = 'tmp'
    ): string {
        $workspace = $this->assertInsideRoot($workspace);

        if (!is_dir($workspace)) {
            throw new RuntimeException(
                'Temporary workspace does not exist.'
            );
        }

        $extension = mb_strtolower(trim($extension));
        $extension = preg_replace(
            '/[^a-z0-9]+/',
            '',
            $extension
        ) ?? '';

        $filename = bin2hex(random_bytes(12));

        if ($extension !== '') {
            $filename .= '.' . mb_substr($extension, 0, 12);
        }

        $path = $workspace
            . DIRECTORY_SEPARATOR
            . $filename;

        $handle = fopen($path, 'x+b');

        if ($handle === false) {
            throw new RuntimeException(
                'Temporary file could not be created.'
            );
        }

        fclose($handle);
        @chmod($path, 0600);

        return $path;
    }

    public function removeWorkspace(string $workspace): void
    {
        $workspace = $this->assertInsideRoot($workspace);

        if (!is_dir($workspace)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $workspace,
                \FilesystemIterator::SKIP_DOTS
            ),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            $path = $item->getPathname();

            if ($item->isDir()) {
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($workspace);
    }

    public function cleanup(int $olderThanSeconds): int
    {
        $olderThanSeconds = max(60, $olderThanSeconds);
        $cutoff = time() - $olderThanSeconds;
        $deleted = 0;

        $entries = glob(
            rtrim($this->rootDirectory, '/\\')
            . DIRECTORY_SEPARATOR
            . '*'
        );

        if (!is_array($entries)) {
            return 0;
        }

        foreach ($entries as $entry) {
            if (is_link($entry)) {
                $modifiedAt = @filemtime($entry);

                if (
                    is_int($modifiedAt)
                    && $modifiedAt < $cutoff
                    && @unlink($entry)
                ) {
                    $deleted++;
                }

                continue;
            }

            if (!is_dir($entry)) {
                $modifiedAt = @filemtime($entry);

                if (is_int($modifiedAt) && $modifiedAt < $cutoff) {
                    if (@unlink($entry)) {
                        $deleted++;
                    }
                }

                continue;
            }

            $modifiedAt = @filemtime($entry);

            if (!is_int($modifiedAt) || $modifiedAt >= $cutoff) {
                continue;
            }

            $this->removeWorkspace($entry);

            if (!is_dir($entry)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    private function assertInsideRoot(string $path): string
    {
        $root = realpath($this->rootDirectory);
        $resolved = realpath($path);

        if ($root === false || $resolved === false) {
            throw new RuntimeException(
                'Temporary path does not exist.'
            );
        }

        $prefix = rtrim($root, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR;

        if (
            $resolved === $root
            || !str_starts_with(
                $resolved . DIRECTORY_SEPARATOR,
                $prefix
            )
        ) {
            throw new RuntimeException(
                'Temporary path escaped the managed workspace.'
            );
        }

        return $resolved;
    }
}
