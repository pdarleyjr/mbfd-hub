<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicEmployeeDirectoryRedactionTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_operator_directory_does_not_expose_login_identifiers(): void
    {
        Employee::create([
            'employee_id' => '20731',
            'name' => 'Firefighter Test',
            'rank' => 'Firefighter',
            'password' => 'private-password-value',
        ]);

        $response = $this->getJson('/api/public/employees/list');

        $response
            ->assertOk()
            ->assertJsonPath('0.name', 'Firefighter Test')
            ->assertJsonMissingPath('0.employee_id')
            ->assertJsonMissingPath('0.password');
    }
}
