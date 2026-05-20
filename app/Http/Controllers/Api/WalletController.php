<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateWalletRequest;
use App\Http\Resources\WalletResource;
use App\Services\EmployeeService;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Database\QueryException;

class WalletController extends Controller
{
    public function __construct(
        private readonly WalletService $walletService,
        private readonly EmployeeService $employeeService,
    ) {}

    public function store(CreateWalletRequest $request, string $employeeId): JsonResponse
    {
        $employee = $this->employeeService->find($employeeId);

        try {
            $wallet = $this->walletService->create($employee, $request->validated());
        } catch (\DomainException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (QueryException $e) {
            if ($e->errorInfo[1] === 1062 || str_contains($e->getMessage(), 'UNIQUE constraint failed')) {
                return response()->json([
                    'error' => 'Wallet with this type and currency already exists for this employee.',
                ], 409);
            }
            throw $e;
        }

        return (new WalletResource($wallet))
            ->response()
            ->setStatusCode(201);
    }

    public function index(string $employeeId): AnonymousResourceCollection
    {
        $employee = $this->employeeService->find($employeeId);
        $wallets = $this->walletService->listForEmployee($employee);

        return WalletResource::collection($wallets);
    }
}
