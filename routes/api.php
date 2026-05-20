<?php

declare(strict_types=1);

use App\Http\Controllers\Api\BankCallbackController;
use App\Http\Controllers\Api\BankPaymentController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\MoneyMovementController;
use App\Http\Controllers\Api\PayrollEventController;
use App\Http\Controllers\Api\PayrollWebhookController;
use App\Http\Controllers\Api\TransactionController;
use App\Http\Controllers\Api\TransactionHistoryController;
use App\Http\Controllers\Api\WalletController;
use App\Http\Controllers\Api\WalletListController;
use App\Http\Controllers\Api\WithdrawalController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

Route::prefix('dashboard')->group(function () {
    Route::get('/summary', [DashboardController::class, 'summary']);
    Route::get('/recent-activity', [DashboardController::class, 'recentActivity']);
});

Route::prefix('employees')->group(function () {
    Route::post('/', [EmployeeController::class, 'store']);
    Route::get('/', [EmployeeController::class, 'index']);
    Route::get('/{id}', [EmployeeController::class, 'show']);

    Route::post('/{employeeId}/wallets', [WalletController::class, 'store']);
    Route::get('/{employeeId}/wallets', [WalletController::class, 'index']);
});

Route::prefix('wallets')->group(function () {
    Route::get('/', [WalletListController::class, 'index']);
    Route::get('/{id}', [WalletListController::class, 'show']);
    Route::post('/transfer', [MoneyMovementController::class, 'transfer']);
    Route::post('/{id}/credit', [MoneyMovementController::class, 'credit']);
    Route::post('/{id}/debit', [MoneyMovementController::class, 'debit']);
    Route::post('/{id}/withdraw', [WithdrawalController::class, 'store']);
    Route::get('/{id}/transactions', TransactionHistoryController::class);
});

Route::get('/transactions', [TransactionController::class, 'index']);
Route::get('/bank-payments', [BankPaymentController::class, 'index']);
Route::get('/payroll-events', [PayrollEventController::class, 'index']);

Route::post('/payroll/webhook', PayrollWebhookController::class);
Route::post('/bank/callback', BankCallbackController::class);

Route::get('/health', HealthController::class);
