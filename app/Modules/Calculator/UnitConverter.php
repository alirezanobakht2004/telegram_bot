<?php

declare(strict_types=1);

namespace SmartToolbox\Modules\Calculator;

use InvalidArgumentException;

final class UnitConverter
{
    /**
     * @var array<string, array{
     *     category: string,
     *     symbol: string,
     *     label: string,
     *     factor?: float
     * }>
     */
    private array $units;

    /**
     * @var array<string, string>
     */
    private array $aliases;

    public function __construct()
    {
        $this->units = $this->buildUnits();
        $this->aliases = $this->buildAliases();
    }

    /**
     * @return array{
     *     amount: float,
     *     result: float,
     *     category: string,
     *     category_label: string,
     *     from: string,
     *     from_symbol: string,
     *     from_label: string,
     *     to: string,
     *     to_symbol: string,
     *     to_label: string
     * }
     */
    public function convert(
        float $amount,
        string $from,
        string $to
    ): array {
        if (!is_finite($amount)) {
            throw new InvalidArgumentException(
                'مقدار تبدیل معتبر نیست.'
            );
        }

        $fromUnit = $this->resolveUnit($from);
        $toUnit = $this->resolveUnit($to);

        $fromDefinition = $this->units[$fromUnit];
        $toDefinition = $this->units[$toUnit];

        if (
            $fromDefinition['category']
            !== $toDefinition['category']
        ) {
            throw new InvalidArgumentException(
                "واحدهای «{$from}» و «{$to}» "
                . 'از یک دسته نیستند.'
            );
        }

        $category = $fromDefinition['category'];

        if ($category === 'temperature') {
            $baseValue = $this->temperatureToKelvin(
                $amount,
                $fromUnit
            );

            $result = $this->kelvinToTemperature(
                $baseValue,
                $toUnit
            );
        } else {
            $fromFactor = $fromDefinition[
                'factor'
            ] ?? null;

            $toFactor = $toDefinition[
                'factor'
            ] ?? null;

            if (
                !is_float($fromFactor)
                && !is_int($fromFactor)
            ) {
                throw new InvalidArgumentException(
                    'ضریب واحد مبدأ معتبر نیست.'
                );
            }

            if (
                !is_float($toFactor)
                && !is_int($toFactor)
            ) {
                throw new InvalidArgumentException(
                    'ضریب واحد مقصد معتبر نیست.'
                );
            }

            $result = $amount
                * (float) $fromFactor
                / (float) $toFactor;
        }

        if (!is_finite($result)) {
            throw new InvalidArgumentException(
                'نتیجه تبدیل بیش از حد بزرگ است.'
            );
        }

        return [
            'amount' => $amount,
            'result' => $result,
            'category' => $category,
            'category_label' =>
                $this->categoryLabel($category),
            'from' => $fromUnit,
            'from_symbol' =>
                $fromDefinition['symbol'],
            'from_label' =>
                $fromDefinition['label'],
            'to' => $toUnit,
            'to_symbol' =>
                $toDefinition['symbol'],
            'to_label' =>
                $toDefinition['label'],
        ];
    }

    public function supportedUnitsText(): string
    {
        return "📚 واحدهای پشتیبانی‌شده\n\n"
            . "📏 طول:\n"
            . "mm, cm, m, km, in, ft, yd, mi\n\n"
            . "⚖️ جرم:\n"
            . "mg, g, kg, oz, lb, ton\n\n"
            . "🌡 دما:\n"
            . "C, F, K\n\n"
            . "⬛️ مساحت:\n"
            . "mm2, cm2, m2, km2, in2, ft2, "
            . "yd2, acre, ha\n\n"
            . "🧪 حجم:\n"
            . "ml, l, m3, tsp, tbsp, cup, "
            . "pint, quart, gal\n\n"
            . "🏎 سرعت:\n"
            . "m/s, km/h, mph, knot\n\n"
            . "⏱ زمان:\n"
            . "ms, s, min, h, day, week\n\n"
            . "💾 داده:\n"
            . "bit, byte, KB, MB, GB, TB, "
            . "KiB, MiB, GiB, TiB\n\n"
            . "نمونه‌ها:\n"
            . "/convert 10 km mi\n"
            . "/convert 32 F C\n"
            . "/convert 1 GiB MiB\n"
            . "/convert 2 ساعت دقیقه";
    }

