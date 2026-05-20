<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('wallet_id');
            $table->bigInteger('amount')->comment('In minor units (cents)');
            $table->char('currency', 3);
            $table->enum('status', ['initiated', 'pending', 'success', 'failed'])->default('initiated')->index();
            $table->string('external_reference')->nullable()->unique()->comment("Bank's transaction ID");
            $table->string('idempotency_key')->unique();
            $table->json('metadata')->nullable();
            $table->timestamp('initiated_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->foreign('wallet_id')->references('id')->on('wallets')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_payments');
    }
};
