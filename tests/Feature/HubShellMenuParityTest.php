<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Models\User;
use App\Support\HubShellNavigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/** The Blade, Filament and React shells draw their menus from one server list. */
final class HubShellMenuParityTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function navigation(array $applications = [], array $more = [], ?array $members = null): array
    {
        return [
            'applications' => $applications,
            'memberNavigation' => $members ?? [['key' => 'home', 'label' => 'Home', 'href' => '/'], ['key' => 'employee', 'label' => 'Employee', 'href' => '/employee']],
            'moreNavigation' => $more,
            'account' => ['name' => 'Test Member', 'href' => '/account'],
        ];
    }

    private function visit(string $path): void
    {
        $this->app->instance('request', Request::create($path));
    }

    public function test_email_pages_mark_email_current_and_not_admin(): void
    {
        $navigation = $this->navigation([
            ['key' => 'home', 'label' => 'Home', 'href' => '/'],
            ['key' => 'admin', 'label' => 'Admin', 'href' => '/admin'],
            ['key' => 'email', 'label' => 'Email', 'href' => '/admin/email'],
            ['key' => 'employee', 'label' => 'Employee', 'href' => '/employee'],
        ]);

        $this->visit('/admin/email/inbox');
        $html = view('components.hub.app-switcher', ['navigation' => $navigation, 'module' => 'Admin'])->render();
        $this->assertMatchesRegularExpression('#<a role="menuitem" href="/admin/email"[^>]*aria-current="page"#', $html);
        $this->assertDoesNotMatchRegularExpression('#<a role="menuitem" href="/admin"[^>]*aria-current#', $html);
        $this->assertSame(1, substr_count($html, 'aria-current="page"'));

        $this->visit('/admin/stations');
        $html = view('components.hub.app-switcher', ['navigation' => $navigation, 'module' => 'Admin'])->render();
        $this->assertMatchesRegularExpression('#<a role="menuitem" href="/admin"[^>]*aria-current="page"#', $html);
        $this->assertDoesNotMatchRegularExpression('#<a role="menuitem" href="/admin/email"[^>]*aria-current#', $html);

        $this->visit('/');
        $html = view('components.hub.app-switcher', ['navigation' => $navigation, 'module' => 'Home'])->render();
        $this->assertMatchesRegularExpression('#<a role="menuitem" href="/"[^>]*aria-current="page"#', $html);
        $this->assertSame(1, substr_count($html, 'aria-current="page"'));
    }

    public function test_home_is_listed_once_whether_or_not_the_server_list_carries_it(): void
    {
        $this->visit('/employee');
        $withHome = view('components.hub.app-switcher', ['navigation' => $this->navigation([['key' => 'home', 'label' => 'Home', 'href' => '/']]), 'module' => 'Employee'])->render();
        $without = view('components.hub.app-switcher', ['navigation' => $this->navigation([]), 'module' => 'Employee'])->render();

        $this->assertSame(1, substr_count($withHome, '>Home<'));
        $this->assertSame(1, substr_count($without, '>Home<'));
    }

    public function test_external_destinations_show_the_arrow_and_say_they_open_a_new_tab(): void
    {
        $this->visit('/');
        $navigation = $this->navigation([
            ['key' => 'bid', 'label' => 'Bid', 'href' => 'https://bid.mbfdhub.com/api/auth/start'],
            ['key' => 'employee', 'label' => 'Employee', 'href' => '/employee'],
        ]);
        $html = view('components.hub.app-switcher', ['navigation' => $navigation, 'module' => 'Home'])->render();

        $this->assertSame(1, substr_count($html, 'hub-shell-external-hint'));
        $this->assertStringContainsString('target="_blank" rel="noopener"', $html);
        $this->assertStringContainsString('(opens in a new tab)', $html);
        $this->assertStringContainsString('aria-hidden="true">&#8599;</span>', $html);
    }

    public function test_phone_more_sheet_is_built_from_the_shared_list_and_keeps_its_fixed_entries(): void
    {
        $navigation = $this->navigation(
            [
                ['key' => 'home', 'label' => 'Home', 'href' => '/'],
                ['key' => 'daily', 'label' => 'Daily Checkout', 'href' => '/daily/stations'],
                ['key' => 'admin', 'label' => 'Admin', 'href' => '/admin'],
                ['key' => 'bid', 'label' => 'Bid', 'href' => 'https://bid.mbfdhub.com/api/auth/start'],
            ],
            [
                ['key' => 'updates', 'label' => 'Department Updates', 'href' => '/updates'],
                ['key' => 'support', 'label' => 'Support', 'href' => '/support/issues'],
                ['key' => 'daily-roster', 'label' => 'Daily Roster', 'href' => '/daily-roster'],
                ['key' => 'take-home-inspection', 'label' => 'Take Home Inspection', 'href' => '/take-home-vehicle-inspections'],
            ],
        );
        $html = view('components.hub.member-navigation', ['navigation' => $navigation])->render();
        $sheet = substr($html, (int) strpos($html, 'role="menu"'));

        foreach (['/admin', 'https://bid.mbfdhub.com/api/auth/start', '/updates', '/support/issues', '/daily-roster', '/take-home-vehicle-inspections', route('account.show'), route('install')] as $href) {
            $this->assertStringContainsString('href="'.$href.'"', $sheet, $href);
        }
        foreach (['>Department Updates<', '>Support<', '>Daily Roster<', '>Take Home Inspection<', '>My account<', '>Install MBFD Hub<'] as $label) {
            $this->assertStringContainsString($label, $sheet, $label);
        }
        // Home and Daily Checkout are tabs on the same bar, so the sheet does not repeat them.
        $this->assertStringNotContainsString('>Daily Checkout<', $sheet);
        $this->assertStringNotContainsString('href="/"', $sheet);
        $this->assertSame(1, substr_count($sheet, 'hub-shell-external-hint'));
    }

    public function test_take_home_pages_get_their_own_module_label_so_hub_home_shows(): void
    {
        $this->withoutVite();
        foreach (['/take-home-vehicle-inspections', '/take-home-vehicle-inspections/new', '/take-home-vehicle-inspections/TH-1'] as $path) {
            $this->visit($path);
            $html = Blade::render('<x-hub.shell />');
            $this->assertStringContainsString('data-hub-home', $html, $path);
            $this->assertStringContainsString('Take Home Inspection · Switch application', $html, $path);
        }
        $this->visit('/');
        $this->assertStringNotContainsString('data-hub-home', Blade::render('<x-hub.shell />'));
    }

    public function test_server_list_leads_with_home_and_offers_daily_checkout_to_employee_panel_members(): void
    {
        $member = $this->actingAsCanonicalFixture();
        $applications = HubShellNavigation::forUser($member, [])['applications'];
        $keys = array_column($applications, 'key');
        $this->assertSame(['home', 'daily'], array_slice($keys, 0, 2));
        $this->assertSame(['key' => 'daily', 'label' => 'Daily Checkout', 'href' => '/daily/stations'], $applications[1]);
        $this->assertSame(1, count(array_keys($keys, 'home', true)));

        $profileLess = User::factory()->create(['account_status' => AccountStatus::Active]);
        $this->assertSame(['home'], array_column(HubShellNavigation::forUser($profileLess, [])['applications'], 'key'));
        $this->assertSame([], HubShellNavigation::forUser(null)['applications']);
    }

    public function test_daily_roster_joins_the_more_list_only_where_home_offers_it(): void
    {
        $member = $this->actingAsCanonicalFixture();

        config(['daily_roster.mode' => 'shadow']);
        $this->assertNotContains('daily-roster', array_column(HubShellNavigation::forUser($member, [])['moreNavigation'], 'key'));

        config(['daily_roster.mode' => 'publish']);
        $more = HubShellNavigation::forUser($member, [])['moreNavigation'];
        $this->assertSame(['updates', 'support', 'daily-roster'], array_column($more, 'key'));
        $this->assertSame(['key' => 'daily-roster', 'label' => 'Daily Roster', 'href' => route('daily-roster.show', absolute: false)], $more[2]);

        $profileLess = User::factory()->create(['account_status' => AccountStatus::Active]);
        $this->assertNotContains('daily-roster', array_column(HubShellNavigation::forUser($profileLess, [])['moreNavigation'], 'key'));
    }
}
