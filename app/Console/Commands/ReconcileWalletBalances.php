<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Wallet;
use App\Services\LedgerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ReconcileWalletBalances extends Command
{
    protected $signature = 'ledger:reconcile-balances
        {--wallet-id= : Reconcile a specific wallet by ID}
        {--fix : Auto-fix detected mismatches (corrects cached balance to match ledger)}
        {--dry-run : Report mismatches without fixing (default behavior without --fix)}';

    protected $description = 'Reconcile wallet cached balances against ledger entries. Detects and optionally fixes drift between the cached balance and the true balance derived from the append-only ledger.';

    public function handle(LedgerService $ledgerService): int
    {
        $autoFix = $this->option('fix') && !$this->option('dry-run');
        $walletId = $this->option('wallet-id');

        if ($autoFix) {
            $this->warn('⚠️  AUTO-FIX mode enabled. Mismatched balances will be corrected.');
        } else {
            $this->info('Running in report-only mode. Use --fix to auto-correct mismatches.');
        }

        // Build wallet query
        $query = Wallet::query();
        if ($walletId) {
            $query->where('id', $walletId);
        }

        $wallets = $query->orderBy('id')->get();

        if ($wallets->isEmpty()) {
            $this->info('No wallets found to reconcile.');
            return Command::SUCCESS;
        }

        $this->info(sprintf('Reconciling %d wallet(s)...', $wallets->count()));
        $this->newLine();

        $mismatches = [];
        $totalWallets = 0;
        $totalMismatches = 0;

        foreach ($wallets as $wallet) {
            $totalWallets++;

            try {
                $result = $ledgerService->reconcileWallet($wallet, $autoFix);

                if ($result['mismatch']) {
                    $totalMismatches++;
                    $mismatches[] = $result;

                    $fixStatus = $result['fixed'] ? '✅ FIXED' : '❌ UNFIXED';
                    $this->error(sprintf(
                        '[MISMATCH] Wallet %s: cached=%d, derived=%d, drift=%+d %s',
                        $result['wallet_id'],
                        $result['cached_balance'],
                        $result['derived_balance'],
                        $result['drift'],
                        $fixStatus
                    ));
                } else {
                    $this->line(sprintf(
                        '  [OK] Wallet %s: balance=%d ✓',
                        $result['wallet_id'],
                        $result['cached_balance']
                    ));
                }
            } catch (\Throwable $e) {
                $this->error(sprintf(
                    '[ERROR] Wallet %s: %s',
                    $wallet->id,
                    $e->getMessage()
                ));

                Log::channel('ledger')->error('Reconciliation error for wallet', [
                    'wallet_id' => $wallet->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Summary report
        $this->newLine();
        $this->info('═══════════════════════════════════════');
        $this->info('         RECONCILIATION REPORT         ');
        $this->info('═══════════════════════════════════════');
        $this->info(sprintf('Total wallets checked:  %d', $totalWallets));
        $this->info(sprintf('Mismatches found:       %d', $totalMismatches));

        if ($totalMismatches > 0) {
            $this->newLine();
            $this->table(
                ['Wallet ID', 'Cached', 'Derived', 'Drift', 'Status'],
                collect($mismatches)->map(fn ($m) => [
                    $m['wallet_id'],
                    $m['cached_balance'],
                    $m['derived_balance'],
                    sprintf('%+d', $m['drift']),
                    $m['fixed'] ? 'FIXED' : 'NEEDS FIX',
                ])->toArray()
            );

            if (!$autoFix) {
                $this->warn('Run with --fix to auto-correct mismatched balances.');
            }

            // Log summary for monitoring
            Log::channel('ledger')->warning('Reconciliation completed with mismatches', [
                'total_wallets' => $totalWallets,
                'mismatches' => $totalMismatches,
                'auto_fixed' => $autoFix,
                'details' => $mismatches,
            ]);

            return $autoFix ? Command::SUCCESS : Command::FAILURE;
        }

        $this->info('All wallets are in sync. ✓');

        Log::channel('ledger')->info('Reconciliation completed successfully', [
            'total_wallets' => $totalWallets,
            'mismatches' => 0,
        ]);

        return Command::SUCCESS;
    }
}
