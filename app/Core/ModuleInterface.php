<?php

declare(strict_types=1);

namespace SmartToolbox\Core;

interface ModuleInterface
{
    public function register(CommandRouter $router): void;
}