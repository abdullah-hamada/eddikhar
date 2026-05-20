<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreditWalletRequest;
use App\Http\Requests\DebitWalletRequest;
use App\Http\Requests\TransferRequest;
use App\Http\Resources\LedgerTransactionResource;
use App\Exceptions\InsufficientFundsException;
use App\Services\WalletService;
use App\Services\LedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class MoneyMovementController extends Controller
{
    public function __construct(
        private readonly WalletService $walletService,
        private readonly LedgerService $ledgerService,
    ) {}

    public function credit(CreditWalletRequest $request, string $id): JsonResponse
    {
        $wallet = $this->walletService->find($id);

        try {
            $transaction = $this->ledgerService->credit(
                wallet: $wallet,
                amount: $request->integer('amount'),
                idempotencyKey: $request->string('idempotency_key')->toString(),
                type: $request->string('type', 'deposit')->toString(),
                description: $request->string('description', '')->toString(),
                referenceType: $request->filled('reference_type') ? $request->string('reference_type')->toString() : null,
                referenceId: $request->filled('reference_id') ? $request->string('reference_id')->toString() : null,
                metadata: $request->input('metadata'),
            );
        } catch (\DomainException|\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return (new LedgerTransactionResource($transaction))
            ->response()
            ->setStatusCode(201);
    }

    public function debit(DebitWalletRequest $request, string $id): JsonResponse
    {
        $wallet = $this->walletService->find($id);

        try {
            $transaction = $this->ledgerService->debit(
                wallet: $wallet,
                amount: $request->integer('amount'),
                idempotencyKey: $request->string('idempotency_key')->toString(),
                type: $request->string('type', 'withdrawal')->toString(),
                description: $request->string('description', '')->toString(),
                referenceType: $request->filled('reference_type') ? $request->string('reference_type')->toString() : null,
                referenceId: $request->filled('reference_id') ? $request->string('reference_id')->toString() : null,
                metadata: $request->input('metadata'),
            );
        } catch (InsufficientFundsException $e) {
            return response()->json(['error' => $e->getMessage(), 'code' => 'INSUFFICIENT_FUNDS'], 422);
        } catch (\DomainException|\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return (new LedgerTransactionResource($transaction))
            ->response()
            ->setStatusCode(201);
    }

    public function transfer(TransferRequest $request): JsonResponse
    {
        $fromWallet = $this->walletService->find($request->string('from_wallet_id')->toString());
        $toWallet = $this->walletService->find($request->string('to_wallet_id')->toString());

        try {
            $transaction = $this->ledgerService->transfer(
                from: $fromWallet,
                to: $toWallet,
                amount: $request->integer('amount'),
                idempotencyKey: $request->string('idempotency_key')->toString(),
                description: $request->string('description', '')->toString(),
                metadata: $request->input('metadata'),
            );
        } catch (InsufficientFundsException $e) {
            return response()->json(['error' => $e->getMessage(), 'code' => 'INSUFFICIENT_FUNDS'], 422);
        } catch (\DomainException|\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return (new LedgerTransactionResource($transaction))
            ->response()
            ->setStatusCode(201);
    }
}
