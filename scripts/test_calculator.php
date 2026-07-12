<?php

declare(strict_types=1);

use SmartToolbox\Modules\Calculator\ExpressionCalculator;
use SmartToolbox\Modules\Calculator\UnitConverter;

$rootPath = dirname(__DIR__);

require $rootPath . '/vendor/autoload.php';

$calculator = new ExpressionCalculator();
$converter = new UnitConverter();

$assertNear = static function (
    float $actual,
    float $expected,
    float $epsilon,
    string $label
): void {
    if (abs($actual - $expected) > $epsilon) {
        throw new RuntimeException(
            sprintf(
                '%s failed: expected %.12f, got %.12f',
                $label,
                $expected,
                $actual
            )
        );
    }
};

$assertNear(
    $calculator->evaluate('2*(3+4)'),
    14.0,
    0.0000000001,
    'basic arithmetic'
);

$assertNear(
    $calculator->evaluate('2^3^2'),
    512.0,
    0.0000000001,
    'right associative power'
);

$assertNear(
    $calculator->evaluate(
        'sqrt(81)+abs(-4)'
    ),
    13.0,
    0.0000000001,
    'functions'
);

$assertNear(
    $calculator->evaluate('۱۲٫۵ × ۲'),
    25.0,
    0.0000000001,
    'Persian digits'
);

$divisionByZeroRejected = false;

try {
    $calculator->evaluate('10/0');
} catch (InvalidArgumentException) {
    $divisionByZeroRejected = true;
}

if (!$divisionByZeroRejected) {
    throw new RuntimeException(
        'Division by zero was not rejected.'
    );
}

$kilometersToMiles = $converter->convert(
    10.0,
    'km',
    'mi'
);

$assertNear(
    $kilometersToMiles['result'],
    6.213711922373,
    0.000000001,
    'kilometers to miles'
);

$fahrenheitToCelsius = $converter->convert(
    32.0,
    'F',
    'C'
);

$assertNear(
    $fahrenheitToCelsius['result'],
    0.0,
    0.000000001,
    'Fahrenheit to Celsius'
);

$gibibytesToMebibytes = $converter->convert(
    1.0,
    'GiB',
    'MiB'
);

$assertNear(
    $gibibytesToMebibytes['result'],
    1024.0,
    0.000000001,
    'GiB to MiB'
);

$hoursToMinutes = $converter->convert(
    2.0,
    'ساعت',
    'دقیقه'
);

$assertNear(
    $hoursToMinutes['result'],
    120.0,
    0.000000001,
    'Persian time units'
);

echo json_encode(
    [
        'status' => 'passed',
        'calculator_tests' => 5,
        'unit_conversion_tests' => 4,
    ],
    JSON_PRETTY_PRINT
    | JSON_UNESCAPED_UNICODE
    | JSON_UNESCAPED_SLASHES
) . PHP_EOL;
