<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Enums\AccountStatus;
use App\Filament\Resources\CapitalProjectResource\Pages\EditCapitalProject;
use App\Filament\Resources\CapitalProjectResource\RelationManagers\UpdatesRelationManager;
use App\Filament\Resources\TodoResource\Pages\ViewTodo;
use App\Models\CapitalProject;
use App\Models\Todo;
use App\Models\TodoUpdate;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Admin-only overrides used to read attributes that do not exist (isAdmin,
 * role), so they never applied. They now use the capability that manages
 * the resource and stay closed for everyone else.
 */
final class AdminOverrideCapabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_project_update_bulk_delete_is_offered_only_to_project_managers(): void
    {
        $project = CapitalProject::factory()->create();

        $this->actingAs($this->userWith([]));
        Livewire::test(UpdatesRelationManager::class, ['ownerRecord' => $project, 'pageClass' => EditCapitalProject::class])
            ->assertTableBulkActionHidden('delete');

        $this->actingAs($this->userWith(['admin.projects.manage']));
        Livewire::test(UpdatesRelationManager::class, ['ownerRecord' => $project, 'pageClass' => EditCapitalProject::class])
            ->assertTableBulkActionVisible('delete');
    }

    public function test_todo_updates_are_deleted_by_their_author_or_a_todo_administrator_only(): void
    {
        $author = $this->userWith([]);
        $todo = Todo::create(['title' => 'Override fixture', 'created_by' => $author->id]);
        $first = TodoUpdate::create(['todo_id' => $todo->id, 'user_id' => $author->id, 'username' => 'Author', 'comment' => 'First']);
        $second = TodoUpdate::create(['todo_id' => $todo->id, 'user_id' => $author->id, 'username' => 'Author', 'comment' => 'Second']);

        $this->actingAs($this->userWith([]));
        Livewire::test(ViewTodo::class, ['record' => $todo->getKey()])->call('deleteUpdate', $first->id);
        $this->assertModelExists($first);

        $this->actingAs($this->userWith(['admin.notifications.manage']));
        Livewire::test(ViewTodo::class, ['record' => $todo->getKey()])->call('deleteUpdate', $first->id);
        $this->assertModelMissing($first);

        $this->actingAs($author);
        Livewire::test(ViewTodo::class, ['record' => $todo->getKey()])->call('deleteUpdate', $second->id);
        $this->assertModelMissing($second);
    }

    /** @param  list<string>  $permissions */
    private function userWith(array $permissions): User
    {
        $user = User::factory()->create(['account_status' => AccountStatus::Active]);
        // Everyone here can open the Todo and the project; only the listed capabilities differ.
        foreach (['admin.access', 'admin.notifications.view', 'admin.projects.view', ...$permissions] as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }

        return $user;
    }
}
