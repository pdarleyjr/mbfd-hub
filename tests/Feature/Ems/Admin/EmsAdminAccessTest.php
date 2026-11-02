<?php

declare(strict_types=1);

namespace Tests\Feature\Ems\Admin;

use App\Filament\Admin\Pages\MedicalDataStatistics;
use App\Filament\Admin\Pages\MedicalSupplyRoom;
use App\Filament\Admin\Pages\SubmittedMedicalSupplyRoomInventory;
use App\Filament\Resources\InventoryRequestResource;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Ems\Concerns\BuildsEmsWorld;
use Tests\TestCase;

/** The Admin -> EMS access matrix: who sees the group, who can open each page, and what the server enforces. */
final class EmsAdminAccessTest extends TestCase
{
    use BuildsEmsWorld;
    use RefreshDatabase;

    private const PAGES = ['/admin/rescue-inventory', '/admin/submitted-medical-supply-room-inventory', '/admin/medical-supply-room', '/admin/medical-statistics'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->buildEmsWorld();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    protected function tearDown(): void
    {
        $this->tearDownEmsWorld();
        parent::tearDown();
    }

    private function as(User $user): void
    {
        $this->forgetEmsMemo();
        $this->actingAs($user->fresh());
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /** @return list<string> */
    private function navigationItems(): array
    {
        $group = collect(Filament::getNavigation())->first(fn ($group): bool => $group->getLabel() === 'EMS');

        return $group === null ? [] : collect($group->getItems())->map(fn ($item): string => $item->getLabel())->all();
    }

    /** @return array<string, array{0: string}> */
    public static function pages(): array
    {
        return array_combine(self::PAGES, array_map(fn (string $p): array => [$p], self::PAGES));
    }

    #[DataProvider('pages')]
    public function test_super_administrator_viewer_and_manager_open_every_page(string $uri): void
    {
        foreach ([$this->emsSuperAdmin(), $this->emsAdmin(['admin.ems.view']), $this->emsAdmin(['admin.ems.manage'])] as $user) {
            $this->as($user);
            $this->get($uri)->assertOk();
        }
    }

    #[DataProvider('pages')]
    public function test_an_administrator_without_ems_access_and_a_plain_member_are_refused(string $uri): void
    {
        $this->as($this->emsAdmin([], 'Plain Admin'));
        $this->get($uri)->assertForbidden();

        $this->as($this->emsMember('Firefighter', 'Fran Fighter'));
        $this->assertNotSame(200, $this->get($uri)->getStatusCode(), 'A member with no Admin access never sees an EMS page.');

        auth()->logout();
        $this->assertNotSame(200, $this->get($uri)->getStatusCode(), 'Signed-out visitors are sent to sign in.');
    }

    public function test_the_ems_group_shows_the_four_screens_only_to_people_who_may_read_them(): void
    {
        $expected = ['Submitted Inventory Forms', 'Submitted Medical Supply Room Inventory', 'Medical Supply Room', 'Medical Data / Statistics'];
        foreach ([$this->emsSuperAdmin(), $this->emsAdmin(['admin.ems.view']), $this->emsAdmin(['admin.ems.manage'])] as $user) {
            $this->as($user);
            $this->assertEqualsCanonicalizing($expected, $this->navigationItems());
            $this->assertSame($expected, $this->navigationOrder());
        }

        $this->as($this->emsAdmin([], 'Plain Admin'));
        $this->assertSame([], $this->navigationItems(), 'No EMS group for an administrator without EMS access.');
        $this->assertFalse(InventoryRequestResource::canViewAny());
        $this->assertFalse(MedicalSupplyRoom::canAccess());
        $this->assertFalse(SubmittedMedicalSupplyRoomInventory::canAccess());
        $this->assertFalse(MedicalDataStatistics::canAccess());
    }

    /** @return list<string> */
    private function navigationOrder(): array
    {
        $group = collect(Filament::getNavigation())->first(fn ($group): bool => $group->getLabel() === 'EMS');

        return collect($group->getItems())->sortBy(fn ($item) => $item->getSort())->map(fn ($item): string => $item->getLabel())->values()->all();
    }

    public function test_the_group_is_collapsed_and_sits_after_fleet(): void
    {
        $this->as($this->emsSuperAdmin());
        $labels = collect(Filament::getNavigation())->map(fn ($group) => $group->getLabel())->filter()->values();
        $fleet = $labels->search('Fleet');
        $this->assertIsInt($fleet);
        $this->assertSame('EMS', $labels[$fleet + 1], 'EMS comes right after Fleet.');
        $this->assertTrue(collect(Filament::getNavigation())->first(fn ($group) => $group->getLabel() === 'EMS')->isCollapsed());
    }

    public function test_the_sidebar_checks_cost_a_super_administrator_no_queries(): void
    {
        $admin = $this->emsSuperAdmin();
        $this->as($admin);
        auth()->user()->hasRole('super_admin'); // roles are loaded once per request, like the sidebar's other checks

        $queries = 0;
        \Illuminate\Support\Facades\DB::listen(static function () use (&$queries): void {
            $queries++;
        });
        InventoryRequestResource::canViewAny();
        MedicalSupplyRoom::canAccess();
        SubmittedMedicalSupplyRoomInventory::canAccess();
        MedicalDataStatistics::canAccess();

        $this->assertSame(0, $queries, 'Admin pages have a 30-query budget, so the EMS sidebar checks must not add to it.');
    }

    public function test_view_only_means_no_navigation_badges_and_actions_follow_the_act_decision(): void
    {
        $viewer = $this->emsAdmin(['admin.ems.view']);
        $manager = $this->emsAdmin(['admin.ems.manage']);
        $this->as($viewer);
        $this->assertTrue(InventoryRequestResource::canViewAny());
        $this->assertNull(InventoryRequestResource::getNavigationBadge(), 'Admin pages have a query budget, so there are no badge queries.');
        $this->assertFalse(app(\App\Services\Ems\EmsAccess::class)->canAct($viewer->fresh()));
        $this->assertTrue(app(\App\Services\Ems\EmsAccess::class)->canAct($manager->fresh()));
        $this->assertFalse(app(\App\Services\Ems\EmsAccess::class)->canConfigure($viewer->fresh()));
        $this->assertTrue(app(\App\Services\Ems\EmsAccess::class)->canConfigure($manager->fresh()));
    }
}
