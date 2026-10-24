<?php

declare(strict_types=1);

namespace Tests\Feature\Pwa;

use App\Models\User;
use App\Support\Release\DeployedRelease;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppReleaseEndpointTest extends TestCase
{
    use RefreshDatabase;

    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir().'/mbfd-app-release-'.bin2hex(random_bytes(8));
        mkdir($this->basePath.'/storage/app/private', 0777, true);
        $this->app->instance(DeployedRelease::class, new DeployedRelease($this->basePath));
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_it_reports_the_first_twelve_characters_of_the_deploy_marker_when_the_image_has_no_snapshot(): void
    {
        file_put_contents($this->basePath.'/storage/app/private/deploy-marker.json', json_encode([
            'sha' => 'ABCDEF0123456789ABCDEF0123456789ABCDEF01',
        ], JSON_THROW_ON_ERROR));

        $this->getJson('/app-release')
            ->assertOk()
            ->assertExactJson(['release' => 'abcdef012345']);
    }

    public function test_the_served_build_wins_over_a_stale_deploy_marker(): void
    {
        // During the deploy window (and after a rollback) the marker on the host mount still names the
        // previous release; the code answering the request is the image's .git-sha. (The X-App-Commit
        // proof header keeps marker-first semantics; AddBuildHeadersTest pins that.)
        file_put_contents($this->basePath.'/.git-sha', str_repeat('a', 40));
        file_put_contents($this->basePath.'/storage/app/private/deploy-marker.json', json_encode([
            'sha' => 'ABCDEF0123456789ABCDEF0123456789ABCDEF01',
        ], JSON_THROW_ON_ERROR));

        $this->getJson('/app-release')
            ->assertOk()
            ->assertExactJson(['release' => 'aaaaaaaaaaaa']);
        $this->assertStringContainsString(
            'aaaaaaaaaaaa',
            view('components.hub.head')->render(),
            'the meta tag names the same build /app-release does',
        );
    }

    public function test_it_falls_back_to_the_source_snapshot(): void
    {
        file_put_contents($this->basePath.'/.git-sha', "0123456789abcdef0123456789abcdef01234567\n");

        $this->getJson('/app-release')->assertOk()->assertExactJson(['release' => '0123456789ab']);
    }

    public function test_it_reports_unknown_when_no_identity_exists_or_it_is_malformed(): void
    {
        $this->getJson('/app-release')->assertOk()->assertExactJson(['release' => 'unknown']);

        file_put_contents($this->basePath.'/.git-sha', 'not-a-sha');
        file_put_contents($this->basePath.'/storage/app/private/deploy-marker.json', '{partial');

        $this->getJson('/app-release')->assertOk()->assertExactJson(['release' => 'unknown']);
    }

    public function test_the_response_is_never_cacheable_and_starts_no_session(): void
    {
        file_put_contents($this->basePath.'/.git-sha', str_repeat('b', 40));

        $response = $this->get('/app-release')->assertOk();

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame([], $response->headers->getCookies(), 'a public release probe must not set cookies');
        $this->assertNotNull($response->headers->get('X-App-Commit'), 'the shared build header still applies');
    }

    public function test_a_signed_in_member_with_a_pending_password_change_is_not_redirected(): void
    {
        file_put_contents($this->basePath.'/.git-sha', str_repeat('c', 40));
        $member = User::factory()->create(['must_change_password' => true]);

        $this->actingAs($member)->getJson('/app-release')
            ->assertOk()
            ->assertExactJson(['release' => 'cccccccccccc']);
    }

    public function test_the_per_address_limit_tolerates_a_whole_station_behind_one_address_but_stays_bounded(): void
    {
        // Station Wi-Fi and the city network present many devices as one address, and every device
        // probes on return to the app and when it comes back online.
        $route = app('router')->getRoutes()->getByName('app-release');
        $this->assertNotNull($route);
        $this->assertContains('throttle:600,1', $route->gatherMiddleware());
        $this->assertNotContains('throttle:60,1', $route->gatherMiddleware());

        for ($attempt = 0; $attempt < 61; $attempt++) {
            $this->getJson('/app-release')->assertOk();
        }
    }

    public function test_every_hub_page_head_carries_the_same_release_identity(): void
    {
        file_put_contents($this->basePath.'/.git-sha', str_repeat('d', 40));

        $head = view('components.hub.head')->render();

        $this->assertStringContainsString('<meta name="mbfd-release" content="dddddddddddd">', $head);
    }
}
