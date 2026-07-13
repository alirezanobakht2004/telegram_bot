<?php

declare(strict_types=1);

namespace SmartToolbox\Modules\MiniApp;

use RuntimeException;

final class MiniAppException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'mini_app_error',
        public readonly int $httpStatus = 400
    ) {
        parent::__construct($message);
    }
}
