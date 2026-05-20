<?php

declare(strict_types=1);

namespace App\Models;

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
