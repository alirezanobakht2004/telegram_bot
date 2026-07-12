<?php

declare(strict_types=1);

namespace SmartToolbox\Modules\Countries;

use RuntimeException;
use SmartToolbox\Core\CommandRouter;
use SmartToolbox\Core\ConversationStateStore;
use SmartToolbox\Core\FileCache;
use SmartToolbox\Core\MessageContext;
use SmartToolbox\Core\ModuleInterface;
use SmartToolbox\Core\RateLimiter;
use Throwable;

final class CountriesModule implements ModuleInterface
{
    private const STATE_AWAITING_COUNTRY =
        'countries.awaiting_country';

    public function __construct(
        private readonly CountryProviderInterface $provider,
        private readonly FileCache $cache,
        private readonly RateLimiter $rateLimiter,
        private readonly ConversationStateStore $states,
        private readonly string $logFile,
        private readonly int $cacheTtl = 86400,
        private readonly int $stateTtl = 300,
        private readonly int $maxAttempts = 30,
        private readonly int $windowSeconds = 60
    ) {
    }

    public function register(CommandRouter $router): void
    {
        $router->command(
            'country',
            function (
                MessageContext $context,
                string $arguments
            ): void {
                $this->handleCountryCommand(
                    $context,
                    $arguments
                );
            }
        );

        $router->command(
            'countrycode',
            function (
                MessageContext $context,
                string $arguments
            ): void {
                $this->handleCountryCommand(
                    $context,
                    $arguments
                );
            }
        );

        $router->command(
            'randomcountry',
            function (MessageContext $context): void {
                $this->sendRandomCountry($context);
            }
        );

        $router->command(
            'random_country',
            function (MessageContext $context): void {
                $this->sendRandomCountry($context);
            }
        );

        $router->text(
            '🌍 کشورها',
            function (MessageContext $context): void {
                $this->askForCountry($context);
            }
        );

        $router->fallbackText(
            function (
                MessageContext $context,
                string $text
            ): bool {
                return $this->handlePendingCountry(
                    $context,
                    $text
                );
            }
        );
    }

    private function handleCountryCommand(
        MessageContext $context,
        string $arguments
    ): void {
        $query = trim($arguments);

        if ($query === '') {
            if ($context->isPrivate()) {
                $this->askForCountry($context);

                return;
            }

            $context->reply(
                $this->usageText()
            );

            return;
        }

        $this->states->clear(
            $context->actorKey()
        );

        $this->sendCountry(
            $context,
            $query
        );
    }

    private function askForCountry(
        MessageContext $context
    ): void {
        if (!$context->isPrivate()) {
            $context->reply(
                $this->usageText()
            );

            return;
        }

        $this->states->set(
            $context->actorKey(),
            self::STATE_AWAITING_COUNTRY,
            ttlSeconds: $this->stateTtl
        );

        $context->reply(
            "نام کشور یا کد ISO آن را بفرست. 🌍\n\n"
            . "نمونه‌ها:\n"
            . "ایران\n"
            . "Japan\n"
            . "DE\n"
            . "BRA\n\n"
            . "کشور تصادفی: /randomcountry\n"
            . "برای لغو: /cancel",
            [
                'reply_markup' => [
                    'force_reply' => true,
                    'selective' => true,
                    'input_field_placeholder' =>
                        'مثلاً ایران یا JP',
                ],
            ]
        );
    }

    private function handlePendingCountry(
        MessageContext $context,
        string $text
    ): bool {
        if (!$context->isPrivate()) {
            return false;
        }

        $state = $this->states->get(
            $context->actorKey()
        );

        if (
            $state === null
            || $state['state']
                !== self::STATE_AWAITING_COUNTRY
        ) {
            return false;
        }

        $query = trim($text);

        if ($query === '') {
            return true;
        }

        $this->states->clear(
            $context->actorKey()
        );

        $this->sendCountry(
            $context,
            $query
        );

        return true;
    }

    private function sendCountry(
        MessageContext $context,
        string $query
    ): void {
        $query = $this->normalizeQuery($query);

        if (mb_strlen($query) < 2) {
            $context->reply(
                'نام یا کد کشور باید حداقل دو کاراکتر داشته باشد.'
            );

            return;
        }

        if (mb_strlen($query) > 100) {
            $context->reply(
                'نام کشور بیش از حد طولانی است.'
            );

            return;
        }

        if (!$this->allowRequest($context)) {
            return;
        }

        try {
            $country = $this->cache->remember(
                'countries.lookup.'
                . mb_strtolower($query),
                $this->cacheTtl,
                fn (): array|null =>
                    $this->provider->find($query)
            );

            if (!is_array($country)) {
                $context->reply(
                    "کشوری با عبارت «{$query}» پیدا نشد. 🔍\n\n"
                    . "نام انگلیسی یا کد دو/سه‌حرفی ISO "
                    . "را امتحان کن؛ مثلاً JP یا JPN."
                );

                return;
            }

            $this->replyWithCountry(
                $context,
                $country
            );
        } catch (Throwable $exception) {
            $this->log(
                $query,
                $exception
            );

            $context->reply(
                "فعلاً دریافت اطلاعات کشور ممکن نشد. ⚠️\n\n"
                . 'چند لحظه بعد دوباره امتحان کن.'
            );
        }
    }

