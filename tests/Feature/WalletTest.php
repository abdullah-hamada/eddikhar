<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WalletTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_wallet_for_active_employee(): void
    {
        $employee = Employee::factory()->create();

        $response = $this->postJson("/api/employees/{$employee->id}/wallets", [
            'type' => 'salary',
            'currency' => 'USD',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.type', 'salary')
            ->assertJsonPath('data.currency', 'USD')
            ->assertJsonPath('data.balance', 0)
            ->assertJsonPath('data.held_balance', 0)
            ->assertJsonPath('data.available_balance', 0)
            ->assertJsonPath('data.status', 'active');

        $this->assertDatabaseHas('wallets', [
            'employee_id' => $employee->id,
            'type' => 'salary',
        ]);
    }

    public function test_cannot_create_wallet_for_terminated_employee(): void
    {
        $employee = Employee::factory()->terminated()->create();

        $response = $this->postJson("/api/employees/{$employee->id}/wallets", [
            'type' => 'salary',
            'currency' => 'USD',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('error', 'Cannot create wallet for non-active employee.');
    }

    public function test_cannot_create_wallet_for_inactive_employee(): void
    {
        $employee = Employee::factory()->inactive()->create();

        $response = $this->postJson("/api/employees/{$employee->id}/wallets", [
            'type' => 'salary',
            'currency' => 'USD',
        ]);

        $response->assertStatus(422);
    }

    public function test_cannot_create_duplicate_wallet_type_and_currency(): void
    {
        $employee = Employee::factory()->create();
        Wallet::factory()->create([
            'employee_id' => $employee->id,
            'type' => 'salary',
            'currency' => 'USD',
        ]);

        $response = $this->postJson("/api/employees/{$employee->id}/wallets", [
            'type' => 'salary',
            'currency' => 'USD',
        ]);

        $response->assertStatus(409);
    }

    public function test_can_create_multiple_wallet_types(): void
    {
        $employee = Employee::factory()->create();

        $this->postJson("/api/employees/{$employee->id}/wallets", [
            'type' => 'salary',
            'currency' => 'USD',
        ])->assertStatus(201);

        $this->postJson("/api/employees/{$employee->id}/wallets", [
            'type' => 'savings',
            'currency' => 'USD',
        ])->assertStatus(201);

        $this->assertDatabaseCount('wallets', 2);
    }

    public function test_can_list_employee_wallets(): void
    {
        $employee = Employee::factory()->create();
        Wallet::factory()->create(['employee_id' => $employee->id, 'type' => 'salary']);
        Wallet::factory()->create(['employee_id' => $employee->id, 'type' => 'savings']);

        $response = $this->getJson("/api/employees/{$employee->id}/wallets");

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    public function test_wallet_validates_type(): void
    {
        $employee = Employee::factory()->create();

        $response = $this->postJson("/api/employees/{$employee->id}/wallets", [
            'type' => 'invalid_type',
            'currency' => 'USD',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['type']);
    }

    public function test_wallet_validates_currency_format(): void
    {
        $employee = Employee::factory()->create();

        $response = $this->postJson("/api/employees/{$employee->id}/wallets", [
            'type' => 'salary',
            'currency' => 'usd',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['currency']);
    }

    public function test_wallet_defaults_currency_to_usd(): void
    {
        $employee = Employee::factory()->create();

        $response = $this->postJson("/api/employees/{$employee->id}/wallets", [
            'type' => 'salary',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.currency', 'USD');
    }

    public function test_available_balance_reflects_held_balance(): void
    {
        $employee = Employee::factory()->create();
        $wallet = Wallet::factory()->create([
            'employee_id' => $employee->id,
            'balance' => 10000,
            'held_balance' => 3000,
        ]);

        $response = $this->getJson("/api/employees/{$employee->id}/wallets");

        $response->assertStatus(200);
        $walletData = $response->json('data.0');
        $this->assertEquals(10000, $walletData['balance']);
        $this->assertEquals(3000, $walletData['held_balance']);
        $this->assertEquals(7000, $walletData['available_balance']);
    }

    public function test_create_wallet_for_nonexistent_employee_returns_404(): void
    {
        $response = $this->postJson('/api/employees/00000000-0000-0000-0000-000000000000/wallets', [
            'type' => 'salary',
            'currency' => 'USD',
        ]);

        $response->assertStatus(404);
    }
}
