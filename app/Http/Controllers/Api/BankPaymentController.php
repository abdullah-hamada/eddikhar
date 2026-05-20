<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BankPayment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BankPaymentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['sometimes', 'string'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = BankPayment::with('wallet.employee')
            ->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        $page = $query->paginate($request->integer('per_page', 25));

        return response()->json([
            'data' => collect($page->items())->map(fn (BankPayment $payment) => [
                'id' => $payment->id,
                'wallet_id' => $payment->wallet_id,
                'employee_name' => $payment->wallet?->employee
                    ? trim($payment->wallet->employee->first_name . ' ' . $payment->wallet->employee->last_name)
                    : null,
                'amount' => $payment->amount,
                'currency' => $payment->currency,
                'status' => $payment->status,
                'external_reference' => $payment->external_reference,
                'idempotency_key' => $payment->idempotency_key,
                'metadata' => $payment->metadata,
                'initiated_at' => $payment->initiated_at?->toISOString(),
                'confirmed_at' => $payment->confirmed_at?->toISOString(),
                'created_at' => $payment->created_at?->toISOString(),
            ])->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }
}
