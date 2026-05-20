<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\BankPayment;
use App\Services\BankService;
use Illuminate\Console\Command;

class ReconcileBankPayments extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ledger:reconcile-payments';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reconcile initiated and pending bank payments stuck for more than 30 minutes.';

    /**
     * Execute the console command.
     */
    public function handle(BankService $bankService): int
    {
        $cutoffTime = now()->subMinutes(config('ledger.reconcile_after_minutes', 30));

        // Find stuck payments
        $stuckPayments = BankPayment::whereIn('status', ['initiated', 'pending'])
            ->where('created_at', '<', $cutoffTime)
            ->get();

        if ($stuckPayments->isEmpty()) {
            $this->info('No stuck bank payments found for reconciliation.');
            return Command::SUCCESS;
        }

        $this->info(sprintf('Found %d stuck bank payment(s) to reconcile.', $stuckPayments->count()));

        foreach ($stuckPayments as $payment) {
            $this->info(sprintf('Reconciling payment: %s (Status: %s, Amount: %d, Created: %s)', 
                $payment->id, 
                $payment->status, 
                $payment->amount, 
                $payment->created_at->toDateTimeString()
            ));

            try {
                // 1. If the status is initiated, the SendBankPayment job did not finish or was never run.
                // We should transition it to pending first by calling sendToBank().
                if ($payment->status === 'initiated') {
                    $this->warn('Payment is in initiated state. Sending to bank partner simulator...');
                    $bankService->sendToBank($payment);
                    $payment->refresh(); // Refresh payment to get the generated external_reference
                }

                // 2. Query/simulate status from the simulated bank partner.
                // We mock/simulate the query. Since it's a simulated partner, we decide the outcome:
                // We check for custom instructions in payment metadata to allow deterministic unit tests!
                $simulatedStatus = 'success';
                $simulatedReason = null;

                if ($payment->metadata && isset($payment->metadata['simulate_reconciliation_status'])) {
                    $simulatedStatus = $payment->metadata['simulate_reconciliation_status'];
                    if (isset($payment->metadata['simulate_reconciliation_reason'])) {
                        $simulatedReason = $payment->metadata['simulate_reconciliation_reason'];
                    }
                } else {
                    // Random simulation: 90% success, 10% failure
                    $simulatedStatus = (rand(1, 100) <= 90) ? 'success' : 'failed';
                    if ($simulatedStatus === 'failed') {
                        $simulatedReason = 'Reconciliation check timeout';
                    }
                }

                if (empty($payment->external_reference)) {
                    $this->error('Failed to get an external reference for payment.');
                    continue;
                }

                $this->info(sprintf('Simulated query status: %s (Reason: %s). Calling confirmPayment...', $simulatedStatus, $simulatedReason ?? 'none'));

                // 3. Confirm/resolve payment
                $bankService->confirmPayment(
                    externalReference: $payment->external_reference,
                    status: $simulatedStatus,
                    reason: $simulatedReason
                );

                $this->info(sprintf('Successfully resolved payment %s as %s.', $payment->id, $simulatedStatus));

            } catch (\Throwable $e) {
                $this->error(sprintf('Error reconciling payment %s: %s', $payment->id, $e->getMessage()));
            }
        }

        $this->info('Reconciliation run completed.');
        return Command::SUCCESS;
    }
}
