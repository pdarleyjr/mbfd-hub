<?php

declare(strict_types=1);

namespace Tests\Feature\Pwa;

use App\Models\User;
use App\Services\Notifications\NotificationLink;
use App\Support\Host\HubHosts;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Tests\TestCase;

/**
 * A member's installed app belongs to the host it was installed from. Devices exist on both www and the apex
 * (production analytics, 2026-11-01), and a window on the apex must stay there or it opens Safari or Chrome.
 */
final class InstalledSiblingHostTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://www.mbfdhub.com']);
    }

    public function test_hosts_are_derived_from_the_configured_url(): void
    {
        $this->assertSame('www.mbfdhub.com', HubHosts::canonicalHost());
        $this->assertSame('mbfdhub.com', HubHosts::siblingHost());
        $this->assertSame('https://mbfdhub.com', HubHosts::siblingOrigin());
        $this->assertTrue(HubHosts::isHubHost('MBFDHUB.com'));
        $this->assertTrue(HubHosts::isHubHost('www.mbfdhub.com'));
        $this->assertFalse(HubHosts::isHubHost('cmd.mbfdhub.com'));
        $this->assertFalse(HubHosts::isHubHost('evilmbfdhub.com'));
        $this->assertFalse(HubHosts::isHubHost(''));
    }

    public function test_a_host_with_no_sibling_never_matches(): void
    {
        foreach (['http://localhost', 'http://127.0.0.1:8031'] as $url) {
            config(['app.url' => $url]);
            $this->assertNull(HubHosts::siblingHost(), $url);
            $this->assertFalse(HubHosts::isInstalledSiblingRequest(Request::create('https://mbfdhub.com/daily/')), $url);
        }
    }

    public function test_an_installed_window_on_the_apex_keeps_its_host_for_every_canonicalized_page(): void
    {
        foreach (['/daily/stations', '/daily-roster'] as $path) {
            $response = $this->kernelResponse($path, installed: true);
            $this->assertSame(302, $response->getStatusCode(), $path);
            $this->assertSame('https://mbfdhub.com/login', $response->headers->get('Location'), $path.' reaches sign-in on its own host');
        }
    }

    public function test_a_browser_tab_on_the_apex_still_goes_to_the_primary_host(): void
    {
        $response = $this->kernelResponse('/daily/stations?foo=bar', installed: false);

        $this->assertSame(308, $response->getStatusCode());
        $this->assertSame('https://www.mbfdhub.com/daily/stations?foo=bar', $response->headers->get('Location'));
    }

    public function test_a_person_opening_the_apex_gets_a_page_that_decides_and_never_stores_as_a_shell(): void
    {
        $request = Request::create('https://mbfdhub.com/daily/stations?foo=bar', server: ['HTTP_SEC_FETCH_MODE' => 'navigate', 'HTTP_SEC_FETCH_DEST' => 'document']);
        $response = app(HttpKernel::class)->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $body = (string) $response->getContent();
        $this->assertStringContainsString('https:\/\/www.mbfdhub.com\/daily\/stations?foo=bar', $body, 'a browser tab still reaches the same page on the primary host');
        $this->assertStringContainsString('content="0;url=https://www.mbfdhub.com/daily/stations?foo=bar"', $body, 'and so does one without scripts');
        $this->assertStringContainsString("document.cookie.indexOf('mbfd_installed_host=1') !== -1", $body, 'no reload unless the cookie stuck');
        $this->assertStringNotContainsString('<div id="root">', $body, 'the Daily worker stores only a page with this as its shell');
    }

    public function test_requests_that_are_not_a_person_opening_a_page_keep_the_plain_redirect(): void
    {
        foreach ([
            ['GET', ['HTTP_SEC_FETCH_MODE' => 'cors', 'HTTP_SEC_FETCH_DEST' => 'empty']],
            ['GET', ['HTTP_SEC_FETCH_MODE' => 'navigate', 'HTTP_SEC_FETCH_DEST' => 'iframe']],
            ['GET', []],
            ['HEAD', ['HTTP_SEC_FETCH_MODE' => 'navigate', 'HTTP_SEC_FETCH_DEST' => 'document']],
        ] as [$method, $server]) {
            $response = app(HttpKernel::class)->handle(Request::create('https://mbfdhub.com/daily/stations', $method, server: $server));
            $this->assertSame(308, $response->getStatusCode(), $method.' '.json_encode($server));
        }
    }

    public function test_the_cookie_means_nothing_on_the_primary_host_or_with_another_value(): void
    {
        $this->assertSame('https://www.mbfdhub.com/login', $this->kernelResponse('/daily/stations', installed: true, host: 'www.mbfdhub.com')->headers->get('Location'));
        $this->assertSame(308, $this->kernelResponse('/daily/stations', installed: true, value: 'yes')->getStatusCode());
    }

    public function test_the_primary_url_keeps_its_port_when_redirecting(): void
    {
        config(['app.url' => 'http://www.mbfd.localhost:8043']);

        $response = app(HttpKernel::class)->handle(Request::create('http://mbfd.localhost:8043/daily/stations'));

        $this->assertSame('http://www.mbfd.localhost:8043/daily/stations', $response->headers->get('Location'));
    }

    public function test_the_cookie_is_read_without_decryption(): void
    {
        $this->assertTrue(app(\Illuminate\Cookie\Middleware\EncryptCookies::class)->isDisabled(HubHosts::INSTALLED_COOKIE));
    }

    public function test_api_calls_from_either_host_carry_the_session(): void
    {
        // The list is computed from the environment, so evaluate the config file with the production value.
        $previous = [$_ENV['APP_URL'] ?? null, $_SERVER['APP_URL'] ?? null];
        $_ENV['APP_URL'] = $_SERVER['APP_URL'] = 'https://www.mbfdhub.com';
        try {
            $stateful = (require config_path('sanctum.php'))['stateful'];
        } finally {
            [$_ENV['APP_URL'], $_SERVER['APP_URL']] = $previous;
        }
        $this->assertContains('mbfdhub.com', $stateful);
        $this->assertContains('www.mbfdhub.com', $stateful);
        config(['sanctum.stateful' => $stateful]);

        foreach (['https://mbfdhub.com/', 'https://www.mbfdhub.com/daily/'] as $referer) {
            $request = Request::create('/api/me/context', 'GET', server: ['HTTP_REFERER' => $referer]);
            $this->assertTrue(EnsureFrontendRequestsAreStateful::fromFrontend($request), $referer);
        }
        $request = Request::create('/api/me/context', 'GET', server: ['HTTP_REFERER' => 'https://cmd.mbfdhub.com/']);
        $this->assertFalse(EnsureFrontendRequestsAreStateful::fromFrontend($request));
    }

    public function test_a_push_deep_link_is_relative_so_it_opens_on_the_devices_own_host(): void
    {
        $member = User::factory()->create();

        foreach ([
            'https://www.mbfdhub.com/updates/5?x=1#y' => '/updates/5?x=1#y',
            'https://mbfdhub.com/updates/5' => '/updates/5',
            '/updates/5' => '/updates/5',
        ] as $link => $expected) {
            $this->assertSame($expected, NotificationLink::resolve($member, $link), $link);
        }
        $this->assertNull(NotificationLink::sameOriginPath('https://cmd.mbfdhub.com/x'));
        $this->assertNull(NotificationLink::sameOriginPath('https://user@www.mbfdhub.com/x'));
        $this->assertNull(NotificationLink::sameOriginPath('//www.mbfdhub.com/x'));
        $this->assertNull(NotificationLink::sameOriginPath('https://www.mbfdhub.com//other.example/x'), 'would mean another site once it stands alone');
        $this->assertSame(NotificationLink::landing($member), NotificationLink::resolve($member, 'https://mbfdhub.com//other.example'));
    }

    public function test_the_saved_work_probe_answers_only_on_the_primary_host_and_only_for_the_sibling_frame(): void
    {
        $response = $this->get('https://www.mbfdhub.com/daily-saved-work-probe');

        $response->assertOk();
        $this->assertSame("default-src 'none'; script-src 'unsafe-inline'; frame-ancestors https://mbfdhub.com", $response->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame([], $response->headers->getCookies(), 'runs no session');
        $response->assertSee('"https:\/\/mbfdhub.com"', false);

        $this->get('https://mbfdhub.com/daily-saved-work-probe')->assertNotFound();
    }

    public function test_only_an_installed_daily_window_on_the_apex_may_frame_the_primary_host(): void
    {
        $policy = fn (string $url): string => (string) $this->withUnencryptedCookie(HubHosts::INSTALLED_COOKIE, '1')->get($url)->headers->get('Content-Security-Policy');

        $this->assertStringContainsString('https://inventory.mbfdhub.com https://www.mbfdhub.com', $policy('https://mbfdhub.com/daily/stations'));
        $this->assertStringNotContainsString('https://inventory.mbfdhub.com https://www.mbfdhub.com', $policy('https://mbfdhub.com/login'));
    }

    private function kernelResponse(string $path, bool $installed, string $host = 'mbfdhub.com', string $value = '1'): \Symfony\Component\HttpFoundation\Response
    {
        return app(HttpKernel::class)->handle(Request::create(
            "https://{$host}{$path}",
            cookies: $installed ? [HubHosts::INSTALLED_COOKIE => $value] : [],
        ));
    }
}
