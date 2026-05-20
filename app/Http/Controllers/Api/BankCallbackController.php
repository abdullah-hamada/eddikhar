<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BankService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BankCallbackController extends Controller
{
    public function __construct(
        private readonly BankService $bankService,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'external_reference' => ['required', 'string', 'max:255'],
            'status' => ['required', 'string', 'in:success,failed'],
            'reason' => ['sometimes', 'string', 'max:255'],
        ]);

        try {
            $this->bankService->confirmPayment(
                externalReference: $validated['external_reference'],
                status: $validated['status'],
                reason: $validated['reason'] ?? null
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json([
            'status' => 'processed',
        ]);
    }
}
