<?php

declare(strict_types=1);

namespace SmartToolbox\Core;

use RuntimeException;

final class Config
{
    /**
     * @param array<string, mixed> $items
     */
    private function __construct(
        private readonly array $items
    ) {
    }

    public static function load(string $rootPath): self
    {
        $baseFile = $rootPath . '/config/app.php';
        $localFile = $rootPath . '/config/local.php';

        if (!is_file($baseFile)) {
            throw new RuntimeException('Main configuration file was not found.');
        }

        $base = require $baseFile;
        $local = is_file($localFile) ? require $localFile : [];

        if (!is_array($base) || !is_array($local)) {
            throw new RuntimeException('Configuration files must return arrays.');
        }

        return new self(array_replace_recursive($base, $local));
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->items;

        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->items;
    }
}