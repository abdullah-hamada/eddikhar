<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_employee(): void
    {
        $response = $this->postJson('/api/employees', [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john@example.com',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.first_name', 'John')
            ->assertJsonPath('data.last_name', 'Doe')
            ->assertJsonPath('data.email', 'john@example.com')
            ->assertJsonPath('data.status', 'active');

        $this->assertDatabaseHas('employees', ['email' => 'john@example.com']);
    }

    public function test_create_employee_validates_required_fields(): void
    {
        $response = $this->postJson('/api/employees', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['first_name', 'last_name', 'email']);
    }

    public function test_create_employee_rejects_duplicate_email(): void
    {
        Employee::factory()->create(['email' => 'john@example.com']);

        $response = $this->postJson('/api/employees', [
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'john@example.com',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_can_create_employee_with_external_id(): void
    {
        $response = $this->postJson('/api/employees', [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john@example.com',
            'external_id' => 'PAY-001',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.external_id', 'PAY-001');
    }

    public function test_can_list_employees_with_pagination(): void
    {
        Employee::factory()->count(20)->create();

        $response = $this->getJson('/api/employees?per_page=10');

        $response->assertStatus(200)
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.total', 20);
    }

    public function test_can_filter_employees_by_status(): void
    {
        Employee::factory()->count(3)->create(['status' => 'active']);
        Employee::factory()->count(2)->create(['status' => 'terminated']);

        $response = $this->getJson('/api/employees?status=active');

        $response->assertStatus(200)
            ->assertJsonCount(3, 'data');
    }

    public function test_can_search_employees(): void
    {
        Employee::factory()->create(['first_name' => 'Alice', 'last_name' => 'Smith']);
        Employee::factory()->create(['first_name' => 'Bob', 'last_name' => 'Jones']);

        $response = $this->getJson('/api/employees?search=Alice');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.first_name', 'Alice');
    }

    public function test_can_show_employee_with_wallets(): void
    {
        $employee = Employee::factory()->create();

        $response = $this->getJson("/api/employees/{$employee->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $employee->id)
            ->assertJsonStructure([
                'data' => ['id', 'first_name', 'last_name', 'email', 'status', 'wallets', 'created_at'],
            ]);
    }

    public function test_show_nonexistent_employee_returns_404(): void
    {
        $response = $this->getJson('/api/employees/00000000-0000-0000-0000-000000000000');

        $response->assertStatus(404);
    }
}
