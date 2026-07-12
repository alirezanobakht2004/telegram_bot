<?php

declare(strict_types=1);

use SmartToolbox\Core\Config;
use SmartToolbox\Core\FileCache;
use SmartToolbox\Core\RuntimeSettings;
use SmartToolbox\Web\AdminSettingRegistry;

$rootPath = dirname(__DIR__);

require $rootPath . '/vendor/autoload.php';

$temporaryDirectory =
    sys_get_temp_dir()
    . '/smart-toolbox-admin-test-'
    . bin2hex(random_bytes(5));

if (
    !mkdir(
        $temporaryDirectory,
        0700,
        true
    )
) {
    throw new RuntimeException(
        'Temporary directory could not be created.'
    );
}

$pdo = new PDO(
    'sqlite:'
    . $temporaryDirectory
    . '/test.sqlite',
    options: [
        PDO::ATTR_ERRMODE =>
            PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE =>
            PDO::FETCH_ASSOC,
    ]
);

$pdo->exec(
    'CREATE TABLE runtime_settings (
        setting_key TEXT PRIMARY KEY,
        value_json TEXT NOT NULL,
        updated_at TEXT NOT NULL,
        updated_by TEXT NOT NULL
    )'
);

$config = Config::load($rootPath);
$runtime = new RuntimeSettings(
    $config,
    $pdo
);
$registry =
    new AdminSettingRegistry();

$value = $registry->validate(
    'modules.weather.forecast_cache_ttl',
    '900'
);

if ($value !== 900) {
    throw new RuntimeException(
        'Setting validation failed.'
    );
}

$runtime->set(
    'modules.weather.forecast_cache_ttl',
    $value,
    'test'
);

if (
    $runtime->get(
        'modules.weather.forecast_cache_ttl'
    ) !== 900
) {
    throw new RuntimeException(
        'Runtime override failed.'
    );
}

$runtime->delete(
    'modules.weather.forecast_cache_ttl'
);

if (
    $runtime->hasOverride(
        'modules.weather.forecast_cache_ttl'
    )
) {
    throw new RuntimeException(
        'Runtime reset failed.'
    );
}

$cache = new FileCache(
    $temporaryDirectory . '/cache'
);

$cache->put(
    'weather.forecast.test',
    ['temperature' => 20],
    60
);

$cache->put(
    'currency.rate.USD.EUR',
    ['rate' => 0.9],
    60
);

if (
    $cache->stats('weather.')['files']
    !== 1
) {
    throw new RuntimeException(
        'Cache prefix statistics failed.'
    );
}

if (
    $cache->clear('weather.')
    !== 1
) {
    throw new RuntimeException(
        'Cache prefix clearing failed.'
    );
}

if ($cache->stats()['files'] !== 1) {
    throw new RuntimeException(
        'Cache isolation failed.'
    );
}

$cache->clear();
$runtime = null;
$pdo = null;

$iterator =
    new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            $temporaryDirectory,
            FilesystemIterator::SKIP_DOTS
        ),
        RecursiveIteratorIterator::CHILD_FIRST
    );

foreach ($iterator as $item) {
    if ($item->isDir()) {
        rmdir($item->getPathname());
    } else {
        unlink($item->getPathname());
    }
}

rmdir($temporaryDirectory);

echo json_encode(
    [
        'status' => 'passed',
        'runtime_settings' => true,
        'setting_validation' => true,
        'cache_prefix_management' => true,
    ],
    JSON_PRETTY_PRINT
    | JSON_UNESCAPED_UNICODE
    | JSON_UNESCAPED_SLASHES
) . PHP_EOL;
