<?php

declare(strict_types=1);

namespace App\Models\Builders;

use DomainException;
use Illuminate\Database\Eloquent\Builder;

/**
 * Eloquent Builder that restricts updates to allowed columns only.
 *
 * Used by LedgerTransaction to allow status transitions via query builder
 * while blocking modification of immutable fields (type, idempotency_key, metadata).
 */
class RestrictedUpdateBuilder extends Builder
{
    /**
     * Columns that may be updated via query builder.
     */
    private const ALLOWED_UPDATE_COLUMNS = ['status', 'updated_at'];

    /**
     * Only allows updates to status and updated_at columns.
     *
     * @throws DomainException If attempting to update immutable fields.
     */
    public function update(array $values): int
    {
        $immutableColumns = array_diff(array_keys($values), self::ALLOWED_UPDATE_COLUMNS);

        if (!empty($immutableColumns)) {
            throw new DomainException(sprintf(
                'Cannot mass-update immutable fields on ledger_transactions: %s',
                implode(', ', $immutableColumns)
            ));
        }

        return parent::update($values);
    }

    /**
     * @throws DomainException Always — ledger transactions are immutable.
     */
    public function delete(): mixed
    {
        throw new DomainException('Ledger transactions are immutable and cannot be deleted via query builder.');
    }

    /**
     * @throws DomainException Always — ledger transactions are immutable.
     */
    public function forceDelete(): mixed
    {
        throw new DomainException('Ledger transactions are immutable and cannot be deleted via query builder.');
    }

    /**
     * @throws DomainException Always — ledger transactions are immutable.
     */
    public function truncate(): void
    {
        throw new DomainException('Ledger transactions are immutable and cannot be truncated.');
    }
}
