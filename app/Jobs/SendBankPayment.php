<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\BankPayment;
use App\Services\BankService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendBankPayment implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 5;

    /**
     * The maximum number of unhandled exceptions to allow before failing.
     * Distinguishes between "retryable" exceptions (network timeouts) and
     * "fatal" exceptions (validation errors). After $maxExceptions fatal
     * errors the job is moved to the failed queue.
     */
    public int $maxExceptions = 3;

    /**
     * The number of seconds the job can run before timing out.
     * Bank API calls should complete well within 30 seconds.
     */
    public int $timeout = 30;

    public function __construct(
        public readonly BankPayment $payment,
    ) {
        $this->afterCommit = true;
    }

    /**
     * Exponential backoff strategy for bank API retries.
     *
     * 5s → 15s → 60s → 5min → 15min
     * Gives the bank partner time to recover from transient failures.
     *
     * @return array<int>
     */
    public function backoff(): array
    {
        return [5, 15, 60, 300, 900];
    }

    /**
     * The unique ID of the job — prevents duplicate queue entries
     * for the same payment.
     */
    public function uniqueId(): string
    {
        return $this->payment->id;
    }

    public function handle(BankService $bankService): void
    {
        $bankService->sendToBank($this->payment);
    }

    /**
     * Handle permanent job failure after all retries exhausted.
     *
     * Logs the failure for alerting/monitoring. The payment remains
     * in 'initiated' status and will be picked up by the reconciliation
     * command (ledger:reconcile-payments).
     */
    public function failed(\Throwable $exception): void
    {
        Log::channel('ledger')->error('SendBankPayment permanently failed', [
            'payment_id' => $this->payment->id,
            'wallet_id' => $this->payment->wallet_id,
            'amount' => $this->payment->amount,
            'attempts' => $this->attempts(),
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }
}
