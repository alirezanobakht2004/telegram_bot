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
            && !mkdir($this->directory, 0700, true)
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

        $contents = file_get_contents($path);

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
            @unlink($path);

            return null;
        }

        if (
            !is_array($payload)
            || !is_int($payload['expires_at'] ?? null)
            || !array_key_exists('value', $payload)
        ) {
            @unlink($path);

            return null;
        }

        if ($payload['expires_at'] <= time()) {
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

        $payload = [
            'expires_at' => time() + $ttlSeconds,
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
        $temporaryPath = $path . '.'
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

        if (is_file($path) && !@unlink($path)) {
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
        $this->put($key, $value, $ttlSeconds);

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
        $files = glob($this->directory . '/*.json');

        if ($files === false) {
            return 0;
        }

        $deleted = 0;

        foreach ($files as $file) {
            $contents = file_get_contents($file);

            if ($contents === false) {
                continue;
            }

            try {
                $payload = json_decode(
                    $contents,
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );
            } catch (JsonException) {
                if (@unlink($file)) {
                    $deleted++;
                }

                continue;
            }

            $expiresAt = is_array($payload)
                ? ($payload['expires_at'] ?? null)
                : null;

            if (
                !is_int($expiresAt)
                || $expiresAt <= time()
            ) {
                if (@unlink($file)) {
                    $deleted++;
                }
            }
        }

        return $deleted;
    }

    private function path(string $key): string
    {
        if (trim($key) === '') {
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
