<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Add CHECK constraints for financial integrity at the database level.
 *
 * These constraints serve as a final safety net against application bugs,
 * ensuring that even if application-level validation fails, the database
 * will reject financially invalid states.
 *
 * Constraints:
 * - wallets.balance >= 0                       — no negative balances
 * - wallets.held_balance >= 0                  — no negative holds
 * - wallets.held_balance <= wallets.balance    — can't hold more than you have
 * - ledger_entries.amount > 0                  — all entries are positive amounts
 * - bank_payments.amount > 0                   — all payments are positive amounts
 *
 * Note: CHECK constraints are supported in MySQL 8.0.16+, PostgreSQL (all versions),
 * and SQLite 3.25+ (though SQLite CHECK enforcement requires the table to be
 * created with them; ALTER TABLE ADD CONSTRAINT is limited in SQLite).
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            $this->installMysqlConstraints();
        } elseif ($driver === 'pgsql') {
            $this->installPostgresConstraints();
        } elseif ($driver === 'sqlite') {
            $this->installSqliteConstraints();
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE wallets DROP CHECK IF EXISTS chk_balance_non_negative');
            DB::statement('ALTER TABLE wallets DROP CHECK IF EXISTS chk_held_balance_non_negative');
            DB::statement('ALTER TABLE wallets DROP CHECK IF EXISTS chk_held_not_exceeds_balance');
            DB::statement('ALTER TABLE ledger_entries DROP CHECK IF EXISTS chk_entry_amount_positive');
            DB::statement('ALTER TABLE bank_payments DROP CHECK IF EXISTS chk_payment_amount_positive');
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE wallets DROP CONSTRAINT IF EXISTS chk_balance_non_negative');
            DB::statement('ALTER TABLE wallets DROP CONSTRAINT IF EXISTS chk_held_balance_non_negative');
            DB::statement('ALTER TABLE wallets DROP CONSTRAINT IF EXISTS chk_held_not_exceeds_balance');
            DB::statement('ALTER TABLE ledger_entries DROP CONSTRAINT IF EXISTS chk_entry_amount_positive');
            DB::statement('ALTER TABLE bank_payments DROP CONSTRAINT IF EXISTS chk_payment_amount_positive');
        }
        // SQLite doesn't support DROP CHECK — triggers are dropped instead
        if ($driver === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS chk_wallets_balance_insert');
            DB::unprepared('DROP TRIGGER IF EXISTS chk_wallets_balance_update');
            DB::unprepared('DROP TRIGGER IF EXISTS chk_ledger_entries_amount_insert');
            DB::unprepared('DROP TRIGGER IF EXISTS chk_bank_payments_amount_insert');
        }
    }

    private function installMysqlConstraints(): void
    {
        DB::statement('ALTER TABLE wallets ADD CONSTRAINT chk_balance_non_negative CHECK (balance >= 0)');
        DB::statement('ALTER TABLE wallets ADD CONSTRAINT chk_held_balance_non_negative CHECK (held_balance >= 0)');
        DB::statement('ALTER TABLE wallets ADD CONSTRAINT chk_held_not_exceeds_balance CHECK (held_balance <= balance)');
        DB::statement('ALTER TABLE ledger_entries ADD CONSTRAINT chk_entry_amount_positive CHECK (amount > 0)');
        DB::statement('ALTER TABLE bank_payments ADD CONSTRAINT chk_payment_amount_positive CHECK (amount > 0)');
    }

    private function installPostgresConstraints(): void
    {
        DB::statement('ALTER TABLE wallets ADD CONSTRAINT chk_balance_non_negative CHECK (balance >= 0)');
        DB::statement('ALTER TABLE wallets ADD CONSTRAINT chk_held_balance_non_negative CHECK (held_balance >= 0)');
        DB::statement('ALTER TABLE wallets ADD CONSTRAINT chk_held_not_exceeds_balance CHECK (held_balance <= balance)');
        DB::statement('ALTER TABLE ledger_entries ADD CONSTRAINT chk_entry_amount_positive CHECK (amount > 0)');
        DB::statement('ALTER TABLE bank_payments ADD CONSTRAINT chk_payment_amount_positive CHECK (amount > 0)');
    }

    /**
     * SQLite doesn't support ALTER TABLE ADD CONSTRAINT for CHECK.
     * We emulate CHECK constraints using BEFORE INSERT/UPDATE triggers.
     */
    private function installSqliteConstraints(): void
    {
        // Wallet balance constraints — on insert
        DB::unprepared(<<<'SQL'
CREATE TRIGGER chk_wallets_balance_insert
BEFORE INSERT ON wallets
WHEN NEW.balance < 0 OR NEW.held_balance < 0 OR NEW.held_balance > NEW.balance
BEGIN
    SELECT RAISE(ABORT, 'Financial constraint violation: balance >= 0, held_balance >= 0, held_balance <= balance');
END
SQL);

        // Wallet balance constraints — on update
        DB::unprepared(<<<'SQL'
CREATE TRIGGER chk_wallets_balance_update
BEFORE UPDATE ON wallets
WHEN NEW.balance < 0 OR NEW.held_balance < 0 OR NEW.held_balance > NEW.balance
BEGIN
    SELECT RAISE(ABORT, 'Financial constraint violation: balance >= 0, held_balance >= 0, held_balance <= balance');
END
SQL);

        // Ledger entry amount must be positive — on insert
        DB::unprepared(<<<'SQL'
CREATE TRIGGER chk_ledger_entries_amount_insert
BEFORE INSERT ON ledger_entries
WHEN NEW.amount <= 0
BEGIN
    SELECT RAISE(ABORT, 'Financial constraint violation: ledger entry amount must be positive');
END
SQL);

        // Bank payment amount must be positive — on insert
        DB::unprepared(<<<'SQL'
CREATE TRIGGER chk_bank_payments_amount_insert
BEFORE INSERT ON bank_payments
WHEN NEW.amount <= 0
BEGIN
    SELECT RAISE(ABORT, 'Financial constraint violation: bank payment amount must be positive');
END
SQL);
    }
};
