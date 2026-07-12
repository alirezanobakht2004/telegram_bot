<?php

declare(strict_types=1);

namespace SmartToolbox\Core;

use JsonException;
use RuntimeException;

final class FileCache
{
    public function __construct(
        private readonly string $directory
    ) {
        if (
            !is_dir($this->directory)
            && !mkdir(
                $this->directory,
                0700,
                true
            )
            && !is_dir($this->directory)
        ) {
            throw new RuntimeException(
                sprintf(
                    'Could not create cache directory: %s',
                    $this->directory
                )
            );
        }
    }

    public function get(string $key): mixed
    {
        $path = $this->path($key);

        if (!is_file($path)) {
            return null;
        }

        $payload = $this->readPayload($path);

        if (
            $payload === null
            || $payload['expires_at'] <= time()
        ) {
            @unlink($path);

            return null;
        }

        return $payload['value'];
    }

    public function put(
        string $key,
        mixed $value,
        int $ttlSeconds
    ): void {
        if ($ttlSeconds < 1) {
            throw new RuntimeException(
                'Cache TTL must be at least one second.'
            );
        }

        $key = trim($key);

        if ($key === '') {
            throw new RuntimeException(
                'Cache key cannot be empty.'
            );
        }

        $payload = [
            'key' => $key,
            'created_at' => time(),
            'expires_at' =>
                time() + $ttlSeconds,
            'value' => $value,
        ];

        try {
            $json = json_encode(
                $payload,
                JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
            );
        } catch (JsonException $exception) {
            throw new RuntimeException(
                'Could not encode cache value.',
                previous: $exception
            );
        }

        $path = $this->path($key);
        $temporaryPath = $path
            . '.'
            . bin2hex(random_bytes(8))
            . '.tmp';

        if (
            file_put_contents(
                $temporaryPath,
                $json,
                LOCK_EX
            ) === false
        ) {
            throw new RuntimeException(
                'Could not write temporary cache file.'
            );
        }

        @chmod($temporaryPath, 0600);

        if (
            is_file($path)
            && !@unlink($path)
        ) {
            @unlink($temporaryPath);

            throw new RuntimeException(
                'Could not replace existing cache file.'
            );
        }

        if (!@rename($temporaryPath, $path)) {
            @unlink($temporaryPath);

            throw new RuntimeException(
                'Could not finalize cache file.'
            );
        }

        @chmod($path, 0600);

        if (random_int(1, 100) === 1) {
            $this->prune();
        }
    }

    /**
     * @param callable(): mixed $resolver
     */
    public function remember(
        string $key,
        int $ttlSeconds,
        callable $resolver
    ): mixed {
        $cached = $this->get($key);

        if ($cached !== null) {
            return $cached;
        }

        $value = $resolver();

        if ($value !== null) {
            $this->put(
                $key,
                $value,
                $ttlSeconds
            );
        }

        return $value;
    }

    public function forget(string $key): void
    {
        $path = $this->path($key);

        if (is_file($path)) {
            @unlink($path);
        }
    }

    public function prune(): int
    {
        $deleted = 0;

        foreach ($this->files() as $file) {
            $payload = $this->readPayload(
                $file
            );

            if (
                $payload === null
                || $payload['expires_at']
                    <= time()
            ) {
                if (@unlink($file)) {
                    $deleted++;
                }
            }
        }

        return $deleted;
    }

    public function clear(
        ?string $keyPrefix = null
    ): int {
        $prefix = $keyPrefix !== null
            ? trim($keyPrefix)
            : null;
        $deleted = 0;

        foreach ($this->files() as $file) {
            if (
                $prefix !== null
                && $prefix !== ''
            ) {
                $payload =
                    $this->readPayload($file);
                $key = is_array($payload)
                    ? ($payload['key'] ?? null)
                    : null;

                if (
                    !is_string($key)
                    || !str_starts_with(
                        $key,
                        $prefix
                    )
                ) {
                    continue;
                }
            }

            if (@unlink($file)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * @return array{
     *     files: int,
     *     bytes: int,
     *     expired: int,
     *     unidentified: int
     * }
     */
    public function stats(
        ?string $keyPrefix = null
    ): array {
        $prefix = $keyPrefix !== null
            ? trim($keyPrefix)
            : null;

        $result = [
            'files' => 0,
            'bytes' => 0,
            'expired' => 0,
            'unidentified' => 0,
        ];

        foreach ($this->files() as $file) {
            $payload =
                $this->readPayload($file);
            $key = is_array($payload)
                ? ($payload['key'] ?? null)
                : null;

            if (
                $prefix !== null
                && $prefix !== ''
                && (
                    !is_string($key)
                    || !str_starts_with(
                        $key,
                        $prefix
                    )
                )
            ) {
                continue;
            }

            $result['files']++;

            $size = filesize($file);

            if (is_int($size)) {
                $result['bytes'] += $size;
            }

            if ($payload === null) {
                $result['expired']++;
                $result['unidentified']++;

                continue;
            }

            if (!is_string($key)) {
                $result['unidentified']++;
            }

            if (
                $payload['expires_at']
                <= time()
            ) {
                $result['expired']++;
            }
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private function files(): array
    {
        $files = glob(
            $this->directory . '/*.json'
        );

        return is_array($files)
            ? array_values($files)
            : [];
    }

    /**
     * @return array{
     *     key?: string,
     *     created_at?: int,
     *     expires_at: int,
     *     value: mixed
     * }|null
     */
    private function readPayload(
        string $path
    ): ?array {
        $contents = file_get_contents(
            $path
        );

        if ($contents === false) {
            return null;
        }

        try {
            $payload = json_decode(
                $contents,
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException) {
            return null;
        }

        if (
            !is_array($payload)
            || !is_int(
                $payload['expires_at']
                ?? null
            )
            || !array_key_exists(
                'value',
                $payload
            )
        ) {
            return null;
        }

        return $payload;
    }

    private function path(string $key): string
    {
        $key = trim($key);

        if ($key === '') {
            throw new RuntimeException(
                'Cache key cannot be empty.'
            );
        }

        return $this->directory
            . '/'
            . hash('sha256', $key)
            . '.json';
    }
}
