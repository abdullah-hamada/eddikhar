<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\PayrollEvent;
use App\Models\Wallet;
use App\Services\LedgerService;
use App\Services\PayrollService;
use App\Jobs\ProcessPayrollEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class PayrollIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private PayrollService $payrollService;

    private LedgerService $ledgerService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->payrollService = $this->app->make(PayrollService::class);
        $this->ledgerService = $this->app->make(LedgerService::class);
    }

    public function test_onboard_employee_via_webhook(): void
    {
        Queue::fake();

        $eventId = (string) Str::uuid();

        $response = $this->postJson('/api/payroll/webhook', [
            'event_id' => $eventId,
            'event_type' => 'employee_onboarded',
            'payload' => [
                'employee_id' => 'EMP-001',
                'first_name' => 'John',
                'last_name' => 'Doe',
                'email' => 'john.doe@example.com',
                'metadata' => ['department' => 'Engineering'],
            ],
        ]);

        $response->assertStatus(202)
            ->assertJsonPath('status', 'accepted');

        $this->assertDatabaseHas('payroll_events', [
            'external_event_id' => $eventId,
            'event_type' => 'employee_onboarded',
            'status' => 'received',
        ]);

        Queue::assertPushed(ProcessPayrollEvent::class, function ($job) use ($eventId) {
            return $job->event->external_event_id === $eventId;
        });

        // Now process the event manually using the service
        $event = PayrollEvent::where('external_event_id', $eventId)->firstOrFail();
        $this->payrollService->processEvent($event);

        $event->refresh();
        $this->assertEquals('processed', $event->status);

        // Verify employee created
        $employee = Employee::where('external_id', 'EMP-001')->firstOrFail();
        $this->assertEquals('John', $employee->first_name);
        $this->assertEquals('john.doe@example.com', $employee->email);

        // Verify salary wallet created
        $wallet = $employee->wallets()->where('type', 'salary')->firstOrFail();
        $this->assertEquals('USD', $wallet->currency);
        $this->assertEquals(0, $wallet->balance);
    }

    public function test_employee_status_change_via_webhook(): void
    {
        $employee = Employee::factory()->create([
            'external_id' => 'EMP-002',
            'status' => 'active',
        ]);

        $eventId = (string) Str::uuid();
        $event = PayrollEvent::create([
            'external_event_id' => $eventId,
            'event_type' => 'employee_status_changed',
            'payload' => [
                'employee_id' => 'EMP-002',
                'status' => 'terminated',
            ],
            'status' => 'received',
        ]);

        $this->payrollService->processEvent($event);

        $employee->refresh();
        $this->assertEquals('terminated', $employee->status);

        $employee->wallets()->each(function ($wallet) {
            $this->assertEquals('closed', $wallet->fresh()->status);
        });
    }

    public function test_termination_blocked_when_wallet_has_balance(): void
    {
        $employee = Employee::factory()->create([
            'external_id' => 'EMP-TERM-BLOCK',
            'status' => 'active',
        ]);
        $wallet = Wallet::factory()->create([
            'employee_id' => $employee->id,
            'balance' => 0,
            'held_balance' => 0,
        ]);

        $this->ledgerService->credit($wallet, 5000, (string) Str::uuid());

        $event = PayrollEvent::create([
            'external_event_id' => (string) Str::uuid(),
            'event_type' => 'employee_status_changed',
            'payload' => [
                'employee_id' => 'EMP-TERM-BLOCK',
                'status' => 'terminated',
            ],
            'status' => 'received',
        ]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Cannot terminate employee');

        $this->payrollService->processEvent($event);

        $employee->refresh();
        $this->assertEquals('active', $employee->status);
    }

    public function test_late_salary_run_for_terminated_employee_is_rejected(): void
    {
        $employee = Employee::factory()->create([
            'external_id' => 'EMP-LATE-SALARY',
            'status' => 'active',
        ]);
        $wallet = Wallet::factory()->create([
            'employee_id' => $employee->id,
            'type' => 'salary',
            'currency' => 'USD',
            'balance' => 0,
        ]);

        $terminateEvent = PayrollEvent::create([
            'external_event_id' => (string) Str::uuid(),
            'event_type' => 'employee_status_changed',
            'payload' => [
                'employee_id' => 'EMP-LATE-SALARY',
                'status' => 'terminated',
            ],
            'status' => 'received',
        ]);
        $this->payrollService->processEvent($terminateEvent);

        $salaryEvent = PayrollEvent::create([
            'external_event_id' => (string) Str::uuid(),
            'event_type' => 'salary_run',
            'payload' => [
                'employee_id' => 'EMP-LATE-SALARY',
                'amount' => 100000,
                'currency' => 'USD',
            ],
            'status' => 'received',
        ]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Cannot credit non-active employee');

        $this->payrollService->processEvent($salaryEvent);

        $wallet->refresh();
        $this->assertEquals(0, $wallet->balance);
        $this->assertDatabaseCount('ledger_entries', 0);
    }

    public function test_salary_run_via_webhook(): void
    {
        $employee = Employee::factory()->create([
            'external_id' => 'EMP-003',
        ]);
        $wallet = Wallet::factory()->create([
            'employee_id' => $employee->id,
            'type' => 'salary',
            'currency' => 'USD',
            'balance' => 0,
        ]);

        $eventId = (string) Str::uuid();
        $event = PayrollEvent::create([
            'external_event_id' => $eventId,
            'event_type' => 'salary_run',
            'payload' => [
                'employee_id' => 'EMP-003',
                'amount' => 150000, // $1500.00
                'currency' => 'USD',
                'salary_period' => '2026-05',
            ],
            'status' => 'received',
        ]);

        $this->payrollService->processEvent($event);

        $wallet->refresh();
        $this->assertEquals(150000, $wallet->balance);

        $this->assertDatabaseHas('ledger_transactions', [
            'type' => 'payroll',
            'status' => 'completed',
            'idempotency_key' => "payroll:salary_run:{$eventId}",
        ]);

        $this->assertDatabaseHas('ledger_entries', [
            'wallet_id' => $wallet->id,
            'type' => 'credit',
            'amount' => 150000,
            'balance_after' => 150000,
            'reference_type' => 'payroll',
        ]);
    }

    public function test_webhook_ingestion_is_idempotent(): void
    {
        $eventId = (string) Str::uuid();
        $payload = [
            'event_id' => $eventId,
            'event_type' => 'employee_onboarded',
            'payload' => [
                'employee_id' => 'EMP-004',
                'first_name' => 'Alice',
                'last_name' => 'Smith',
                'email' => 'alice@example.com',
            ],
        ];

        // Send first webhook
        $this->postJson('/api/payroll/webhook', $payload)
            ->assertStatus(202);

        // Send duplicate webhook
        $this->postJson('/api/payroll/webhook', $payload)
            ->assertStatus(202)
            ->assertJsonPath('message', 'Event already received');

        $this->assertDatabaseCount('payroll_events', 1);
    }

    public function test_payroll_processing_is_idempotent(): void
    {
        $employee = Employee::factory()->create([
            'external_id' => 'EMP-005',
        ]);
        $wallet = Wallet::factory()->create([
            'employee_id' => $employee->id,
            'type' => 'salary',
            'currency' => 'USD',
            'balance' => 0,
        ]);

        $eventId = (string) Str::uuid();
        $event = PayrollEvent::create([
            'external_event_id' => $eventId,
            'event_type' => 'salary_run',
            'payload' => [
                'employee_id' => 'EMP-005',
                'amount' => 100000,
                'currency' => 'USD',
            ],
            'status' => 'received',
        ]);

        // Process first time
        $this->payrollService->processEvent($event);

        // Process second time (simulate queue retry or multiple executions)
        $this->payrollService->processEvent($event);

        $wallet->refresh();
        $this->assertEquals(100000, $wallet->balance); // Only credited once

        $this->assertDatabaseCount('ledger_transactions', 1);
        $this->assertDatabaseCount('ledger_entries', 1);
    }
}
