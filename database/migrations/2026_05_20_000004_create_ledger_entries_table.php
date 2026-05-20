<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('transaction_id');
            $table->uuid('wallet_id');
            $table->enum('type', ['credit', 'debit']);
            $table->bigInteger('amount')->unsigned()->comment('Always positive, in minor units');
            $table->bigInteger('balance_after')->comment('Wallet balance snapshot after this entry');
            $table->string('description')->nullable();
            $table->string('reference_type')->nullable()->comment('payroll, bank, transfer, manual');
            $table->uuid('reference_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at');

            $table->foreign('transaction_id')->references('id')->on('ledger_transactions')->onDelete('restrict');
            $table->foreign('wallet_id')->references('id')->on('wallets')->onDelete('restrict');
            $table->index(['wallet_id', 'created_at']);
            $table->index('transaction_id');
            $table->index('reference_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};
