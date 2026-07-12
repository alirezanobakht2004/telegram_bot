<?php

declare(strict_types=1);

use SmartToolbox\Core\CommandRouter;
use SmartToolbox\Core\ConversationStateStore;
use SmartToolbox\Core\Database;
use SmartToolbox\Core\FileCache;
use SmartToolbox\Core\HttpClient;
use SmartToolbox\Core\RateLimiter;
use SmartToolbox\Core\TelegramClient;
use SmartToolbox\Core\UpdateProcessor;
use SmartToolbox\Core\UserPreferenceStore;
use SmartToolbox\Modules\Animals\AnimalsModule;
use SmartToolbox\Modules\Core\CoreModule;
use SmartToolbox\Modules\Countries\CountriesDevProvider;
use SmartToolbox\Modules\Countries\CountriesModule;
use SmartToolbox\Modules\Currency\CurrencyModule;
use SmartToolbox\Modules\Currency\FrankfurterProvider;
use SmartToolbox\Modules\Utilities\UtilitiesModule;
use SmartToolbox\Modules\Settings\SettingsModule;
use SmartToolbox\Modules\Weather\WeatherModule;

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

    $telegram = new TelegramClient(
        (string) $config->get('telegram.token')
    );

    $router = new CommandRouter(
        (string) $config->get('telegram.username')
    );

    $coreModule = new CoreModule();
    $coreModule->register($router);

    $http = new HttpClient(
        userAgent: (string) $config->get(
            'http.user_agent',
            'SmartToolboxFaBot/1.0'
        ),
        connectTimeout: (int) $config->get(
            'http.connect_timeout',
            4
        ),
        timeout: (int) $config->get(
            'http.timeout',
            8
        ),
        maxResponseBytes: (int) $config->get(
            'http.max_response_bytes',
            1048576
        )
    );

    $cache = new FileCache(
        (string) $config->get('paths.cache')
        . '/api'
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

    if (
        (bool) $config->get(
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
            cacheTtl: (int) $config->get(
                'modules.animals.cache_ttl',
                5
            ),
            maxAttempts: (int) $config->get(
                'modules.animals.rate_limit.max_attempts',
                30
            ),
            windowSeconds: (int) $config->get(
                'modules.animals.rate_limit.window_seconds',
                60
            )
        );

        $animalsModule->register($router);
    }

    if (
        (bool) $config->get(
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
            geocodingCacheTtl: (int) $config->get(
                'modules.weather.geocoding_cache_ttl',
                86400
            ),
            forecastCacheTtl: (int) $config->get(
                'modules.weather.forecast_cache_ttl',
                600
            ),
            stateTtl: (int) $config->get(
                'modules.weather.state_ttl',
                300
            ),
            maxAttempts: (int) $config->get(
                'modules.weather.rate_limit.max_attempts',
                30
            ),
            windowSeconds: (int) $config->get(
                'modules.weather.rate_limit.window_seconds',
                60
            ),
            forecastDays: (int) $config->get(
                'modules.weather.forecast_days',
                4
            )
        );

        $weatherModule->register($router);
    }

    if (
        (bool) $config->get(
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
            rateCacheTtl: (int) $config->get(
                'modules.currency.rate_cache_ttl',
                3600
            ),
            stateTtl: (int) $config->get(
                'modules.currency.state_ttl',
                300
            ),
            maxAttempts: (int) $config->get(
                'modules.currency.rate_limit.max_attempts',
                30
            ),
            windowSeconds: (int) $config->get(
                'modules.currency.rate_limit.window_seconds',
                60
            )
        );

        $currencyModule->register($router);
    }

    if (
        (bool) $config->get(
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
            cacheTtl: (int) $config->get(
                'modules.countries.cache_ttl',
                86400
            ),
            stateTtl: (int) $config->get(
                'modules.countries.state_ttl',
                300
            ),
            maxAttempts: (int) $config->get(
                'modules.countries.rate_limit.max_attempts',
                30
            ),
            windowSeconds: (int) $config->get(
                'modules.countries.rate_limit.window_seconds',
                60
            )
        );

        $countriesModule->register($router);
    }

    if (
        (bool) $config->get(
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
            defaultTimezone: (string) $config->get(
                'modules.settings.default_timezone',
                (string) $config->get(
                    'app.timezone',
                    'Asia/Tehran'
                )
            ),
            defaultPasswordLength: (int) $config->get(
                'modules.utilities.default_password_length',
                20
            ),
            stateTtl: (int) $config->get(
                'modules.utilities.state_ttl',
                300
            ),
            maxAttempts: (int) $config->get(
                'modules.utilities.rate_limit.max_attempts',
                60
            ),
            windowSeconds: (int) $config->get(
                'modules.utilities.rate_limit.window_seconds',
                60
            ),
            maxInputLength: (int) $config->get(
                'modules.utilities.max_input_length',
                2500
            )
        );

        $utilitiesModule->register($router);
    }


    if (
        (bool) $config->get(
            'modules.settings.enabled',
            true
        )
    ) {
        $settingsModule = new SettingsModule(
            preferences: $userPreferences,
            states: $conversationStates,
            logFile: (string) $config->get('paths.logs')
                . '/settings.log',
            defaultTimezone: (string) $config->get(
                'modules.settings.default_timezone',
                (string) $config->get(
                    'app.timezone',
                    'Asia/Tehran'
                )
            ),
            defaultPasswordLength: (int) $config->get(
                'modules.settings.default_password_length',
                20
            ),
            stateTtl: (int) $config->get(
                'modules.settings.state_ttl',
                300
            )
        );

        $settingsModule->register($router);
    }

    $processor = new UpdateProcessor(
        $pdo,
        $telegram,
        $router
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
