<?php

declare(strict_types=1);

use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\WalletController;
use App\Http\Controllers\Api\MoneyMovementController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

Route::prefix('employees')->group(function () {
    Route::post('/', [EmployeeController::class, 'store']);
    Route::get('/', [EmployeeController::class, 'index']);
    Route::get('/{id}', [EmployeeController::class, 'show']);

    Route::post('/{employeeId}/wallets', [WalletController::class, 'store']);
    Route::get('/{employeeId}/wallets', [WalletController::class, 'index']);
});

Route::prefix('wallets')->group(function () {
    Route::post('/transfer', [MoneyMovementController::class, 'transfer']);
    Route::post('/{id}/credit', [MoneyMovementController::class, 'credit']);
    Route::post('/{id}/debit', [MoneyMovementController::class, 'debit']);
    Route::post('/{id}/withdraw', [\App\Http\Controllers\Api\WithdrawalController::class, 'store']);
    Route::get('/{id}/transactions', \App\Http\Controllers\Api\TransactionHistoryController::class);
});

Route::post('/payroll/webhook', \App\Http\Controllers\Api\PayrollWebhookController::class);
Route::post('/bank/callback', \App\Http\Controllers\Api\BankCallbackController::class);

Route::get('/health', \App\Http\Controllers\Api\HealthController::class);

