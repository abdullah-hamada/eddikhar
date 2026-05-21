<?php

declare(strict_types=1);

namespace App\Models;

use App\Exceptions\InvalidStateTransitionException;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PayrollEvent extends Model
{
    use HasUuids;

    protected $fillable = [
        'external_event_id',
        'event_type',
        'payload',
        'status',
        'processed_at',
        'attempts',
        'error_message',
    ];

    protected $attributes = [
        'status' => 'received',
        'attempts' => 0,
    ];

    /**
     * Valid state transitions for payroll event lifecycle.
     *
     * received   → processing:  Event picked up for processing
     * processing → processed:   Event processed successfully
     * processing → failed:      Event processing failed
     * failed     → processing:  Retry after failure (explicit retry flow)
     * processed  → (none):      Terminal state — prevents double-processing
     */
    private const VALID_TRANSITIONS = [
        'received'   => ['processing'],
        'processing' => ['processed', 'failed'],
        'processed'  => [],
        'failed'     => ['processing'],
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'processed_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (PayrollEvent $event) {
            if ($event->isDirty('status')) {
                $originalStatus = $event->getOriginal('status');
                $newStatus = $event->status;
                $allowed = self::VALID_TRANSITIONS[$originalStatus] ?? [];

                if (!in_array($newStatus, $allowed, true)) {
                    throw new InvalidStateTransitionException(
                        'PayrollEvent',
                        $event->id,
                        $originalStatus,
                        $newStatus
                    );
                }
            }
        });
    }

    /**
     * Transition the event to a new status with validation.
     *
     * @throws InvalidStateTransitionException If the transition is not allowed.
     */
    public function transitionTo(string $newStatus): self
    {
        $allowed = self::VALID_TRANSITIONS[$this->status] ?? [];

        if (!in_array($newStatus, $allowed, true)) {
            throw new InvalidStateTransitionException(
                'PayrollEvent',
                $this->id,
                $this->status,
                $newStatus
            );
        }

        $this->status = $newStatus;

        return $this;
    }

    public function isTerminal(): bool
    {
        return $this->status === 'processed';
    }
}
