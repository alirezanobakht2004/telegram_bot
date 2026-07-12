<?php

declare(strict_types=1);

use SmartToolbox\Core\Config;

$rootPath = dirname(__DIR__);

$autoloadFile = $rootPath . '/vendor/autoload.php';

if (!is_file($autoloadFile)) {
    throw new RuntimeException(
        'Composer autoload was not found. Run composer install.'
    );
}

require $autoloadFile;

$config = Config::load($rootPath);

date_default_timezone_set(
    (string) $config->get('app.timezone', 'UTC')
);

return $config;