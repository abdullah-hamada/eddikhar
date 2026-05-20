<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LedgerEntry;
use App\Models\Wallet;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $dbStatus = 'OK';
        $integrityStatus = 'OK';
        $details = [];

        try {
            // Test DB connection
            DB::connection()->getPdo();

            // Run Global Ledger Integrity Check
            $totalCredits = (int) LedgerEntry::where('type', 'credit')->sum('amount');
            $totalDebits = (int) LedgerEntry::where('type', 'debit')->sum('amount');
            $expectedTotalBalance = $totalCredits - $totalDebits;

            $actualTotalBalance = (int) Wallet::sum('balance');

            if ($expectedTotalBalance !== $actualTotalBalance) {
                $integrityStatus = 'FAIL';
                $details['integrity_error'] = "Global balance mismatch. Expected (ledger): {$expectedTotalBalance}, Actual (cached): {$actualTotalBalance}";
            }

            // Run Per-Wallet Ledger Integrity Check to ensure NO individual wallet is corrupted
            $mismatches = DB::table('wallets')
                ->leftJoin('ledger_entries', 'wallets.id', '=', 'ledger_entries.wallet_id')
                ->select(
                    'wallets.id',
                    'wallets.balance',
                    DB::raw("COALESCE(SUM(CASE WHEN ledger_entries.type = 'credit' THEN ledger_entries.amount ELSE 0 END), 0) as calculated_credits"),
                    DB::raw("COALESCE(SUM(CASE WHEN ledger_entries.type = 'debit' THEN ledger_entries.amount ELSE 0 END), 0) as calculated_debits")
                )
                ->groupBy('wallets.id', 'wallets.balance')
                ->get()
                ->filter(function ($w) {
                    return (int) $w->balance !== ((int) $w->calculated_credits - (int) $w->calculated_debits);
                });

            if ($mismatches->isNotEmpty()) {
                $integrityStatus = 'FAIL';
                $details['mismatches'] = $mismatches->map(function ($w) {
                    return [
                        'wallet_id' => $w->id,
                        'cached_balance' => (int) $w->balance,
                        'ledger_credits' => (int) $w->calculated_credits,
                        'ledger_debits' => (int) $w->calculated_debits,
                        'ledger_derived' => (int) $w->calculated_credits - (int) $w->calculated_debits,
                    ];
                })->values()->all();
            }

            $details['ledger'] = [
                'total_credits' => $totalCredits,
                'total_debits' => $totalDebits,
                'expected_balance' => $expectedTotalBalance,
                'actual_balance' => $actualTotalBalance,
                'total_wallets_checked' => Wallet::count(),
                'mismatched_wallets_count' => $mismatches->count(),
            ];

        } catch (\Throwable $e) {
            $dbStatus = 'FAIL';
            $details['db_error'] = $e->getMessage();
        }

        $statusCode = ($dbStatus === 'OK' && $integrityStatus === 'OK') ? 200 : 503;

        return response()->json([
            'status' => $statusCode === 200 ? 'healthy' : 'unhealthy',
            'timestamp' => now()->toIso8601String(),
            'checks' => [
                'database' => $dbStatus,
                'ledger_integrity' => $integrityStatus,
            ],
            'details' => $details,
        ], $statusCode);
    }
}
