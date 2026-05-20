<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

class InsufficientFundsException extends Exception
{
    public function __construct(string $walletId, int $requested, int $available)
    {
        parent::__construct(
            "Insufficient funds in wallet {$walletId}. Requested: {$requested}, Available: {$available}"
        );
    }
}
