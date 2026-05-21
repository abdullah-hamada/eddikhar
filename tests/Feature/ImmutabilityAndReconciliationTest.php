<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BankPayment;
use App\Models\Employee;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Models\Wallet;
use App\Jobs\SendBankPayment;
use App\Services\BankService;
use App\Services\LedgerService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ImmutabilityAndReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private LedgerService $ledgerService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledgerService = $this->app->make(LedgerService::class);
    }

    /**
     * Test LedgerEntry mass updates are blocked by ImmutableBuilder.
     *
     * The ImmutableBuilder catches mass update attempts at the query builder level
     * (before the query reaches the database), providing defense-in-depth alongside
     * the DB-level triggers.
     */
    public function test_mass_update_on_ledger_entries_is_rejected_by_builder(): void
    {
        $employee = Employee::factory()->create();
        $wallet = Wallet::factory()->create(['employee_id' => $employee->id]);

        $this->ledgerService->credit($wallet, 1000, (string) Str::uuid());

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('immutable');

        LedgerEntry::query()->update(['amount' => 9999]);
    }

    public function test_send_bank_payment_job_uses_after_commit(): void
    {
        $employee = Employee::factory()->create();
        $wallet = Wallet::factory()->create(['employee_id' => $employee->id]);

        $payment = BankPayment::create([
            'wallet_id' => $wallet->id,
            'amount' => 1000,
            'currency' => 'USD',
            'status' => 'initiated',
            'idempotency_key' => (string) Str::uuid(),
            'initiated_at' => now(),
        ]);

        $job = new SendBankPayment($payment);

        $this->assertTrue($job->afterCommit);
    }

    public function test_send_bank_payment_not_queued_on_transaction_rollback(): void
    {
        config(['queue.default' => 'database']);

        $employee = Employee::factory()->create();
        $wallet = Wallet::factory()->create(['employee_id' => $employee->id]);
        $this->ledgerService->credit($wallet, 10000, (string) Str::uuid());

        $bankService = $this->app->make(BankService::class);

        try {
            DB::transaction(function () use ($bankService, $wallet) {
                $bankService->initiateWithdrawal($wallet, 1000, (string) Str::uuid());
                throw new \RuntimeException('Simulated rollback');
            });
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertEquals(0, DB::table('jobs')->count());
    }

    public function test_ledger_entry_is_immutable_on_update(): void
    {
        $employee = Employee::factory()->create();
        $wallet = Wallet::factory()->create(['employee_id' => $employee->id]);
        
        $transaction = $this->ledgerService->credit($wallet, 1000, (string) Str::uuid());
        $entry = $transaction->entries->first();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Ledger entry is immutable and cannot be updated.');

        $entry->update(['amount' => 2000]);
    }

    /**
     * Test LedgerEntry deletions throw DomainException.
     */
    public function test_ledger_entry_is_immutable_on_delete(): void
    {
        $employee = Employee::factory()->create();
        $wallet = Wallet::factory()->create(['employee_id' => $employee->id]);
        
        $transaction = $this->ledgerService->credit($wallet, 1000, (string) Str::uuid());
        $entry = $transaction->entries->first();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Ledger entry is immutable and cannot be deleted.');

        $entry->delete();
    }

    /**
     * Test LedgerTransaction updates throw DomainException for immutable fields.
     */
    public function test_ledger_transaction_is_immutable_on_immutable_field_update(): void
    {
        $employee = Employee::factory()->create();
        $wallet = Wallet::factory()->create(['employee_id' => $employee->id]);
        
        $transaction = $this->ledgerService->credit($wallet, 1000, (string) Str::uuid());

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Cannot update immutable fields: type');

        $transaction->update(['type' => 'transfer']);
    }

    /**
     * Test LedgerTransaction status updates are allowed.
     */
    public function test_ledger_transaction_allows_status_updates(): void
    {
        $employee = Employee::factory()->create();
        $wallet = Wallet::factory()->create(['employee_id' => $employee->id]);
        
        $transaction = LedgerTransaction::create([
            'type' => 'deposit',
            'status' => 'pending',
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $this->assertEquals('pending', $transaction->status);

        $transaction->update(['status' => 'completed']);
        $this->assertEquals('completed', $transaction->fresh()->status);
    }

    /**
     * Test LedgerTransaction deletions throw DomainException.
     */
    public function test_ledger_transaction_is_immutable_on_delete(): void
    {
        $employee = Employee::factory()->create();
        $wallet = Wallet::factory()->create(['employee_id' => $employee->id]);
        
        $transaction = $this->ledgerService->credit($wallet, 1000, (string) Str::uuid());

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Ledger transaction is immutable and cannot be deleted.');

        $transaction->delete();
    }

    /**
     * Test stuck bank hold reconciliation command success scenario.
     */
    public function test_reconciliation_command_resolves_stuck_initiated_and_pending_payments_to_success(): void
    {
        $employee = Employee::factory()->create();
        $wallet = Wallet::factory()->create([
            'employee_id' => $employee->id,
            'balance' => 10000,
            'held_balance' => 0,
        ]);

        // 1. Create a payment stuck in 'initiated' state for 45 minutes
        $initiatedPayment = BankPayment::create([
            'wallet_id' => $wallet->id,
            'amount' => 2000,
            'currency' => 'USD',
            'status' => 'initiated',
            'idempotency_key' => (string) Str::uuid(),
            'metadata' => [
                'simulate_reconciliation_status' => 'success',
            ],
            'initiated_at' => now()->subMinutes(45),
        ]);
        $initiatedPayment->created_at = now()->subMinutes(45);
        $initiatedPayment->save();

        // Place hold on funds manually to simulate initial state
        $wallet->held_balance += 2000;
        $wallet->save();

        // 2. Create a payment stuck in 'pending' state for 45 minutes
        $pendingPayment = BankPayment::create([
            'wallet_id' => $wallet->id,
            'amount' => 3000,
            'currency' => 'USD',
            'status' => 'pending',
            'external_reference' => 'BANK-TX-PENDING-MOCK',
            'idempotency_key' => (string) Str::uuid(),
            'metadata' => [
                'simulate_reconciliation_status' => 'success',
            ],
            'initiated_at' => now()->subMinutes(45),
        ]);
        $pendingPayment->created_at = now()->subMinutes(45);
        $pendingPayment->save();

        $wallet->held_balance += 3000;
        $wallet->save();

        $this->assertEquals(5000, $wallet->fresh()->held_balance);
        $this->assertEquals(10000, $wallet->fresh()->balance);

        // Run the Artisan command
        $this->artisan('ledger:reconcile-payments')
            ->expectsOutputToContain('Found 2 stuck bank payment(s) to reconcile.')
            ->expectsOutputToContain('Successfully resolved payment')
            ->assertExitCode(0);

        // Verify outcomes
        $initiatedPayment->refresh();
        $pendingPayment->refresh();

        $this->assertEquals('success', $initiatedPayment->status);
        $this->assertEquals('success', $pendingPayment->status);
        $this->assertNotEmpty($initiatedPayment->external_reference);

        // Wallet cached balance should be debited by 5000, and held balance should be released
        $wallet->refresh();
        $this->assertEquals(0, $wallet->held_balance);
        $this->assertEquals(5000, $wallet->balance);

        // Verify double-entry ledger entries exist
        $this->assertEquals(2, LedgerEntry::where('wallet_id', $wallet->id)->where('type', 'debit')->count());
    }

    /**
     * Test stuck bank hold reconciliation command failure scenario.
     */
    public function test_reconciliation_command_resolves_stuck_payments_to_failed(): void
    {
        $employee = Employee::factory()->create();
        $wallet = Wallet::factory()->create([
            'employee_id' => $employee->id,
            'balance' => 10000,
            'held_balance' => 0,
        ]);

        // Create a payment stuck in 'pending' state for 45 minutes that will fail reconciliation
        $pendingPayment = BankPayment::create([
            'wallet_id' => $wallet->id,
            'amount' => 3000,
            'currency' => 'USD',
            'status' => 'pending',
            'external_reference' => 'BANK-TX-FAILED-MOCK',
            'idempotency_key' => (string) Str::uuid(),
            'metadata' => [
                'simulate_reconciliation_status' => 'failed',
                'simulate_reconciliation_reason' => 'Account blocked',
            ],
            'initiated_at' => now()->subMinutes(45),
        ]);
        $pendingPayment->created_at = now()->subMinutes(45);
        $pendingPayment->save();

        $wallet->held_balance += 3000;
        $wallet->save();

        // Run the Artisan command
        $this->artisan('ledger:reconcile-payments')
            ->assertExitCode(0);

        // Verify outcomes
        $pendingPayment->refresh();
        $this->assertEquals('failed', $pendingPayment->status);
        $this->assertEquals('Account blocked', $pendingPayment->metadata['failure_reason']);

        // Wallet cached balance should remain 10000, and held balance should be completely released back to available
        $wallet->refresh();
        $this->assertEquals(0, $wallet->held_balance);
        $this->assertEquals(10000, $wallet->balance);

        // No debit ledger entries should exist
        $this->assertEquals(0, LedgerEntry::where('wallet_id', $wallet->id)->where('type', 'debit')->count());
    }
}
