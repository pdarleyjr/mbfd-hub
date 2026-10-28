<?php

declare(strict_types=1);

namespace Tests\Feature\OperationalForms;

use App\Enums\AccountStatus;
use App\Models\DepartmentUpdate;
use App\Models\User;
use App\Support\HubShellNavigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class HomeNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_home_uses_the_shared_brand_employee_navigation_and_stations_action(): void
    {
        $this->withoutVite();
        $user = $this->actingAsCanonicalFixture();

        $navigation = HubShellNavigation::forUser($user, []);
        $this->assertSame(['home', 'checkout', 'employee', 'requests'], array_column($navigation['memberNavigation'], 'key'));
        $this->assertSame(['Home', 'Checkout', 'Employee', 'Requests'], array_column($navigation['memberNavigation'], 'label'));
        $this->assertSame('/employee', $navigation['memberNavigation'][2]['href']);

        $this->get('/')->assertOk()
            ->assertSee('aria-label="MBFD Hub home"', false)
            ->assertSee('src="/images/mbfd-official-seal-256.png"', false)
            ->assertSee('<span>Hub</span>', false)
            ->assertSee('hub-checkout-icon', false)
            ->assertSee('Vehicle Checkout')
            ->assertSee('href="'.url('/daily/vehicle-inspections').'"', false)
            ->assertDontSee('Start Daily Checkout')
            ->assertDontSee('ICS Forms')
            ->assertDontSee('href="'.url('/employee/forms').'"', false);
    }

    public function test_vehicle_checkout_button_replaces_stations_and_points_at_vehicle_selection(): void
    {
        $this->withoutVite();
        $this->actingAsCanonicalFixture();

        $response = $this->get('/')->assertOk();
        $buttons = $this->xpath($response->getContent())
            ->query('//section[contains(@class,"home-today")]//a[contains(@class,"hub-btn--primary")]');

        self::assertSame(1, $buttons->length);
        self::assertSame(url('/daily/vehicle-inspections'), $buttons->item(0)->getAttribute('href'));
        self::assertSame('Vehicle Checkout', trim(preg_replace('/\s+/', ' ', $buttons->item(0)->textContent)));
        self::assertStringContainsString('hub-btn--field', $buttons->item(0)->getAttribute('class'));
        self::assertTrue($buttons->item(0)->hasAttribute('data-important-target'));
    }

    public function test_vehicle_checkout_button_keeps_its_employee_portal_visibility_rule(): void
    {
        $this->withoutVite();
        $this->actingAs(User::factory()->create(['account_status' => AccountStatus::Active]));

        $content = $this->get('/')->assertOk()->getContent();

        self::assertSame(0, $this->xpath($content)->query('//section[contains(@class,"home-today")]//a[contains(@class,"hub-btn--primary")]')->length);
        self::assertStringNotContainsString('Vehicle Checkout', $content);
    }

    public function test_meeting_launch_requires_current_access_and_verified_consumer_configuration(): void
    {
        $user = $this->actingAsCanonicalFixture();
        $states = ['meeting' => ['allowed' => true]];
        config(['application_access.runtime_verified.meeting' => false]);
        self::assertNotContains('meeting', array_column(HubShellNavigation::forUser($user, $states)['applications'], 'key'));
        config(['application_access.runtime_verified.meeting' => true]);
        $applications = HubShellNavigation::forUser($user, $states)['applications'];
        self::assertContains(['key' => 'meeting', 'label' => 'Meeting Intelligence', 'href' => 'https://meet.mbfdhub.com/'], $applications);
        self::assertNotContains('meeting', array_column(HubShellNavigation::forUser($user, ['meeting' => ['allowed' => false]])['applications'], 'key'));
    }

    public function test_entitled_home_renders_the_exact_quick_access_stack_without_pulsepoint(): void
    {
        $this->withoutVite();
        $user = $this->actingAsCanonicalFixture();
        $user->assignRole(Role::findOrCreate('admin', 'web'));
        $user->givePermissionTo([
            Permission::findOrCreate('app.media_control.access', 'web'),
            Permission::findOrCreate('admin.workgroups.view', 'web'),
            Permission::findOrCreate('admin.access', 'web'),
            Permission::findOrCreate('admin.fleet.manage', 'web'),
        ]);
        config(['services.media_control.authorization.service_token' => 'test-media-control-token']);

        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('Department Updates');
        $response->assertSee('No current department updates');
        $response->assertSeeInOrder(['Department Updates', 'Quick Access']);
        $response->assertSee('Quick Access');
        $response->assertSeeInOrder([
            'Station / Vehicles / Equipment',
            'Employee Portal',
            'Additional Links',
            'Fleet operations',
            'Workgroup Dashboard',
            'Pump Panel',
            'Vehicle Placement Simulator',
            'Videos',
            'Media Control',
        ]);
        $response->assertSee('Station forms, Vehicle Checkouts, Video Conferencing');
        $response->assertSee('View assigned gear, track requests, and request approved uniform items');
        $response->assertSee('Evaluations &amp; reviews', false);
        $response->assertSee('Training simulator');
        $response->assertSee('Training videos, support services content, and live media');
        $response->assertSee('Videowall controls, displays, and classroom media management');

        foreach ([
            url('/daily/stations'),
            url('/employee'),
            url('/admin/apparatuses'),
            url('/workgroups'),
            'https://pump-panel.pages.dev/',
            'https://www.firesim.mbfdhub.com',
            'https://videos.mbfdhub.com',
            'https://media.mbfdhub.com/api/auth/hub/start',
        ] as $destination) {
            $response->assertSee('href="'.$destination.'"', false);
        }

        // The retired cards are gone from Home; their apps and routes still exist.
        foreach (['ICS Forms', 'ICS 214 &amp; F-ROC reports', 'Take Home Vehicle Inspection', 'Complete a take-home vehicle inspection.'] as $removed) {
            $response->assertDontSee($removed, false);
        }
        $response->assertDontSee('href="'.route('take-home-inspections.index').'"', false);
        $response->assertDontSee('href="'.url('/employee/forms').'"', false);

        $response->assertDontSee('/workgroups/login', false);
        $response->assertDontSee('MBFD Support Assistant');
        $response->assertDontSee('aiChat()', false);
        $response->assertDontSee('MBFD Incidents');
        $response->assertDontSee('PulsePoint');
        $response->assertDontSee('pulsePointFeed', false);
        $response->assertDontSee('/api/incidents', false);
    }

    public function test_home_renders_only_active_department_updates_in_the_primary_column(): void
    {
        $this->withoutVite();
        $author = $this->actingAsCanonicalFixture();
        DepartmentUpdate::query()->create([
            'title' => 'Active operations notice',
            'body' => '<p>Review the current operational notice.</p>',
            'category' => 'operations',
            'priority' => 'important',
            'status' => 'published',
            'publish_at' => now()->subMinute(),
            'author_id' => $author->id,
        ]);
        DepartmentUpdate::query()->create([
            'title' => 'Draft notice',
            'body' => '<p>Not public.</p>',
            'category' => 'general',
            'priority' => 'normal',
            'status' => 'draft',
            'author_id' => $author->id,
        ]);

        $response = $this->get('/');

        $response->assertOk()
            ->assertSee('data-home-column="primary"', false)
            ->assertSee('data-home-section="department-updates"', false)
            ->assertSee('data-home-section="quick-access"', false)
            ->assertSee('Active operations notice')
            ->assertDontSee('Draft notice');
    }

    public function test_quick_access_uses_the_shared_mbfd_brand_surface_without_decorative_category_colors(): void
    {
        $this->withoutVite();
        $user = $this->actingAsCanonicalFixture();
        $user->assignRole(Role::findOrCreate('admin', 'web'));
        $user->givePermissionTo([
            Permission::findOrCreate('app.media_control.access', 'web'),
            Permission::findOrCreate('admin.workgroups.view', 'web'),
        ]);
        config(['services.media_control.authorization.service_token' => 'test-media-control-token']);

        $response = $this->get('/');

        $response->assertOk()
            ->assertSee('data-quick-access-card', false)
            ->assertSee('bg-hub-surface', false)
            ->assertSee('text-hub-blue', false)
            ->assertDontSee('bg-purple-500', false)
            ->assertDontSee('bg-emerald-500', false)
            ->assertDontSee('bg-indigo-500', false)
            ->assertDontSee('bg-cyan-500', false)
            ->assertDontSee('bg-orange-500', false);
    }

    public function test_admin_staying_user_sees_permitted_admin_destination_in_app_switcher(): void
    {
        $this->withoutVite();
        $user = $this->actingAsCanonicalFixture();
        $user->assignRole(Role::findOrCreate('logistics_admin', 'web'));
        $user->givePermissionTo(Permission::findOrCreate('admin.access', 'web'));

        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('Applications');
        $response->assertSee('data-hub-menu', false);
        $response->assertDontSee('Admin Panel');
        $response->assertSee('href="/admin"', false);
        $response->assertDontSee('/admin/login', false);
        $response->assertDontSee('Admin Login');
    }

    public function test_training_only_user_does_not_see_misleading_admin_panel_control(): void
    {
        $this->withoutVite();
        $user = $this->actingAsCanonicalFixture();
        $user->assignRole(Role::findOrCreate('training_admin', 'web'));

        $response = $this->get('/');

        $response->assertOk();
        $response->assertDontSee('Admin Panel');
        $response->assertDontSee('/admin/login', false);
    }

    public function test_non_media_control_entitled_user_does_not_see_privileged_card(): void
    {
        $this->withoutVite();
        $this->actingAsCanonicalFixture();

        $response = $this->get('/');

        $response->assertOk();
        $response->assertDontSee('Media Control');
        $response->assertDontSee('https://media.mbfdhub.com/api/auth/hub/start', false);
    }

    public function test_additional_links_is_one_collapsed_native_disclosure_with_the_six_items_in_order(): void
    {
        $this->withoutVite();
        $user = $this->actingAsCanonicalFixture();
        $user->assignRole(Role::findOrCreate('admin', 'web'));
        $user->givePermissionTo([
            Permission::findOrCreate('app.media_control.access', 'web'),
            Permission::findOrCreate('admin.workgroups.view', 'web'),
            Permission::findOrCreate('admin.access', 'web'),
            Permission::findOrCreate('admin.fleet.manage', 'web'),
        ]);
        config(['services.media_control.authorization.service_token' => 'test-media-control-token']);

        $xpath = $this->xpath($this->get('/')->assertOk()->getContent());

        $cards = $xpath->query('//details[@data-additional-links]');
        self::assertSame(1, $cards->length, 'exactly one Additional Links card');
        $card = $cards->item(0);
        self::assertFalse($card->hasAttribute('open'), 'collapsed by default');
        self::assertSame(1, $xpath->query('.//summary', $card)->length);
        self::assertSame('Additional Links', trim($xpath->query('.//summary//*[contains(@class,"hub-tile__title")]', $card)->item(0)->textContent));
        self::assertSame(1, $xpath->query('ancestor::aside[@data-home-column="rail"]', $card)->length);

        $links = $xpath->query('.//a[@data-additional-link]', $card);
        $actual = [];
        foreach ($links as $link) {
            $actual[] = [
                trim($xpath->query('.//*[contains(@class,"hub-tile__title")]', $link)->item(0)->textContent),
                $link->getAttribute('href'),
                $link->getAttribute('target'),
                $link->getAttribute('rel'),
            ];
        }
        self::assertSame([
            ['Fleet operations', url('/admin/apparatuses'), '', ''],
            ['Workgroup Dashboard', url('/workgroups'), '', ''],
            ['Pump Panel', 'https://pump-panel.pages.dev/', '_blank', 'noopener noreferrer'],
            ['Vehicle Placement Simulator', 'https://www.firesim.mbfdhub.com', '_blank', 'noopener noreferrer'],
            ['Videos', 'https://videos.mbfdhub.com', '_blank', 'noopener noreferrer'],
            ['Media Control', 'https://media.mbfdhub.com/api/auth/hub/start', '_blank', 'noopener noreferrer'],
        ], $actual);

        // External links keep the new-tab announcement; internal links do not.
        self::assertStringContainsString('(opens in a new tab)', $xpath->query('(.//a[@data-additional-link])[3]', $card)->item(0)->textContent);
        self::assertStringNotContainsString('(opens in a new tab)', $xpath->query('(.//a[@data-additional-link])[1]', $card)->item(0)->textContent);

        // The six are not repeated as standalone cards.
        $standalone = $xpath->query('//a[@data-quick-access-card]');
        self::assertSame(['Station / Vehicles / Equipment', 'Employee Portal'], array_map(
            fn (\DOMNode $node): string => trim($xpath->query('.//*[contains(@class,"hub-tile__title")]', $node)->item(0)->textContent),
            iterator_to_array($standalone),
        ));
    }

    public function test_additional_links_hides_unentitled_items_from_the_markup(): void
    {
        $this->withoutVite();
        $this->actingAsCanonicalFixture();

        $response = $this->get('/')->assertOk();
        $xpath = $this->xpath($response->getContent());

        $titles = [];
        foreach ($xpath->query('//details[@data-additional-links]//a[@data-additional-link]') as $link) {
            $titles[] = trim($xpath->query('.//*[contains(@class,"hub-tile__title")]', $link)->item(0)->textContent);
        }
        self::assertSame(['Pump Panel', 'Vehicle Placement Simulator', 'Videos'], $titles);

        $response->assertDontSee('Fleet operations');
        $response->assertDontSee('/admin/apparatuses', false);
        $response->assertDontSee('Workgroup Dashboard');
        $response->assertDontSee('href="'.url('/workgroups').'"', false);
        $response->assertDontSee('Media Control');
        $response->assertDontSee('https://media.mbfdhub.com/api/auth/hub/start', false);
    }

    public function test_profile_less_account_keeps_a_path_to_the_take_home_inspection(): void
    {
        $this->withoutVite();
        $this->actingAs(User::factory()->create(['account_status' => AccountStatus::Active]));
        $inspection = route('take-home-inspections.index');

        $response = $this->get('/')->assertOk()
            ->assertDontSee('Vehicle Checkout')
            ->assertDontSee('Take Home Vehicle Inspection')
            ->assertSee('Take Home Inspection')
            ->assertSee('href="'.$inspection.'"', false);

        $xpath = $this->xpath($response->getContent());
        self::assertSame(1, $xpath->query('//a[@data-quick-access-card and @href="'.$inspection.'"]')->length);
        self::assertSame(0, $xpath->query('//a[@href="'.url('/employee/forms').'"]')->length);
    }

    public function test_profile_less_shell_navigation_lists_the_inspection_and_members_do_not(): void
    {
        $profileLess = User::factory()->create(['account_status' => AccountStatus::Active]);
        $more = HubShellNavigation::forUser($profileLess, [])['moreNavigation'];
        self::assertSame(['updates', 'support', 'take-home-inspection'], array_column($more, 'key'));
        self::assertSame(
            ['key' => 'take-home-inspection', 'label' => 'Take Home Inspection', 'href' => route('take-home-inspections.index', absolute: false)],
            $more[2],
        );

        $member = $this->actingAsCanonicalFixture();
        self::assertSame(['updates', 'support'], array_column(HubShellNavigation::forUser($member, [])['moreNavigation'], 'key'));
        self::assertSame([], HubShellNavigation::forUser(null)['moreNavigation']);
    }

    public function test_anonymous_home_requires_canonical_login(): void
    {
        $this->get('/')->assertRedirect('/login');
    }

    private function xpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument;
        @$document->loadHTML($html);

        return new \DOMXPath($document);
    }
}
