<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Wallet;
use App\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private LedgerService $ledgerService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledgerService = $this->app->make(LedgerService::class);
    }

    public function test_credit_acquires_pessimistic_row_lock_on_wallet(): void
    {
        $employee = Employee::factory()->create();
        $wallet = Wallet::factory()->create([
            'employee_id' => $employee->id,
            'balance' => 0,
        ]);

        DB::enableQueryLog();

        $this->ledgerService->credit(
            wallet: $wallet,
            amount: 1000,
            idempotencyKey: Str::uuid()->toString()
        );

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $driver = DB::connection()->getDriverName();
        $selectQueryFound = false;

        foreach ($queries as $query) {
            $sql = strtolower($query['query']);
            if (str_contains($sql, 'select') && str_contains($sql, 'wallets') && str_contains($sql, 'id')) {
                $selectQueryFound = true;
                if ($driver === 'mysql' || $driver === 'pgsql') {
                    $this->assertTrue(
                        str_contains($sql, 'for update') || str_contains($sql, 'shared'),
                        'Credit query did not acquire pessimistic row lock (lockForUpdate) under MySQL/PostgreSQL.'
                    );
                }
            }
        }

        $this->assertTrue($selectQueryFound, 'Credit operation did not execute a select query on wallets.');
    }

    public function test_debit_acquires_pessimistic_row_lock_on_wallet(): void
    {
        $employee = Employee::factory()->create();
        $wallet = Wallet::factory()->create([
            'employee_id' => $employee->id,
            'balance' => 2000,
        ]);

        $this->ledgerService->credit($wallet, 2000, Str::uuid()->toString());

        DB::enableQueryLog();

        $this->ledgerService->debit(
            wallet: $wallet,
            amount: 500,
            idempotencyKey: Str::uuid()->toString()
        );

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $driver = DB::connection()->getDriverName();
        $selectQueryFound = false;

        foreach ($queries as $query) {
            $sql = strtolower($query['query']);
            if (str_contains($sql, 'select') && str_contains($sql, 'wallets') && str_contains($sql, 'id')) {
                $selectQueryFound = true;
                if ($driver === 'mysql' || $driver === 'pgsql') {
                    $this->assertTrue(
                        str_contains($sql, 'for update') || str_contains($sql, 'shared'),
                        'Debit query did not acquire pessimistic row lock (lockForUpdate) under MySQL/PostgreSQL.'
                    );
                }
            }
        }

        $this->assertTrue($selectQueryFound, 'Debit operation did not execute a select query on wallets.');
    }

    public function test_transfer_acquires_ordered_locks_to_prevent_deadlocks(): void
    {
        $employee1 = Employee::factory()->create();
        $employee2 = Employee::factory()->create();

        $walletA = Wallet::factory()->create([
            'employee_id' => $employee1->id,
            'balance' => 0,
            'currency' => 'USD',
        ]);
        $walletB = Wallet::factory()->create([
            'employee_id' => $employee2->id,
            'balance' => 0,
            'currency' => 'USD',
        ]);

        $this->ledgerService->credit($walletA, 2000, Str::uuid()->toString());

        DB::enableQueryLog();

        $this->ledgerService->transfer(
            from: $walletA,
            to: $walletB,
            amount: 500,
            idempotencyKey: Str::uuid()->toString()
        );

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $driver = DB::connection()->getDriverName();
        $selectQueryFound = false;

        foreach ($queries as $query) {
            $sql = strtolower($query['query']);
            if (str_contains($sql, 'select') && str_contains($sql, 'wallets') && str_contains($sql, 'in')) {
                $selectQueryFound = true;
                if ($driver === 'mysql' || $driver === 'pgsql') {
                    $this->assertTrue(
                        str_contains($sql, 'for update') || str_contains($sql, 'shared'),
                        'Transfer query did not acquire pessimistic row locks under MySQL/PostgreSQL.'
                    );
                }
                $this->assertTrue(
                    str_contains($sql, 'order by') && str_contains($sql, 'id'),
                    'Transfer locks are not ordered by ID in the query, risking deadlocks.'
                );
            }
        }

        $this->assertTrue($selectQueryFound, 'Transfer operation did not select the wallets for update.');
    }

    /**
     * Static analysis assertion to verify pessimistic locks and lock ordering
     * are syntactically present in LedgerService.php.
     */
    public function test_static_analysis_verifies_concurrency_implementation(): void
    {
        $ledgerServicePath = app_path('Services/LedgerService.php');
        $this->assertFileExists($ledgerServicePath);
        $content = file_get_contents($ledgerServicePath);

        // Assert lockForUpdate is called multiple times (for credit, debit, and transfer)
        $lockForUpdateCount = substr_count($content, 'lockForUpdate()');
        $this->assertGreaterThanOrEqual(3, $lockForUpdateCount, 'LedgerService must use lockForUpdate() in at least 3 places (credit, debit, transfer).');

        // Assert orderBy('id') or orderBy('id', 'asc') is called to enforce lock ordering during transfer
        $this->assertTrue(
            str_contains($content, "orderBy('id')") || str_contains($content, 'orderBy("id")'),
            'LedgerService must sort wallets by ID (orderBy(\'id\')) during transfer locking to prevent deadlocks.'
        );

        // Assert database transaction is used in credit, debit, and transfer
        $dbTransactionCount = substr_count($content, 'DB::transaction');
        $this->assertGreaterThanOrEqual(3, $dbTransactionCount, 'LedgerService must run credit, debit, and transfer inside database transactions.');
    }
}
