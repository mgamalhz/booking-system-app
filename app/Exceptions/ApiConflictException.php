<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class ApiConflictException extends RuntimeException
{
    public function __construct(
        string $message = 'The request conflicts with the current state of the resource.',
        private readonly ?string $publicMessage = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function publicMessage(): string
    {
        return $this->publicMessage ?? $this->getMessage();
    }
}
