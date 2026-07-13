<?php

declare(strict_types=1);

namespace SmartToolbox\Modules\Developer;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonException;
use SmartToolbox\Core\CommandRouter;
use SmartToolbox\Core\MessageContext;
use SmartToolbox\Core\ModuleInterface;
use SmartToolbox\Core\RateLimiter;
use Throwable;

final class DeveloperUtilitiesModule implements ModuleInterface
{
    public function __construct(
        private readonly JsonPathEvaluator $jsonPath,
        private readonly UlidGenerator $ulid,
        private readonly CronExpression $cron,
        private readonly RateLimiter $rateLimiter,
        private readonly string $logFile,
        private readonly string $defaultTimezone = 'Asia/Tehran',
        private readonly int $maxInputLength = 3000,
        private readonly int $maxRegexPatternLength = 300,
        private readonly int $regexBacktrackLimit = 100000,
        private readonly int $maxAttempts = 60,
        private readonly int $windowSeconds = 60
    ) {
    }

    public function register(CommandRouter $router): void
    {
        $router->text(
            '🧑‍💻 توسعه‌دهنده',
            function (
                MessageContext $context
            ): void {
                $context->reply(
                    "🧑‍💻 ابزارهای توسعه‌دهندگان\n\n"
                    . "/json و /jsonpath\n"
                    . "/base64 و /base64decode\n"
                    . "/urlencode و /urldecode\n"
                    . "/jwtdecode و /regex\n"
                    . "/uuid و /ulid\n"
                    . "/hash و /timestamp\n"
                    . "/cron و /color\n"
                    . "/ip و /useragent"
                );
            },
            'developer'
        );

        foreach ([
            'json' => 'handleJson',
            'jsonpath' => 'handleJsonPath',
            'urlencode' => 'handleUrlEncode',
            'urldecode' => 'handleUrlDecode',
            'jwtdecode' => 'handleJwtDecode',
            'regex' => 'handleRegex',
            'ulid' => 'handleUlid',
            'hash' => 'handleHash',
            'cron' => 'handleCron',
            'color' => 'handleColor',
            'ip' => 'handleIp',
            'useragent' => 'handleUserAgent',
        ] as $command => $method) {
            $router->command(
                $command,
                function (
                    MessageContext $context,
                    string $arguments
                ) use ($method): void {
                    $this->{$method}(
                        $context,
                        $arguments
                    );
                },
                'developer'
            );
        }
    }

    private function handleJson(
        MessageContext $context,
        string $arguments
    ): void {
        $this->execute(
            $context,
            $arguments,
            function (string $input): string {
                try {
                    $value = json_decode(
                        $input,
                        true,
                        512,
                        JSON_THROW_ON_ERROR
                    );

                    return "✅ JSON معتبر\n\n"
                        . $this->encodePretty($value);
                } catch (JsonException $exception) {
                    throw new InvalidArgumentException(
                        'JSON نامعتبر است: '
                        . $exception->getMessage()
                    );
                }
            },
            'نمونه: /json {"name":"Ali","active":true}'
        );
    }

    private function handleJsonPath(
        MessageContext $context,
        string $arguments
    ): void {
        $this->execute(
            $context,
            $arguments,
            function (string $input): string {
                $parts = preg_split(
                    '/\s+/u',
                    $input,
                    2,
                    PREG_SPLIT_NO_EMPTY
                );

                if (
                    !is_array($parts)
                    || count($parts) !== 2
                ) {
                    throw new InvalidArgumentException(
                        'ابتدا JSONPath و سپس JSON را وارد کن.'
                    );
                }

                try {
                    $json = json_decode(
                        $parts[1],
                        true,
                        512,
                        JSON_THROW_ON_ERROR
                    );
                } catch (JsonException $exception) {
                    throw new InvalidArgumentException(
                        'JSON نامعتبر است: '
                        . $exception->getMessage()
                    );
                }

                $result = $this->jsonPath->evaluate(
                    $json,
                    $parts[0]
                );

                return "🔎 نتیجه JSONPath\n\n"
                    . $this->encodePretty($result);
            },
            'نمونه: /jsonpath $.users[0].name {"users":[{"name":"Ali"}]}'
        );
    }

