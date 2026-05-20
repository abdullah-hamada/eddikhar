<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\InsufficientFundsException;
use App\Models\Employee;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Models\Wallet;
use App\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class LedgerTest extends TestCase
{
    use RefreshDatabase;

    private LedgerService $ledgerService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledgerService = $this->app->make(LedgerService::class);
    }

    public function test_can_credit_active_wallet(): void
    {
        $employee = Employee::factory()->create();
        $wallet = Wallet::factory()->create([
            'employee_id' => $employee->id,
            'balance' => 0,
        ]);

        // Seed initial balance of 1000 via credit
        $this->ledgerService->credit($wallet, 1000, (string) Str::uuid(), 'deposit', 'Initial deposit');

        $idempotencyKey = (string) Str::uuid();
        $transaction = $this->ledgerService->credit(
            wallet: $wallet,
            amount: 500,
            idempotencyKey: $idempotencyKey,
            type: 'deposit',
            description: 'Test Deposit',
            referenceType: 'bank',
            referenceId: (string) Str::uuid(),
            metadata: ['source' => 'test']
        );

        $this->assertInstanceOf(LedgerTransaction::class, $transaction);
        $this->assertEquals('completed', $transaction->status);
        $this->assertEquals('deposit', $transaction->type);
        $this->assertEquals($idempotencyKey, $transaction->idempotency_key);
        $this->assertEquals(['source' => 'test'], $transaction->metadata);

        // Check wallet balance
        $wallet->refresh();
        $this->assertEquals(1500, $wallet->balance);

        // Check ledger entry
        $this->assertDatabaseHas('ledger_entries', [
            'transaction_id' => $transaction->id,
            'wallet_id' => $wallet->id,
            'type' => 'credit',
            'amount' => 500,
            'balance_after' => 1500,
            'description' => 'Test Deposit',
            'reference_type' => 'bank',
        ]);

        // Verify ledger integrity
        $this->assertTrue($this->ledgerService->verifyBalance($wallet));
    }

    public function test_cannot_credit_negative_or_zero_amount(): void
    {
        $employee = Employee::factory()->create();
        $wallet = Wallet::factory()->create(['employee_id' => $employee->id]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Amount must be positive.');

        $this->ledgerService->credit($wallet, 0, (string) Str::uuid());
    }

    public function test_cannot_credit_inactive_wallet(): void
    {
        $employee = Employee::factory()->create();
        $wallet = Wallet::factory()->frozen()->create(['employee_id' => $employee->id]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage("Wallet {$wallet->id} is not active");

        $this->ledgerService->credit($wallet, 100, (string) Str::uuid());
    }

    public function test_credit_is_idempotent(): void
    {
        $employee = Employee::factory()->create();
        $wallet = Wallet::factory()->create(['employee_id' => $employee->id, 'balance' => 0]);

        // Seed initial balance
        $this->ledgerService->credit($wallet, 1000, (string) Str::uuid(), 'deposit', 'Initial deposit');

        $idempotencyKey = (string) Str::uuid();

        // First call
        $tx1 = $this->ledgerService->credit($wallet, 500, $idempotencyKey);
        // Second call with same key
        $tx2 = $this->ledgerService->credit($wallet, 500, $idempotencyKey);

        $this->assertEquals($tx1->id, $tx2->id);

        $wallet->refresh();
        $this->assertEquals(1500, $wallet->balance); // Only credited once

        // 1 initial deposit + 1 credit transaction = 2
        $this->assertDatabaseCount('ledger_transactions', 2);
        $this->assertDatabaseCount('ledger_entries', 2);
    }

    public function test_can_debit_active_wallet_with_sufficient_funds(): void
    {
        $employee = Employee::factory()->create();
        $wallet = Wallet::factory()->create([
            'employee_id' => $employee->id,
            'balance' => 0,
        ]);

        // Seed 2000 balance via credit
        $this->ledgerService->credit($wallet, 2000, (string) Str::uuid(), 'deposit', 'Initial deposit');

        // Set held balance manually to simulate pending withdrawal/hold
        $wallet->held_balance = 500;
        $wallet->save();

        $idempotencyKey = (string) Str::uuid();
        $transaction = $this->ledgerService->debit(
            wallet: $wallet,
            amount: 1000,
            idempotencyKey: $idempotencyKey,
            type: 'withdrawal',
            description: 'Test Withdrawal',
            referenceType: 'bank',
            referenceId: (string) Str::uuid(),
            metadata: ['device' => 'mobile']
        );

        $this->assertInstanceOf(LedgerTransaction::class, $transaction);
        $this->assertEquals('completed', $transaction->status);
        $this->assertEquals('withdrawal', $transaction->type);

        $wallet->refresh();
        $this->assertEquals(1000, $wallet->balance);

        $this->assertDatabaseHas('ledger_entries', [
            'transaction_id' => $transaction->id,
            'wallet_id' => $wallet->id,
            'type' => 'debit',
            'amount' => 1000,
            'balance_after' => 1000,
        ]);

        $this->assertTrue($this->ledgerService->verifyBalance($wallet));
    }

    public function test_cannot_debit_with_insufficient_funds(): void
    {
        $employee = Employee::factory()->create();
        $wallet = Wallet::factory()->create([
            'employee_id' => $employee->id,
            'balance' => 0,
        ]);

        // Seed 1000 balance via credit
        $this->ledgerService->credit($wallet, 1000, (string) Str::uuid(), 'deposit', 'Initial deposit');

        // Lock 800
        $wallet->held_balance = 800;
        $wallet->save();

        $this->expectException(InsufficientFundsException::class);
        // Requesting 300, but only 200 available
        $this->ledgerService->debit($wallet, 300, (string) Str::uuid());
    }

    public function test_debit_is_idempotent(): void
    {
        $employee = Employee::factory()->create();
        $wallet = Wallet::factory()->create(['employee_id' => $employee->id, 'balance' => 0]);

        // Seed 2000
        $this->ledgerService->credit($wallet, 2000, (string) Str::uuid(), 'deposit', 'Initial deposit');

        $idempotencyKey = (string) Str::uuid();

        $tx1 = $this->ledgerService->debit($wallet, 500, $idempotencyKey);
        $tx2 = $this->ledgerService->debit($wallet, 500, $idempotencyKey);

        $this->assertEquals($tx1->id, $tx2->id);

        $wallet->refresh();
        $this->assertEquals(1500, $wallet->balance); // Only debited once

        // 1 initial deposit + 1 debit = 2
        $this->assertDatabaseCount('ledger_transactions', 2);
        $this->assertDatabaseCount('ledger_entries', 2);
    }

    public function test_can_transfer_between_active_wallets_same_currency(): void
    {
        $employee1 = Employee::factory()->create();
        $employee2 = Employee::factory()->create();

        $fromWallet = Wallet::factory()->create([
            'employee_id' => $employee1->id,
            'balance' => 0,
            'type' => 'salary',
            'currency' => 'USD',
        ]);

        $toWallet = Wallet::factory()->create([
            'employee_id' => $employee2->id,
            'balance' => 0,
            'type' => 'savings',
            'currency' => 'USD',
        ]);

        // Seed initial balances
        $this->ledgerService->credit($fromWallet, 2000, (string) Str::uuid(), 'deposit', 'Initial deposit');
        $this->ledgerService->credit($toWallet, 500, (string) Str::uuid(), 'deposit', 'Initial deposit');

        $idempotencyKey = (string) Str::uuid();
        $transaction = $this->ledgerService->transfer(
            from: $fromWallet,
            to: $toWallet,
            amount: 1000,
            idempotencyKey: $idempotencyKey,
            description: 'Test Transfer',
            metadata: ['reason' => 'gift']
        );

        $this->assertInstanceOf(LedgerTransaction::class, $transaction);
        $this->assertEquals('transfer', $transaction->type);
        $this->assertEquals('completed', $transaction->status);

        $fromWallet->refresh();
        $toWallet->refresh();

        $this->assertEquals(1000, $fromWallet->balance);
        $this->assertEquals(1500, $toWallet->balance);

        // Verify ledger entries:
        // 1 deposit for fromWallet (credit)
        // 1 deposit for toWallet (credit)
        // 1 transfer debit for fromWallet
        // 1 transfer credit for toWallet
        $this->assertDatabaseCount('ledger_entries', 4);
        $this->assertDatabaseHas('ledger_entries', [
            'transaction_id' => $transaction->id,
            'wallet_id' => $fromWallet->id,
            'type' => 'debit',
            'amount' => 1000,
            'balance_after' => 1000,
        ]);

        $this->assertDatabaseHas('ledger_entries', [
            'transaction_id' => $transaction->id,
            'wallet_id' => $toWallet->id,
            'type' => 'credit',
            'amount' => 1000,
            'balance_after' => 1500,
        ]);

        $this->assertTrue($this->ledgerService->verifyBalance($fromWallet));
        $this->assertTrue($this->ledgerService->verifyBalance($toWallet));
    }

    public function test_cannot_transfer_between_mismatched_currencies(): void
    {
        $employee1 = Employee::factory()->create();
        $employee2 = Employee::factory()->create();

        $fromWallet = Wallet::factory()->create([
            'employee_id' => $employee1->id,
            'balance' => 0,
            'currency' => 'USD',
        ]);

        $toWallet = Wallet::factory()->create([
            'employee_id' => $employee2->id,
            'balance' => 0,
            'currency' => 'EUR',
        ]);

        $this->ledgerService->credit($fromWallet, 2000, (string) Str::uuid());
        $this->ledgerService->credit($toWallet, 500, (string) Str::uuid());

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Currency mismatch');

        $this->ledgerService->transfer($fromWallet, $toWallet, 1000, (string) Str::uuid());
    }

    public function test_cannot_transfer_to_same_wallet(): void
    {
        $employee = Employee::factory()->create();
        $wallet = Wallet::factory()->create([
            'employee_id' => $employee->id,
            'balance' => 0,
        ]);

        $this->ledgerService->credit($wallet, 2000, (string) Str::uuid());

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Cannot transfer to the same wallet.');

        $this->ledgerService->transfer($wallet, $wallet, 1000, (string) Str::uuid());
    }

    public function test_transfer_is_idempotent(): void
    {
        $employee1 = Employee::factory()->create();
        $employee2 = Employee::factory()->create();

        $fromWallet = Wallet::factory()->create([
            'employee_id' => $employee1->id,
            'balance' => 0,
        ]);

        $toWallet = Wallet::factory()->create([
            'employee_id' => $employee2->id,
            'balance' => 0,
        ]);

        $this->ledgerService->credit($fromWallet, 2000, (string) Str::uuid());
        $this->ledgerService->credit($toWallet, 500, (string) Str::uuid());

        $idempotencyKey = (string) Str::uuid();

        $tx1 = $this->ledgerService->transfer($fromWallet, $toWallet, 500, $idempotencyKey);
        $tx2 = $this->ledgerService->transfer($fromWallet, $toWallet, 500, $idempotencyKey);

        $this->assertEquals($tx1->id, $tx2->id);

        $fromWallet->refresh();
        $toWallet->refresh();

        $this->assertEquals(1500, $fromWallet->balance);
        $this->assertEquals(1000, $toWallet->balance); // Only transferred once

        // 2 credits + 1 transfer = 3 transactions
        $this->assertDatabaseCount('ledger_transactions', 3);
        // 2 credit entries + 2 transfer entries = 4 entries
        $this->assertDatabaseCount('ledger_entries', 4);
    }
}