    private function sendRandomCountry(
        MessageContext $context
    ): void {
        $this->states->clear(
            $context->actorKey()
        );

        if (!$this->allowRequest($context)) {
            return;
        }

        try {
            /*
             * نتیجه تصادفی عمداً کش نمی‌شود تا هر بار کشور تازه‌ای
             * دریافت شود.
             */
            $country = $this->provider->random();

            $this->replyWithCountry(
                $context,
                $country,
                true
            );
        } catch (Throwable $exception) {
            $this->log(
                'random',
                $exception
            );

            $context->reply(
                "فعلاً دریافت کشور تصادفی ممکن نشد. ⚠️\n\n"
                . 'چند لحظه بعد دوباره امتحان کن.'
            );
        }
    }

    private function allowRequest(
        MessageContext $context
    ): bool {
        $rateLimit = $this->rateLimiter->attempt(
            'countries:' . $context->actorKey(),
            $this->maxAttempts,
            $this->windowSeconds
        );

        if ($rateLimit->allowed) {
            return true;
        }

        $context->reply(
            "درخواست‌های زیادی فرستادی. ⏳\n\n"
            . "حدود {$rateLimit->retryAfter} ثانیه دیگر "
            . 'دوباره امتحان کن.'
        );

        return false;
    }

    /**
     * @param array<string, mixed> $country
     */
    private function replyWithCountry(
        MessageContext $context,
        array $country,
        bool $random = false
    ): void {
        $caption = $this->formatCountry(
            $country,
            $random
        );

        $flagUrl = $this->flagUrl($country);

        if ($flagUrl === null) {
            $context->reply($caption);

            return;
        }

        try {
            $context->replyWithPhoto(
                $flagUrl,
                $caption
            );
        } catch (Throwable $exception) {
            $this->log(
                'send-flag',
                $exception
            );

            $context->reply($caption);
        }
    }

