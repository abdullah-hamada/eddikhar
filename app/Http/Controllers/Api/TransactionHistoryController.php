<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\LedgerEntryResource;
use App\Services\WalletService;
use App\Models\LedgerEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TransactionHistoryController extends Controller
{
    public function __construct(
        private readonly WalletService $walletService,
    ) {}

    public function __invoke(Request $request, string $walletId): AnonymousResourceCollection
    {
        $wallet = $this->walletService->find($walletId);

        $request->validate([
            'type' => ['sometimes', 'string', 'in:credit,debit'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
            'reference_type' => ['sometimes', 'string', 'max:50'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = LedgerEntry::where('wallet_id', $wallet->id);

        if ($request->filled('type')) {
            $query->where('type', $request->string('type')->toString());
        }

        if ($request->filled('from')) {
            $query->where('created_at', '>=', $request->string('from')->toString());
        }

        if ($request->filled('to')) {
            $query->where('created_at', '<=', $request->string('to')->toString());
        }

        if ($request->filled('reference_type')) {
            $query->where('reference_type', $request->string('reference_type')->toString());
        }

        $perPage = $request->integer('per_page', 20);

        $entries = $query->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->paginate($perPage);

        return LedgerEntryResource::collection($entries);
    }
}
