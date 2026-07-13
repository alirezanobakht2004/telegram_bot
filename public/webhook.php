<?php

declare(strict_types=1);

use SmartToolbox\Core\ApiMetricsTracker;
use SmartToolbox\Core\CacheMetricsTracker;
use SmartToolbox\Core\CallbackRouter;
use SmartToolbox\Core\CommandHistory;
use SmartToolbox\Core\CommandRouter;
use SmartToolbox\Core\ConversationStateStore;
use SmartToolbox\Core\Database;
use SmartToolbox\Core\EventDispatcher;
use SmartToolbox\Core\FeatureRegistry;
use SmartToolbox\Core\FileCache;
use SmartToolbox\Core\HttpClient;
use SmartToolbox\Core\InlineQueryRouter;
use SmartToolbox\Core\RateLimiter;
use SmartToolbox\Core\RuntimeSettings;
use SmartToolbox\Core\SsrfGuard;
use SmartToolbox\Core\TelegramClient;
use SmartToolbox\Core\UpdateProcessor;
use SmartToolbox\Core\UsageTracker;
use SmartToolbox\Core\UserPreferenceStore;
use SmartToolbox\Modules\Admin\AdminModule;
use SmartToolbox\Modules\Animals\AnimalsModule;
use SmartToolbox\Modules\Calculator\CalculatorModule;
use SmartToolbox\Modules\Calculator\ExpressionCalculator;
use SmartToolbox\Modules\Calculator\UnitConverter;
use SmartToolbox\Modules\Core\CoreModule;
use SmartToolbox\Modules\Countries\CountriesDevProvider;
use SmartToolbox\Modules\Countries\CountriesModule;
use SmartToolbox\Modules\Developer\CronExpression;
use SmartToolbox\Modules\Developer\DeveloperUtilitiesModule;
use SmartToolbox\Modules\Developer\JsonPathEvaluator;
use SmartToolbox\Modules\Developer\UlidGenerator;
use SmartToolbox\Modules\Currency\CurrencyModule;
use SmartToolbox\Modules\Currency\FrankfurterProvider;
use SmartToolbox\Modules\GitHub\GitHubClient;
use SmartToolbox\Modules\GitHub\GitHubModule;
use SmartToolbox\Modules\GitHub\GitHubWatchRepository;
use SmartToolbox\Modules\Inline\InlineDataService;
use SmartToolbox\Modules\Inline\InlineModule;
use SmartToolbox\Modules\Inline\InlineResultFactory;
use SmartToolbox\Modules\Inline\InlineSelectionRecorder;
use SmartToolbox\Modules\Profile\ProfileModule;
use SmartToolbox\Modules\Profile\ProfileRepository;
use SmartToolbox\Modules\Reminders\ReminderModule;
use SmartToolbox\Modules\Reminders\ReminderRepository;
use SmartToolbox\Modules\Reminders\ReminderTimeParser;
use SmartToolbox\Modules\Utilities\UtilitiesModule;
use SmartToolbox\Modules\Settings\SettingsModule;
use SmartToolbox\Modules\Weather\WeatherModule;
use SmartToolbox\Modules\Wiki\WikiClient;
use SmartToolbox\Modules\Wiki\WikiModule;

$rootPath = dirname(__DIR__);