    /**
     * @param array<string, mixed> $country
     */
    private function formatCountry(
        array $country,
        bool $random
    ): string {
        $name = trim(
            (string) ($country['name'] ?? '')
        );

        if ($name === '') {
            throw new RuntimeException(
                'Country record does not contain a name.'
            );
        }

        $alpha2 = mb_strtoupper(
            trim(
                (string) (
                    $country['alpha2Code'] ?? ''
                )
            )
        );

        $alpha3 = mb_strtoupper(
            trim(
                (string) (
                    $country['alpha3Code'] ?? ''
                )
            )
        );

        $persianName = $this->persianName(
            $alpha2
        );

        $title = $persianName !== null
            && mb_strtolower($persianName)
                !== mb_strtolower($name)
            ? "{$persianName} — {$name}"
            : $name;

        $prefix = $random
            ? "🎲 کشور تصادفی\n\n"
            : '';

        $message = $prefix
            . "🌍 {$title}\n";

        $nativeName = trim(
            (string) ($country['nativeName'] ?? '')
        );

        if (
            $nativeName !== ''
            && mb_strtolower($nativeName)
                !== mb_strtolower($name)
            && mb_strtolower($nativeName)
                !== mb_strtolower((string) $persianName)
        ) {
            $message .= "🏷 نام بومی: {$nativeName}\n";
        }

        $capital = trim(
            (string) ($country['capital'] ?? '')
        );

        if ($capital !== '') {
            $message .= "🏛 پایتخت: {$capital}\n";
        }

        $region = $this->regionLabel(
            (string) ($country['region'] ?? '')
        );

        $subregion = trim(
            (string) ($country['subregion'] ?? '')
        );

        $regionParts = array_values(
            array_filter(
                [$region, $subregion],
                static fn (string $value): bool =>
                    $value !== ''
            )
        );

        if ($regionParts !== []) {
            $message .= "🗺 منطقه: "
                . implode(' / ', $regionParts)
                . "\n";
        }

        $population = $country['population'] ?? null;

        if (is_numeric($population)) {
            $message .= "👥 جمعیت: "
                . number_format((float) $population, 0)
                . "\n";
        }

        $area = $country['area'] ?? null;

        if (is_numeric($area)) {
            $message .= "📐 مساحت: "
                . number_format((float) $area, 0)
                . " km²\n";
        }

        $density = $country['populationDensity'] ?? null;

        if (
            !is_numeric($density)
            && is_numeric($population)
            && is_numeric($area)
            && (float) $area > 0
        ) {
            $density = (float) $population
                / (float) $area;
        }

        if (is_numeric($density)) {
            $message .= "📊 تراکم: "
                . number_format((float) $density, 1)
                . " نفر/km²\n";
        }

        $currencies = $this->currencyLabels(
            $country['currencies'] ?? null
        );

        if ($currencies !== []) {
            $message .= "💰 ارز: "
                . implode('، ', $currencies)
                . "\n";
        }

        $languages = $this->languageLabels(
            $country['languages'] ?? null
        );

        if ($languages !== []) {
            $message .= "🗣 زبان‌ها: "
                . implode('، ', $languages)
                . "\n";
        }

        $callingCodes = $this->stringList(
            $country['callingCodes'] ?? null,
            4,
            static fn (string $value): string =>
                str_starts_with($value, '+')
                    ? $value
                    : '+' . $value
        );

        if ($callingCodes !== []) {
            $message .= "☎️ کد تماس: "
                . implode('، ', $callingCodes)
                . "\n";
        }

        $timezones = $this->stringList(
            $country['timezones'] ?? null,
            4
        );

        if ($timezones !== []) {
            $message .= "🕒 منطقه زمانی: "
                . implode('، ', $timezones)
                . "\n";
        }

        $codes = array_values(
            array_filter(
                [$alpha2, $alpha3],
                static fn (string $value): bool =>
                    $value !== ''
            )
        );

        if ($codes !== []) {
            $message .= "🔤 کدهای ISO: "
                . implode(' / ', $codes)
                . "\n";
        }

        $domains = $this->stringList(
            $country['topLevelDomain'] ?? null,
            4
        );

        if ($domains !== []) {
            $message .= "🌐 دامنه: "
                . implode('، ', $domains)
                . "\n";
        }

        $borders = $this->stringList(
            $country['borders'] ?? null,
            8
        );

        if ($borders !== []) {
            $message .= "🧭 همسایه‌ها: "
                . implode('، ', $borders)
                . "\n";
        }

        $message .= "\n"
            . "منبع: countries.dev\n"
            . "کشور تصادفی بعدی: /randomcountry";

        return trim($message);
    }

