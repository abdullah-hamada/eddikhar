<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Wallet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WalletListController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['sometimes', 'string'],
            'type' => ['sometimes', 'string'],
            'employee_id' => ['sometimes', 'uuid'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Wallet::with('employee')->orderByDesc('created_at');

        foreach (['status', 'type', 'employee_id'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->string($field)->toString());
            }
        }

        $page = $query->paginate($request->integer('per_page', 25));

        return response()->json([
            'data' => collect($page->items())->map(fn (Wallet $wallet) => $this->serialize($wallet))->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $wallet = Wallet::with('employee')->findOrFail($id);

        return response()->json([
            'data' => $this->serialize($wallet, detailed: true),
        ]);
    }

    private function serialize(Wallet $wallet, bool $detailed = false): array
    {
        $payload = [
            'id' => $wallet->id,
            'employee_id' => $wallet->employee_id,
            'employee_name' => $wallet->employee
                ? trim($wallet->employee->first_name . ' ' . $wallet->employee->last_name)
                : null,
            'type' => $wallet->type,
            'currency' => $wallet->currency,
            'balance' => $wallet->balance,
            'held_balance' => $wallet->held_balance,
            'available_balance' => $wallet->available_balance,
            'status' => $wallet->status,
            'created_at' => $wallet->created_at?->toISOString(),
        ];

        if ($detailed) {
            $payload['employee_email'] = $wallet->employee?->email;
            $payload['updated_at'] = $wallet->updated_at?->toISOString();
        }

        return $payload;
    }
}
