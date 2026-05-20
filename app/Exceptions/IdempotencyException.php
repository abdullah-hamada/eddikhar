<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

class IdempotencyException extends Exception
{
    public function __construct(string $key)
    {
        parent::__construct("Duplicate idempotency key: {$key}");
    }
}
