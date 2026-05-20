<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BankPayment;
use App\Models\Employee;
use App\Models\LedgerEntry;
use App\Models\PayrollEvent;
use App\Models\Wallet;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /**
     * Aggregate snapshot used to render the dashboard landing page.
     * All money values are returned in cents.
     */
    public function summary(): JsonResponse
    {
        $totalCredits = (int) LedgerEntry::where('type', 'credit')->sum('amount');
        $totalDebits = (int) LedgerEntry::where('type', 'debit')->sum('amount');
        $ledgerAssets = $totalCredits - $totalDebits;
        $cachedAssets = (int) Wallet::sum('balance');
        $heldBalance = (int) Wallet::sum('held_balance');

        $integrityHealthy = $ledgerAssets === $cachedAssets;

        $bankPaymentsByStatus = BankPayment::query()
            ->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        $payrollEventsByStatus = PayrollEvent::query()
            ->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        return response()->json([
            'totals' => [
                'employees' => Employee::count(),
                'active_employees' => Employee::where('status', 'active')->count(),
                'wallets' => Wallet::count(),
                'transactions' => LedgerEntry::count(),
            ],
            'ledger' => [
                'available_balance' => $ledgerAssets - $heldBalance,
                'cached_balance' => $cachedAssets,
                'held_balance' => $heldBalance,
                'total_credits' => $totalCredits,
                'total_debits' => $totalDebits,
                'integrity_ok' => $integrityHealthy,
            ],
            'bank_payments' => [
                'total' => array_sum($bankPaymentsByStatus),
                'pending' => (int) ($bankPaymentsByStatus['pending'] ?? 0)
                    + (int) ($bankPaymentsByStatus['initiated'] ?? 0),
                'succeeded' => (int) ($bankPaymentsByStatus['success'] ?? 0)
                    + (int) ($bankPaymentsByStatus['confirmed'] ?? 0),
                'failed' => (int) ($bankPaymentsByStatus['failed'] ?? 0),
                'reversed' => (int) ($bankPaymentsByStatus['reversed'] ?? 0),
            ],
            'payroll_events' => [
                'total' => array_sum($payrollEventsByStatus),
                'received' => (int) ($payrollEventsByStatus['received'] ?? 0),
                'processing' => (int) ($payrollEventsByStatus['processing'] ?? 0),
                'processed' => (int) ($payrollEventsByStatus['processed'] ?? 0),
                'failed' => (int) ($payrollEventsByStatus['failed'] ?? 0),
            ],
        ]);
    }

    /**
     * Recent ledger activity used in the dashboard activity feed.
     */
    public function recentActivity(): JsonResponse
    {
        $entries = LedgerEntry::with('wallet.employee')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        return response()->json([
            'data' => $entries->map(fn (LedgerEntry $entry) => [
                'id' => $entry->id,
                'type' => $entry->type,
                'amount' => $entry->amount,
                'description' => $entry->description,
                'reference_type' => $entry->reference_type,
                'created_at' => $entry->created_at?->toISOString(),
                'employee_name' => $entry->wallet?->employee
                    ? trim($entry->wallet->employee->first_name . ' ' . $entry->wallet->employee->last_name)
                    : 'System',
                'wallet_type' => $entry->wallet?->type,
            ])->all(),
        ]);
    }
}
