<?php

declare(strict_types=1);

namespace SmartToolbox\Modules\Developer;

final class UlidGenerator
{
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public function generate(?int $milliseconds = null): string
    {
        $milliseconds ??= (int) floor(microtime(true) * 1000);
        $milliseconds = max(0, min(281474976710655, $milliseconds));

        $time = '';
        $value = $milliseconds;

        for ($index = 0; $index < 10; $index++) {
            $time = self::ALPHABET[$value % 32] . $time;
            $value = intdiv($value, 32);
        }

        $random = random_bytes(10);
        $bits = '';
        foreach (str_split($random) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';
        for ($offset = 0; $offset < 80; $offset += 5) {
            $encoded .= self::ALPHABET[bindec(substr($bits, $offset, 5))];
        }

        return $time . $encoded;
    }
}
