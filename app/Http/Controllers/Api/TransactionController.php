<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LedgerEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransactionController extends Controller
{
    /**
     * Global ledger feed across all wallets.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'type' => ['sometimes', 'string', 'in:credit,debit'],
            'reference_type' => ['sometimes', 'string', 'max:50'],
            'wallet_id' => ['sometimes', 'uuid'],
            'employee_id' => ['sometimes', 'uuid'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = LedgerEntry::with('wallet.employee')
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($request->filled('type')) {
            $query->where('type', $request->string('type')->toString());
        }

        if ($request->filled('reference_type')) {
            $query->where('reference_type', $request->string('reference_type')->toString());
        }

        if ($request->filled('wallet_id')) {
            $query->where('wallet_id', $request->string('wallet_id')->toString());
        }

        if ($request->filled('employee_id')) {
            $employeeId = $request->string('employee_id')->toString();
            $query->whereHas('wallet', fn ($q) => $q->where('employee_id', $employeeId));
        }

        $perPage = $request->integer('per_page', 25);
        $page = $query->paginate($perPage);

        return response()->json([
            'data' => collect($page->items())->map(fn (LedgerEntry $entry) => [
                'id' => $entry->id,
                'transaction_id' => $entry->transaction_id,
                'wallet_id' => $entry->wallet_id,
                'wallet_type' => $entry->wallet?->type,
                'employee_id' => $entry->wallet?->employee_id,
                'employee_name' => $entry->wallet?->employee
                    ? trim($entry->wallet->employee->first_name . ' ' . $entry->wallet->employee->last_name)
                    : 'System',
                'type' => $entry->type,
                'amount' => $entry->amount,
                'balance_after' => $entry->balance_after,
                'description' => $entry->description,
                'reference_type' => $entry->reference_type,
                'reference_id' => $entry->reference_id,
                'created_at' => $entry->created_at?->toISOString(),
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
