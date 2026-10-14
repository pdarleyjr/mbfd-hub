<?php

declare(strict_types=1);

namespace Tests\Feature\Workgroup;

use App\Filament\Workgroup\Pages\Dashboard;
use App\Filament\Workgroup\Pages\Notes;
use App\Filament\Workgroup\Pages\SessionResultsPage;
use App\Filament\Workgroup\Pages\SharedUploads;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Workgroup pages a person cannot use are not offered, and an empty programme is not a 404. */
final class WorkgroupNavigationDeadEndTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->withoutVite();
    }

    public function test_an_administrator_without_a_membership_is_not_offered_member_only_pages(): void
    {
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole(Role::findOrCreate('super_admin', 'web'));
        $this->actingAs($superAdmin);
        Filament::setCurrentPanel(Filament::getPanel('workgroups'));

        $this->assertFalse(Notes::canAccess());
        $this->assertFalse(SharedUploads::canAccess());
        $this->get(Dashboard::getUrl())->assertOk()
            ->assertDontSee(Notes::getUrl(), false);
    }

    public function test_session_results_without_any_session_returns_to_the_dashboard_instead_of_a_404(): void
    {
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole(Role::findOrCreate('super_admin', 'web'));
        $this->actingAs($superAdmin);
        Filament::setCurrentPanel(Filament::getPanel('workgroups'));

        $this->get(SessionResultsPage::getUrl())->assertRedirect(Dashboard::getUrl());
    }
}
