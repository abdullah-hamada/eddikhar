<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Employee;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\Factories\Factory;

class WalletFactory extends Factory
{
    protected $model = Wallet::class;

    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'type' => 'salary',
            'currency' => 'USD',
            'balance' => 0,
            'held_balance' => 0,
            'status' => 'active',
        ];
    }

    public function savings(): static
    {
        return $this->state(['type' => 'savings']);
    }

    public function bonus(): static
    {
        return $this->state(['type' => 'bonus']);
    }

    public function withBalance(int $cents): static
    {
        return $this->state(['balance' => $cents]);
    }

    public function frozen(): static
    {
        return $this->state(['status' => 'frozen']);
    }

    public function closed(): static
    {
        return $this->state(['status' => 'closed']);
    }
}
