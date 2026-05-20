<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Employee;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;

class WalletService
{
    public function create(Employee $employee, array $data): Wallet
    {
        if (!$employee->isActive()) {
            throw new \DomainException('Cannot create wallet for non-active employee.');
        }

        return DB::transaction(function () use ($employee, $data) {
            return $employee->wallets()->create([
                'type' => $data['type'],
                'currency' => $data['currency'] ?? 'USD',
            ]);
        });
    }

    public function listForEmployee(Employee $employee): \Illuminate\Database\Eloquent\Collection
    {
        return $employee->wallets()->get();
    }

    public function find(string $id): Wallet
    {
        return Wallet::findOrFail($id);
    }
}
