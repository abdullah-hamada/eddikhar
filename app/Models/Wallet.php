<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Wallet extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'employee_id',
        'type',
        'currency',
        'status',
    ];

    protected $attributes = [
        'balance' => 0,
        'held_balance' => 0,
        'currency' => 'USD',
        'status' => 'active',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function getAvailableBalanceAttribute(): int
    {
        return $this->balance - $this->held_balance;
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
