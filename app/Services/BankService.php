<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BankPayment;
use App\Models\Wallet;
use App\Exceptions\InsufficientFundsException;
use App\Jobs\SendBankPayment;
use App\Services\LedgerService;
use App\Services\BankSimulator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BankService
{
    public function __construct(
        private readonly LedgerService $ledgerService,
        private readonly BankSimulator $bankSimulator,
    ) {}

    /**
     * Initiate a withdrawal: Lock/hold funds and create bank payment.
     *
     * Idempotency is enforced inside the DB transaction using insert-first strategy.
     * @see LedgerService::credit() for idempotency strategy documentation.
     */
    public function initiateWithdrawal(Wallet $wallet, int $amount, string $idempotencyKey, ?array $metadata = null): BankPayment
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Amount must be positive.');
        }

        if (!$wallet->isActive() || !$wallet->employee->isActive()) {
            throw new \DomainException("Wallet or Employee is not active.");
        }

        return DB::transaction(function () use ($wallet, $amount, $idempotencyKey, $metadata) {
            // Lock wallet row
            $lockedWallet = Wallet::where('id', $wallet->id)->lockForUpdate()->firstOrFail();

            // Idempotency check — inside transaction, under wallet lock
            $existing = BankPayment::where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return $existing;
            }

            $availableBalance = $lockedWallet->balance - $lockedWallet->held_balance;
            if ($availableBalance < $amount) {
                throw new InsufficientFundsException($lockedWallet->id, $amount, $availableBalance);
            }

            // Create bank payment — catch unique constraint violation from concurrent insert
            try {
                $payment = BankPayment::create([
                    'wallet_id' => $lockedWallet->id,
                    'amount' => $amount,
                    'currency' => $lockedWallet->currency,
                    'status' => 'initiated',
                    'idempotency_key' => $idempotencyKey,
                    'metadata' => $metadata,
                    'initiated_at' => now(),
                ]);
            } catch (QueryException $e) {
                if ($this->isUniqueViolation($e)) {
                    return BankPayment::where('idempotency_key', $idempotencyKey)->firstOrFail();
                }
                throw $e;
            }

            // Increase held balance to lock funds
            $lockedWallet->held_balance += $amount;
            $lockedWallet->save();

            // Dispatch payment to bank asynchronously
            SendBankPayment::dispatch($payment);

            return $payment;
        });
    }

    /**
     * Send payment details to bank partner (run inside SendBankPayment job).
     *
     * State machine: initiated → pending
     */
    public function sendToBank(BankPayment $payment): void
    {
        DB::transaction(function () use ($payment) {
            $lockedPayment = BankPayment::where('id', $payment->id)->lockForUpdate()->firstOrFail();

            if ($lockedPayment->status !== 'initiated') {
                return;
            }

            $response = $this->bankSimulator->sendPayment($lockedPayment);

            $lockedPayment->transitionTo('pending');
            $lockedPayment->external_reference = $response['external_reference'];
            $lockedPayment->metadata = array_merge($lockedPayment->metadata ?? [], ['simulator_response' => $response]);
            $lockedPayment->save();
        });
    }

    /**
     * Process bank callback to confirm or fail a payment.
     *
     * State machine: pending → success | failed
     *
     * IMPORTANT: When confirming a successful withdrawal, held_balance is reduced
     * BEFORE the debit is applied. This maintains the invariant held_balance <= balance
     * at every point in the transaction, which is enforced by a DB CHECK constraint.
     */
    public function confirmPayment(string $externalReference, string $status, ?string $reason = null): void
    {
        if (!in_array($status, ['success', 'failed'], true)) {
            throw new \InvalidArgumentException("Invalid callback status: {$status}");
        }

        DB::transaction(function () use ($externalReference, $status, $reason) {
            // Lock bank payment row
            $payment = BankPayment::where('external_reference', $externalReference)->lockForUpdate()->firstOrFail();

            if ($payment->status === 'success' || $payment->status === 'failed') {
                return; // Already processed — idempotent
            }

            // Lock wallet row
            $wallet = Wallet::where('id', $payment->wallet_id)->lockForUpdate()->firstOrFail();

            if ($status === 'success') {
                // CRITICAL: Release held_balance BEFORE debiting to maintain held_balance <= balance invariant.
                // The debit will reduce balance, so held_balance must be reduced first.
                $wallet->held_balance = max(0, $wallet->held_balance - $payment->amount);
                $wallet->save();

                // Debit wallet via LedgerService using idempotency key derived from bank payment ID
                $ledgerIdempotencyKey = "bank:withdrawal:{$payment->id}";

                $this->ledgerService->debit(
                    wallet: $wallet,
                    amount: $payment->amount,
                    idempotencyKey: $ledgerIdempotencyKey,
                    type: 'withdrawal',
                    description: 'Bank Withdrawal',
                    referenceType: 'bank',
                    referenceId: $payment->id,
                    metadata: ['external_reference' => $externalReference]
                );

                $payment->transitionTo('success');
                $payment->confirmed_at = now();
                $payment->save();
            } else {
                // Outbound transaction failed. Simply release held balance (funds return to available)
                $wallet->held_balance = max(0, $wallet->held_balance - $payment->amount);
                $wallet->save();

                $payment->transitionTo('failed');
                $payment->confirmed_at = now();
                $payment->metadata = array_merge($payment->metadata ?? [], ['failure_reason' => $reason]);
                $payment->save();
            }

            Log::channel('ledger')->info('Bank payment resolved', [
                'payment_id' => $payment->id,
                'wallet_id' => $payment->wallet_id,
                'status' => $status,
                'amount' => $payment->amount,
                'external_reference' => $externalReference,
            ]);
        });
    }

    /**
     * Detect unique constraint violation across database drivers.
     */
    private function isUniqueViolation(QueryException $e): bool
    {
        $driverCode = $e->errorInfo[1] ?? null;

        return $driverCode === 1062
            || $driverCode === 2067
            || $driverCode === 19
            || ($e->errorInfo[0] ?? null) === '23505'
            || str_contains($e->getMessage(), 'UNIQUE constraint failed')
            || str_contains($e->getMessage(), 'Duplicate entry');
    }
}
