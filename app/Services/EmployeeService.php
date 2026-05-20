<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Employee;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class EmployeeService
{
    public function create(array $data): Employee
    {
        return Employee::create($data);
    }

    public function list(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Employee::query();

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        return $query->latest()->paginate($perPage);
    }

    public function find(string $id): Employee
    {
        return Employee::findOrFail($id);
    }
}
