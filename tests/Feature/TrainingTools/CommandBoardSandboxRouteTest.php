<?php

declare(strict_types=1);

namespace Tests\Feature\TrainingTools;

use App\Enums\AccountStatus;
use App\Http\Controllers\Training\CommandBoardSandboxController;
use App\Models\Employee;
use App\Models\User;
use App\Services\Security\Command300Access;
use App\Support\Home\HomeQuickActions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Role;
use Tests\Support\TrainingSandboxFixture;
use Tests\TestCase;

/**
 * The Hub route that hosts the Command Board training sandbox: every signed-in member, one closed policy, a friendly 503
 * when the bundle is not there. Runs against tests/fixtures/training-sandbox/dist, never the real vendored bundle.
 */
final class CommandBoardSandboxRouteTest extends TestCase
{
    use RefreshDatabase;

    private const PATH = '/training-tools/command-board';

    private const CSP = "default-src 'none'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self' data:; connect-src 'none'; worker-src 'none'; manifest-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'";

    private TrainingSandboxFixture $bundle;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->bundle = new TrainingSandboxFixture;
        $this->bundle->useIn($this->app['config']);
    }

    protected function tearDown(): void
    {
        $this->bundle->destroy();
        parent::tearDown();
    }

    // ---- who may open it --------------------------------------------------------------------------

    public function test_the_route_sits_beside_the_training_panel_not_inside_it(): void
    {
        self::assertSame(self::PATH, route('training.command-board', absolute: false));
        self::assertSame('/training', route('filament.training.pages.dashboard', absolute: false));
        foreach (Route::getRoutes()->getRoutes() as $route) {
            self::assertNotSame('training/command-board', $route->uri(), 'The Training panel path is left alone.');
        }
    }

    public function test_a_signed_out_visitor_is_sent_to_sign_in_and_sees_nothing(): void
    {
        $response = $this->get(self::PATH);

        $response->assertRedirect();
        self::assertSame('/login', parse_url((string) $response->headers->get('Location'), PHP_URL_PATH));
        self::assertStringNotContainsString(TrainingSandboxFixture::MARKER, (string) $response->getContent());
    }

    public function test_an_ordinary_firefighter_gets_the_page_with_the_closed_policy_and_no_extra_login(): void
    {
        $this->signIn($this->member('Firefighter'));

        $response = $this->get(self::PATH)->assertOk();

        self::assertSame(self::CSP, CommandBoardSandboxController::CONTENT_SECURITY_POLICY);
        self::assertSame(self::CSP, $response->headers->get('Content-Security-Policy'), 'No global policy replaces or merges into it.');
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame('same-origin', $response->headers->get('Referrer-Policy'));
        self::assertSame('microphone=(), camera=(), geolocation=()', $response->headers->get('Permissions-Policy'));
        self::assertStringStartsWith('text/html', (string) $response->headers->get('Content-Type'));
        self::assertSame($this->bundle->page(), $response->getContent(), 'The page is served byte for byte, with nothing injected.');
        self::assertCount(1, $response->headers->all('Content-Security-Policy'));
    }

    public function test_a_captain_a_chief_and_an_administrator_get_it_too(): void
    {
        $administrator = $this->member('Firefighter');
        $administrator->assignRole(Role::findOrCreate('super_admin', 'web'));

        foreach ([$this->member('Captain'), $this->member('Division Chief'), $administrator] as $user) {
            $this->signIn($user->fresh());
            $this->get(self::PATH)->assertOk()->assertSee(TrainingSandboxFixture::MARKER, false);
        }
    }

    public function test_an_account_without_a_personnel_profile_gets_it_like_home_quick_access_does(): void
    {
        $this->signIn(User::factory()->create(['account_status' => AccountStatus::Active, 'must_change_password' => false]));

        $this->get(self::PATH)->assertOk()->assertSee(TrainingSandboxFixture::MARKER, false);
    }

    public function test_a_disabled_account_is_refused(): void
    {
        $user = $this->member('Firefighter');
        $this->signIn($user);
        $this->get(self::PATH)->assertOk();

        $user->forceFill(['account_status' => AccountStatus::Disabled])->save();
        $response = $this->get(self::PATH);

        $response->assertRedirect();
        self::assertSame('/login', parse_url((string) $response->headers->get('Location'), PHP_URL_PATH));
        self::assertStringNotContainsString(TrainingSandboxFixture::MARKER, (string) $response->getContent());
    }

    public function test_an_account_that_has_not_been_activated_is_refused(): void
    {
        $user = $this->member('Firefighter');
        $this->signIn($user);
        $user->forceFill(['account_status' => AccountStatus::PendingActivation])->save();

        $this->get(self::PATH)->assertRedirect();
    }

    public function test_a_member_who_must_still_change_their_password_is_held_at_that_step(): void
    {
        $this->signIn($this->member('Firefighter', mustChangePassword: true));

        $response = $this->get(self::PATH);

        $response->assertRedirect();
        self::assertStringNotContainsString(TrainingSandboxFixture::MARKER, (string) $response->getContent());
    }

    // ---- what it serves ---------------------------------------------------------------------------

    public function test_the_query_string_changes_nothing(): void
    {
        $this->signIn($this->member('Firefighter'));
        $plain = $this->get(self::PATH)->assertOk();

        $noisy = $this->get(self::PATH.'?path=../../.env&file=index.html&debug=1&scenario=live&mode=command')->assertOk();

        self::assertSame($plain->getContent(), $noisy->getContent());
        self::assertSame($plain->headers->get('Content-Security-Policy'), $noisy->headers->get('Content-Security-Policy'));
        self::assertSame($plain->headers->get('Cache-Control'), $noisy->headers->get('Cache-Control'));
    }

    public function test_only_get_is_accepted_and_no_path_beyond_the_page_exists(): void
    {
        $this->signIn($this->member('Firefighter'));

        $this->post(self::PATH)->assertStatus(405);
        $this->get(self::PATH.'/index.html')->assertNotFound();
        $this->get(self::PATH.'/assets/'.$this->bundle->assetNames()[0])->assertNotFound();
        $this->get('/training-tools')->assertNotFound();
    }

    public function test_the_response_carries_no_command_data_and_grants_no_command_access(): void
    {
        config(['services.command_board.url' => 'https://cmd.mbfdhub.com']);
        $user = $this->member('Firefighter');
        $this->signIn($user);

        $content = (string) $this->get(self::PATH)->assertOk()->getContent();

        self::assertStringNotContainsString('cmd.mbfdhub.com', $content);
        self::assertStringNotContainsString('Command300', $content);
        self::assertStringNotContainsString('csrf-token', $content);
        self::assertStringNotContainsString('window.__MBFD', $content);
        $fresh = $user->fresh();
        self::assertFalse(app(Command300Access::class)->allows($fresh), 'Opening the sandbox grants nothing.');
        self::assertNull(HomeQuickActions::commandBoardUrl($fresh), 'The live board button stays hidden for this member.');
        self::assertCount(0, $fresh->getAllPermissions());
        self::assertSame(0, $fresh->roles()->count());
        $this->assertDatabaseCount('oidc_sessions', 0);
    }

    public function test_a_member_without_300_access_still_cannot_reach_the_live_board_through_home_or_the_policy(): void
    {
        config(['services.command_board.url' => 'https://cmd.mbfdhub.com']);
        $this->signIn($this->member('Firefighter'));

        $home = $this->get('/')->assertOk()->assertDontSee('data-home-command-board', false)->assertDontSee('href="https://cmd.mbfdhub.com"', false);
        self::assertStringContainsString('href="'.route('training.command-board').'"', (string) $home->getContent());
        // The policy cannot reach the live board's origin even if the page tried: connections are closed outright.
        self::assertStringContainsString("connect-src 'none'", CommandBoardSandboxController::CONTENT_SECURITY_POLICY);
        self::assertStringNotContainsString('cmd.mbfdhub.com', CommandBoardSandboxController::CONTENT_SECURITY_POLICY);
    }

    public function test_other_pages_keep_the_default_policy_and_referrer_rules(): void
    {
        $this->signIn($this->member('Firefighter'));

        $home = $this->get('/')->assertOk();

        self::assertSame('strict-origin-when-cross-origin', $home->headers->get('Referrer-Policy'));
        self::assertStringContainsString("default-src 'self'", (string) $home->headers->get('Content-Security-Policy'));
        self::assertStringContainsString("script-src 'self' 'unsafe-inline'", (string) $home->headers->get('Content-Security-Policy'));
    }

    // ---- when the bundle is not there -------------------------------------------------------------

    public function test_a_missing_bundle_shows_the_friendly_page_with_a_way_back(): void
    {
        config([
            'training-tools.command_board.html_directory' => $this->bundle->htmlDirectory.'-absent',
            'training-tools.command_board.assets_directory' => $this->bundle->assetsDirectory.'-absent',
        ]);
        $this->signIn($this->member('Firefighter'));

        $response = $this->get(self::PATH);

        $this->assertFriendlyUnavailable($response);
    }

    public function test_a_page_that_no_longer_matches_its_manifest_is_not_served(): void
    {
        file_put_contents($this->bundle->htmlDirectory.'/index.html', $this->bundle->page().'<!-- edited -->');
        $this->signIn($this->member('Firefighter'));

        $this->assertFriendlyUnavailable($this->get(self::PATH));
    }

    public function test_a_missing_or_resized_asset_is_not_served(): void
    {
        $this->signIn($this->member('Firefighter'));
        $asset = $this->bundle->assetsDirectory.'/'.$this->bundle->assetNames()[0];
        $original = (string) file_get_contents($asset);

        file_put_contents($asset, $original.'x');
        $this->assertFriendlyUnavailable($this->get(self::PATH));

        unlink($asset);
        $this->assertFriendlyUnavailable($this->get(self::PATH));
    }

    public function test_a_broken_or_hostile_manifest_is_not_served(): void
    {
        $this->signIn($this->member('Firefighter'));
        $manifest = $this->bundle->htmlDirectory.'/SANDBOX_SOURCE.json';

        file_put_contents($manifest, '{not json');
        $this->assertFriendlyUnavailable($this->get(self::PATH));

        $page = $this->bundle->entry('index.html', $this->bundle->htmlDirectory.'/index.html');
        foreach (['assets/../../.env', '../index.html', '/etc/passwd', 'assets/sub/x.js', 'assets/'] as $path) {
            $this->bundle->writeManifest([$page, ['path' => $path, 'sha256' => str_repeat('0', 64), 'bytes' => 1]]);
            $this->assertFriendlyUnavailable($this->get(self::PATH));
        }

        $this->bundle->writeManifest([$page]);
        $this->assertFriendlyUnavailable($this->get(self::PATH));
    }

    public function test_the_unavailable_page_never_shows_a_stack_trace_or_the_closed_policy(): void
    {
        config(['app.debug' => true]);
        unlink($this->bundle->htmlDirectory.'/SANDBOX_SOURCE.json');
        $this->signIn($this->member('Firefighter'));

        $response = $this->get(self::PATH);

        $this->assertFriendlyUnavailable($response);
        $content = (string) $response->getContent();
        foreach (['Stack trace', 'SandboxBundleUnavailable', 'Illuminate\\', 'vendor/', 'SANDBOX_SOURCE'] as $leak) {
            self::assertStringNotContainsString($leak, $content);
        }
        self::assertNotSame(self::CSP, $response->headers->get('Content-Security-Policy'), 'The Hub-styled page uses the Hub policy.');
    }

    // ---- helpers ----------------------------------------------------------------------------------

    private function assertFriendlyUnavailable(\Illuminate\Testing\TestResponse $response): void
    {
        $response->assertStatus(503)
            ->assertSee('The Command Board Sandbox is not available right now')
            ->assertSee('Return to Hub home')
            ->assertSee('href="'.url('/').'"', false)
            ->assertDontSee(TrainingSandboxFixture::MARKER, false);
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
    }

    private function signIn(User $user): void
    {
        $this->actingAsCanonicalUser($user);
    }

    private function member(string $rank, bool $mustChangePassword = false): User
    {
        $this->sequence++;
        $employee = Employee::query()->create([
            'employee_id' => 'E-SBX-'.$this->sequence, 'name' => 'Sandbox Member '.$this->sequence, 'rank' => $rank,
            'password' => 'not-used-by-tests', 'must_change_password' => false,
        ]);

        return User::factory()->create([
            'account_status' => AccountStatus::Active, 'must_change_password' => $mustChangePassword,
            'employee_profile_id' => $employee->id, 'employee_id' => $employee->employee_id,
        ]);
    }
}
