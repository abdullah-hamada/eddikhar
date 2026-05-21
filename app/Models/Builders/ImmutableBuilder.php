<?php

declare(strict_types=1);

namespace App\Models\Builders;

use DomainException;
use Illuminate\Database\Eloquent\Builder;

/**
 * Eloquent Builder that enforces full immutability.
 *
 * Prevents all update, delete, and truncate operations at the query builder level,
 * catching mass operations that bypass model events (e.g., Model::query()->update()).
 */
class ImmutableBuilder extends Builder
{
    /**
     * @throws DomainException Always — ledger entries are immutable.
     */
    public function update(array $values): int
    {
        throw new DomainException('Ledger entries are immutable and cannot be updated via query builder.');
    }

    /**
     * @throws DomainException Always — ledger entries are immutable.
     */
    public function delete(): mixed
    {
        throw new DomainException('Ledger entries are immutable and cannot be deleted via query builder.');
    }

    /**
     * @throws DomainException Always — ledger entries are immutable.
     */
    public function forceDelete(): mixed
    {
        throw new DomainException('Ledger entries are immutable and cannot be deleted via query builder.');
    }

    /**
     * @throws DomainException Always — ledger entries are immutable.
     */
    public function truncate(): void
    {
        throw new DomainException('Ledger entries are immutable and cannot be truncated.');
    }
}
