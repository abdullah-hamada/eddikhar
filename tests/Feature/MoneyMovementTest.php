<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Wallet;
use App\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MoneyMovementTest extends TestCase
{
    use RefreshDatabase;

    private LedgerService $ledgerService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledgerService = $this->app->make(LedgerService::class);
    }

    public function test_can_credit_wallet_via_api(): void
    {
        $employee = Employee::factory()->create();
        $wallet = Wallet::factory()->create(['employee_id' => $employee->id]);

        $idempotencyKey = (string) Str::uuid();

        $response = $this->postJson("/api/wallets/{$wallet->id}/credit", [
            'amount' => 5000, // $50.00
            'idempotency_key' => $idempotencyKey,
            'description' => 'Salary Credit',
            'reference_type' => 'manual',
            'reference_id' => (string) Str::uuid(),
            'metadata' => ['note' => 'test-credit'],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.type', 'deposit')
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.idempotency_key', $idempotencyKey)
            ->assertJsonPath('data.metadata.note', 'test-credit');

        $wallet->refresh();
        $this->assertEquals(5000, $wallet->balance);

        $this->assertDatabaseHas('ledger_entries', [
            'wallet_id' => $wallet->id,
            'type' => 'credit',
            'amount' => 5000,
            'balance_after' => 5000,
            'description' => 'Salary Credit',
        ]);
    }

    public function test_credit_wallet_requires_valid_input(): void
    {
        $employee = Employee::factory()->create();
        $wallet = Wallet::factory()->create(['employee_id' => $employee->id]);

        // Missing fields
        $this->postJson("/api/wallets/{$wallet->id}/credit", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['amount', 'idempotency_key']);

        // Invalid amount
        $this->postJson("/api/wallets/{$wallet->id}/credit", [
            'amount' => -100,
            'idempotency_key' => (string) Str::uuid(),
        ])->assertStatus(422)
          ->assertJsonValidationErrors(['amount']);
    }

    public function test_credit_wallet_idempotency(): void
    {
        $employee = Employee::factory()->create();
        $wallet = Wallet::factory()->create(['employee_id' => $employee->id]);

        $idempotencyKey = (string) Str::uuid();

        // First call
        $r1 = $this->postJson("/api/wallets/{$wallet->id}/credit", [
            'amount' => 5000,
            'idempotency_key' => $idempotencyKey,
        ]);
        $r1->assertStatus(201);
        $txId = $r1->json('data.id');

        // Second call
        $r2 = $this->postJson("/api/wallets/{$wallet->id}/credit", [
            'amount' => 5000,
            'idempotency_key' => $idempotencyKey,
        ]);
        $r2->assertStatus(201)
            ->assertJsonPath('data.id', $txId);

        $wallet->refresh();
        $this->assertEquals(5000, $wallet->balance); // credited only once
    }

    public function test_can_debit_wallet_via_api(): void
    {
        $employee = Employee::factory()->create();
        $wallet = Wallet::factory()->create(['employee_id' => $employee->id]);

        // Seed funds
        $this->ledgerService->credit($wallet, 10000, (string) Str::uuid());

        $idempotencyKey = (string) Str::uuid();

        $response = $this->postJson("/api/wallets/{$wallet->id}/debit", [
            'amount' => 3000,
            'idempotency_key' => $idempotencyKey,
            'description' => 'Debit payment',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.type', 'withdrawal')
            ->assertJsonPath('data.status', 'completed');

        $wallet->refresh();
        $this->assertEquals(7000, $wallet->balance);
    }

    public function test_debit_wallet_insufficient_funds(): void
    {
        $employee = Employee::factory()->create();
        $wallet = Wallet::factory()->create(['employee_id' => $employee->id]);

        // Seed 1000
        $this->ledgerService->credit($wallet, 1000, (string) Str::uuid());

        $response = $this->postJson("/api/wallets/{$wallet->id}/debit", [
            'amount' => 1500, // exceeds balance
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'INSUFFICIENT_FUNDS')
            ->assertJsonStructure(['error']);
    }

    public function test_debit_wallet_idempotency(): void
    {
        $employee = Employee::factory()->create();
        $wallet = Wallet::factory()->create(['employee_id' => $employee->id]);

        $this->ledgerService->credit($wallet, 10000, (string) Str::uuid());

        $idempotencyKey = (string) Str::uuid();

        $r1 = $this->postJson("/api/wallets/{$wallet->id}/debit", [
            'amount' => 2000,
            'idempotency_key' => $idempotencyKey,
        ]);
        $r1->assertStatus(201);
        $txId = $r1->json('data.id');

        $r2 = $this->postJson("/api/wallets/{$wallet->id}/debit", [
            'amount' => 2000,
            'idempotency_key' => $idempotencyKey,
        ]);
        $r2->assertStatus(201)
            ->assertJsonPath('data.id', $txId);

        $wallet->refresh();
        $this->assertEquals(8000, $wallet->balance);
    }

    public function test_can_transfer_between_wallets_via_api(): void
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

        // Seed fromWallet
        $this->ledgerService->credit($fromWallet, 5000, (string) Str::uuid());

        $idempotencyKey = (string) Str::uuid();

        $response = $this->postJson('/api/wallets/transfer', [
            'from_wallet_id' => $fromWallet->id,
            'to_wallet_id' => $toWallet->id,
            'amount' => 2000,
            'idempotency_key' => $idempotencyKey,
            'description' => 'P2P Transfer',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.type', 'transfer')
            ->assertJsonPath('data.status', 'completed');

        $fromWallet->refresh();
        $toWallet->refresh();

        $this->assertEquals(3000, $fromWallet->balance);
        $this->assertEquals(2000, $toWallet->balance);
    }

    public function test_transfer_mismatched_currencies_fails(): void
    {
        $employee1 = Employee::factory()->create();
        $employee2 = Employee::factory()->create();

        $fromWallet = Wallet::factory()->create([
            'employee_id' => $employee1->id,
            'currency' => 'USD',
        ]);
        $toWallet = Wallet::factory()->create([
            'employee_id' => $employee2->id,
            'currency' => 'EUR',
        ]);

        $this->ledgerService->credit($fromWallet, 5000, (string) Str::uuid());

        $response = $this->postJson('/api/wallets/transfer', [
            'from_wallet_id' => $fromWallet->id,
            'to_wallet_id' => $toWallet->id,
            'amount' => 2000,
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $response->assertStatus(422)
            ->assertJsonStructure(['error']);
    }

    public function test_transfer_idempotency(): void
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

        $this->ledgerService->credit($fromWallet, 5000, (string) Str::uuid());

        $idempotencyKey = (string) Str::uuid();

        $r1 = $this->postJson('/api/wallets/transfer', [
            'from_wallet_id' => $fromWallet->id,
            'to_wallet_id' => $toWallet->id,
            'amount' => 2000,
            'idempotency_key' => $idempotencyKey,
        ]);
        $r1->assertStatus(201);
        $txId = $r1->json('data.id');

        $r2 = $this->postJson('/api/wallets/transfer', [
            'from_wallet_id' => $fromWallet->id,
            'to_wallet_id' => $toWallet->id,
            'amount' => 2000,
            'idempotency_key' => $idempotencyKey,
        ]);
        $r2->assertStatus(201)
            ->assertJsonPath('data.id', $txId);

        $fromWallet->refresh();
        $toWallet->refresh();

        $this->assertEquals(3000, $fromWallet->balance);
        $this->assertEquals(2000, $toWallet->balance);
    }
}
