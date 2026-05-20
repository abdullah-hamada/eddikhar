<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BankPaymentResource;
use App\Exceptions\InsufficientFundsException;
use App\Services\WalletService;
use App\Services\BankService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WithdrawalController extends Controller
{
    public function __construct(
        private readonly WalletService $walletService,
        private readonly BankService $bankService,
    ) {}

    public function store(Request $request, string $walletId): JsonResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'integer', 'min:1'],
            'idempotency_key' => ['required', 'string', 'max:255'],
            'metadata' => ['sometimes', 'array'],
        ]);

        $wallet = $this->walletService->find($walletId);

        try {
            $payment = $this->bankService->initiateWithdrawal(
                wallet: $wallet,
                amount: (int) $validated['amount'],
                idempotencyKey: $validated['idempotency_key'],
                metadata: $validated['metadata'] ?? null
            );
        } catch (InsufficientFundsException $e) {
            return response()->json(['error' => $e->getMessage(), 'code' => 'INSUFFICIENT_FUNDS'], 422);
        } catch (\DomainException|\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return (new BankPaymentResource($payment))
            ->response()
            ->setStatusCode(201);
    }
}
