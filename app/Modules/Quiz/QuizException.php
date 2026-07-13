<?php

declare(strict_types=1);

namespace SmartToolbox\Modules\Quiz;

use RuntimeException;

final class QuizException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'quiz_error'
    ) {
        parent::__construct($message);
    }
}
