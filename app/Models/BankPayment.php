<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankPayment extends Model
{
    use HasUuids;

    protected $fillable = [
        'wallet_id',
        'amount',
        'currency',
        'status',
        'external_reference',
        'idempotency_key',
        'metadata',
        'initiated_at',
        'confirmed_at',
    ];

    protected $attributes = [
        'status' => 'initiated',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'metadata' => 'array',
            'initiated_at' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }
}
