<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Wallet;
use App\Models\BankPayment;
use App\Services\LedgerService;
use App\Services\BankService;
use App\Jobs\SendBankPayment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class BankIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private LedgerService $ledgerService;
    private BankService $bankService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledgerService = $this->app->make(LedgerService::class);
        $this->bankService = $this->app->make(BankService::class);
    }

    public function test_can_initiate_withdrawal_via_api(): void
    {
        Queue::fake();

        $employee = Employee::factory()->create();
        $wallet = Wallet::factory()->create(['employee_id' => $employee->id]);

        // Seed USD 100.00
        $this->ledgerService->credit($wallet, 10000, (string) Str::uuid());

        $idempotencyKey = (string) Str::uuid();

        $response = $this->postJson("/api/wallets/{$wallet->id}/withdraw", [
            'amount' => 4000, // withdraw $40.00
            'idempotency_key' => $idempotencyKey,
            'metadata' => ['device' => 'web'],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'initiated')
            ->assertJsonPath('data.amount', 4000)
            ->assertJsonPath('data.idempotency_key', $idempotencyKey);

        $wallet->refresh();
        // Balance is still $100.00, but $40.00 is held/locked
        $this->assertEquals(10000, $wallet->balance);
        $this->assertEquals(4000, $wallet->held_balance);
        $this->assertEquals(6000, $wallet->available_balance);

        Queue::assertPushed(SendBankPayment::class, function ($job) use ($idempotencyKey) {
            return $job->payment->idempotency_key === $idempotencyKey;
        });
    }

    public function test_withdrawal_insufficient_funds(): void
    {
        $employee = Employee::factory()->create();
        $wallet = Wallet::factory()->create(['employee_id' => $employee->id]);

        $this->ledgerService->credit($wallet, 5000, (string) Str::uuid());

        $response = $this->postJson("/api/wallets/{$wallet->id}/withdraw", [
            'amount' => 6000, // exceeds $50.00
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'INSUFFICIENT_FUNDS');

        $wallet->refresh();
        $this->assertEquals(0, $wallet->held_balance);
    }

    public function test_withdrawal_idempotency(): void
    {
        $employee = Employee::factory()->create();
        $wallet = Wallet::factory()->create(['employee_id' => $employee->id]);

        $this->ledgerService->credit($wallet, 10000, (string) Str::uuid());

        $idempotencyKey = (string) Str::uuid();

        $r1 = $this->postJson("/api/wallets/{$wallet->id}/withdraw", [
            'amount' => 4000,
            'idempotency_key' => $idempotencyKey,
        ]);
        $r1->assertStatus(201);
        $paymentId = $r1->json('data.id');

        $r2 = $this->postJson("/api/wallets/{$wallet->id}/withdraw", [
            'amount' => 4000,
            'idempotency_key' => $idempotencyKey,
        ]);
        $r2->assertStatus(201)
            ->assertJsonPath('data.id', $paymentId);

        $wallet->refresh();
        $this->assertEquals(4000, $wallet->held_balance); // Only held once
        $this->assertDatabaseCount('bank_payments', 1);
    }

    public function test_job_sends_to_bank_and_updates_status(): void
    {
        $employee = Employee::factory()->create();
        $wallet = Wallet::factory()->create(['employee_id' => $employee->id]);

        $this->ledgerService->credit($wallet, 10000, (string) Str::uuid());

        $payment = $this->bankService->initiateWithdrawal($wallet, 4000, (string) Str::uuid());

        // Process job
        $job = new SendBankPayment($payment);
        $this->app->call([$job, 'handle']);

        $payment->refresh();
        $this->assertEquals('pending', $payment->status);
        $this->assertNotNull($payment->external_reference);
        $this->assertStringStartsWith('BANK-TX-', $payment->external_reference);
    }

    public function test_bank_callback_success(): void
    {
        $employee = Employee::factory()->create();
        $wallet = Wallet::factory()->create(['employee_id' => $employee->id]);

        $this->ledgerService->credit($wallet, 10000, (string) Str::uuid());

        // Initiate and run job to simulate bank pendency
        $payment = $this->bankService->initiateWithdrawal($wallet, 4000, (string) Str::uuid());
        $this->bankService->sendToBank($payment);
        $payment->refresh();

        $response = $this->postJson('/api/bank/callback', [
            'external_reference' => $payment->external_reference,
            'status' => 'success',
        ]);

        $response->assertStatus(200);

        $payment->refresh();
        $wallet->refresh();

        $this->assertEquals('success', $payment->status);
        $this->assertEquals(0, $wallet->held_balance); // Hold released
        $this->assertEquals(6000, $wallet->balance); // Debited!

        // Verify ledger record
        $this->assertDatabaseHas('ledger_entries', [
            'wallet_id' => $wallet->id,
            'type' => 'debit',
            'amount' => 4000,
            'balance_after' => 6000,
            'reference_type' => 'bank',
            'reference_id' => $payment->id,
        ]);

        $this->assertTrue($this->ledgerService->verifyBalance($wallet));
    }

    public function test_bank_callback_failure(): void
    {
        $employee = Employee::factory()->create();
        $wallet = Wallet::factory()->create(['employee_id' => $employee->id]);

        $this->ledgerService->credit($wallet, 10000, (string) Str::uuid());

        $payment = $this->bankService->initiateWithdrawal($wallet, 4000, (string) Str::uuid());
        $this->bankService->sendToBank($payment);
        $payment->refresh();

        $response = $this->postJson('/api/bank/callback', [
            'external_reference' => $payment->external_reference,
            'status' => 'failed',
            'reason' => 'Account blocked',
        ]);

        $response->assertStatus(200);

        $payment->refresh();
        $wallet->refresh();

        $this->assertEquals('failed', $payment->status);
        $this->assertEquals('Account blocked', $payment->metadata['failure_reason']);
        $this->assertEquals(0, $wallet->held_balance); // Hold released
        $this->assertEquals(10000, $wallet->balance); // Balance unchanged!

        // Verify NO debit entry was created
        $this->assertDatabaseMissing('ledger_entries', [
            'wallet_id' => $wallet->id,
            'type' => 'debit',
            'amount' => 4000,
        ]);

        $this->assertTrue($this->ledgerService->verifyBalance($wallet));
    }
}
