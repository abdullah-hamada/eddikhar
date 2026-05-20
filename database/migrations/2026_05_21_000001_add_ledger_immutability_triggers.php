<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            $this->installSqliteTriggers();
        } elseif ($driver === 'mysql') {
            $this->installMysqlTriggers();
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS ledger_entries_no_update');
            DB::unprepared('DROP TRIGGER IF EXISTS ledger_entries_no_delete');
            DB::unprepared('DROP TRIGGER IF EXISTS ledger_transactions_no_delete');
            DB::unprepared('DROP TRIGGER IF EXISTS ledger_transactions_immutable_fields');
        } elseif ($driver === 'mysql') {
            DB::unprepared('DROP TRIGGER IF EXISTS ledger_entries_no_update');
            DB::unprepared('DROP TRIGGER IF EXISTS ledger_entries_no_delete');
            DB::unprepared('DROP TRIGGER IF EXISTS ledger_transactions_no_delete');
            DB::unprepared('DROP TRIGGER IF EXISTS ledger_transactions_immutable_fields');
        }
    }

    private function installSqliteTriggers(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER ledger_entries_no_update
BEFORE UPDATE ON ledger_entries
BEGIN
    SELECT RAISE(ABORT, 'ledger_entries are immutable');
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER ledger_entries_no_delete
BEFORE DELETE ON ledger_entries
BEGIN
    SELECT RAISE(ABORT, 'ledger_entries are immutable');
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER ledger_transactions_no_delete
BEFORE DELETE ON ledger_transactions
BEGIN
    SELECT RAISE(ABORT, 'ledger_transactions are immutable');
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER ledger_transactions_immutable_fields
BEFORE UPDATE ON ledger_transactions
WHEN NEW.type != OLD.type
   OR NEW.idempotency_key != OLD.idempotency_key
   OR (NEW.metadata IS NOT OLD.metadata AND (NEW.metadata IS NULL OR OLD.metadata IS NULL OR NEW.metadata != OLD.metadata))
BEGIN
    SELECT RAISE(ABORT, 'Cannot update immutable fields on ledger_transactions');
END
SQL);
    }

    private function installMysqlTriggers(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER ledger_entries_no_update
BEFORE UPDATE ON ledger_entries
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ledger_entries are immutable';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER ledger_entries_no_delete
BEFORE DELETE ON ledger_entries
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ledger_entries are immutable';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER ledger_transactions_no_delete
BEFORE DELETE ON ledger_transactions
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ledger_transactions are immutable';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER ledger_transactions_immutable_fields
BEFORE UPDATE ON ledger_transactions
FOR EACH ROW
BEGIN
    IF NEW.type <> OLD.type
       OR NEW.idempotency_key <> OLD.idempotency_key
       OR (NEW.metadata <> OLD.metadata OR (NEW.metadata IS NULL XOR OLD.metadata IS NULL)) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Cannot update immutable fields on ledger_transactions';
    END IF;
END
SQL);
    }
};
