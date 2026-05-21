<?php

declare(strict_types=1);

namespace App\Exceptions;

use DomainException;

class InvalidStateTransitionException extends DomainException
{
    public function __construct(
        public readonly string $model,
        public readonly string $modelId,
        public readonly string $fromStatus,
        public readonly string $toStatus,
    ) {
        parent::__construct(
            "Invalid state transition for {$model} [{$modelId}]: '{$fromStatus}' → '{$toStatus}'"
        );
    }
}