    private function handleBase64Encode(
        MessageContext $context,
        string $arguments
    ): void {
        $this->execute(
            $context,
            $arguments,
            static fn (string $input): string =>
                "🔤 Base64\n\n"
                . base64_encode($input),
            'نمونه: /base64 hello world'
        );
    }

    private function handleBase64Decode(
        MessageContext $context,
        string $arguments
    ): void {
        $this->execute(
            $context,
            $arguments,
            static function (string $input): string {
                $decoded = base64_decode(
                    preg_replace(
                        '/\s+/',
                        '',
                        $input
                    ) ?? $input,
                    true
                );

                if ($decoded === false) {
                    throw new InvalidArgumentException(
                        'Base64 معتبر نیست.'
                    );
                }

                return "🔓 Base64 Decode\n\n"
                    . $decoded;
            },
            'نمونه: /base64decode aGVsbG8='
        );
    }

    private function handleUrlEncode(
        MessageContext $context,
        string $arguments
    ): void {
        $this->execute(
            $context,
            $arguments,
            static fn (string $input): string =>
                "🔗 URL Encode\n\n"
                . rawurlencode($input),
            'نمونه: /urlencode hello world'
        );
    }

    private function handleUrlDecode(
        MessageContext $context,
        string $arguments
    ): void {
        $this->execute(
            $context,
            $arguments,
            static fn (string $input): string =>
                "🔓 URL Decode\n\n"
                . rawurldecode($input),
            'نمونه: /urldecode hello%20world'
        );
    }

    private function handleJwtDecode(
        MessageContext $context,
        string $arguments
    ): void {
        $this->execute(
            $context,
            $arguments,
            function (string $input): string {
                $parts = explode('.', trim($input));

                if (count($parts) !== 3) {
                    throw new InvalidArgumentException(
                        'JWT باید سه بخش داشته باشد.'
                    );
                }

                $header = $this->decodeJwtPart(
                    $parts[0]
                );

                $payload = $this->decodeJwtPart(
                    $parts[1]
                );

                return "🪪 JWT Decode\n\n"
                    . "Header:\n"
                    . $this->encodePretty($header)
                    . "\n\nPayload:\n"
                    . $this->encodePretty($payload)
                    . "\n\n⚠️ امضا بررسی نشده است؛ "
                    . "این خروجی به‌معنای معتبر یا تأییدشده بودن Token نیست.";
            },
            'نمونه: /jwtdecode eyJ...'
        );
    }

    private function handleRegex(
        MessageContext $context,
        string $arguments
    ): void {
        $this->execute(
            $context,
            $arguments,
            function (string $input): string {
                $parts = preg_split(
                    '/\s+/u',
                    $input,
                    2,
                    PREG_SPLIT_NO_EMPTY
                );

                if (
                    !is_array($parts)
                    || count($parts) !== 2
                ) {
                    throw new InvalidArgumentException(
                        'Regex و متن باید جدا وارد شوند.'
                    );
                }

                $pattern = $parts[0];
                $subject = $parts[1];

                if (
                    mb_strlen($pattern)
                    > $this->safeRegexLength()
                ) {
                    throw new InvalidArgumentException(
                        'Regex بیش از حد طولانی است.'
                    );
                }

                if (
                    preg_match(
                        '/^(.)(.*)\1([imsxuADSUXJ]*)$/s',
                        $pattern
                    ) !== 1
                ) {
                    throw new InvalidArgumentException(
                        'Regex باید همراه Delimiter باشد؛ مانند /php/i.'
                    );
                }

                $oldBacktrack = ini_get(
                    'pcre.backtrack_limit'
                );
                $oldRecursion = ini_get(
                    'pcre.recursion_limit'
                );

                ini_set(
                    'pcre.backtrack_limit',
                    (string) $this->safeBacktrackLimit()
                );
                ini_set(
                    'pcre.recursion_limit',
                    '10000'
                );

                try {
                    $matched = @preg_match_all(
                        $pattern,
                        $subject,
                        $matches,
                        PREG_SET_ORDER
                    );
                } finally {
                    if ($oldBacktrack !== false) {
                        ini_set(
                            'pcre.backtrack_limit',
                            (string) $oldBacktrack
                        );
                    }

                    if ($oldRecursion !== false) {
                        ini_set(
                            'pcre.recursion_limit',
                            (string) $oldRecursion
                        );
                    }
                }

                if ($matched === false) {
                    throw new InvalidArgumentException(
                        'Regex نامعتبر یا پرهزینه است: '
                        . preg_last_error_msg()
                    );
                }

                $preview = array_slice(
                    $matches,
                    0,
                    20
                );

                return "🧩 Regex\n\n"
                    . "تعداد تطابق: {$matched}\n\n"
                    . $this->encodePretty($preview);
            },
            'نمونه: /regex /php/i PHP and php'
        );
    }

