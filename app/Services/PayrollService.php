<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Employee;
use App\Models\PayrollEvent;
use App\Models\Wallet;
use App\Services\EmployeeService;
use App\Services\WalletService;
use App\Services\LedgerService;
use Illuminate\Support\Facades\DB;

class PayrollService
{
    public function __construct(
        private readonly EmployeeService $employeeService,
        private readonly WalletService $walletService,
        private readonly LedgerService $ledgerService,
    ) {}

    /**
     * Process a payroll event.
     */
    public function processEvent(PayrollEvent $event): void
    {
        if ($event->status === 'processed') {
            return;
        }

        DB::transaction(function () use ($event) {
            // Lock payroll event for update
            $lockedEvent = PayrollEvent::where('id', $event->id)->lockForUpdate()->firstOrFail();

            if ($lockedEvent->status === 'processed') {
                return;
            }

            $lockedEvent->update([
                'status' => 'processing',
                'attempts' => $lockedEvent->attempts + 1,
            ]);

            try {
                $payload = $lockedEvent->payload;

                switch ($lockedEvent->event_type) {
                    case 'employee_onboarded':
                        $this->handleEmployeeOnboarded($payload);
                        break;
                    case 'employee_status_changed':
                        $this->handleEmployeeStatusChanged($payload);
                        break;
                    case 'salary_run':
                        $this->handleSalaryRun($payload, $lockedEvent->external_event_id);
                        break;
                    default:
                        throw new \InvalidArgumentException("Unknown event type: {$lockedEvent->event_type}");
                }

                $lockedEvent->update([
                    'status' => 'processed',
                    'processed_at' => now(),
                    'error_message' => null,
                ]);
            } catch (\Throwable $e) {
                $lockedEvent->update([
                    'status' => 'failed',
                    'error_message' => $e->getMessage() . "\n" . $e->getTraceAsString(),
                ]);
                throw $e;
            }
        });
    }

    private function handleEmployeeOnboarded(array $payload): void
    {
        $externalId = $payload['employee_id'] ?? null;
        if (!$externalId) {
            throw new \InvalidArgumentException('Missing employee_id in payload');
        }

        // Deduplicate: check if employee with this external_id already exists
        $employee = Employee::where('external_id', $externalId)->first();

        if (!$employee) {
            $employee = $this->employeeService->create([
                'external_id' => $externalId,
                'first_name' => $payload['first_name'] ?? '',
                'last_name' => $payload['last_name'] ?? '',
                'email' => $payload['email'] ?? '',
                'status' => 'active',
                'metadata' => $payload['metadata'] ?? null,
            ]);
        }

        // Create salary wallet if doesn't exist
        $hasSalaryWallet = $employee->wallets()->where('type', 'salary')->where('currency', 'USD')->exists();
        if (!$hasSalaryWallet) {
            $this->walletService->create($employee, [
                'type' => 'salary',
                'currency' => 'USD',
            ]);
        }
    }

    private function handleEmployeeStatusChanged(array $payload): void
    {
        $externalId = $payload['employee_id'] ?? null;
        $status = $payload['status'] ?? null;

        if (!$externalId || !$status) {
            throw new \InvalidArgumentException('Missing employee_id or status in payload');
        }

        $employee = Employee::where('external_id', $externalId)->firstOrFail();

        if ($status === 'terminated') {
            $lockedWallets = Wallet::where('employee_id', $employee->id)
                ->lockForUpdate()
                ->get();

            $totalBalance = (int) $lockedWallets->sum('balance');
            $totalHeld = (int) $lockedWallets->sum('held_balance');

            if ($totalBalance !== 0 || $totalHeld !== 0) {
                throw new \DomainException("Cannot terminate employee {$employee->id} with non-zero wallet balance or active locks (Balance: {$totalBalance}, Held: {$totalHeld}).");
            }

            $employee->update(['status' => $status]);

            foreach ($lockedWallets as $wallet) {
                $wallet->update(['status' => 'closed']);
            }

            return;
        }

        $employee->update(['status' => $status]);
    }

    private function handleSalaryRun(array $payload, string $externalEventId): void
    {
        $externalId = $payload['employee_id'] ?? null;
        $amount = $payload['amount'] ?? null;
        $currency = $payload['currency'] ?? 'USD';

        if (!$externalId || !$amount) {
            throw new \InvalidArgumentException('Missing employee_id or amount in payload');
        }

        $employee = Employee::where('external_id', $externalId)->firstOrFail();

        if (!$employee->isActive()) {
            throw new \DomainException("Cannot credit non-active employee {$employee->id} (status: {$employee->status}).");
        }

        $wallet = $employee->wallets()
            ->where('type', 'salary')
            ->where('currency', $currency)
            ->first();

        if (!$wallet) {
            $wallet = $this->walletService->create($employee, [
                'type' => 'salary',
                'currency' => $currency,
            ]);
        }

        // Credit the wallet using LedgerService with idempotency key tied to external event ID
        $idempotencyKey = "payroll:salary_run:{$externalEventId}";
        $salaryPeriod = $payload['salary_period'] ?? 'Monthly Salary';

        $this->ledgerService->credit(
            wallet: $wallet,
            amount: (int) $amount,
            idempotencyKey: $idempotencyKey,
            type: 'payroll',
            description: "Payroll Run: {$salaryPeriod}",
            referenceType: 'payroll',
            referenceId: null,
            metadata: ['external_event_id' => $externalEventId]
        );
    }
}
