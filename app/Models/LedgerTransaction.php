<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Builders\RestrictedUpdateBuilder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LedgerTransaction extends Model
{
    use HasUuids;

    protected $fillable = [
        'type',
        'status',
        'idempotency_key',
        'metadata',
    ];

    protected $attributes = [
        'status' => 'pending',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (LedgerTransaction $transaction) {
            $dirtyFields = array_keys($transaction->getDirty());
            $immutableDirtyFields = array_diff($dirtyFields, ['status', 'updated_at']);

            if (!empty($immutableDirtyFields)) {
                throw new \DomainException(sprintf(
                    'Cannot update immutable fields: %s',
                    implode(', ', $immutableDirtyFields)
                ));
            }
        });

        static::deleting(function (LedgerTransaction $transaction) {
            throw new \DomainException('Ledger transaction is immutable and cannot be deleted.');
        });
    }

    /**
     * Use RestrictedUpdateBuilder to block mass delete/truncate and restrict
     * mass updates to only status/updated_at columns.
     *
     * Eloquent's query()->update() and query()->delete() bypass model events,
     * so we override the builder to catch those paths at the query level.
     * DB-level triggers provide a final safety net.
     */
    public function newEloquentBuilder($query): RestrictedUpdateBuilder
    {
        return new RestrictedUpdateBuilder($query);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class, 'transaction_id');
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function markCompleted(): void
    {
        $this->update(['status' => 'completed']);
    }

    public function markFailed(): void
    {
        $this->update(['status' => 'failed']);
    }
}
