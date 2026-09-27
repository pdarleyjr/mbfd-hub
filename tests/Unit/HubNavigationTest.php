<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Filament\Support\ContextualBack;
use App\Support\HubNavigation;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class HubNavigationTest extends TestCase
{
    private function navigationRequest(string $url = 'https://hub.test/admin/stations/7'): Request
    {
        $request = Request::create($url);
        $request->setLaravelSession(new Store('navigation-test', new ArraySessionHandler(120)));
        $this->app->instance('request', $request);

        return $request;
    }

    public static function unsafeDestinations(): array
    {
        return array_map(fn ($value) => [$value], [
            null, [], '', 'https://evil.test/admin', '//evil.test/admin', 'https://hub.test//evil.test/admin',
            'https://hub.test.evil.test/admin', 'https://hub.test@evil.test/admin',
            'https://evil.test@hub.test/admin', 'http://hub.test/admin',
            'https://hub.test:444/admin', 'javascript:alert(1)', 'data:text/html,test',
            '/\\evil.test/admin', '/%2f%2fevil.test/admin', '/%252f%252fevil.test/admin',
            '/admin/../auth/identity', '/admin/%2e%2e/auth/identity',
            "/admin\r\nLocation:https://evil.test", '/admin?x=%0d%0aLocation:evil',
            '/api/users', '/auth/identity', '/admin/login', '/employee/logout',
            'admin/stations', ' /admin',
        ]);
    }

    #[DataProvider('unsafeDestinations')]
    public function test_rejects_unsafe_or_non_ui_targets(mixed $candidate): void
    {
        $this->navigationRequest();
        $this->assertNull(HubNavigation::safePath($candidate));
    }

    public function test_accepts_same_origin_and_preserves_filters_and_anchor(): void
    {
        $this->navigationRequest();
        $target = '/admin/stations?tableSearch=Station%201&tableFilters[status][value]=active&page=2#table';
        $this->assertSame($target, HubNavigation::safePath('https://hub.test'.$target));
        $this->assertSame('/daily/stations/2', HubNavigation::safePath('/daily/stations/2'));
        $this->assertSame('/workgroups/surveys', HubNavigation::safePath('/workgroups/surveys'));
        $this->assertSame('/', HubNavigation::safePath('/'));
    }

    public function test_explicit_target_wins_over_parent_and_history(): void
    {
        $request = $this->navigationRequest('https://hub.test/admin/stations/7?return_to=%2Fdaily%2Fstations%2F7');
        $request->session()->setPreviousUrl('https://hub.test/admin/employees');
        $this->assertSame('/daily/stations/7', HubNavigation::backUrl('/admin/stations'));
    }

    public function test_parent_wins_over_unrelated_history_and_rejects_external_explicit_target(): void
    {
        $request = $this->navigationRequest('https://hub.test/admin/stations/7?return_to=https%3A%2F%2Fevil.test');
        $request->session()->setPreviousUrl('https://hub.test/admin/employees');
        $this->assertSame('/admin/stations', HubNavigation::backUrl('/admin/stations'));
    }

    public function test_restores_exact_parent_list_query_from_verified_session_history(): void
    {
        $request = $this->navigationRequest();
        $request->session()->setPreviousUrl('https://hub.test/admin/stations?tableSearch=Engine&page=3');
        $this->assertSame('/admin/stations?tableSearch=Engine&page=3', HubNavigation::backUrl('/admin/stations'));
    }

    public function test_history_requires_safe_hub_target_and_falls_back_deterministically(): void
    {
        $request = $this->navigationRequest();
        $request->session()->setPreviousUrl('https://evil.test/admin');
        $this->assertSame('/', HubNavigation::backUrl());
        $request->session()->setPreviousUrl('https://hub.test/admin/employees');
        $this->assertSame('/admin/employees', HubNavigation::backUrl());
    }

    public function test_self_target_and_array_target_do_not_create_back_loops(): void
    {
        $this->navigationRequest('https://hub.test/admin/stations/7?return_to=%2Fadmin%2Fstations%2F7');
        $this->assertSame('/admin/stations', HubNavigation::backUrl('/admin/stations'));
        $this->navigationRequest('https://hub.test/admin/stations/7?return_to[]=x');
        $this->assertSame('/admin/stations', HubNavigation::backUrl('/admin/stations'));
    }

    public function test_livewire_refresh_retains_explicit_target_from_same_page_referrer(): void
    {
        $request = $this->navigationRequest('https://hub.test/livewire/update');
        $request->setMethod('POST');
        $request->headers->set('X-Livewire', '');
        $request->headers->set('referer', 'https://hub.test/admin/stations/7?return_to=%2Fdaily%2Fstations%2F7');
        $request->request->set('components', [['snapshot' => json_encode(['memo' => ['path' => 'admin/stations/7']])]]);
        $this->assertSame('/daily/stations/7', HubNavigation::backUrl('/admin/stations'));

        $request->headers->set('referer', 'https://evil.test/admin/stations/7?return_to=%2Fdaily%2Fstations%2F7');
        $this->assertSame('/admin/stations', HubNavigation::backUrl('/admin/stations'));
    }

    public function test_native_contextual_back_uses_resource_parent_and_excludes_owned_communications(): void
    {
        $this->navigationRequest();
        filament()->setCurrentPanel(filament()->getPanel('admin'));
        $this->assertSame(['url' => '/admin/stations', 'label' => 'Back to stations'], ContextualBack::destination([\App\Filament\Resources\StationResource\Pages\EditStation::class]));
        $this->assertNull(ContextualBack::destination([\App\Filament\Resources\StationResource\Pages\ListStations::class]));
        $this->assertNull(ContextualBack::destination([\App\Filament\Resources\OutboundEmailResource\Pages\ViewOutboundEmail::class]));
        $this->assertNull(ContextualBack::destination([\App\Filament\Pages\ComposeEmail::class]));
        $this->assertNull(ContextualBack::destination([\App\Filament\Workgroup\Pages\Notes::class]));
        $this->assertNull(ContextualBack::destination([\App\Filament\Workgroup\Pages\AdminDashboard::class]));
        $html = view('filament.partials.contextual-back', ['destination' => ['url' => '/admin/stations?page=3', 'label' => 'Back to stations']])->render();
        $this->assertStringContainsString('href="/admin/stations?page=3"', $html);
        $this->assertStringContainsString('fi-btn', $html);
    }

    public function test_public_header_keeps_parent_label_for_rejected_explicit_return(): void
    {
        $this->navigationRequest('https://hub.test/support/issues/create?return_to=https%3A%2F%2Fevil.test');
        $html = \Illuminate\Support\Facades\Blade::render('<x-hub-header back-href="/support/issues" back-label="My Reports" />');
        $this->assertStringContainsString('href="/support/issues"', $html);
        $this->assertStringContainsString('aria-label="My Reports"', $html);
        $this->assertStringContainsString('data-hub-back', $html);
        $this->assertStringContainsString('aria-hidden="true"', $html);
        $this->assertStringNotContainsString('evil.test', $html);
    }
}
