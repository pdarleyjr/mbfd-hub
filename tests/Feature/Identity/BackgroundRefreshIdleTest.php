<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Enums\AccountStatus;
use App\Models\AuthenticationSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A page left open on a shared station must still sign out: a timer asking for updates is not a member using
 * the Hub. Only the activity timestamp stays put; every other session and account check still runs.
 */
final class BackgroundRefreshIdleTest extends TestCase
{
    use RefreshDatabase;

    private const VERSION = '/api/daily-roster/version';

    private const FRAGMENT = '/daily-roster/sections';

    private const BACKGROUND = ['X-Hub-Background-Refresh' => '1'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /** @return array<string, array{string}> */
    public static function refreshEndpoints(): array
    {
        return ['version poll' => [self::VERSION], 'roster fragment' => [self::FRAGMENT]];
    }

    private function registered(): AuthenticationSession
    {
        return AuthenticationSession::query()->sole();
    }

    #[DataProvider('refreshEndpoints')]
    public function test_a_background_refresh_leaves_the_idle_clock_where_it_is(string $url): void
    {
        $this->actingAsCanonicalFixture();
        $before = $this->registered();

        $this->travel(5)->minutes();
        $this->get($url, self::BACKGROUND)->assertOk();

        $after = $this->registered();
        self::assertSame($before->last_activity_at->toIso8601String(), $after->last_activity_at->toIso8601String());
        self::assertSame($before->idle_expires_at->toIso8601String(), $after->idle_expires_at->toIso8601String());
    }

    #[DataProvider('refreshEndpoints')]
    public function test_the_same_request_without_the_header_is_activity(string $url): void
    {
        $this->actingAsCanonicalFixture();
        $before = $this->registered();

        $this->travel(5)->minutes();
        $this->get($url)->assertOk();

        $after = $this->registered();
        self::assertTrue($after->last_activity_at->greaterThan($before->last_activity_at));
        self::assertTrue($after->idle_expires_at->greaterThan($before->idle_expires_at));
    }

    #[DataProvider('refreshEndpoints')]
    public function test_only_the_exact_header_value_is_background(string $url): void
    {
        $this->actingAsCanonicalFixture();
        $before = $this->registered();

        $this->travel(5)->minutes();
        $this->get($url, ['X-Hub-Background-Refresh' => 'true'])->assertOk();

        self::assertTrue($this->registered()->last_activity_at->greaterThan($before->last_activity_at));
    }

    public function test_an_unattended_page_that_keeps_polling_is_still_signed_out_at_the_idle_limit(): void
    {
        $this->actingAsCanonicalFixture();
        $idleEnds = $this->registered()->idle_expires_at;

        foreach (range(1, 11) as $step) {
            $this->travel(5)->minutes();
            $this->getJson(self::VERSION, self::BACKGROUND)->assertOk();
            $this->get(self::FRAGMENT, self::BACKGROUND)->assertOk();
        }
        self::assertSame($idleEnds->toIso8601String(), $this->registered()->idle_expires_at->toIso8601String());

        $this->travelTo($idleEnds->addSecond());
        $this->getJson(self::VERSION, self::BACKGROUND)->assertUnauthorized();
    }

    public function test_the_fragment_of_an_idle_expired_session_goes_to_login_and_the_session_is_ended(): void
    {
        $this->actingAsCanonicalFixture();
        $this->travelTo($this->registered()->idle_expires_at->addSecond());

        $this->get(self::FRAGMENT, self::BACKGROUND)->assertRedirect('/login');

        self::assertNotNull($this->registered()->revoked_at);
    }

    public function test_the_absolute_limit_still_ends_a_session_on_a_background_refresh(): void
    {
        $this->actingAsCanonicalFixture();
        $this->registered()->forceFill(['idle_expires_at' => now()->addHours(4), 'absolute_expires_at' => now()->addHour()])->save();

        $this->travel(59)->minutes();
        $this->getJson(self::VERSION, self::BACKGROUND)->assertOk();

        $this->travel(2)->minutes();
        $this->getJson(self::VERSION, self::BACKGROUND)->assertUnauthorized();
    }

    public function test_a_disabled_account_is_refused_on_a_background_refresh(): void
    {
        $member = $this->actingAsCanonicalFixture();
        $member->forceFill(['account_status' => AccountStatus::Disabled])->save();

        $this->getJson(self::VERSION, self::BACKGROUND)->assertUnauthorized();
    }

    public function test_the_password_rule_still_applies_to_a_background_refresh(): void
    {
        $member = $this->actingAsCanonicalFixture();
        $member->forceFill(['must_change_password' => true])->save();

        $this->getJson(self::VERSION, self::BACKGROUND)->assertForbidden()->assertJsonPath('code', 'password_change_required');
    }

    public function test_a_member_without_roster_access_is_refused_on_a_background_refresh(): void
    {
        $this->actingAsCanonicalUser(User::factory()->create());

        $this->getJson(self::VERSION, self::BACKGROUND)->assertForbidden();
    }
}
