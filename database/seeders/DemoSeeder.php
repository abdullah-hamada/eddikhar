<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\BankPayment;
use App\Models\Employee;
use App\Models\Wallet;
use App\Services\LedgerService;
use Illuminate\Database\Seeder;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $ledger = app(LedgerService::class);

        // --- Primary demo employee (matches Postman PAYROLL-EMP-1001 / happy path) ---
        $alice = Employee::create([
            'external_id' => 'PAYROLL-EMP-DEMO-001',
            'first_name' => 'Alice',
            'last_name' => 'Demo',
            'email' => 'alice.demo@eddikhar.local',
            'status' => 'active',
            'metadata' => ['department' => 'Engineering', 'seeded' => true],
        ]);

        $aliceSalary = Wallet::create([
            'employee_id' => $alice->id,
            'type' => 'salary',
            'currency' => 'USD',
            'balance' => 0,
            'held_balance' => 0,
            'status' => 'active',
        ]);

        $aliceSavings = Wallet::create([
            'employee_id' => $alice->id,
            'type' => 'savings',
            'currency' => 'USD',
            'balance' => 0,
            'held_balance' => 0,
            'status' => 'active',
        ]);

        $ledger->credit(
            wallet: $aliceSalary,
            amount: 500_000,
            idempotencyKey: 'seed:alice:salary:opening',
            type: 'deposit',
            description: 'Demo opening balance',
            referenceType: 'manual',
            referenceId: null,
            metadata: ['source' => 'DemoSeeder'],
        );

        $ledger->transfer(
            from: $aliceSalary->fresh(),
            to: $aliceSavings,
            amount: 100_000,
            idempotencyKey: 'seed:alice:transfer:savings',
            description: 'Demo transfer to savings',
            metadata: ['source' => 'DemoSeeder'],
        );

        // Stuck outbound payment (pending > 30 min) for reconciliation demo
        $stuckAmount = 30_000;
        $aliceSalary->refresh();
        $aliceSalary->held_balance = $stuckAmount;
        $aliceSalary->save();

        $stuckPayment = BankPayment::create([
            'wallet_id' => $aliceSalary->id,
            'amount' => $stuckAmount,
            'currency' => 'USD',
            'status' => 'pending',
            'external_reference' => 'BANK-TX-STUCK-DEMO-001',
            'idempotency_key' => 'seed:alice:withdraw:stuck',
            'metadata' => [
                'seeded' => true,
                'simulate_reconciliation_status' => 'success',
            ],
            'initiated_at' => now()->subMinutes(45),
        ]);
        $stuckPayment->created_at = now()->subMinutes(45);
        $stuckPayment->updated_at = now()->subMinutes(45);
        $stuckPayment->save();

        // --- Terminated employee (zero balance, closed wallets) ---
        $bob = Employee::create([
            'external_id' => 'PAYROLL-EMP-DEMO-002',
            'first_name' => 'Bob',
            'last_name' => 'Terminated',
            'email' => 'bob.terminated@eddikhar.local',
            'status' => 'terminated',
            'metadata' => ['seeded' => true],
        ]);

        Wallet::create([
            'employee_id' => $bob->id,
            'type' => 'salary',
            'currency' => 'USD',
            'balance' => 0,
            'held_balance' => 0,
            'status' => 'closed',
        ]);

        // --- Second active employee (lighter dataset) ---
        $carol = Employee::create([
            'external_id' => 'PAYROLL-EMP-DEMO-003',
            'first_name' => 'Carol',
            'last_name' => 'Reviewer',
            'email' => 'carol.reviewer@eddikhar.local',
            'status' => 'active',
            'metadata' => ['seeded' => true],
        ]);

        $carolSalary = Wallet::create([
            'employee_id' => $carol->id,
            'type' => 'salary',
            'currency' => 'USD',
            'balance' => 0,
            'held_balance' => 0,
            'status' => 'active',
        ]);

        $ledger->credit(
            wallet: $carolSalary,
            amount: 150_000,
            idempotencyKey: 'seed:carol:salary:opening',
            type: 'deposit',
            description: 'Demo payroll credit',
            referenceType: 'payroll',
            referenceId: null,
            metadata: ['source' => 'DemoSeeder'],
        );

        $aliceSalary->refresh();
        $aliceSavings->refresh();

        $this->command?->newLine();
        $this->command?->info('Demo data seeded successfully.');
        $this->command?->table(
            ['Resource', 'ID / reference', 'Notes'],
            [
                ['Primary employee', $alice->id, 'external_id: PAYROLL-EMP-DEMO-001'],
                ['Salary wallet (Alice)', $aliceSalary->id, 'balance: ' . $aliceSalary->balance . ' cents, held: ' . $aliceSalary->held_balance],
                ['Savings wallet (Alice)', $aliceSavings->id, 'balance: ' . $aliceSavings->balance . ' cents'],
                ['Stuck bank payment', $stuckPayment->id, 'pending since 45m — run ledger:reconcile-payments'],
                ['Bank external_reference', $stuckPayment->external_reference, 'use in POST /api/bank/callback'],
                ['Terminated employee', $bob->id, 'zero balance, closed wallet'],
                ['Second active employee', $carol->id, 'external_id: PAYROLL-EMP-DEMO-003'],
            ]
        );
        $this->command?->warn('Postman: set employee_id and wallet_id to Alice\'s IDs above, or run folder "1. Happy Path".');
        $this->command?->warn('Requires: php artisan serve + php artisan queue:work for async payroll/bank jobs.');
    }
}
