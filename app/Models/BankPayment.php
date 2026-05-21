<?php

declare(strict_types=1);

namespace App\Models;

use App\Exceptions\InvalidStateTransitionException;
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

    /**
     * Valid state transitions for bank payment lifecycle.
     *
     * initiated → pending:  Payment sent to bank partner
     * initiated → failed:   Payment failed before reaching bank (e.g., reconciliation timeout)
     * pending   → success:  Bank confirms payment completed
     * pending   → failed:   Bank reports payment failure
     * success   → (none):   Terminal state
     * failed    → (none):   Terminal state
     */
    private const VALID_TRANSITIONS = [
        'initiated' => ['pending', 'failed'],
        'pending'   => ['success', 'failed'],
        'success'   => [],
        'failed'    => [],
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

    protected static function booted(): void
    {
        static::updating(function (BankPayment $payment) {
            if ($payment->isDirty('status')) {
                $originalStatus = $payment->getOriginal('status');
                $newStatus = $payment->status;
                $allowed = self::VALID_TRANSITIONS[$originalStatus] ?? [];

                if (!in_array($newStatus, $allowed, true)) {
                    throw new InvalidStateTransitionException(
                        'BankPayment',
                        $payment->id,
                        $originalStatus,
                        $newStatus
                    );
                }
            }
        });
    }

    /**
     * Transition the payment to a new status with validation.
     *
     * @throws InvalidStateTransitionException If the transition is not allowed.
     */
    public function transitionTo(string $newStatus): self
    {
        $allowed = self::VALID_TRANSITIONS[$this->status] ?? [];

        if (!in_array($newStatus, $allowed, true)) {
            throw new InvalidStateTransitionException(
                'BankPayment',
                $this->id,
                $this->status,
                $newStatus
            );
        }

        $this->status = $newStatus;

        return $this;
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, ['success', 'failed'], true);
    }
}
