<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\ShopWorkResource\Pages\CreateShopWork;
use App\Filament\Resources\ShopWorkResource\Pages\EditShopWork;
use App\Models\ShopWork;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class ShopWorkResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('super_admin', 'web'));
        Gate::before(fn (User $user): ?bool => $user->hasRole('super_admin') ? true : null);
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->withoutVite();
    }

    public function test_a_shop_need_can_be_created_and_edited_from_the_admin_form(): void
    {
        Livewire::test(CreateShopWork::class)
            ->fillForm([
                'project_name' => '[QA TEST] Rebuild tool board',
                'status' => 'In Progress',
                'assigned_to' => 'Shop crew',
                'estimated_cost' => 125.5,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $work = ShopWork::query()->where('project_name', '[QA TEST] Rebuild tool board')->sole();
        $this->assertSame('In Progress', $work->status);
        $this->assertSame('Shop crew', $work->assigned_to);

        Livewire::test(EditShopWork::class, ['record' => $work->getRouteKey()])
            ->fillForm(['status' => 'Completed', 'notes' => 'Finished.'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Completed', $work->refresh()->status);
        $this->assertSame('Finished.', $work->notes);
    }
}
