<?php

namespace Tests\Feature;

use App\Filament\Resources\TrainingTodoAdminResource\Pages\ListTrainingTodos as AdminListTrainingTodos;
use App\Filament\Training\Resources\TrainingTodoResource\Pages\CreateTrainingTodo;
use App\Filament\Training\Resources\TrainingTodoResource\Pages\EditTrainingTodo;
use App\Filament\Training\Resources\TrainingTodoResource\Pages\ListTrainingTodos;
use App\Filament\Training\Resources\TrainingTodoResource\Pages\ViewTrainingTodo;
use App\Models\Training\TrainingTodo;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
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

    public function test_training_task_trash_restore_preserve_completed_state_attachments_and_history_in_both_panels(): void
    {
        Notification::fake();
        Storage::fake('public');
        $manager = User::factory()->create();
        $manager->assignRole('training_admin');
        $manager->givePermissionTo([Permission::findOrCreate('admin.access', 'web'), Permission::findOrCreate('admin.training.view', 'web'), Permission::findOrCreate('admin.training.manage', 'web')]);
        $this->actingAs($manager);
        $this->withoutVite();
        Filament::setCurrentPanel(Filament::getPanel('training'));
        Livewire::test(CreateTrainingTodo::class)->fillForm([
            'title' => '[QA TEST] Completed training task',
            'status' => 'pending',
            'priority' => 'medium',
            'attachments' => [UploadedFile::fake()->createWithContent('qa.pdf', '%PDF QA retained')],
        ])->call('create')->assertHasNoFormErrors();
        $todo = TrainingTodo::query()->where('title', '[QA TEST] Completed training task')->sole();
        self::assertSame($manager->id, $todo->created_by);
        self::assertSame('pending', $todo->status);
        $attachments = $todo->attachments;
        self::assertCount(1, $attachments);
        Livewire::test(EditTrainingTodo::class, ['record' => $todo->id])->fillForm(['status' => 'completed'])
            ->call('save')->assertHasNoFormErrors();
        Livewire::test(ViewTrainingTodo::class, ['record' => $todo->id])
            ->callAction('addUpdate', data: ['comment' => '[QA TEST] Completion evidence'])->assertHasNoActionErrors();
        $update = $todo->updates()->sole();
        self::assertSame($manager->id, $update->user_id);
        self::assertSame('[QA TEST] Completion evidence', $update->comment);
        $completedAt = $todo->fresh()->completed_at->toISOString();
        Livewire::test(ListTrainingTodos::class)->assertCanSeeTableRecords([$todo])
            ->callTableAction('delete', $todo)->assertHasNoTableActionErrors()->assertCanNotSeeTableRecords([$todo]);
        $this->assertSoftDeleted($todo);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(AdminListTrainingTodos::class)->filterTable('trashed', '0')->assertCanSeeTableRecords([$todo])
            ->callTableAction('restore', $todo->getKey())->assertHasNoTableActionErrors()
            ->filterTable('trashed', null)->assertCanSeeTableRecords([$todo]);
        $this->assertNotSoftDeleted($todo);
        self::assertSame('completed', $todo->fresh()->status);
        self::assertSame($completedAt, $todo->fresh()->completed_at->toISOString());
        self::assertSame($update->id, $todo->updates()->sole()->id);
        self::assertSame($attachments, $todo->fresh()->attachments);
        self::assertSame('%PDF QA retained', Storage::disk('public')->get($attachments[0]));
        Notification::assertNothingSent();
    }

    public function test_training_update_removal_and_restore_are_record_bound_and_viewers_cannot_mutate_history(): void
    {
        Notification::fake();
        $manager = User::factory()->create();
        $manager->assignRole('training_admin');
        $this->actingAs($manager);
        $this->withoutVite();
        Filament::setCurrentPanel(Filament::getPanel('training'));
        $first = TrainingTodo::create(['title' => '[QA TEST] First task', 'status' => 'pending', 'created_by' => $manager->id]);
        $second = TrainingTodo::create(['title' => '[QA TEST] Other task', 'status' => 'pending', 'created_by' => $manager->id]);
        $ownUpdate = $first->updates()->create(['user_id' => $manager->id, 'username' => $manager->name, 'comment' => 'First task history']);
        $otherUpdate = $second->updates()->create(['user_id' => $manager->id, 'username' => $manager->name, 'comment' => 'Other task history']);

        Livewire::test(ViewTrainingTodo::class, ['record' => $first->id])->call('deleteUpdate', $otherUpdate->id);
        $this->assertNotSoftDeleted($otherUpdate);
        Livewire::test(ViewTrainingTodo::class, ['record' => $first->id])->call('deleteUpdate', $ownUpdate->id)->assertSee('Trash')
            ->call('restoreUpdate', $ownUpdate->id)->assertSee('First task history');
        $this->assertNotSoftDeleted($ownUpdate);
        $otherUpdate->delete();
        Livewire::test(ViewTrainingTodo::class, ['record' => $first->id])->call('restoreUpdate', $otherUpdate->id)->assertStatus(404);
        $this->assertSoftDeleted($otherUpdate);

        $viewer = User::factory()->create();
        $viewer->assignRole('training_viewer');
        $this->actingAs($viewer);
        Livewire::test(ViewTrainingTodo::class, ['record' => $first->id])->assertActionHidden('addUpdate')
            ->call('deleteUpdate', $ownUpdate->id)->assertStatus(403);
        $this->assertNotSoftDeleted($ownUpdate);
        Livewire::test(ViewTrainingTodo::class, ['record' => $second->id])->call('restoreUpdate', $otherUpdate->id)->assertStatus(403);
        $this->assertSoftDeleted($otherUpdate);
    }
}