    /**
     * @param array<string, mixed> $country
     */
    private function flagUrl(array $country): ?string
    {
        $flags = $country['flags'] ?? null;

        $candidates = [];

        if (is_array($flags)) {
            $candidates[] = $flags['png'] ?? null;
            $candidates[] = $flags['svg'] ?? null;
        }

        $candidates[] = $country['flag'] ?? null;

        foreach ($candidates as $candidate) {
            if (!is_string($candidate)) {
                continue;
            }

            $candidate = trim($candidate);

            if (
                filter_var(
                    $candidate,
                    FILTER_VALIDATE_URL
                ) === false
            ) {
                continue;
            }

            if (
                mb_strtolower(
                    (string) parse_url(
                        $candidate,
                        PHP_URL_SCHEME
                    )
                ) !== 'https'
            ) {
                continue;
            }

            /*
             * Telegram معمولاً فایل PNG/JPG را مطمئن‌تر از SVG
             * به‌عنوان photo دریافت می‌کند.
             */
            $path = mb_strtolower(
                (string) parse_url(
                    $candidate,
                    PHP_URL_PATH
                )
            );

            if (
                str_ends_with($path, '.png')
                || str_ends_with($path, '.jpg')
                || str_ends_with($path, '.jpeg')
                || str_ends_with($path, '.webp')
            ) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function currencyLabels(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $labels = [];

        foreach ($value as $currency) {
            if (!is_array($currency)) {
                continue;
            }

            $code = trim(
                (string) ($currency['code'] ?? '')
            );

            $name = trim(
                (string) ($currency['name'] ?? '')
            );

            $symbol = trim(
                (string) ($currency['symbol'] ?? '')
            );

            $parts = [];

            if ($code !== '') {
                $parts[] = $code;
            }

            if ($name !== '') {
                $parts[] = $name;
            }

            $label = implode(' — ', $parts);

            if ($symbol !== '') {
                $label .= $label !== ''
                    ? " ({$symbol})"
                    : $symbol;
            }

            if ($label !== '') {
                $labels[] = $label;
            }

            if (count($labels) >= 4) {
                break;
            }
        }

        return $labels;
    }

    /**
     * @return list<string>
     */
    private function languageLabels(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $labels = [];

        foreach ($value as $language) {
            if (!is_array($language)) {
                continue;
            }

            $name = trim(
                (string) ($language['name'] ?? '')
            );

            $nativeName = trim(
                (string) (
                    $language['nativeName'] ?? ''
                )
            );

            if ($name === '') {
                continue;
            }

            $label = $name;

            if (
                $nativeName !== ''
                && mb_strtolower($nativeName)
                    !== mb_strtolower($name)
            ) {
                $label .= " ({$nativeName})";
            }

            $labels[] = $label;

            if (count($labels) >= 6) {
                break;
            }
        }

        return $labels;
    }

    /**
     * @param callable(string): string|null $mapper
     *
     * @return list<string>
     */
    private function stringList(
        mixed $value,
        int $limit,
        ?callable $mapper = null
    ): array {
        if (!is_array($value)) {
            return [];
        }

        $items = [];

        foreach ($value as $item) {
            if (!is_string($item)) {
                continue;
            }

            $item = trim($item);

            if ($item === '') {
                continue;
            }

            if ($mapper !== null) {
                $item = $mapper($item);
            }

            if (!in_array($item, $items, true)) {
                $items[] = $item;
            }

            if (count($items) >= $limit) {
                break;
            }
        }

        return $items;
    }

    private function normalizeQuery(
        string $query
    ): string {
        $query = trim(
            strtr(
                $query,
                [
                    'ي' => 'ی',
                    'ى' => 'ی',
                    'ك' => 'ک',
                    "\u{200C}" => ' ',
                    "\u{200F}" => '',
                    "\u{200E}" => '',
                ]
            )
        );

        $query = preg_replace(
            '/\s+/u',
            ' ',
            $query
        ) ?? $query;

        $normalized = mb_strtolower($query);

        return $this->countryAliases()[$normalized]
            ?? $query;
    }

    /**
     * @return array<string, string>
     */
    private function countryAliases(): array
    {
        return [
            'ایران' => 'IR',
            'افغانستان' => 'AF',
            'پاکستان' => 'PK',
            'هند' => 'IN',
            'چین' => 'CN',
            'ژاپن' => 'JP',
            'کره جنوبی' => 'KR',
            'کره شمالی' => 'KP',
            'روسیه' => 'RU',
            'ترکیه' => 'TR',
            'عراق' => 'IQ',
            'عربستان' => 'SA',
            'عربستان سعودی' => 'SA',
            'امارات' => 'AE',
            'امارات متحده عربی' => 'AE',
            'قطر' => 'QA',
            'کویت' => 'KW',
            'عمان' => 'OM',
            'بحرین' => 'BH',
            'اردن' => 'JO',
            'لبنان' => 'LB',
            'سوریه' => 'SY',
            'فلسطین' => 'PS',
            'اسرائیل' => 'IL',
            'یمن' => 'YE',
            'مصر' => 'EG',
            'آذربایجان' => 'AZ',
            'ارمنستان' => 'AM',
            'گرجستان' => 'GE',
            'ترکمنستان' => 'TM',
            'تاجیکستان' => 'TJ',
            'ازبکستان' => 'UZ',
            'قزاقستان' => 'KZ',
            'قرقیزستان' => 'KG',
            'اندونزی' => 'ID',
            'مالزی' => 'MY',
            'سنگاپور' => 'SG',
            'تایلند' => 'TH',
            'ویتنام' => 'VN',
            'فیلیپین' => 'PH',
            'استرالیا' => 'AU',
            'نیوزیلند' => 'NZ',
            'آمریکا' => 'US',
            'ایالات متحده' => 'US',
            'ایالات متحده آمریکا' => 'US',
            'کانادا' => 'CA',
            'مکزیک' => 'MX',
            'برزیل' => 'BR',
            'آرژانتین' => 'AR',
            'شیلی' => 'CL',
            'پرو' => 'PE',
            'کلمبیا' => 'CO',
            'ونزوئلا' => 'VE',
            'بریتانیا' => 'GB',
            'انگلیس' => 'GB',
            'فرانسه' => 'FR',
            'آلمان' => 'DE',
            'ایتالیا' => 'IT',
            'اسپانیا' => 'ES',
            'پرتغال' => 'PT',
            'هلند' => 'NL',
            'بلژیک' => 'BE',
            'سوئیس' => 'CH',
            'اتریش' => 'AT',
            'سوئد' => 'SE',
            'نروژ' => 'NO',
            'دانمارک' => 'DK',
            'فنلاند' => 'FI',
            'ایسلند' => 'IS',
            'ایرلند' => 'IE',
            'لهستان' => 'PL',
            'اوکراین' => 'UA',
            'رومانی' => 'RO',
            'یونان' => 'GR',
            'چک' => 'CZ',
            'مجارستان' => 'HU',
            'بلغارستان' => 'BG',
            'صربستان' => 'RS',
            'کرواسی' => 'HR',
            'بوسنی' => 'BA',
            'بوسنی و هرزگوین' => 'BA',
            'آلبانی' => 'AL',
            'آفریقای جنوبی' => 'ZA',
            'نیجریه' => 'NG',
            'کنیا' => 'KE',
            'اتیوپی' => 'ET',
            'مراکش' => 'MA',
            'الجزایر' => 'DZ',
            'تونس' => 'TN',
            'لیبی' => 'LY',
            'سودان' => 'SD',
            'سومالی' => 'SO',
        ];
    }

    private function persianName(
        string $alpha2
    ): ?string {
        $names = [
            'IR' => 'ایران',
            'AF' => 'افغانستان',
            'PK' => 'پاکستان',
            'IN' => 'هند',
            'CN' => 'چین',
            'JP' => 'ژاپن',
            'KR' => 'کره جنوبی',
            'KP' => 'کره شمالی',
            'RU' => 'روسیه',
            'TR' => 'ترکیه',
            'IQ' => 'عراق',
            'SA' => 'عربستان سعودی',
            'AE' => 'امارات متحده عربی',
            'QA' => 'قطر',
            'KW' => 'کویت',
            'OM' => 'عمان',
            'BH' => 'بحرین',
            'JO' => 'اردن',
            'LB' => 'لبنان',
            'SY' => 'سوریه',
            'PS' => 'فلسطین',
            'IL' => 'اسرائیل',
            'YE' => 'یمن',
            'EG' => 'مصر',
            'AZ' => 'آذربایجان',
            'AM' => 'ارمنستان',
            'GE' => 'گرجستان',
            'AU' => 'استرالیا',
            'NZ' => 'نیوزیلند',
            'US' => 'ایالات متحده آمریکا',
            'CA' => 'کانادا',
            'MX' => 'مکزیک',
            'BR' => 'برزیل',
            'AR' => 'آرژانتین',
            'GB' => 'بریتانیا',
            'FR' => 'فرانسه',
            'DE' => 'آلمان',
            'IT' => 'ایتالیا',
            'ES' => 'اسپانیا',
            'PT' => 'پرتغال',
            'NL' => 'هلند',
            'BE' => 'بلژیک',
            'CH' => 'سوئیس',
            'AT' => 'اتریش',
            'SE' => 'سوئد',
            'NO' => 'نروژ',
            'DK' => 'دانمارک',
            'FI' => 'فنلاند',
            'IE' => 'ایرلند',
            'PL' => 'لهستان',
            'UA' => 'اوکراین',
            'GR' => 'یونان',
            'ZA' => 'آفریقای جنوبی',
            'NG' => 'نیجریه',
            'KE' => 'کنیا',
            'MA' => 'مراکش',
            'DZ' => 'الجزایر',
        ];

        return $names[$alpha2] ?? null;
    }

    private function regionLabel(
        string $region
    ): string {
        $region = trim($region);

        return match (mb_strtolower($region)) {
            'asia' => 'آسیا',
            'europe' => 'اروپا',
            'africa' => 'آفریقا',
            'americas' => 'قاره آمریکا',
            'oceania' => 'اقیانوسیه',
            'antarctic', 'antarctica' => 'جنوبگان',
            default => $region,
        };
    }

    private function usageText(): string
    {
        return "اطلاعات کشورها 🌍\n\n"
            . "فرمت استفاده:\n"
            . "/country Iran\n"
            . "/country ایران\n"
            . "/country JP\n"
            . "/countrycode DE\n"
            . "/randomcountry\n\n"
            . "نام انگلیسی یا کد دو/سه‌حرفی ISO "
            . "برای همه کشورها قابل استفاده است."
            . "\nنام فارسی بسیاری از کشورهای پرکاربرد "
            . "نیز پشتیبانی می‌شود.";
    }

    private function log(
        string $context,
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

        $entry = sprintf(
            "[%s] [context:%s] %s\n",
            date(DATE_ATOM),
            str_replace(
                ["\r", "\n"],
                ' ',
                mb_substr($context, 0, 150)
            ),
            $exception->getMessage()
        );

        @file_put_contents(
            $this->logFile,
            $entry,
            FILE_APPEND | LOCK_EX
        );
    }
}