    private function resolveUnit(string $unit): string
    {
        $normalized = $this->normalizeUnit(
            $unit
        );

        $canonical = $this->aliases[
            $normalized
        ] ?? null;

        if (
            $canonical === null
            || !isset($this->units[$canonical])
        ) {
            throw new InvalidArgumentException(
                "واحد «{$unit}» پشتیبانی نمی‌شود."
            );
        }

        return $canonical;
    }

    private function normalizeUnit(string $unit): string
    {
        $unit = strtr(
            trim($unit),
            [
                'ي' => 'ی',
                'ى' => 'ی',
                'ك' => 'ک',
                '‌' => '',
                ' ' => '',
                '²' => '2',
                '³' => '3',
                '／' => '/',
                '°' => '',
            ]
        );

        return mb_strtolower($unit);
    }

    /**
     * @return array<string, array{
     *     category: string,
     *     symbol: string,
     *     label: string,
     *     factor?: float
     * }>
     */
    private function buildUnits(): array
    {
        return [
            'mm' => [
                'category' => 'length',
                'symbol' => 'mm',
                'label' => 'میلی‌متر',
                'factor' => 0.001,
            ],
            'cm' => [
                'category' => 'length',
                'symbol' => 'cm',
                'label' => 'سانتی‌متر',
                'factor' => 0.01,
            ],
            'm' => [
                'category' => 'length',
                'symbol' => 'm',
                'label' => 'متر',
                'factor' => 1.0,
            ],
            'km' => [
                'category' => 'length',
                'symbol' => 'km',
                'label' => 'کیلومتر',
                'factor' => 1000.0,
            ],
            'in' => [
                'category' => 'length',
                'symbol' => 'in',
                'label' => 'اینچ',
                'factor' => 0.0254,
            ],
            'ft' => [
                'category' => 'length',
                'symbol' => 'ft',
                'label' => 'فوت',
                'factor' => 0.3048,
            ],
            'yd' => [
                'category' => 'length',
                'symbol' => 'yd',
                'label' => 'یارد',
                'factor' => 0.9144,
            ],
            'mi' => [
                'category' => 'length',
                'symbol' => 'mi',
                'label' => 'مایل',
                'factor' => 1609.344,
            ],

            'mg' => [
                'category' => 'mass',
                'symbol' => 'mg',
                'label' => 'میلی‌گرم',
                'factor' => 0.001,
            ],
            'g' => [
                'category' => 'mass',
                'symbol' => 'g',
                'label' => 'گرم',
                'factor' => 1.0,
            ],
            'kg' => [
                'category' => 'mass',
                'symbol' => 'kg',
                'label' => 'کیلوگرم',
                'factor' => 1000.0,
            ],
            'oz' => [
                'category' => 'mass',
                'symbol' => 'oz',
                'label' => 'اونس',
                'factor' => 28.349523125,
            ],
            'lb' => [
                'category' => 'mass',
                'symbol' => 'lb',
                'label' => 'پوند',
                'factor' => 453.59237,
            ],
            'ton' => [
                'category' => 'mass',
                'symbol' => 'ton',
                'label' => 'تن متریک',
                'factor' => 1_000_000.0,
            ],

            'c' => [
                'category' => 'temperature',
                'symbol' => '°C',
                'label' => 'درجه سلسیوس',
            ],
            'f' => [
                'category' => 'temperature',
                'symbol' => '°F',
                'label' => 'درجه فارنهایت',
            ],
            'k' => [
                'category' => 'temperature',
                'symbol' => 'K',
                'label' => 'کلوین',
            ],

            'mm2' => [
                'category' => 'area',
                'symbol' => 'mm²',
                'label' => 'میلی‌متر مربع',
                'factor' => 0.000001,
            ],
            'cm2' => [
                'category' => 'area',
                'symbol' => 'cm²',
                'label' => 'سانتی‌متر مربع',
                'factor' => 0.0001,
            ],
            'm2' => [
                'category' => 'area',
                'symbol' => 'm²',
                'label' => 'متر مربع',
                'factor' => 1.0,
            ],
            'km2' => [
                'category' => 'area',
                'symbol' => 'km²',
                'label' => 'کیلومتر مربع',
                'factor' => 1_000_000.0,
            ],
            'in2' => [
                'category' => 'area',
                'symbol' => 'in²',
                'label' => 'اینچ مربع',
                'factor' => 0.00064516,
            ],
            'ft2' => [
                'category' => 'area',
                'symbol' => 'ft²',
                'label' => 'فوت مربع',
                'factor' => 0.09290304,
            ],
            'yd2' => [
                'category' => 'area',
                'symbol' => 'yd²',
                'label' => 'یارد مربع',
                'factor' => 0.83612736,
            ],
            'acre' => [
                'category' => 'area',
                'symbol' => 'acre',
                'label' => 'ایکر',
                'factor' => 4046.8564224,
            ],
            'ha' => [
                'category' => 'area',
                'symbol' => 'ha',
                'label' => 'هکتار',
                'factor' => 10_000.0,
            ],

            'ml' => [
                'category' => 'volume',
                'symbol' => 'ml',
                'label' => 'میلی‌لیتر',
                'factor' => 0.001,
            ],
            'l' => [
                'category' => 'volume',
                'symbol' => 'L',
                'label' => 'لیتر',
                'factor' => 1.0,
            ],
            'm3' => [
                'category' => 'volume',
                'symbol' => 'm³',
                'label' => 'متر مکعب',
                'factor' => 1000.0,
            ],
            'tsp' => [
                'category' => 'volume',
                'symbol' => 'tsp',
                'label' => 'قاشق چای‌خوری',
                'factor' => 0.00492892159375,
            ],
            'tbsp' => [
                'category' => 'volume',
                'symbol' => 'tbsp',
                'label' => 'قاشق غذاخوری',
                'factor' => 0.01478676478125,
            ],
            'cup' => [
                'category' => 'volume',
                'symbol' => 'cup',
                'label' => 'پیمانه آمریکایی',
                'factor' => 0.2365882365,
            ],
            'pint' => [
                'category' => 'volume',
                'symbol' => 'pint',
                'label' => 'پینت آمریکایی',
                'factor' => 0.473176473,
            ],
            'quart' => [
                'category' => 'volume',
                'symbol' => 'quart',
                'label' => 'کوارت آمریکایی',
                'factor' => 0.946352946,
            ],
            'gal' => [
                'category' => 'volume',
                'symbol' => 'gal',
                'label' => 'گالن آمریکایی',
                'factor' => 3.785411784,
            ],

            'mps' => [
                'category' => 'speed',
                'symbol' => 'm/s',
                'label' => 'متر بر ثانیه',
                'factor' => 1.0,
            ],
            'kmh' => [
                'category' => 'speed',
                'symbol' => 'km/h',
                'label' => 'کیلومتر بر ساعت',
                'factor' => 0.2777777777777778,
            ],
            'mph' => [
                'category' => 'speed',
                'symbol' => 'mph',
                'label' => 'مایل بر ساعت',
                'factor' => 0.44704,
            ],
            'knot' => [
                'category' => 'speed',
                'symbol' => 'knot',
                'label' => 'گره دریایی',
                'factor' => 0.5144444444444445,
            ],

            'ms' => [
                'category' => 'time',
                'symbol' => 'ms',
                'label' => 'میلی‌ثانیه',
                'factor' => 0.001,
            ],
            's' => [
                'category' => 'time',
                'symbol' => 's',
                'label' => 'ثانیه',
                'factor' => 1.0,
            ],
            'min' => [
                'category' => 'time',
                'symbol' => 'min',
                'label' => 'دقیقه',
                'factor' => 60.0,
            ],
            'h' => [
                'category' => 'time',
                'symbol' => 'h',
                'label' => 'ساعت',
                'factor' => 3600.0,
            ],
            'day' => [
                'category' => 'time',
                'symbol' => 'day',
                'label' => 'روز',
                'factor' => 86400.0,
            ],
            'week' => [
                'category' => 'time',
                'symbol' => 'week',
                'label' => 'هفته',
                'factor' => 604800.0,
            ],

            'bit' => [
                'category' => 'data',
                'symbol' => 'bit',
                'label' => 'بیت',
                'factor' => 0.125,
            ],
            'byte' => [
                'category' => 'data',
                'symbol' => 'B',
                'label' => 'بایت',
                'factor' => 1.0,
            ],
            'kb' => [
                'category' => 'data',
                'symbol' => 'KB',
                'label' => 'کیلوبایت ده‌دهی',
                'factor' => 1000.0,
            ],
            'mb' => [
                'category' => 'data',
                'symbol' => 'MB',
                'label' => 'مگابایت ده‌دهی',
                'factor' => 1_000_000.0,
            ],
            'gb' => [
                'category' => 'data',
                'symbol' => 'GB',
                'label' => 'گیگابایت ده‌دهی',
                'factor' => 1_000_000_000.0,
            ],
            'tb' => [
                'category' => 'data',
                'symbol' => 'TB',
                'label' => 'ترابایت ده‌دهی',
                'factor' => 1_000_000_000_000.0,
            ],
            'kib' => [
                'category' => 'data',
                'symbol' => 'KiB',
                'label' => 'کیبی‌بایت',
                'factor' => 1024.0,
            ],
            'mib' => [
                'category' => 'data',
                'symbol' => 'MiB',
                'label' => 'مبی‌بایت',
                'factor' => 1_048_576.0,
            ],
            'gib' => [
                'category' => 'data',
                'symbol' => 'GiB',
                'label' => 'گیبی‌بایت',
                'factor' => 1_073_741_824.0,
            ],
            'tib' => [
                'category' => 'data',
                'symbol' => 'TiB',
                'label' => 'تبی‌بایت',
                'factor' => 1_099_511_627_776.0,
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function buildAliases(): array
    {
        $aliases = [];

        $groups = [
            'mm' => [
                'mm',
                'millimeter',
                'millimeters',
                'میلیمتر',
            ],
            'cm' => [
                'cm',
                'centimeter',
                'centimeters',
                'سانتیمتر',
                'سانت',
            ],
            'm' => [
                'm',
                'meter',
                'meters',
                'metre',
                'metres',
                'متر',
            ],
            'km' => [
                'km',
                'kilometer',
                'kilometers',
                'kilometre',
                'kilometres',
                'کیلومتر',
            ],
            'in' => [
                'in',
                'inch',
                'inches',
                'اینچ',
            ],
            'ft' => [
                'ft',
                'foot',
                'feet',
                'فوت',
            ],
            'yd' => [
                'yd',
                'yard',
                'yards',
                'یارد',
            ],
            'mi' => [
                'mi',
                'mile',
                'miles',
                'مایل',
            ],

            'mg' => [
                'mg',
                'milligram',
                'milligrams',
                'میلیگرم',
            ],
            'g' => [
                'g',
                'gram',
                'grams',
                'گرم',
            ],
            'kg' => [
                'kg',
                'kilogram',
                'kilograms',
                'کیلوگرم',
                'کیلو',
            ],
            'oz' => [
                'oz',
                'ounce',
                'ounces',
                'اونس',
            ],
            'lb' => [
                'lb',
                'lbs',
                'pound',
                'pounds',
                'پوند',
            ],
            'ton' => [
                'ton',
                'tons',
                'tonne',
                'tonnes',
                'تن',
            ],

            'c' => [
                'c',
                'celsius',
                'centigrade',
                'سلسیوس',
                'سانتیگراد',
            ],
            'f' => [
                'f',
                'fahrenheit',
                'فارنهایت',
            ],
            'k' => [
                'k',
                'kelvin',
                'کلوین',
            ],

            'mm2' => [
                'mm2',
                'میلیمترمربع',
            ],
            'cm2' => [
                'cm2',
                'سانتیمترمربع',
            ],
            'm2' => [
                'm2',
                'مترمربع',
            ],
            'km2' => [
                'km2',
                'کیلومترمربع',
            ],
            'in2' => [
                'in2',
                'اینچمربع',
            ],
            'ft2' => [
                'ft2',
                'فوتمربع',
            ],
            'yd2' => [
                'yd2',
                'یاردمربع',
            ],
            'acre' => [
                'acre',
                'acres',
                'ایکر',
            ],
            'ha' => [
                'ha',
                'hectare',
                'hectares',
                'هکتار',
            ],

            'ml' => [
                'ml',
                'milliliter',
                'milliliters',
                'میلیلیتر',
            ],
            'l' => [
                'l',
                'liter',
                'liters',
                'litre',
                'litres',
                'لیتر',
            ],
            'm3' => [
                'm3',
                'مترمکعب',
            ],
            'tsp' => [
                'tsp',
                'teaspoon',
                'teaspoons',
                'قاشقچایخوری',
            ],
            'tbsp' => [
                'tbsp',
                'tablespoon',
                'tablespoons',
                'قاشقغذاخوری',
            ],
            'cup' => [
                'cup',
                'cups',
                'پیمانه',
            ],
            'pint' => [
                'pint',
                'pints',
                'پینت',
            ],
            'quart' => [
                'quart',
                'quarts',
                'کوارت',
            ],
            'gal' => [
                'gal',
                'gallon',
                'gallons',
                'گالن',
            ],

            'mps' => [
                'm/s',
                'mps',
                'متر/ثانیه',
                'متر/بر/ثانیه',
                'متربرثانیه',
            ],
            'kmh' => [
                'km/h',
                'kmh',
                'kph',
                'کیلومتر/ساعت',
                'کیلومتر/بر/ساعت',
                'کیلومتر‌بر‌ساعت',
                'کیلومتر برساعت',
            ],
            'mph' => [
                'mph',
                'مایل/ساعت',
                'مایلبرساعت',
            ],
            'knot' => [
                'knot',
                'knots',
                'گره',
            ],

            'ms' => [
                'ms',
                'millisecond',
                'milliseconds',
                'میلیثانیه',
            ],
            's' => [
                's',
                'sec',
                'second',
                'seconds',
                'ثانیه',
            ],
            'min' => [
                'min',
                'minute',
                'minutes',
                'دقیقه',
            ],
            'h' => [
                'h',
                'hr',
                'hour',
                'hours',
                'ساعت',
            ],
            'day' => [
                'day',
                'days',
                'روز',
            ],
            'week' => [
                'week',
                'weeks',
                'هفته',
            ],

            'bit' => [
                'bit',
                'bits',
                'بیت',
            ],
            'byte' => [
                'byte',
                'bytes',
                'بایت',
            ],
            'kb' => [
                'kb',
                'kilobyte',
                'kilobytes',
                'کیلوبایت',
            ],
            'mb' => [
                'mb',
                'megabyte',
                'megabytes',
                'مگابایت',
            ],
            'gb' => [
                'gb',
                'gigabyte',
                'gigabytes',
                'گیگابایت',
            ],
            'tb' => [
                'tb',
                'terabyte',
                'terabytes',
                'ترابایت',
            ],
            'kib' => [
                'kib',
                'kibibyte',
                'kibibytes',
            ],
            'mib' => [
                'mib',
                'mebibyte',
                'mebibytes',
            ],
            'gib' => [
                'gib',
                'gibibyte',
                'gibibytes',
            ],
            'tib' => [
                'tib',
                'tebibyte',
                'tebibytes',
            ],
        ];

        foreach ($groups as $canonical => $values) {
            foreach ($values as $value) {
                $aliases[
                    $this->normalizeUnit($value)
                ] = $canonical;
            }
        }

        return $aliases;
    }

    private function temperatureToKelvin(
        float $value,
        string $unit
    ): float {
        return match ($unit) {
            'c' => $value + 273.15,
            'f' => ($value - 32.0)
                * 5.0 / 9.0 + 273.15,
            'k' => $value,
            default => throw new InvalidArgumentException(
                'واحد دمای مبدأ معتبر نیست.'
            ),
        };
    }

    private function kelvinToTemperature(
        float $value,
        string $unit
    ): float {
        return match ($unit) {
            'c' => $value - 273.15,
            'f' => ($value - 273.15)
                * 9.0 / 5.0 + 32.0,
            'k' => $value,
            default => throw new InvalidArgumentException(
                'واحد دمای مقصد معتبر نیست.'
            ),
        };
    }

    private function categoryLabel(
        string $category
    ): string {
        return match ($category) {
            'length' => 'طول',
            'mass' => 'جرم',
            'temperature' => 'دما',
            'area' => 'مساحت',
            'volume' => 'حجم',
            'speed' => 'سرعت',
            'time' => 'زمان',
            'data' => 'داده دیجیتال',
            default => $category,
        };
    }
}
