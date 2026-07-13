<?php

declare(strict_types=1);

namespace SmartToolbox\Modules\FileTools;

use RuntimeException;

final class FileToolException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'file_tool_error',
        public readonly bool $retryable = false
    ) {
        parent::__construct($message);
    }
}
