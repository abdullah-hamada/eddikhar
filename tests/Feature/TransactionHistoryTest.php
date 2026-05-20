<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Wallet;
use App\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TransactionHistoryTest extends TestCase
{
    use RefreshDatabase;

    private LedgerService $ledgerService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledgerService = $this->app->make(LedgerService::class);
    }

    public function test_can_get_transaction_history_ordered(): void
    {
        $employee = Employee::factory()->create();
        $wallet = Wallet::factory()->create(['employee_id' => $employee->id]);

        // Create 3 ledger transactions
        $this->ledgerService->credit($wallet, 5000, (string) Str::uuid(), 'deposit', 'Salary deposit', 'payroll');
        $this->ledgerService->debit($wallet, 2000, (string) Str::uuid(), 'withdrawal', 'ATM cash out', 'bank');
        $this->ledgerService->credit($wallet, 1000, (string) Str::uuid(), 'deposit', 'Refund', 'manual');

        $response = $this->getJson("/api/wallets/{$wallet->id}/transactions");

        $response->assertStatus(200)
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.description', 'Refund') // ordered desc
            ->assertJsonPath('data.1.description', 'ATM cash out')
            ->assertJsonPath('data.2.description', 'Salary deposit');
    }

    public function test_can_filter_transaction_history_by_type(): void
    {
        $employee = Employee::factory()->create();
        $wallet = Wallet::factory()->create(['employee_id' => $employee->id]);

        $this->ledgerService->credit($wallet, 5000, (string) Str::uuid());
        $this->ledgerService->debit($wallet, 2000, (string) Str::uuid());
        $this->ledgerService->credit($wallet, 1000, (string) Str::uuid());

        $response = $this->getJson("/api/wallets/{$wallet->id}/transactions?type=debit");

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'debit')
            ->assertJsonPath('data.0.amount', 2000);
    }

    public function test_can_filter_transaction_history_by_reference_type(): void
    {
        $employee = Employee::factory()->create();
        $wallet = Wallet::factory()->create(['employee_id' => $employee->id]);

        $this->ledgerService->credit($wallet, 5000, (string) Str::uuid(), 'deposit', 'Salary', 'payroll');
        $this->ledgerService->debit($wallet, 2000, (string) Str::uuid(), 'withdrawal', 'ATM', 'bank');

        $response = $this->getJson("/api/wallets/{$wallet->id}/transactions?reference_type=payroll");

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reference_type', 'payroll')
            ->assertJsonPath('data.0.description', 'Salary');
    }

    public function test_can_paginate_transaction_history(): void
    {
        $employee = Employee::factory()->create();
        $wallet = Wallet::factory()->create(['employee_id' => $employee->id]);

        for ($i = 1; $i <= 5; $i++) {
            $this->ledgerService->credit($wallet, 1000, (string) Str::uuid(), 'deposit', "Tx {$i}");
        }

        $response = $this->getJson("/api/wallets/{$wallet->id}/transactions?per_page=2");

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 5);
    }
}
