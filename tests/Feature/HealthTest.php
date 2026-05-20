<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Wallet;
use App\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class HealthTest extends TestCase
{
    use RefreshDatabase;

    private LedgerService $ledgerService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledgerService = $this->app->make(LedgerService::class);
    }

    public function test_health_check_returns_healthy_when_system_is_consistent(): void
    {
        $employee = Employee::factory()->create();
        $wallet = Wallet::factory()->create([
            'employee_id' => $employee->id,
            'balance' => 0,
            'held_balance' => 0,
        ]);

        // Transaction 1: credit wallet
        $this->ledgerService->credit(
            wallet: $wallet,
            amount: 10000, // $100.00
            idempotencyKey: Str::uuid()->toString(),
            description: 'Monthly Salary Credit'
        );

        // Transaction 2: debit wallet
        $this->ledgerService->debit(
            wallet: $wallet,
            amount: 3000, // $30.00
            idempotencyKey: Str::uuid()->toString(),
            description: 'Lunch withdrawal'
        );

        $response = $this->getJson('/api/health');

        $response->assertStatus(200)
            ->assertJsonPath('status', 'healthy')
            ->assertJsonPath('checks.database', 'OK')
            ->assertJsonPath('checks.ledger_integrity', 'OK')
            ->assertJsonPath('details.ledger.total_credits', 10000)
            ->assertJsonPath('details.ledger.total_debits', 3000)
            ->assertJsonPath('details.ledger.expected_balance', 7000)
            ->assertJsonPath('details.ledger.actual_balance', 7000)
            ->assertJsonPath('details.ledger.total_wallets_checked', 1)
            ->assertJsonPath('details.ledger.mismatched_wallets_count', 0);
    }

    public function test_health_check_returns_unhealthy_when_ledger_mismatch_occurs(): void
    {
        $employee = Employee::factory()->create();
        $wallet = Wallet::factory()->create([
            'employee_id' => $employee->id,
            'balance' => 0,
            'held_balance' => 0,
        ]);

        // Credit $100.00
        $this->ledgerService->credit(
            wallet: $wallet,
            amount: 10000,
            idempotencyKey: Str::uuid()->toString(),
            description: 'Credit'
        );

        // Directly manipulate wallet balance in database to simulate tampering/corruption
        // (This skips LedgerService and creates a discrepancy between Ledger Entries and Wallet balance)
        $wallet->balance = 5000; // Expected balance should be 10000, but we force it to 5000.
        $wallet->save();

        $response = $this->getJson('/api/health');

        $response->assertStatus(503)
            ->assertJsonPath('status', 'unhealthy')
            ->assertJsonPath('checks.database', 'OK')
            ->assertJsonPath('checks.ledger_integrity', 'FAIL')
            ->assertJsonPath('details.ledger.total_credits', 10000)
            ->assertJsonPath('details.ledger.total_debits', 0)
            ->assertJsonPath('details.ledger.expected_balance', 10000)
            ->assertJsonPath('details.ledger.actual_balance', 5000)
            ->assertJsonCount(1, 'details.mismatches')
            ->assertJsonPath('details.mismatches.0.wallet_id', $wallet->id)
            ->assertJsonPath('details.mismatches.0.cached_balance', 5000)
            ->assertJsonPath('details.mismatches.0.ledger_derived', 10000);
    }
}