try {
    $config = require $rootPath . '/bootstrap/app.php';

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);

        echo json_encode(
            [
                'ok' => false,
                'error' => 'Method not allowed.',
            ],
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
        ) . PHP_EOL;

        exit;
    }

    $expectedSecret = (string) $config->get(
        'telegram.webhook_secret'
    );

    $receivedSecret = (string) (
        $_SERVER[
            'HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'
        ] ?? ''
    );

    if (
        $expectedSecret === ''
        || $receivedSecret === ''
        || !hash_equals(
            $expectedSecret,
            $receivedSecret
        )
    ) {
        http_response_code(403);

        echo json_encode(
            [
                'ok' => false,
                'error' => 'Forbidden.',
            ],
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
        ) . PHP_EOL;

        exit;
    }

    $rawBody = file_get_contents('php://input');

    if (
        $rawBody === false
        || trim($rawBody) === ''
    ) {
        throw new RuntimeException(
            'Webhook request body is empty.'
        );
    }

    try {
        $update = json_decode(
            $rawBody,
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    } catch (\JsonException $exception) {
        throw new RuntimeException(
            'Webhook body contains invalid JSON.',
            previous: $exception
        );
    }

    if (!is_array($update)) {
        throw new RuntimeException(
            'Webhook update must be a JSON object.'
        );
    }

    $pdo = Database::connect(
        (string) $config->get('database.path')
    );

    $runtime = new RuntimeSettings(
        $config,
        $pdo
    );

    $features = new FeatureRegistry(
        $pdo,
        (array) $config->get(
            'features.defaults',
            []
        )
    );

    $analyticsEnabled = (bool) $runtime->get(
        'analytics.enabled',
        true
    ) && $features->isEnabled('analytics');

    $usageTracker = new UsageTracker(
        pdo: $pdo,
        enabled: $analyticsEnabled,
        sampleRate: (int) $runtime->get(
            'analytics.sample_rate',
            100
        )
    );

    $apiMetrics = new ApiMetricsTracker(
        pdo: $pdo,
        enabled: $analyticsEnabled
            && (bool) $runtime->get(
                'analytics.api_metrics.enabled',
                true
            ),
        sampleRate: (int) $runtime->get(
            'analytics.api_metrics.sample_rate',
            100
        )
    );

    $cacheMetrics = new CacheMetricsTracker(
        pdo: $pdo,
        enabled: $analyticsEnabled
            && (bool) $runtime->get(
                'analytics.cache_metrics.enabled',
                true
            ),
        sampleRate: (int) $runtime->get(
            'analytics.cache_metrics.sample_rate',
            100
        )
    );

    $commandHistory = new CommandHistory(
        pdo: $pdo,
        enabled: (bool) $runtime->get(
            'analytics.command_history.enabled',
            true
        ),
        storeArguments: (bool) $runtime->get(
            'analytics.command_history.store_arguments',
            false
        ),
        maxArgumentCharacters: (int) $runtime->get(
            'analytics.command_history.max_argument_characters',
            200
        )
    );

    $telegram = new TelegramClient(
        token: (string) $config->get('telegram.token'),
        metrics: $apiMetrics
    );

    $router = new CommandRouter(
        botUsername: (string) $config->get(
            'telegram.username'
        ),
        usageTracker: $usageTracker,
        history: $commandHistory
    );

    $events = new EventDispatcher();
    $callbackRouter = new CallbackRouter(
        usageTracker: $usageTracker,
        features: $features
    );
    $inlineRouter = new InlineQueryRouter(
        usageTracker: $usageTracker,
        features: $features
    );

    $callbackRouter->fallback(
        static function ($context, string $data): void {
            $context->answer(
                'این دکمه دیگر فعال نیست.'
            );
        },
        'core'
    );

    $inlineRouter->fallback(
        static function ($context, string $query): void {
            $context->answer(
                [],
                [
                    'cache_time' => 1,
                    'is_personal' => true,
                    'button' => [
                        'text' => 'راهنمای حالت Inline',
                        'start_parameter' => 'inline_help',
                    ],
                ]
            );
        },
        'core'
    );

    $ssrfGuard = new SsrfGuard(
        allowHttp: (bool) $runtime->get(
            'http.ssrf.allow_http',
            false
        ),
        allowedPorts: (array) $runtime->get(
            'http.ssrf.allowed_ports',
            [443]
        )
    );

    $http = new HttpClient(
        userAgent: (string) $config->get(
            'http.user_agent',
            'SmartToolboxFaBot/1.0'
        ),
        connectTimeout: (int) $runtime->get(
            'http.connect_timeout',
            4
        ),
        timeout: (int) $runtime->get(
            'http.timeout',
            8
        ),
        maxResponseBytes: (int) $runtime->get(
            'http.max_response_bytes',
            1048576
        ),
        metrics: $apiMetrics,
        ssrfGuard: $ssrfGuard,
        maxRedirects: (int) $runtime->get(
            'http.max_redirects',
            3
        )
    );

    $cache = new FileCache(
        directory: (string) $config->get('paths.cache')
            . '/api',
        metrics: $cacheMetrics
    );

    $rateLimiter = new RateLimiter($pdo);

    /*
     * تمام ماژول‌های مرحله‌ای از یک Store مشترک استفاده می‌کنند.
     */
    $conversationStates = new ConversationStateStore(
        $pdo
    );

    $userPreferences = new UserPreferenceStore(
        $pdo
    );

    (new CoreModule(
        preferences: $userPreferences
    ))->register($router);

    $profileRepository = new ProfileRepository(
        $pdo
    );

    $wikiClient = new WikiClient(
        http: $http,
        cache: $cache,
        searchCacheTtl: (int) $runtime->get(
            'modules.wiki.search_cache_ttl',
            21600
        ),
        randomCacheTtl: (int) $runtime->get(
            'modules.wiki.random_cache_ttl',
            300
        ),
        todayCacheTtl: (int) $runtime->get(
            'modules.wiki.today_cache_ttl',
            21600
        )
    );

    $githubClient = new GitHubClient(
        cache: $cache,
        userAgent: (string) $config->get(
            'http.user_agent',
            'SmartToolboxFaBot/1.0'
        ),
        token: (string) $config->get(
            'modules.github.token',
            ''
        ),
        apiVersion: (string) $runtime->get(
            'modules.github.api_version',
            '2026-03-10'
        ),
        cacheTtl: (int) $runtime->get(
            'modules.github.cache_ttl',
            1800
        ),
        releaseCacheTtl: (int) $runtime->get(
            'modules.github.release_cache_ttl',
            900
        ),
        connectTimeout: (int) $runtime->get(
            'http.connect_timeout',
            4
        ),
        timeout: (int) $runtime->get(
            'http.timeout',
            8
        ),
        maxResponseBytes: (int) $runtime->get(
            'http.max_response_bytes',
            1048576
        ),
        metrics: $apiMetrics
    );

    $githubWatches = new GitHubWatchRepository(
        $pdo
    );

    if (
        (bool) $runtime->get(
            'modules.animals.enabled',
            true
        )
    ) {
        $animalsModule = new AnimalsModule(
            http: $http,
            cache: $cache,
            rateLimiter: $rateLimiter,
            dogEndpoint: (string) $config->get(
                'modules.animals.providers.dog.endpoint'
            ),
            catEndpoint: (string) $config->get(
                'modules.animals.providers.cat.endpoint'
            ),
            foxEndpoint: (string) $config->get(
                'modules.animals.providers.fox.endpoint'
            ),
            logFile: (string) $config->get('paths.logs')
                . '/animals.log',
            cacheTtl: (int) $runtime->get(
                'modules.animals.cache_ttl',
                5
            ),
            maxAttempts: (int) $runtime->get(
                'modules.animals.rate_limit.max_attempts',
                30
            ),
            windowSeconds: (int) $runtime->get(
                'modules.animals.rate_limit.window_seconds',
                60
            )
        );

        $animalsModule->register($router);
    }

    if (
        (bool) $runtime->get(
            'modules.weather.enabled',
            true
        )
    ) {
        $weatherModule = new WeatherModule(
            http: $http,
            cache: $cache,
            rateLimiter: $rateLimiter,
            states: $conversationStates,
            geocodingEndpoint: (string) $config->get(
                'modules.weather.providers.geocoding_endpoint'
            ),
            forecastEndpoint: (string) $config->get(
                'modules.weather.providers.forecast_endpoint'
            ),
            logFile: (string) $config->get('paths.logs')
                . '/weather.log',
            geocodingCacheTtl: (int) $runtime->get(
                'modules.weather.geocoding_cache_ttl',
                86400
            ),
            forecastCacheTtl: (int) $runtime->get(
                'modules.weather.forecast_cache_ttl',
                600
            ),
            stateTtl: (int) $runtime->get(
                'modules.weather.state_ttl',
                300
            ),
            maxAttempts: (int) $runtime->get(
                'modules.weather.rate_limit.max_attempts',
                30
            ),
            windowSeconds: (int) $runtime->get(
                'modules.weather.rate_limit.window_seconds',
                60
            ),
            forecastDays: (int) $runtime->get(
                'modules.weather.forecast_days',
                4
            )
        );

        $weatherModule->register($router);
    }

    if (
        (bool) $runtime->get(
            'modules.currency.enabled',
            true
        )
    ) {
        $currencyProvider = new FrankfurterProvider(
            http: $http,
            baseUrl: (string) $config->get(
                'modules.currency.provider.base_url'
            )
        );

        $currencyModule = new CurrencyModule(
            provider: $currencyProvider,
            cache: $cache,
            rateLimiter: $rateLimiter,
            states: $conversationStates,
            logFile: (string) $config->get('paths.logs')
                . '/currency.log',
            rateCacheTtl: (int) $runtime->get(
                'modules.currency.rate_cache_ttl',
                3600
            ),
            stateTtl: (int) $runtime->get(
                'modules.currency.state_ttl',
                300
            ),
            maxAttempts: (int) $runtime->get(
                'modules.currency.rate_limit.max_attempts',
                30
            ),
            windowSeconds: (int) $runtime->get(
                'modules.currency.rate_limit.window_seconds',
                60
            )
        );

        $currencyModule->register($router);
    }

    if (
        (bool) $runtime->get(
            'modules.countries.enabled',
            true
        )
    ) {
        $countryProvider = new CountriesDevProvider(
            http: $http,
            baseUrl: (string) $config->get(
                'modules.countries.provider.base_url'
            )
        );

        $countriesModule = new CountriesModule(
            provider: $countryProvider,
            cache: $cache,
            rateLimiter: $rateLimiter,
            states: $conversationStates,
            logFile: (string) $config->get('paths.logs')
                . '/countries.log',
            cacheTtl: (int) $runtime->get(
                'modules.countries.cache_ttl',
                86400
            ),
            stateTtl: (int) $runtime->get(
                'modules.countries.state_ttl',
                300
            ),
            maxAttempts: (int) $runtime->get(
                'modules.countries.rate_limit.max_attempts',
                30
            ),
            windowSeconds: (int) $runtime->get(
                'modules.countries.rate_limit.window_seconds',
                60
            )
        );

        $countriesModule->register($router);
    }



    if (
        (bool) $runtime->get(
            'modules.reminders.enabled',
            true
        )
    ) {
        $reminderModule = new ReminderModule(
            repository: new ReminderRepository($pdo),
            parser: new ReminderTimeParser(),
            rateLimiter: $rateLimiter,
            states: $conversationStates,
            preferences: $userPreferences,
            logFile: (string) $config->get('paths.logs')
                . '/reminders.log',
            defaultTimezone: (string) $runtime->get(
                'modules.settings.default_timezone',
                (string) $config->get(
                    'app.timezone',
                    'Asia/Tehran'
                )
            ),
            stateTtl: (int) $runtime->get(
                'modules.reminders.state_ttl',
                300
            ),
            maxAttempts: (int) $runtime->get(
                'modules.reminders.rate_limit.max_attempts',
                30
            ),
            windowSeconds: (int) $runtime->get(
                'modules.reminders.rate_limit.window_seconds',
                60
            ),
            maxTextLength: (int) $runtime->get(
                'modules.reminders.max_text_length',
                1000
            ),
            maxPendingPerUser: (int) $runtime->get(
                'modules.reminders.max_pending_per_user',
                50
            ),
            maxFutureDays: (int) $runtime->get(
                'modules.reminders.max_future_days',
                365
            )
        );

        $reminderModule->register($router);
    }

    if (
        (bool) $runtime->get(
            'modules.calculator.enabled',
            true
        )
    ) {
        $calculatorModule = new CalculatorModule(
            calculator: new ExpressionCalculator(),
            converter: new UnitConverter(),
            rateLimiter: $rateLimiter,
            states: $conversationStates,
            logFile: (string) $config->get('paths.logs')
                . '/calculator.log',
            stateTtl: (int) $runtime->get(
                'modules.calculator.state_ttl',
                300
            ),
            maxAttempts: (int) $runtime->get(
                'modules.calculator.rate_limit.max_attempts',
                60
            ),
            windowSeconds: (int) $runtime->get(
                'modules.calculator.rate_limit.window_seconds',
                60
            ),
            maxExpressionLength: (int) $runtime->get(
                'modules.calculator.max_expression_length',
                500
            ),
            maxConversionLength: (int) $runtime->get(
                'modules.calculator.max_conversion_length',
                200
            )
        );

        $calculatorModule->register($router);
    }

    if (
        (bool) $runtime->get(
            'modules.utilities.enabled',
            true
        )
    ) {
        $utilitiesModule = new UtilitiesModule(
            rateLimiter: $rateLimiter,
            states: $conversationStates,
            preferences: $userPreferences,
            logFile: (string) $config->get('paths.logs')
                . '/utilities.log',
            defaultTimezone: (string) $runtime->get(
                'modules.settings.default_timezone',
                (string) $config->get(
                    'app.timezone',
                    'Asia/Tehran'
                )
            ),
            defaultPasswordLength: (int) $runtime->get(
                'modules.utilities.default_password_length',
                20
            ),
            stateTtl: (int) $runtime->get(
                'modules.utilities.state_ttl',
                300
            ),
            maxAttempts: (int) $runtime->get(
                'modules.utilities.rate_limit.max_attempts',
                60
            ),
            windowSeconds: (int) $runtime->get(
                'modules.utilities.rate_limit.window_seconds',
                60
            ),
            maxInputLength: (int) $runtime->get(
                'modules.utilities.max_input_length',
                2500
            )
        );

        $utilitiesModule->register($router);
    }


    if (
        (bool) $runtime->get(
            'modules.settings.enabled',
            true
        )
    ) {
        $settingsModule = new SettingsModule(
            preferences: $userPreferences,
            states: $conversationStates,
            logFile: (string) $config->get('paths.logs')
                . '/settings.log',
            defaultTimezone: (string) $runtime->get(
                'modules.settings.default_timezone',
                (string) $config->get(
                    'app.timezone',
                    'Asia/Tehran'
                )
            ),
            defaultPasswordLength: (int) $runtime->get(
                'modules.settings.default_password_length',
                20
            ),
            stateTtl: (int) $runtime->get(
                'modules.settings.state_ttl',
                300
            )
        );

        $settingsModule->register($router);
    }



    if (
        (bool) $runtime->get(
            'modules.wiki.enabled',
            true
        )
    ) {
        (new WikiModule(
            client: $wikiClient,
            rateLimiter: $rateLimiter,
            maxAttempts: (int) $runtime->get(
                'modules.wiki.rate_limit.max_attempts',
                30
            ),
            windowSeconds: (int) $runtime->get(
                'modules.wiki.rate_limit.window_seconds',
                60
            ),
            maxQueryLength: (int) $runtime->get(
                'modules.wiki.max_query_length',
                150
            )
        ))->register($router);
    }

    if (
        (bool) $runtime->get(
            'modules.github.enabled',
            true
        )
    ) {
        (new GitHubModule(
            client: $githubClient,
            watches: $githubWatches,
            rateLimiter: $rateLimiter,
            maxWatchesPerUser: (int) $runtime->get(
                'modules.github.max_watches_per_user',
                20
            ),
            maxAttempts: (int) $runtime->get(
                'modules.github.rate_limit.max_attempts',
                30
            ),
            windowSeconds: (int) $runtime->get(
                'modules.github.rate_limit.window_seconds',
                60
            )
        ))->register($router);
    }

    if (
        (bool) $runtime->get(
            'modules.developer.enabled',
            true
        )
    ) {
        (new DeveloperUtilitiesModule(
            jsonPath: new JsonPathEvaluator(),
            ulid: new UlidGenerator(),
            cron: new CronExpression(),
            rateLimiter: $rateLimiter,
            logFile: (string) $config->get(
                'paths.logs'
            ) . '/developer.log',
            defaultTimezone: (string) $runtime->get(
                'modules.settings.default_timezone',
                (string) $config->get(
                    'app.timezone',
                    'Asia/Tehran'
                )
            ),
            maxInputLength: (int) $runtime->get(
                'modules.developer.max_input_length',
                3000
            ),
            maxRegexPatternLength: (int) $runtime->get(
                'modules.developer.max_regex_pattern_length',
                300
            ),
            regexBacktrackLimit: (int) $runtime->get(
                'modules.developer.regex_backtrack_limit',
                100000
            ),
            maxAttempts: (int) $runtime->get(
                'modules.developer.rate_limit.max_attempts',
                60
            ),
            windowSeconds: (int) $runtime->get(
                'modules.developer.rate_limit.window_seconds',
                60
            )
        ))->register($router);
    }

    if (
        (bool) $runtime->get(
            'modules.profile.enabled',
            true
        )
    ) {
        $profileModule = new ProfileModule(
            repository: $profileRepository,
            rateLimiter: $rateLimiter,
            preferences: $userPreferences,
            maxFavorites: (int) $runtime->get(
                'modules.profile.max_favorites',
                50
            ),
            maxShortcuts: (int) $runtime->get(
                'modules.profile.max_shortcuts',
                30
            ),
            maxAttempts: (int) $runtime->get(
                'modules.profile.rate_limit.max_attempts',
                60
            ),
            windowSeconds: (int) $runtime->get(
                'modules.profile.rate_limit.window_seconds',
                60
            )
        );

        $profileModule->register($router);
        $profileModule->registerCallbacks(
            $callbackRouter
        );
    }

    if (
        (bool) $runtime->get(
            'modules.admin.enabled',
            true
        )
    ) {
        $adminModule = new AdminModule(
            pdo: $pdo,
            telegram: $telegram,
            states: $conversationStates,
            adminUserIds: (array) $config->get(
                'admins',
                []
            ),
            databasePath: (string) $config->get(
                'database.path'
            ),
            logFile: (string) $config->get('paths.logs')
                . '/admin.log',
            stateTtl: (int) $runtime->get(
                'modules.admin.state_ttl',
                600
            ),
            broadcastBatchSize: (int) $runtime->get(
                'modules.admin.broadcast_batch_size',
                5
            ),
            maxBroadcastLength: (int) $runtime->get(
                'modules.admin.max_broadcast_length',
                3000
            )
        );

        $adminModule->register($router);
    }


    if (
        (bool) $runtime->get(
            'modules.inline.enabled',
            true
        )
    ) {
        $inlineCurrency =
            new FrankfurterProvider(
                http: $http,
                baseUrl: (string) $config->get(
                    'modules.currency.provider.base_url'
                )
            );

        $inlineCountries =
            new CountriesDevProvider(
                http: $http,
                baseUrl: (string) $config->get(
                    'modules.countries.provider.base_url'
                )
            );

        (new InlineModule(
            data: new InlineDataService(
                http: $http,
                cache: $cache,
                currency: $inlineCurrency,
                countries: $inlineCountries,
                calculator:
                    new ExpressionCalculator(),
                wiki: $wikiClient,
                github: $githubClient,
                geocodingEndpoint:
                    (string) $config->get(
                        'modules.weather.providers.geocoding_endpoint'
                    ),
                forecastEndpoint:
                    (string) $config->get(
                        'modules.weather.providers.forecast_endpoint'
                    ),
                weatherCacheTtl:
                    (int) $runtime->get(
                        'modules.inline.weather_cache_ttl',
                        600
                    )
            ),
            factory: new InlineResultFactory(),
            rateLimiter: $rateLimiter,
            cacheTime: (int) $runtime->get(
                'modules.inline.cache_time',
                60
            ),
            maxResults: (int) $runtime->get(
                'modules.inline.max_results',
                5
            ),
            maxAttempts: (int) $runtime->get(
                'modules.inline.rate_limit.max_attempts',
                40
            ),
            windowSeconds: (int) $runtime->get(
                'modules.inline.rate_limit.window_seconds',
                60
            )
        ))->register($inlineRouter);
    }

    $inlineSelectionRecorder =
        new InlineSelectionRecorder($pdo);

    $events->listen(
        'update.chosen_inline_result',
        static function ($context) use (
            $inlineSelectionRecorder
        ): void {
            $inlineSelectionRecorder->record(
                $context
            );
        }
    );

    $processor = new UpdateProcessor(
        pdo: $pdo,
        telegram: $telegram,
        router: $router,
        events: $events,
        callbackRouter: $callbackRouter,
        inlineRouter: $inlineRouter,
        usageTracker: $usageTracker
    );

    $processor->process($update);

    http_response_code(200);

    echo json_encode(
        [
            'ok' => true,
        ],
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
    ) . PHP_EOL;
} catch (Throwable $exception) {
    $logDirectory = $rootPath . '/storage/logs';

    if (!is_dir($logDirectory)) {
        @mkdir(
            $logDirectory,
            0700,
            true
        );
    }

    $logEntry = sprintf(
        "[%s] %s\n%s\n\n",
        date(DATE_ATOM),
        $exception->getMessage(),
        $exception->getTraceAsString()
    );

    @file_put_contents(
        $logDirectory . '/webhook.log',
        $logEntry,
        FILE_APPEND | LOCK_EX
    );

    http_response_code(500);

    header(
        'Content-Type: application/json; charset=utf-8'
    );
    header('Cache-Control: no-store');

    echo json_encode(
        [
            'ok' => false,
            'error' => 'Internal server error.',
        ],
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
    ) . PHP_EOL;
}
