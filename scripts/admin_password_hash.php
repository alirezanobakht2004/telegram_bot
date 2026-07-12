<?php

declare(strict_types=1);

$password = '';

if (
    isset($argv[1])
    && trim($argv[1]) !== ''
) {
    $password = trim($argv[1]);
} else {
    $alphabet =
        'ABCDEFGHJKLMNPQRSTUVWXYZ'
        . 'abcdefghijkmnopqrstuvwxyz'
        . '23456789'
        . '!@#$%^&*_-+=';

    for (
        $index = 0;
        $index < 24;
        $index++
    ) {
        $password .= $alphabet[
            random_int(
                0,
                strlen($alphabet) - 1
            )
        ];
    }
}

$hash = password_hash(
    $password,
    PASSWORD_DEFAULT
);

if (!is_string($hash)) {
    fwrite(
        STDERR,
        "Could not create password hash.\n"
    );

    exit(1);
}

echo "Admin password (save it now):\n";
echo $password . "\n\n";
echo "Password hash for config/local.php:\n";
echo $hash . "\n";
