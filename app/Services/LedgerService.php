<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\InsufficientFundsException;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Models\Wallet;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LedgerService
{
    /**
     * Credit a wallet (add funds).
     * Idempotent: duplicate idempotency_key returns existing transaction.
     *
     * Idempotency is enforced inside the DB transaction using insert-first strategy:
     * 1. Lock the wallet row for update
     * 2. Check for existing transaction under lock
     * 3. Attempt insert — if unique constraint fails, return existing
     *
     * This eliminates the TOCTOU race condition where two concurrent requests
     * could both pass an outside-transaction existence check.
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

        return DB::transaction(function () use ($wallet, $amount, $idempotencyKey, $type, $description, $referenceType, $referenceId, $metadata) {
            // Lock wallet row for update
            $lockedWallet = Wallet::where('id', $wallet->id)->lockForUpdate()->firstOrFail();

            // Idempotency check — inside transaction, under wallet lock
            $existing = LedgerTransaction::where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return $existing->load('entries');
            }

            $newBalance = $lockedWallet->balance + $amount;

            // Create transaction — catch unique constraint violation from concurrent insert
            try {
                $transaction = LedgerTransaction::create([
                    'type' => $type,
                    'status' => 'completed',
                    'idempotency_key' => $idempotencyKey,
                    'metadata' => $metadata,
                ]);
            } catch (QueryException $e) {
                if ($this->isUniqueViolation($e)) {
                    return LedgerTransaction::where('idempotency_key', $idempotencyKey)
                        ->firstOrFail()
                        ->load('entries');
                }
                throw $e;
            }

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
     *
     * @see credit() for idempotency strategy documentation.
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

        return DB::transaction(function () use ($wallet, $amount, $idempotencyKey, $type, $description, $referenceType, $referenceId, $metadata) {
            // Lock wallet row
            $lockedWallet = Wallet::where('id', $wallet->id)->lockForUpdate()->firstOrFail();

            // Idempotency check — inside transaction, under wallet lock
            $existing = LedgerTransaction::where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return $existing->load('entries');
            }

            $availableBalance = $lockedWallet->balance - $lockedWallet->held_balance;

            if ($availableBalance < $amount) {
                throw new InsufficientFundsException($lockedWallet->id, $amount, $availableBalance);
            }

            $newBalance = $lockedWallet->balance - $amount;

            // Create transaction — catch unique constraint violation from concurrent insert
            try {
                $transaction = LedgerTransaction::create([
                    'type' => $type,
                    'status' => 'completed',
                    'idempotency_key' => $idempotencyKey,
                    'metadata' => $metadata,
                ]);
            } catch (QueryException $e) {
                if ($this->isUniqueViolation($e)) {
                    return LedgerTransaction::where('idempotency_key', $idempotencyKey)
                        ->firstOrFail()
                        ->load('entries');
                }
                throw $e;
            }

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
     *
     * DEADLOCK PREVENTION:
     * Wallets are always locked in ascending ID order (lexicographic for UUIDs).
     * This prevents circular wait conditions when two concurrent transfers
     * operate on the same wallets in opposite directions (A→B and B→A).
     * The deterministic ordering guarantees that all transactions acquire locks
     * in the same sequence, eliminating deadlock potential.
     *
     * @see credit() for idempotency strategy documentation.
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

        return DB::transaction(function () use ($from, $to, $amount, $idempotencyKey, $description, $metadata) {
            // Lock BOTH wallets in deterministic ID order to prevent deadlocks
            $wallets = $this->lockWalletsInOrder($from->id, $to->id);
            $lockedFrom = $wallets[$from->id];
            $lockedTo = $wallets[$to->id];

            // Idempotency check — inside transaction, under wallet locks
            $existing = LedgerTransaction::where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return $existing->load('entries');
            }

            $availableBalance = $lockedFrom->balance - $lockedFrom->held_balance;

            if ($availableBalance < $amount) {
                throw new InsufficientFundsException($lockedFrom->id, $amount, $availableBalance);
            }

            $newFromBalance = $lockedFrom->balance - $amount;
            $newToBalance = $lockedTo->balance + $amount;

            // Create transaction — catch unique constraint violation from concurrent insert
            try {
                $transaction = LedgerTransaction::create([
                    'type' => 'transfer',
                    'status' => 'completed',
                    'idempotency_key' => $idempotencyKey,
                    'metadata' => array_merge($metadata ?? [], [
                        'from_wallet_id' => $from->id,
                        'to_wallet_id' => $to->id,
                    ]),
                ]);
            } catch (QueryException $e) {
                if ($this->isUniqueViolation($e)) {
                    return LedgerTransaction::where('idempotency_key', $idempotencyKey)
                        ->firstOrFail()
                        ->load('entries');
                }
                throw $e;
            }

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

    /**
     * Reconcile a wallet's cached balance against its ledger entries.
     *
     * Locks the wallet row, recalculates the derived balance from all ledger entries,
     * and optionally auto-fixes any detected mismatch.
     *
     * @return array{wallet_id: string, cached_balance: int, derived_balance: int, mismatch: bool, drift: int, fixed: bool}
     */
    public function reconcileWallet(Wallet $wallet, bool $autoFix = false): array
    {
        return DB::transaction(function () use ($wallet, $autoFix) {
            $lockedWallet = Wallet::where('id', $wallet->id)->lockForUpdate()->firstOrFail();

            $credits = (int) LedgerEntry::where('wallet_id', $lockedWallet->id)
                ->where('type', 'credit')
                ->sum('amount');

            $debits = (int) LedgerEntry::where('wallet_id', $lockedWallet->id)
                ->where('type', 'debit')
                ->sum('amount');

            $derivedBalance = $credits - $debits;
            $cachedBalance = $lockedWallet->balance;
            $drift = $cachedBalance - $derivedBalance;
            $mismatch = $drift !== 0;
            $fixed = false;

            if ($mismatch) {
                Log::channel('ledger')->warning('Wallet balance mismatch detected', [
                    'wallet_id' => $lockedWallet->id,
                    'cached_balance' => $cachedBalance,
                    'derived_balance' => $derivedBalance,
                    'drift' => $drift,
                    'total_credits' => $credits,
                    'total_debits' => $debits,
                ]);

                if ($autoFix) {
                    $lockedWallet->balance = $derivedBalance;
                    $lockedWallet->save();
                    $fixed = true;

                    Log::channel('ledger')->info('Wallet balance auto-corrected', [
                        'wallet_id' => $lockedWallet->id,
                        'old_balance' => $cachedBalance,
                        'new_balance' => $derivedBalance,
                    ]);
                }
            }

            return [
                'wallet_id' => $lockedWallet->id,
                'cached_balance' => $cachedBalance,
                'derived_balance' => $derivedBalance,
                'mismatch' => $mismatch,
                'drift' => $drift,
                'fixed' => $fixed,
            ];
        });
    }

    /**
     * Lock multiple wallets in deterministic ID order to prevent deadlocks.
     *
     * This ensures that all concurrent transactions acquire row-level locks
     * in the same sequence, eliminating circular wait conditions.
     *
     * @return \Illuminate\Database\Eloquent\Collection<string, Wallet>  Keyed by wallet ID
     */
    private function lockWalletsInOrder(string ...$walletIds): \Illuminate\Database\Eloquent\Collection
    {
        $ids = array_unique($walletIds);
        sort($ids); // Deterministic order — lexicographic for UUIDs

        return Wallet::whereIn('id', $ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
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

    /**
     * Detect unique constraint violation across database drivers.
     *
     * Handles:
     * - MySQL: error code 1062 (ER_DUP_ENTRY)
     * - PostgreSQL: SQLSTATE 23505 (unique_violation)
     * - SQLite: error code 19 (SQLITE_CONSTRAINT) with UNIQUE message
     */
    private function isUniqueViolation(QueryException $e): bool
    {
        $driverCode = $e->errorInfo[1] ?? null;

        return $driverCode === 1062                                           // MySQL
            || $driverCode === 2067                                           // SQLite SQLITE_CONSTRAINT_UNIQUE
            || $driverCode === 19                                             // SQLite SQLITE_CONSTRAINT
            || ($e->errorInfo[0] ?? null) === '23505'                         // PostgreSQL SQLSTATE
            || str_contains($e->getMessage(), 'UNIQUE constraint failed')     // SQLite message
            || str_contains($e->getMessage(), 'Duplicate entry');             // MySQL message
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
