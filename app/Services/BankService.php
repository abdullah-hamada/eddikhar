<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BankPayment;
use App\Models\Wallet;
use App\Exceptions\InsufficientFundsException;
use App\Jobs\SendBankPayment;
use App\Services\LedgerService;
use App\Services\BankSimulator;
use Illuminate\Support\Facades\DB;

class BankService
{
    public function __construct(
        private readonly LedgerService $ledgerService,
        private readonly BankSimulator $bankSimulator,
    ) {}

    /**
     * Initiate a withdrawal: Lock/hold funds and create bank payment.
     */
    public function initiateWithdrawal(Wallet $wallet, int $amount, string $idempotencyKey, ?array $metadata = null): BankPayment
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Amount must be positive.');
        }

        if (!$wallet->isActive() || !$wallet->employee->isActive()) {
            throw new \DomainException("Wallet or Employee is not active.");
        }

        // Idempotency check: check if bank payment with this key already exists
        $existing = BankPayment::where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($wallet, $amount, $idempotencyKey, $metadata) {
            // Lock wallet row
            $lockedWallet = Wallet::where('id', $wallet->id)->lockForUpdate()->firstOrFail();

            $availableBalance = $lockedWallet->balance - $lockedWallet->held_balance;
            if ($availableBalance < $amount) {
                throw new InsufficientFundsException($lockedWallet->id, $amount, $availableBalance);
            }

            // Create bank payment
            $payment = BankPayment::create([
                'wallet_id' => $lockedWallet->id,
                'amount' => $amount,
                'currency' => $lockedWallet->currency,
                'status' => 'initiated',
                'idempotency_key' => $idempotencyKey,
                'metadata' => $metadata,
                'initiated_at' => now(),
            ]);

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
     */
    public function sendToBank(BankPayment $payment): void
    {
        DB::transaction(function () use ($payment) {
            $lockedPayment = BankPayment::where('id', $payment->id)->lockForUpdate()->firstOrFail();

            if ($lockedPayment->status !== 'initiated') {
                return;
            }

            $response = $this->bankSimulator->sendPayment($lockedPayment);

            $lockedPayment->update([
                'status' => 'pending',
                'external_reference' => $response['external_reference'],
                'metadata' => array_merge($lockedPayment->metadata ?? [], ['simulator_response' => $response]),
            ]);
        });
    }

    /**
     * Process bank callback to confirm or fail a payment.
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
                return; // Already processed
            }

            // Lock wallet row
            $wallet = Wallet::where('id', $payment->wallet_id)->lockForUpdate()->firstOrFail();

            if ($status === 'success') {
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

                // Release held balance (subtract from held_balance since it has been debited)
                // Note: The wallet balance was updated by ledgerService->debit, but held_balance must be manually decreased.
                $wallet->refresh();
                $wallet->held_balance = max(0, $wallet->held_balance - $payment->amount);
                $wallet->save();

                $payment->update([
                    'status' => 'success',
                    'confirmed_at' => now(),
                ]);
            } else {
                // Outbound transaction failed. Simply release held balance (funds return to available)
                $wallet->held_balance = max(0, $wallet->held_balance - $payment->amount);
                $wallet->save();

                $payment->update([
                    'status' => 'failed',
                    'confirmed_at' => now(),
                    'metadata' => array_merge($payment->metadata ?? [], ['failure_reason' => $reason]),
                ]);
            }
        });
    }
}
