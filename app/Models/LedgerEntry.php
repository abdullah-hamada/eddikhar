<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Builders\ImmutableBuilder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LedgerEntry extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'transaction_id',
        'wallet_id',
        'type',
        'amount',
        'balance_after',
        'description',
        'reference_type',
        'reference_id',
        'metadata',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'balance_after' => 'integer',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (LedgerEntry $entry) {
            throw new \DomainException('Ledger entry is immutable and cannot be updated.');
        });

        static::deleting(function (LedgerEntry $entry) {
            throw new \DomainException('Ledger entry is immutable and cannot be deleted.');
        });
    }

    /**
     * Use ImmutableBuilder to block mass update/delete/truncate operations.
     *
     * Eloquent's query()->update() and query()->delete() bypass model events,
     * so we override the builder to catch those paths at the query level.
     * DB-level triggers provide a final safety net.
     */
    public function newEloquentBuilder($query): ImmutableBuilder
    {
        return new ImmutableBuilder($query);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(LedgerTransaction::class, 'transaction_id');
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function isCredit(): bool
    {
        return $this->type === 'credit';
    }

    public function isDebit(): bool
    {
        return $this->type === 'debit';
    }
}
