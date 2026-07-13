<?php

declare(strict_types=1);

namespace SmartToolbox\Modules\Developer;

use RuntimeException;

final class JsonPathEvaluator
{
    public function evaluate(mixed $data, string $path): mixed
    {
        $path = trim($path);

        if ($path === '$') {
            return $data;
        }

        if (!str_starts_with($path, '$')) {
            throw new RuntimeException('JSONPath must start with $.');
        }

        $tokens = $this->tokens(substr($path, 1));
        $values = [$data];

        foreach ($tokens as $token) {
            $next = [];

            foreach ($values as $value) {
                if ($token === '*') {
                    if (is_array($value)) {
                        foreach ($value as $item) {
                            $next[] = $item;
                        }
                    }
                    continue;
                }

                if (!is_array($value)) {
                    continue;
                }

                if (array_key_exists($token, $value)) {
                    $next[] = $value[$token];
                    continue;
                }

                if (ctype_digit($token)) {
                    $index = (int) $token;
                    if (array_key_exists($index, $value)) {
                        $next[] = $value[$index];
                    }
                }
            }

            $values = $next;
        }

        if ($values === []) {
            throw new RuntimeException('JSONPath did not match any value.');
        }

        return count($values) === 1
            ? $values[0]
            : $values;
    }

    /**
     * @return list<string>
     */
    private function tokens(string $path): array
    {
        $tokens = [];
        $offset = 0;
        $length = strlen($path);

        while ($offset < $length) {
            if ($path[$offset] === '.') {
                $offset++;
                if ($offset < $length && $path[$offset] === '*') {
                    $tokens[] = '*';
                    $offset++;
                    continue;
                }

                if (preg_match('/\G([A-Za-z_][A-Za-z0-9_-]*)/A', $path, $matches, 0, $offset) !== 1) {
                    throw new RuntimeException('JSONPath property syntax is invalid.');
                }

                $tokens[] = $matches[1];
                $offset += strlen($matches[1]);
                continue;
            }

            if ($path[$offset] === '[') {
                $end = strpos($path, ']', $offset);
                if ($end === false) {
                    throw new RuntimeException('JSONPath bracket is not closed.');
                }

                $inside = trim(substr($path, $offset + 1, $end - $offset - 1));
                if ($inside === '*') {
                    $tokens[] = '*';
                } elseif (preg_match('/^\d+$/', $inside) === 1) {
                    $tokens[] = $inside;
                } elseif (preg_match('/^[\'\"](.+)[\'\"]$/', $inside, $matches) === 1) {
                    $tokens[] = stripcslashes($matches[1]);
                } else {
                    throw new RuntimeException('JSONPath bracket selector is unsupported.');
                }

                $offset = $end + 1;
                continue;
            }

            throw new RuntimeException('JSONPath contains an unsupported token.');
        }

        return $tokens;
    }
}
