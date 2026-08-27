<?php

declare(strict_types=1);

namespace Tests\Feature\OperationalForms;

use App\Models\OperationalFormRecord;
use Database\Seeders\OperationalFormsE2ESeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class OperationalFormsE2ESeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake((string) config('filesystems.private', 'local'));
    }

    public function test_it_seeds_after_migrations_create_the_permission_schema(): void
    {
        $this->assertTrue(Schema::hasTable('permissions'));
        $this->assertTrue(Schema::hasTable('roles'));

        $this->seed(OperationalFormsE2ESeeder::class);

        $this->assertDatabaseHas('users', ['email' => 'forms-admin@example.test']);
        $this->assertDatabaseHas('roles', ['name' => 'admin', 'guard_name' => 'web']);
        $this->assertSame(2, OperationalFormRecord::query()->count());
    }
}
