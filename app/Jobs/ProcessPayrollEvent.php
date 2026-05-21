<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\PayrollEvent;
use App\Services\PayrollService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessPayrollEvent implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 5;

    /**
     * The maximum number of unhandled exceptions to allow before failing.
     */
    public int $maxExceptions = 3;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 60;

    public function __construct(
        public readonly PayrollEvent $event,
    ) {
        $this->afterCommit = true;
    }

    /**
     * Exponential backoff strategy for payroll processing retries.
     *
     * 5s → 30s → 2min → 10min → 30min
     * Gives time for dependent services to recover.
     *
     * @return array<int>
     */
    public function backoff(): array
    {
        return [5, 30, 120, 600, 1800];
    }

    /**
     * The unique ID of the job — prevents duplicate queue entries
     * for the same payroll event.
     */
    public function uniqueId(): string
    {
        return $this->event->id;
    }

    public function handle(PayrollService $payrollService): void
    {
        $payrollService->processEvent($this->event);
    }

    /**
     * Handle permanent job failure after all retries exhausted.
     *
     * The payroll event will remain in 'failed' status for manual investigation.
     */
    public function failed(\Throwable $exception): void
    {
        Log::channel('ledger')->error('ProcessPayrollEvent permanently failed', [
            'event_id' => $this->event->id,
            'external_event_id' => $this->event->external_event_id,
            'event_type' => $this->event->event_type,
            'attempts' => $this->attempts(),
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }
}
