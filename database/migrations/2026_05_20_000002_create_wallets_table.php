<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('employee_id');
            $table->enum('type', ['salary', 'savings', 'bonus'])->default('salary');
            $table->char('currency', 3)->default('USD')->comment('ISO 4217');
            $table->bigInteger('balance')->default(0)->comment('Cached balance in minor units (cents)');
            $table->bigInteger('held_balance')->default(0)->comment('Funds locked for pending operations');
            $table->enum('status', ['active', 'frozen', 'closed'])->default('active');
            $table->timestamps();

            $table->foreign('employee_id')->references('id')->on('employees')->onDelete('restrict');
            $table->unique(['employee_id', 'type', 'currency'], 'wallets_employee_type_currency_unique');
            $table->index('employee_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallets');
    }
};
