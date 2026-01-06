<?php

declare(strict_types=1);

namespace Famiq\RedmineBridge\Exceptions;

use RuntimeException;

final class RedmineValidationException extends RuntimeException
{
    /**
     * @param array<string, mixed> $errors
     */
    public function __construct(string $message, public array $errors = [], int $code = 0)
    {
        parent::__construct($message, $code);
    }
}
