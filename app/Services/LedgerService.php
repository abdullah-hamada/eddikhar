<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\InsufficientFundsException;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LedgerService
{
    /**
     * Credit a wallet (add funds).
     * Idempotent: duplicate idempotency_key returns existing transaction.
     */
    public function credit(
        Wallet $wallet,
        int $amount,
        string $idempotencyKey,
        string $type = 'deposit',
        string $description = '',
        ?string $referenceType = null,
        ?string $referenceId = null,
        ?array $metadata = null,
    ): LedgerTransaction {
        $this->validateAmount($amount);
        $this->validateWalletActive($wallet);

        // Idempotency check — return existing if already processed
        $existing = LedgerTransaction::where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            return $existing->load('entries');
        }

        return DB::transaction(function () use ($wallet, $amount, $idempotencyKey, $type, $description, $referenceType, $referenceId, $metadata) {
            // Lock wallet row for update
            $lockedWallet = Wallet::where('id', $wallet->id)->lockForUpdate()->firstOrFail();

            $newBalance = $lockedWallet->balance + $amount;

            // Create transaction
            $transaction = LedgerTransaction::create([
                'type' => $type,
                'status' => 'completed',
                'idempotency_key' => $idempotencyKey,
                'metadata' => $metadata,
            ]);

            // Create ledger entry
            $entry = LedgerEntry::create([
                'transaction_id' => $transaction->id,
                'wallet_id' => $lockedWallet->id,
                'type' => 'credit',
                'amount' => $amount,
                'balance_after' => $newBalance,
                'description' => $description,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'metadata' => $metadata,
                'created_at' => now(),
            ]);

            $this->logLedgerEntryCreated($entry);

            // Update cached balance
            $lockedWallet->balance = $newBalance;
            $lockedWallet->save();

            return $transaction->load('entries');
        });
    }

    /**
     * Debit a wallet (remove funds).
     * Checks available_balance (balance - held_balance).
     * Idempotent: duplicate idempotency_key returns existing transaction.
     */
    public function debit(
        Wallet $wallet,
        int $amount,
        string $idempotencyKey,
        string $type = 'withdrawal',
        string $description = '',
        ?string $referenceType = null,
        ?string $referenceId = null,
        ?array $metadata = null,
    ): LedgerTransaction {
        $this->validateAmount($amount);
        $this->validateWalletActive($wallet);

        // Idempotency check
        $existing = LedgerTransaction::where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            return $existing->load('entries');
        }

        return DB::transaction(function () use ($wallet, $amount, $idempotencyKey, $type, $description, $referenceType, $referenceId, $metadata) {
            // Lock wallet row
            $lockedWallet = Wallet::where('id', $wallet->id)->lockForUpdate()->firstOrFail();

            $availableBalance = $lockedWallet->balance - $lockedWallet->held_balance;

            if ($availableBalance < $amount) {
                throw new InsufficientFundsException($lockedWallet->id, $amount, $availableBalance);
            }

            $newBalance = $lockedWallet->balance - $amount;

            // Create transaction
            $transaction = LedgerTransaction::create([
                'type' => $type,
                'status' => 'completed',
                'idempotency_key' => $idempotencyKey,
                'metadata' => $metadata,
            ]);

            // Create ledger entry
            $entry = LedgerEntry::create([
                'transaction_id' => $transaction->id,
                'wallet_id' => $lockedWallet->id,
                'type' => 'debit',
                'amount' => $amount,
                'balance_after' => $newBalance,
                'description' => $description,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'metadata' => $metadata,
                'created_at' => now(),
            ]);

            $this->logLedgerEntryCreated($entry);

            // Update cached balance
            $lockedWallet->balance = $newBalance;
            $lockedWallet->save();

            return $transaction->load('entries');
        });
    }

    /**
     * Transfer between two wallets. Double-entry: debit source + credit destination.
     * Both wallets must be same currency. Atomic.
     * Idempotent: duplicate idempotency_key returns existing transaction.
     */
    public function transfer(
        Wallet $from,
        Wallet $to,
        int $amount,
        string $idempotencyKey,
        string $description = '',
        ?array $metadata = null,
    ): LedgerTransaction {
        $this->validateAmount($amount);
        $this->validateWalletActive($from);
        $this->validateWalletActive($to);

        if ($from->id === $to->id) {
            throw new \DomainException('Cannot transfer to the same wallet.');
        }

        if ($from->currency !== $to->currency) {
            throw new \DomainException("Currency mismatch: {$from->currency} vs {$to->currency}. Cross-currency transfers not supported.");
        }

        // Idempotency check
        $existing = LedgerTransaction::where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            return $existing->load('entries');
        }

        return DB::transaction(function () use ($from, $to, $amount, $idempotencyKey, $description, $metadata) {
            // Lock BOTH wallets — always lock in ID order to prevent deadlocks
            $ids = [$from->id, $to->id];
            sort($ids);
            $wallets = Wallet::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            $lockedFrom = $wallets[$from->id];
            $lockedTo = $wallets[$to->id];

            $availableBalance = $lockedFrom->balance - $lockedFrom->held_balance;

            if ($availableBalance < $amount) {
                throw new InsufficientFundsException($lockedFrom->id, $amount, $availableBalance);
            }

            $newFromBalance = $lockedFrom->balance - $amount;
            $newToBalance = $lockedTo->balance + $amount;

            // Create transaction
            $transaction = LedgerTransaction::create([
                'type' => 'transfer',
                'status' => 'completed',
                'idempotency_key' => $idempotencyKey,
                'metadata' => array_merge($metadata ?? [], [
                    'from_wallet_id' => $from->id,
                    'to_wallet_id' => $to->id,
                ]),
            ]);

            // Debit entry (source)
            $debitEntry = LedgerEntry::create([
                'transaction_id' => $transaction->id,
                'wallet_id' => $lockedFrom->id,
                'type' => 'debit',
                'amount' => $amount,
                'balance_after' => $newFromBalance,
                'description' => $description ?: 'Transfer out',
                'reference_type' => 'transfer',
                'reference_id' => $transaction->id,
                'metadata' => $metadata,
                'created_at' => now(),
            ]);

            $this->logLedgerEntryCreated($debitEntry);

            // Credit entry (destination)
            $creditEntry = LedgerEntry::create([
                'transaction_id' => $transaction->id,
                'wallet_id' => $lockedTo->id,
                'type' => 'credit',
                'amount' => $amount,
                'balance_after' => $newToBalance,
                'description' => $description ?: 'Transfer in',
                'reference_type' => 'transfer',
                'reference_id' => $transaction->id,
                'metadata' => $metadata,
                'created_at' => now(),
            ]);

            $this->logLedgerEntryCreated($creditEntry);

            // Update cached balances
            $lockedFrom->balance = $newFromBalance;
            $lockedFrom->save();
            $lockedTo->balance = $newToBalance;
            $lockedTo->save();

            return $transaction->load('entries');
        });
    }

    /**
     * Verify ledger integrity: cached balance matches sum of entries.
     */
    public function verifyBalance(Wallet $wallet): bool
    {
        $credits = LedgerEntry::where('wallet_id', $wallet->id)->where('type', 'credit')->sum('amount');
        $debits = LedgerEntry::where('wallet_id', $wallet->id)->where('type', 'debit')->sum('amount');
        $derivedBalance = (int) $credits - (int) $debits;

        return $derivedBalance === $wallet->fresh()->balance;
    }

    private function validateAmount(int $amount): void
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Amount must be positive.');
        }
    }

    private function validateWalletActive(Wallet $wallet): void
    {
        if (!$wallet->isActive()) {
            throw new \DomainException("Wallet {$wallet->id} is not active (status: {$wallet->status}).");
        }
    }

    private function logLedgerEntryCreated(LedgerEntry $entry): void
    {
        Log::channel('ledger')->info('Ledger entry created', [
            'entry_id' => $entry->id,
            'transaction_id' => $entry->transaction_id,
            'wallet_id' => $entry->wallet_id,
            'amount' => $entry->amount,
            'type' => $entry->type,
            'balance_after' => $entry->balance_after,
            'reference_type' => $entry->reference_type,
            'reference_id' => $entry->reference_id,
            'timestamp' => $entry->created_at->toISOString(),
        ]);
    }
}
