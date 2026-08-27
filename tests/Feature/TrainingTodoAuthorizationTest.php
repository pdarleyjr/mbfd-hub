<?php

namespace Tests\Feature;

use App\Models\Training\TrainingTodo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TrainingTodoAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        Role::create(['name' => 'super_admin', 'guard_name' => 'web']);
        Role::create(['name' => 'training_admin', 'guard_name' => 'web']);
        Role::create(['name' => 'training_viewer', 'guard_name' => 'web']);
    }

    public function test_training_viewer_is_read_only_for_training_todos(): void
    {
        $creator = User::factory()->create();
        $viewer = User::factory()->create();
        $viewer->assignRole('training_viewer');
        $todo = TrainingTodo::create([
            'title' => 'Review drill plan',
            'status' => 'pending',
            'priority' => 'medium',
            'created_by' => $creator->id,
        ]);

        $this->assertTrue($viewer->can('view', $todo));
        $this->assertFalse($viewer->can('create', TrainingTodo::class));
        $this->assertFalse($viewer->can('update', $todo));
        $this->assertFalse($viewer->can('delete', $todo));
    }

    public function test_training_admin_can_manage_training_todos_created_by_other_users(): void
    {
        $creator = User::factory()->create();
        $trainingAdmin = User::factory()->create();
        $trainingAdmin->assignRole('training_admin');
        $todo = TrainingTodo::create([
            'title' => 'Review drill plan',
            'status' => 'pending',
            'priority' => 'medium',
            'created_by' => $creator->id,
        ]);

        $this->assertTrue($trainingAdmin->can('create', TrainingTodo::class));
        $this->assertTrue($trainingAdmin->can('update', $todo));
        $this->assertTrue($trainingAdmin->can('delete', $todo));
    }
}
