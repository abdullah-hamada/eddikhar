<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateEmployeeRequest;
use App\Http\Resources\EmployeeResource;
use App\Services\EmployeeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class EmployeeController extends Controller
{
    public function __construct(
        private readonly EmployeeService $employeeService,
    ) {}

    public function store(CreateEmployeeRequest $request): JsonResponse
    {
        $employee = $this->employeeService->create($request->validated());

        return (new EmployeeResource($employee))
            ->response()
            ->setStatusCode(201);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $employees = $this->employeeService->list(
            filters: $request->only(['status', 'search']),
            perPage: (int) $request->get('per_page', 15),
        );

        return EmployeeResource::collection($employees);
    }

    public function show(string $id): EmployeeResource
    {
        $employee = $this->employeeService->find($id);
        $employee->load('wallets');

        return new EmployeeResource($employee);
    }
}