    private function handleUuid(
        MessageContext $context,
        string $arguments
    ): void {
        if (!$this->allow($context)) {
            return;
        }

        $bytes = random_bytes(16);
        $bytes[6] = chr(
            (ord($bytes[6]) & 0x0f) | 0x40
        );
        $bytes[8] = chr(
            (ord($bytes[8]) & 0x3f) | 0x80
        );

        $hex = bin2hex($bytes);
        $uuid = sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );

        $context->reply(
            "🆔 UUID v4\n\n{$uuid}"
        );
    }

    private function handleUlid(
        MessageContext $context,
        string $arguments
    ): void {
        if (!$this->allow($context)) {
            return;
        }

        $context->reply(
            "🆔 ULID\n\n"
            . $this->ulid->generate()
        );
    }

    private function handleHash(
        MessageContext $context,
        string $arguments
    ): void {
        $this->execute(
            $context,
            $arguments,
            static function (string $input): string {
                $parts = preg_split(
                    '/\s+/u',
                    $input,
                    2,
                    PREG_SPLIT_NO_EMPTY
                );

                if (
                    !is_array($parts)
                    || count($parts) !== 2
                ) {
                    throw new InvalidArgumentException(
                        'الگوریتم و متن باید مشخص باشند.'
                    );
                }

                $algorithm = mb_strtolower(
                    $parts[0]
                );

                $allowed = [
                    'sha256',
                    'sha384',
                    'sha512',
                    'sha3-256',
                    'sha3-512',
                    'md5',
                    'sha1',
                ];

                if (
                    !in_array(
                        $algorithm,
                        $allowed,
                        true
                    )
                    || !in_array(
                        $algorithm,
                        hash_algos(),
                        true
                    )
                ) {
                    throw new InvalidArgumentException(
                        'الگوریتم پشتیبانی‌شده نیست.'
                    );
                }

                $warning = in_array(
                    $algorithm,
                    ['md5', 'sha1'],
                    true
                )
                    ? "\n\n⚠️ این الگوریتم برای ذخیره رمز عبور امن نیست."
                    : '';

                return "🔐 {$algorithm}\n\n"
                    . hash(
                        $algorithm,
                        $parts[1]
                    )
                    . $warning;
            },
            'نمونه: /hash sha256 hello'
        );
    }

    private function handleTimestamp(
        MessageContext $context,
        string $arguments
    ): void {
        if (!$this->allow($context)) {
            return;
        }

        $input = trim($arguments);
        $timezone = $this->timezone();

        try {
            if ($input === '') {
                $date = new DateTimeImmutable(
                    'now',
                    $timezone
                );
            } elseif (
                preg_match(
                    '/^-?\d+$/',
                    $input
                ) === 1
            ) {
                $date = (
                    new DateTimeImmutable(
                        '@' . $input
                    )
                )->setTimezone($timezone);
            } else {
                $date = new DateTimeImmutable(
                    $input,
                    $timezone
                );
            }

            $context->reply(
                "🕒 Timestamp\n\n"
                . "Unix: "
                . $date->getTimestamp()
                . "\n"
                . "Local: "
                . $date->format(
                    'Y-m-d H:i:s P'
                )
                . "\n"
                . "UTC: "
                . $date->setTimezone(
                    new DateTimeZone('UTC')
                )->format(
                    'Y-m-d H:i:s'
                )
            );
        } catch (Throwable) {
            $context->reply(
                "زمان معتبر نیست.\n\n"
                . "نمونه: /timestamp 1700000000\n"
                . "/timestamp 2026-07-15 18:30"
            );
        }
    }

    private function handleCron(
        MessageContext $context,
        string $arguments
    ): void {
        $this->execute(
            $context,
            $arguments,
            function (string $input): string {
                $runs = $this->cron->nextRuns(
                    $input,
                    $this->timezone(),
                    5
                );

                $lines = [
                    "⏱ Cron: {$input}",
                    '',
                    'پنج اجرای بعدی:',
                ];

                foreach ($runs as $index => $run) {
                    $lines[] = ($index + 1)
                        . '. '
                        . $run->format(
                            'Y-m-d H:i P'
                        );
                }

                return implode("\n", $lines);
            },
            'نمونه: /cron */15 9-17 * * 1-5'
        );
    }

    private function handleColor(
        MessageContext $context,
        string $arguments
    ): void {
        $this->execute(
            $context,
            $arguments,
            static function (string $input): string {
                $hex = ltrim(
                    trim($input),
                    '#'
                );

                if (
                    preg_match(
                        '/^[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/',
                        $hex
                    ) !== 1
                ) {
                    throw new InvalidArgumentException(
                        'رنگ باید Hex سه یا شش رقمی باشد.'
                    );
                }

                if (strlen($hex) === 3) {
                    $hex = $hex[0] . $hex[0]
                        . $hex[1] . $hex[1]
                        . $hex[2] . $hex[2];
                }

                $red = hexdec(
                    substr($hex, 0, 2)
                );
                $green = hexdec(
                    substr($hex, 2, 2)
                );
                $blue = hexdec(
                    substr($hex, 4, 2)
                );

                [$hue, $saturation, $lightness] =
                    self::rgbToHsl(
                        $red,
                        $green,
                        $blue
                    );

                return "🎨 رنگ\n\n"
                    . 'HEX: #'
                    . mb_strtoupper($hex)
                    . "\nRGB: {$red}, {$green}, {$blue}"
                    . "\nHSL: {$hue}°, {$saturation}%, {$lightness}%";
            },
            'نمونه: /color #3366ff'
        );
    }

    private function handleIp(
        MessageContext $context,
        string $arguments
    ): void {
        $this->execute(
            $context,
            $arguments,
            static function (string $input): string {
                $ip = trim($input);

                if (
                    filter_var(
                        $ip,
                        FILTER_VALIDATE_IP
                    ) === false
                ) {
                    throw new InvalidArgumentException(
                        'IP معتبر نیست.'
                    );
                }

                $version = str_contains(
                    $ip,
                    ':'
                ) ? 'IPv6' : 'IPv4';

                $public = filter_var(
                    $ip,
                    FILTER_VALIDATE_IP,
                    FILTER_FLAG_NO_PRIV_RANGE
                    | FILTER_FLAG_NO_RES_RANGE
                ) !== false;

                return "🌐 IP\n\n"
                    . "آدرس: {$ip}\n"
                    . "نسخه: {$version}\n"
                    . 'نوع: '
                    . ($public
                        ? 'عمومی'
                        : 'خصوصی، رزروشده یا محلی');
            },
            'نمونه: /ip 8.8.8.8'
        );
    }

    private function handleUserAgent(
        MessageContext $context,
        string $arguments
    ): void {
        $this->execute(
            $context,
            $arguments,
            static function (string $input): string {
                $browser = 'ناشناخته';
                $operatingSystem = 'ناشناخته';
                $device = preg_match(
                    '/Mobile|Android|iPhone|iPad/i',
                    $input
                ) === 1
                    ? 'موبایل/تبلت'
                    : 'دسکتاپ یا ربات';

                $router->text(
            '🧑‍💻 توسعه‌دهنده',
            function (
                MessageContext $context
            ): void {
                $context->reply(
                    "🧑‍💻 ابزارهای توسعه‌دهندگان\n\n"
                    . "/json و /jsonpath\n"
                    . "/base64 و /base64decode\n"
                    . "/urlencode و /urldecode\n"
                    . "/jwtdecode و /regex\n"
                    . "/uuid و /ulid\n"
                    . "/hash و /timestamp\n"
                    . "/cron و /color\n"
                    . "/ip و /useragent"
                );
            },
            'developer'
        );

        foreach ([
                    'Edge' => '/Edg\/([\d.]+)/',
                    'Chrome' => '/Chrome\/([\d.]+)/',
                    'Firefox' => '/Firefox\/([\d.]+)/',
                    'Safari' => '/Version\/([\d.]+).*Safari\//',
                    'curl' => '/curl\/([\d.]+)/',
                ] as $name => $pattern) {
                    if (
                        preg_match(
                            $pattern,
                            $input,
                            $matches
                        ) === 1
                    ) {
                        $browser = $name
                            . ' '
                            . ($matches[1] ?? '');
                        break;
                    }
                }

                $router->text(
            '🧑‍💻 توسعه‌دهنده',
            function (
                MessageContext $context
            ): void {
                $context->reply(
                    "🧑‍💻 ابزارهای توسعه‌دهندگان\n\n"
                    . "/json و /jsonpath\n"
                    . "/base64 و /base64decode\n"
                    . "/urlencode و /urldecode\n"
                    . "/jwtdecode و /regex\n"
                    . "/uuid و /ulid\n"
                    . "/hash و /timestamp\n"
                    . "/cron و /color\n"
                    . "/ip و /useragent"
                );
            },
            'developer'
        );

        foreach ([
                    'Windows' => '/Windows NT/i',
                    'Android' => '/Android/i',
                    'iOS' => '/iPhone|iPad/i',
                    'macOS' => '/Macintosh|Mac OS X/i',
                    'Linux' => '/Linux/i',
                ] as $name => $pattern) {
                    if (
                        preg_match(
                            $pattern,
                            $input
                        ) === 1
                    ) {
                        $operatingSystem = $name;
                        break;
                    }
                }

                return "🧭 User-Agent\n\n"
                    . "مرورگر: {$browser}\n"
                    . "سیستم‌عامل: {$operatingSystem}\n"
                    . "دستگاه: {$device}\n\n"
                    . "توجه: تشخیص User-Agent قطعی نیست.";
            },
            'نمونه: /useragent Mozilla/5.0 ...'
        );
    }

    /**
     * @param callable(string): string $handler
     */
    private function execute(
        MessageContext $context,
        string $arguments,
        callable $handler,
        string $usage
    ): void {
        if (!$this->allow($context)) {
            return;
        }

        $input = trim($arguments);

        if ($input === '') {
            $context->reply($usage);
            return;
        }

        if (
            mb_strlen($input)
            > $this->safeMaxInputLength()
        ) {
            $context->reply(
                'ورودی بیش از حد طولانی است.'
            );
            return;
        }

        try {
            $output = $handler($input);

            $context->reply(
                mb_substr(
                    $output,
                    0,
                    3900
                )
            );
        } catch (InvalidArgumentException $exception) {
            $context->reply(
                "ورودی معتبر نیست. ⚠️\n\n"
                . $exception->getMessage()
                . "\n\n"
                . $usage
            );
        } catch (Throwable $exception) {
            $this->log($exception);
            $context->reply(
                'پردازش ابزار توسعه‌دهندگان با خطا مواجه شد.'
            );
        }
    }

    private function allow(
        MessageContext $context
    ): bool {
        $result = $this->rateLimiter->attempt(
            'developer:'
            . $context->actorKey(),
            max(1, $this->maxAttempts),
            max(1, $this->windowSeconds)
        );

        if ($result->allowed) {
            return true;
        }

        $context->reply(
            "درخواست‌های زیادی فرستادی. "
            . "{$result->retryAfter} ثانیه دیگر تلاش کن."
        );

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJwtPart(
        string $part
    ): array {
        $padding = strlen($part) % 4;

        if ($padding > 0) {
            $part .= str_repeat(
                '=',
                4 - $padding
            );
        }

        $decoded = base64_decode(
            strtr(
                $part,
                '-_',
                '+/'
            ),
            true
        );

        if ($decoded === false) {
            throw new InvalidArgumentException(
                'بخش Base64URL در JWT معتبر نیست.'
            );
        }

        try {
            $value = json_decode(
                $decoded,
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException $exception) {
            throw new InvalidArgumentException(
                'بخش JSON در JWT معتبر نیست: '
                . $exception->getMessage()
            );
        }

        if (!is_array($value)) {
            throw new InvalidArgumentException(
                'ساختار JWT معتبر نیست.'
            );
        }

        return $value;
    }

    private function encodePretty(
        mixed $value
    ): string {
        try {
            return json_encode(
                $value,
                JSON_THROW_ON_ERROR
                | JSON_PRETTY_PRINT
                | JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
            );
        } catch (JsonException $exception) {
            throw new InvalidArgumentException(
                'خروجی JSON قابل ساخت نیست: '
                . $exception->getMessage()
            );
        }
    }

    private function timezone(): DateTimeZone
    {
        try {
            return new DateTimeZone(
                $this->defaultTimezone
            );
        } catch (Throwable) {
            return new DateTimeZone(
                'Asia/Tehran'
            );
        }
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private static function rgbToHsl(
        int $red,
        int $green,
        int $blue
    ): array {
        $r = $red / 255;
        $g = $green / 255;
        $b = $blue / 255;
        $maximum = max($r, $g, $b);
        $minimum = min($r, $g, $b);
        $lightness = (
            $maximum + $minimum
        ) / 2;

        if ($maximum === $minimum) {
            return [
                0,
                0,
                (int) round(
                    $lightness * 100
                ),
            ];
        }

        $delta = $maximum - $minimum;
        $saturation = $lightness > 0.5
            ? $delta / (
                2 - $maximum - $minimum
            )
            : $delta / (
                $maximum + $minimum
            );

        if ($maximum === $r) {
            $hue = (
                ($g - $b) / $delta
                + ($g < $b ? 6 : 0)
            );
        } elseif ($maximum === $g) {
            $hue = (
                ($b - $r) / $delta
                + 2
            );
        } else {
            $hue = (
                ($r - $g) / $delta
                + 4
            );
        }

        return [
            (int) round(
                $hue * 60
            ),
            (int) round(
                $saturation * 100
            ),
            (int) round(
                $lightness * 100
            ),
        ];
    }

    private function safeMaxInputLength(): int
    {
        return max(
            100,
            min(3900, $this->maxInputLength)
        );
    }

    private function safeRegexLength(): int
    {
        return max(
            20,
            min(1000, $this->maxRegexPatternLength)
        );
    }

    private function safeBacktrackLimit(): int
    {
        return max(
            10000,
            min(
                1000000,
                $this->regexBacktrackLimit
            )
        );
    }

    private function log(
        Throwable $exception
    ): void {
        $directory = dirname(
            $this->logFile
        );

        if (!is_dir($directory)) {
            @mkdir(
                $directory,
                0700,
                true
            );
        }

        @file_put_contents(
            $this->logFile,
            sprintf(
                "[%s] %s\n%s\n\n",
                date(DATE_ATOM),
                $exception->getMessage(),
                $exception->getTraceAsString()
            ),
            FILE_APPEND | LOCK_EX
        );
    }
}
