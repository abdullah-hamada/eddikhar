<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\PayrollEvent;
use App\Services\PayrollService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessPayrollEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public int $backoff = 5;
    public function __construct(
        public readonly PayrollEvent $event,
    ) {
        $this->afterCommit = true;
    }

    public function handle(PayrollService $payrollService): void
    {
        $payrollService->processEvent($this->event);
    }
}
