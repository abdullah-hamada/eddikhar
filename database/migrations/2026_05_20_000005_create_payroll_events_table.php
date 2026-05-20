<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('external_event_id')->unique()->comment('Idempotency key from payroll provider');
            $table->enum('event_type', ['employee_onboarded', 'employee_status_changed', 'salary_run'])->index();
            $table->json('payload');
            $table->enum('status', ['received', 'processing', 'processed', 'failed'])->default('received')->index();
            $table->timestamp('processed_at')->nullable();
            $table->integer('attempts')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_events');
    }
};
