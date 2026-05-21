<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\InsufficientFundsException;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\BankPayment;
use App\Models\Employee;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Models\PayrollEvent;
use App\Models\Wallet;
use App\Services\BankService;
use App\Services\LedgerService;
use App\Services\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Production-grade financial concurrency and reliability tests.
 *
 * These tests validate critical invariants that must hold under all conditions:
 * 1. No negative balances (overdraw prevention)
 * 2. No double-spend (idempotency)
 * 3. No deadlocks (deterministic lock ordering)
 * 4. No duplicate processing (payroll, bank callbacks)
 * 5. Correct fund recovery on failures
 * 6. State machine integrity
 *
 * Note: SQLite serializes writes, so true concurrency isn't testable here.
 * These tests simulate race conditions by rapidly invoking operations sequentially,
 * which validates idempotency, state guards, and invariant enforcement.
 */
class FinancialConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private LedgerService $ledgerService;
    private BankService $bankService;
    private PayrollService $payrollService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledgerService = $this->app->make(LedgerService::class);
        $this->bankService = $this->app->make(BankService::class);
        $this->payrollService = $this->app->make(PayrollService::class);
    }

    // =========================================================================
    // 1. CONCURRENT WITHDRAWALS — NO NEGATIVE BALANCE, NO DOUBLE SPEND
    // =========================================================================

    public function test_multiple_debits_cannot_overdraw_wallet(): void
    {
        $employee = Employee::factory()->create();
        $wallet = Wallet::factory()->create(['employee_id' => $employee->id]);

        // Seed 10000 cents ($100)
        $this->ledgerService->credit($wallet, 10000, (string) Str::uuid());

        $successCount = 0;
        $failCount = 0;

        // Attempt 5 debits of 3000 each (total 15000 > balance of 10000)
        for ($i = 0; $i < 5; $i++) {
            try {
                $this->ledgerService->debit(
                    $wallet->fresh(),
                    3000,
                    (string) Str::uuid(),
                    'withdrawal',
                    "Debit attempt {$i}"
                );
                $successCount++;
            } catch (InsufficientFundsException) {
                $failCount++;
            }
        }

        $wallet->refresh();

        // At most 3 debits can succeed (3 × 3000 = 9000 ≤ 10000)
        $this->assertLessThanOrEqual(3, $successCount);
        $this->assertGreaterThanOrEqual(2, $failCount);

        // Balance must never go negative
        $this->assertGreaterThanOrEqual(0, $wallet->balance);

        // Total debited must not exceed original balance
        $totalDebited = $successCount * 3000;
        $this->assertLessThanOrEqual(10000, $totalDebited);

        // Final balance must be correct
        $this->assertEquals(10000 - $totalDebited, $wallet->balance);

        // Verify ledger integrity
        $this->assertTrue($this->ledgerService->verifyBalance($wallet));
    }

    public function test_exact_balance_debit_succeeds_then_subsequent_fails(): void
    {
        $employee = Employee::factory()->create();
        $wallet = Wallet::factory()->create(['employee_id' => $employee->id]);

        $this->ledgerService->credit($wallet, 5000, (string) Str::uuid());

        // Debit exact balance
        $this->ledgerService->debit($wallet->fresh(), 5000, (string) Str::uuid());

        // Subsequent debit of even 1 cent should fail
        $this->expectException(InsufficientFundsException::class);
        $this->ledgerService->debit($wallet->fresh(), 1, (string) Str::uuid());
    }

    // =========================================================================
    // 2. DUPLICATE WITHDRAWAL REQUESTS — SAME IDEMPOTENCY KEY
    // =========================================================================

    public function test_duplicate_credit_requests_with_same_key_are_idempotent(): void
    {
        $employee = Employee::factory()->create();
        $wallet = Wallet::factory()->create(['employee_id' => $employee->id]);

        $idempotencyKey = (string) Str::uuid();
        $results = [];

        // Fire 5 rapid credits with the same idempotency key
        for ($i = 0; $i < 5; $i++) {
            $results[] = $this->ledgerService->credit(
                $wallet->fresh(),
                5000,
                $idempotencyKey,
                'deposit',
                "Credit attempt {$i}"
            );
        }

        // All must return the same transaction ID
        $ids = array_unique(array_map(fn ($r) => $r->id, $results));
        $this->assertCount(1, $ids, 'All idempotent credit calls should return the same transaction');

        // Balance should only be credited once
        $wallet->refresh();
        $this->assertEquals(5000, $wallet->balance);

        // Only 1 transaction and 1 entry in the database
        $this->assertDatabaseCount('ledger_transactions', 1);
        $this->assertDatabaseCount('ledger_entries', 1);
    }

    public function test_duplicate_debit_requests_with_same_key_are_idempotent(): void
    {
        $employee = Employee::factory()->create();
        $wallet = Wallet::factory()->create(['employee_id' => $employee->id]);

        $this->ledgerService->credit($wallet, 10000, (string) Str::uuid());

        $idempotencyKey = (string) Str::uuid();
        $results = [];

        // Fire 5 rapid debits with the same idempotency key
        for ($i = 0; $i < 5; $i++) {
            $results[] = $this->ledgerService->debit(
                $wallet->fresh(),
                3000,
                $idempotencyKey,
                'withdrawal',
                "Debit attempt {$i}"
            );
        }

        $ids = array_unique(array_map(fn ($r) => $r->id, $results));
        $this->assertCount(1, $ids, 'All idempotent debit calls should return the same transaction');

        $wallet->refresh();
        $this->assertEquals(7000, $wallet->balance); // Only debited once

        // 1 credit + 1 debit = 2 transactions
        $this->assertDatabaseCount('ledger_transactions', 2);
    }

    public function test_duplicate_transfer_requests_with_same_key_are_idempotent(): void
    {
        $employee1 = Employee::factory()->create();
        $employee2 = Employee::factory()->create();

        $fromWallet = Wallet::factory()->create([
            'employee_id' => $employee1->id,
            'currency' => 'USD',
        ]);
        $toWallet = Wallet::factory()->create([
            'employee_id' => $employee2->id,
            'currency' => 'USD',
        ]);

        $this->ledgerService->credit($fromWallet, 10000, (string) Str::uuid());

        $idempotencyKey = (string) Str::uuid();
        $results = [];

        for ($i = 0; $i < 5; $i++) {
            $results[] = $this->ledgerService->transfer(
                $fromWallet->fresh(),
                $toWallet->fresh(),
                2000,
                $idempotencyKey,
                "Transfer attempt {$i}"
            );
        }

        $ids = array_unique(array_map(fn ($r) => $r->id, $results));
        $this->assertCount(1, $ids);

        $fromWallet->refresh();
        $toWallet->refresh();

        $this->assertEquals(8000, $fromWallet->balance);
        $this->assertEquals(2000, $toWallet->balance);
    }

    // =========================================================================
    // 3. CONCURRENT OPPOSITE TRANSFERS — DEADLOCK PREVENTION
    // =========================================================================

    public function test_opposite_direction_transfers_complete_without_deadlock(): void
    {
        $employee1 = Employee::factory()->create();
        $employee2 = Employee::factory()->create();

        $walletA = Wallet::factory()->create([
            'employee_id' => $employee1->id,
            'currency' => 'USD',
        ]);
        $walletB = Wallet::factory()->create([
            'employee_id' => $employee2->id,
            'currency' => 'USD',
        ]);

        // Fund both wallets
        $this->ledgerService->credit($walletA, 10000, (string) Str::uuid());
        $this->ledgerService->credit($walletB, 10000, (string) Str::uuid());

        // Transfer A → B
        $this->ledgerService->transfer(
            $walletA->fresh(),
            $walletB->fresh(),
            3000,
            (string) Str::uuid(),
            'A to B'
        );

        // Transfer B → A (opposite direction)
        $this->ledgerService->transfer(
            $walletB->fresh(),
            $walletA->fresh(),
            2000,
            (string) Str::uuid(),
            'B to A'
        );

        $walletA->refresh();
        $walletB->refresh();

        // A: 10000 - 3000 + 2000 = 9000
        $this->assertEquals(9000, $walletA->balance);
        // B: 10000 + 3000 - 2000 = 11000
        $this->assertEquals(11000, $walletB->balance);

        // Verify ledger integrity for both
        $this->assertTrue($this->ledgerService->verifyBalance($walletA));
        $this->assertTrue($this->ledgerService->verifyBalance($walletB));

        // Total money in system should be conserved
        $this->assertEquals(20000, $walletA->balance + $walletB->balance);
    }

    public function test_multiple_cross_transfers_maintain_total_conservation(): void
    {
        $employees = Employee::factory()->count(4)->create();
        $wallets = [];

        foreach ($employees as $emp) {
            $w = Wallet::factory()->create([
                'employee_id' => $emp->id,
                'currency' => 'USD',
            ]);
            $this->ledgerService->credit($w, 10000, (string) Str::uuid());
            $wallets[] = $w;
        }

        $initialTotal = 40000; // 4 × 10000

        // Execute many cross-transfers
        $this->ledgerService->transfer($wallets[0]->fresh(), $wallets[1]->fresh(), 2000, (string) Str::uuid());
        $this->ledgerService->transfer($wallets[1]->fresh(), $wallets[2]->fresh(), 3000, (string) Str::uuid());
        $this->ledgerService->transfer($wallets[2]->fresh(), $wallets[3]->fresh(), 1500, (string) Str::uuid());
        $this->ledgerService->transfer($wallets[3]->fresh(), $wallets[0]->fresh(), 4000, (string) Str::uuid());
        $this->ledgerService->transfer($wallets[0]->fresh(), $wallets[2]->fresh(), 1000, (string) Str::uuid());

        // Total money must be conserved
        $finalTotal = 0;
        foreach ($wallets as $w) {
            $w->refresh();
            $finalTotal += $w->balance;
            $this->assertGreaterThanOrEqual(0, $w->balance);
            $this->assertTrue($this->ledgerService->verifyBalance($w));
        }

        $this->assertEquals($initialTotal, $finalTotal, 'Total money in system must be conserved across transfers');
    }

    // =========================================================================
    // 4. DUPLICATE PAYROLL EVENTS — SINGLE PROCESSING
    // =========================================================================

    public function test_processing_same_payroll_event_multiple_times_credits_once(): void
    {
        $employee = Employee::factory()->create(['external_id' => 'EMP-PAYROLL-DUP']);
        $wallet = Wallet::factory()->create([
            'employee_id' => $employee->id,
            'type' => 'salary',
            'currency' => 'USD',
        ]);

        $eventId = (string) Str::uuid();
        $event = PayrollEvent::create([
            'external_event_id' => $eventId,
            'event_type' => 'salary_run',
            'payload' => [
                'employee_id' => 'EMP-PAYROLL-DUP',
                'amount' => 100000,
                'currency' => 'USD',
            ],
            'status' => 'received',
        ]);

        // Process first time
        $this->payrollService->processEvent($event);

        // Attempt to process again (simulate queue retry)
        $this->payrollService->processEvent($event->fresh());

        // Third time for good measure
        $this->payrollService->processEvent($event->fresh());

        $wallet->refresh();
        $this->assertEquals(100000, $wallet->balance, 'Salary should only be credited once');

        $this->assertDatabaseCount('ledger_transactions', 1);
        $this->assertDatabaseCount('ledger_entries', 1);

        $event->refresh();
        $this->assertEquals('processed', $event->status);
    }

    public function test_duplicate_payroll_webhook_creates_single_event(): void
    {
        $eventId = (string) Str::uuid();
        $payload = [
            'event_id' => $eventId,
            'event_type' => 'employee_onboarded',
            'payload' => [
                'employee_id' => 'EMP-WEBHOOK-DUP',
                'first_name' => 'Test',
                'last_name' => 'User',
                'email' => 'webhook.dup@test.com',
            ],
        ];

        // Send webhook 3 times
        for ($i = 0; $i < 3; $i++) {
            $response = $this->postJson('/api/payroll/webhook', $payload);
            $response->assertStatus(202);
        }

        // Only 1 event should exist
        $this->assertDatabaseCount('payroll_events', 1);
    }

    // =========================================================================
    // 5. BANK CALLBACK REPLAY — DUPLICATE CALLBACK SAFETY
    // =========================================================================

    public function test_duplicate_bank_success_callback_debits_wallet_once(): void
    {
        $employee = Employee::factory()->create();
        $wallet = Wallet::factory()->create(['employee_id' => $employee->id]);

        $this->ledgerService->credit($wallet, 10000, (string) Str::uuid());

        // Initiate withdrawal and send to bank
        $payment = $this->bankService->initiateWithdrawal($wallet, 4000, (string) Str::uuid());
        $this->bankService->sendToBank($payment);
        $payment->refresh();

        $externalRef = $payment->external_reference;

        // First callback — success
        $this->bankService->confirmPayment($externalRef, 'success');

        // Replay the same callback (simulate network retry)
        $this->bankService->confirmPayment($externalRef, 'success');

        // Third replay
        $this->bankService->confirmPayment($externalRef, 'success');

        $wallet->refresh();
        $payment->refresh();

        $this->assertEquals('success', $payment->status);
        $this->assertEquals(6000, $wallet->balance, 'Balance should be debited exactly once');
        $this->assertEquals(0, $wallet->held_balance, 'Held balance should be fully released');

        // Only 1 debit entry should exist (plus the initial credit)
        $this->assertEquals(1, LedgerEntry::where('wallet_id', $wallet->id)->where('type', 'debit')->count());

        $this->assertTrue($this->ledgerService->verifyBalance($wallet));
    }

    public function test_duplicate_bank_failure_callback_releases_hold_once(): void
    {
        $employee = Employee::factory()->create();
        $wallet = Wallet::factory()->create(['employee_id' => $employee->id]);

        $this->ledgerService->credit($wallet, 10000, (string) Str::uuid());

        $payment = $this->bankService->initiateWithdrawal($wallet, 4000, (string) Str::uuid());
        $this->bankService->sendToBank($payment);
        $payment->refresh();

        $externalRef = $payment->external_reference;

        // Multiple failure callbacks
        $this->bankService->confirmPayment($externalRef, 'failed', 'Timeout');
        $this->bankService->confirmPayment($externalRef, 'failed', 'Timeout');

        $wallet->refresh();
        $payment->refresh();

        $this->assertEquals('failed', $payment->status);
        $this->assertEquals(10000, $wallet->balance, 'Balance should be unchanged after failure');
        $this->assertEquals(0, $wallet->held_balance, 'Held balance should be released');

        // No debit entries should exist
        $this->assertEquals(0, LedgerEntry::where('wallet_id', $wallet->id)->where('type', 'debit')->count());
    }

    // =========================================================================
    // 6. FAILED WITHDRAWAL RECOVERY — HELD FUNDS RESTORED
    // =========================================================================

    public function test_failed_withdrawal_releases_held_funds_correctly(): void
    {
        $employee = Employee::factory()->create();
        $wallet = Wallet::factory()->create(['employee_id' => $employee->id]);

        $this->ledgerService->credit($wallet, 10000, (string) Str::uuid());

        // Initiate withdrawal — funds are held
        $payment = $this->bankService->initiateWithdrawal($wallet, 4000, (string) Str::uuid());

        $wallet->refresh();
        $this->assertEquals(4000, $wallet->held_balance);
        $this->assertEquals(6000, $wallet->available_balance);

        // Bank sends failure callback
        $this->bankService->sendToBank($payment);
        $payment->refresh();

        $this->bankService->confirmPayment($payment->external_reference, 'failed', 'Account blocked');

        $wallet->refresh();
        $this->assertEquals(0, $wallet->held_balance, 'Hold should be fully released');
        $this->assertEquals(10000, $wallet->balance, 'Balance should be unchanged');
        $this->assertEquals(10000, $wallet->available_balance, 'Full balance should be available again');

        $this->assertTrue($this->ledgerService->verifyBalance($wallet));
    }

    public function test_multiple_concurrent_withdrawals_with_mixed_outcomes(): void
    {
        $employee = Employee::factory()->create();
        $wallet = Wallet::factory()->create(['employee_id' => $employee->id]);

        $this->ledgerService->credit($wallet, 10000, (string) Str::uuid());

        // Initiate 3 withdrawals
        $payment1 = $this->bankService->initiateWithdrawal($wallet->fresh(), 3000, (string) Str::uuid());
        $payment2 = $this->bankService->initiateWithdrawal($wallet->fresh(), 2000, (string) Str::uuid());
        $payment3 = $this->bankService->initiateWithdrawal($wallet->fresh(), 1000, (string) Str::uuid());

        $wallet->refresh();
        $this->assertEquals(6000, $wallet->held_balance); // 3000 + 2000 + 1000
        $this->assertEquals(4000, $wallet->available_balance);

        // Send all to bank
        $this->bankService->sendToBank($payment1);
        $this->bankService->sendToBank($payment2);
        $this->bankService->sendToBank($payment3);

        $payment1->refresh();
        $payment2->refresh();
        $payment3->refresh();

        // Mixed outcomes: 1 success, 1 failure, 1 success
        $this->bankService->confirmPayment($payment1->external_reference, 'success');
        $this->bankService->confirmPayment($payment2->external_reference, 'failed', 'Rejected');
        $this->bankService->confirmPayment($payment3->external_reference, 'success');

        $wallet->refresh();

        // Balance: 10000 - 3000 - 1000 = 6000 (two successful debits)
        $this->assertEquals(6000, $wallet->balance);
        // All holds released
        $this->assertEquals(0, $wallet->held_balance);
        // Available equals balance
        $this->assertEquals(6000, $wallet->available_balance);

        $this->assertTrue($this->ledgerService->verifyBalance($wallet));
    }

    // =========================================================================
    // 7. RECONCILIATION INTEGRITY
    // =========================================================================

    public function test_reconciliation_detects_balance_mismatch(): void
    {
        $employee = Employee::factory()->create();
        $wallet = Wallet::factory()->create(['employee_id' => $employee->id]);

        $this->ledgerService->credit($wallet, 10000, (string) Str::uuid());
        $this->ledgerService->debit($wallet->fresh(), 3000, (string) Str::uuid());

        // Simulate drift by manually corrupting the cached balance
        Wallet::where('id', $wallet->id)->update(['balance' => 9999]);

        $result = $this->ledgerService->reconcileWallet($wallet->fresh());

        $this->assertTrue($result['mismatch']);
        $this->assertEquals(9999, $result['cached_balance']);
        $this->assertEquals(7000, $result['derived_balance']);
        $this->assertEquals(2999, $result['drift']);
        $this->assertFalse($result['fixed']);
    }

    public function test_reconciliation_auto_fixes_mismatch(): void
    {
        $employee = Employee::factory()->create();
        $wallet = Wallet::factory()->create(['employee_id' => $employee->id]);

        $this->ledgerService->credit($wallet, 10000, (string) Str::uuid());

        // Corrupt the cached balance
        Wallet::where('id', $wallet->id)->update(['balance' => 5000]);

        $result = $this->ledgerService->reconcileWallet($wallet->fresh(), autoFix: true);

        $this->assertTrue($result['mismatch']);
        $this->assertTrue($result['fixed']);
        $this->assertEquals(10000, $result['derived_balance']);

        // Verify the fix persisted
        $wallet->refresh();
        $this->assertEquals(10000, $wallet->balance);
    }

    public function test_reconciliation_confirms_consistent_wallet(): void
    {
        $employee = Employee::factory()->create();
        $wallet = Wallet::factory()->create(['employee_id' => $employee->id]);

        $this->ledgerService->credit($wallet, 10000, (string) Str::uuid());
        $this->ledgerService->debit($wallet->fresh(), 3000, (string) Str::uuid());

        $result = $this->ledgerService->reconcileWallet($wallet->fresh());

        $this->assertFalse($result['mismatch']);
        $this->assertEquals(7000, $result['cached_balance']);
        $this->assertEquals(7000, $result['derived_balance']);
        $this->assertEquals(0, $result['drift']);
    }

    // =========================================================================
    // 8. STATE MACHINE VALIDATION
    // =========================================================================

    public function test_bank_payment_rejects_invalid_state_transitions(): void
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

        // initiated → success (invalid — must go through pending)
        $this->expectException(InvalidStateTransitionException::class);
        $payment->transitionTo('success');
    }

    public function test_bank_payment_cannot_transition_from_terminal_states(): void
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

        // Move to terminal state
        $payment->transitionTo('pending');
        $payment->save();
        $payment->transitionTo('success');
        $payment->save();

        // Attempt any transition from terminal state
        $this->expectException(InvalidStateTransitionException::class);
        $payment->transitionTo('pending');
    }

    public function test_bank_payment_valid_lifecycle_transitions(): void
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

        // initiated → pending (valid)
        $payment->transitionTo('pending');
        $payment->save();
        $this->assertEquals('pending', $payment->fresh()->status);

        // pending → success (valid)
        $payment->transitionTo('success');
        $payment->confirmed_at = now();
        $payment->save();
        $this->assertEquals('success', $payment->fresh()->status);
    }

    public function test_payroll_event_rejects_invalid_state_transitions(): void
    {
        $event = PayrollEvent::create([
            'external_event_id' => (string) Str::uuid(),
            'event_type' => 'salary_run',
            'payload' => ['employee_id' => 'X', 'amount' => 1000],
            'status' => 'received',
        ]);

        // received → processed (invalid — must go through processing)
        $this->expectException(InvalidStateTransitionException::class);
        $event->transitionTo('processed');
    }

    public function test_payroll_event_allows_retry_from_failed(): void
    {
        $event = PayrollEvent::create([
            'external_event_id' => (string) Str::uuid(),
            'event_type' => 'salary_run',
            'payload' => ['employee_id' => 'X', 'amount' => 1000],
            'status' => 'received',
        ]);

        // received → processing → failed → processing (retry)
        $event->transitionTo('processing');
        $event->save();
        $event->transitionTo('failed');
        $event->save();

        // Retry: failed → processing (valid)
        $event->transitionTo('processing');
        $event->save();
        $this->assertEquals('processing', $event->fresh()->status);
    }

    // =========================================================================
    // 9. WITHDRAWAL IDEMPOTENCY (BANK SERVICE)
    // =========================================================================

    public function test_duplicate_withdrawal_initiation_with_same_key(): void
    {
        $employee = Employee::factory()->create();
        $wallet = Wallet::factory()->create(['employee_id' => $employee->id]);

        $this->ledgerService->credit($wallet, 10000, (string) Str::uuid());

        $idempotencyKey = (string) Str::uuid();

        $payment1 = $this->bankService->initiateWithdrawal($wallet->fresh(), 3000, $idempotencyKey);
        $payment2 = $this->bankService->initiateWithdrawal($wallet->fresh(), 3000, $idempotencyKey);
        $payment3 = $this->bankService->initiateWithdrawal($wallet->fresh(), 3000, $idempotencyKey);

        // All should return the same payment
        $this->assertEquals($payment1->id, $payment2->id);
        $this->assertEquals($payment1->id, $payment3->id);

        // Only one payment should exist
        $this->assertDatabaseCount('bank_payments', 1);

        // Held balance should only be applied once
        $wallet->refresh();
        $this->assertEquals(3000, $wallet->held_balance);
    }

    // =========================================================================
    // 10. IMMUTABILITY ENFORCEMENT
    // =========================================================================

    public function test_ledger_entry_mass_update_is_blocked(): void
    {
        $employee = Employee::factory()->create();
        $wallet = Wallet::factory()->create(['employee_id' => $employee->id]);

        $this->ledgerService->credit($wallet, 1000, (string) Str::uuid());

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('immutable');

        LedgerEntry::where('wallet_id', $wallet->id)->update(['amount' => 9999]);
    }

    public function test_ledger_entry_mass_delete_is_blocked(): void
    {
        $employee = Employee::factory()->create();
        $wallet = Wallet::factory()->create(['employee_id' => $employee->id]);

        $this->ledgerService->credit($wallet, 1000, (string) Str::uuid());

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('immutable');

        LedgerEntry::where('wallet_id', $wallet->id)->delete();
    }

    public function test_ledger_transaction_mass_delete_is_blocked(): void
    {
        $employee = Employee::factory()->create();
        $wallet = Wallet::factory()->create(['employee_id' => $employee->id]);

        $tx = $this->ledgerService->credit($wallet, 1000, (string) Str::uuid());

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('immutable');

        LedgerTransaction::where('id', $tx->id)->delete();
    }

    public function test_ledger_transaction_mass_update_of_immutable_fields_is_blocked(): void
    {
        $employee = Employee::factory()->create();
        $wallet = Wallet::factory()->create(['employee_id' => $employee->id]);

        $tx = $this->ledgerService->credit($wallet, 1000, (string) Str::uuid());

        $this->expectException(\DomainException::class);

        LedgerTransaction::where('id', $tx->id)->update(['type' => 'transfer']);
    }

    public function test_ledger_transaction_mass_update_of_status_is_allowed(): void
    {
        $employee = Employee::factory()->create();
        $wallet = Wallet::factory()->create(['employee_id' => $employee->id]);

        $tx = $this->ledgerService->credit($wallet, 1000, (string) Str::uuid());

        // This should NOT throw — status updates are allowed
        LedgerTransaction::where('id', $tx->id)->update(['status' => 'failed']);

        $this->assertEquals('failed', $tx->fresh()->status);
    }
}
