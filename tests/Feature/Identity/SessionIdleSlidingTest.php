<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Enums\AccountStatus;
use App\Enums\SessionContextClass;
use App\Models\AuthenticationSession;
use App\Models\User;
use App\Services\Identity\SessionRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The idle limit slides with use. It used to be fixed at sign-in time, so every session ended exactly one hour
 * after login (found on production: a rescue lieutenant was signed out mid-form and his request never saved).
 */
final class SessionIdleSlidingTest extends TestCase
{
    use RefreshDatabase;

    private const IDLE = 14400;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('security.canonical_session.unmanaged_idle_seconds', self::IDLE);
    }

    private function sessionAt(Carbon $issuedAt, ?Carbon $absolute = null): array
    {
        $user = User::factory()->create(['account_status' => AccountStatus::Active]);
        $registry = app(SessionRegistry::class);
        // The shape every session on production had before the fix: idle one hour after sign-in.
        $session = $registry->register(
            $user, 'raw-session-'.$user->id, SessionContextClass::UnmanagedBrowser,
            $issuedAt, $issuedAt->copy()->addHour(), $absolute ?? $issuedAt->copy()->addDay(),
        );

        return [$user, $session, $registry];
    }

    public function test_use_pushes_the_idle_deadline_out_so_a_working_member_is_not_signed_out_after_an_hour(): void
    {
        $issued = Carbon::parse('2026-10-30 13:00:00 UTC');
        [$user, $session, $registry] = $this->sessionAt($issued);

        $this->assertFalse($registry->isCurrent($user, $session, $issued->copy()->addMinutes(65)), 'without activity the old deadline still holds');

        $registry->recordActivity($session, $issued->copy()->addMinutes(50));
        $session->refresh();

        $this->assertTrue($registry->isCurrent($user, $session, $issued->copy()->addMinutes(65)));
        $this->assertTrue($registry->isCurrent($user, $session, $issued->copy()->addMinutes(50)->addSeconds(self::IDLE - 1)));
        $this->assertSame($issued->copy()->addMinutes(50)->toIso8601String(), $session->last_activity_at->toIso8601String());
        $this->assertSame($issued->copy()->addMinutes(50)->addSeconds(self::IDLE)->toIso8601String(), $session->idle_expires_at->toIso8601String());
    }

    public function test_a_session_left_alone_still_ends_after_the_idle_window(): void
    {
        $issued = Carbon::parse('2026-10-30 13:00:00 UTC');
        [$user, $session, $registry] = $this->sessionAt($issued);
        $registry->recordActivity($session, $issued->copy()->addMinutes(50));
        $session->refresh();

        $this->assertFalse($registry->isCurrent($user, $session, $issued->copy()->addMinutes(50)->addSeconds(self::IDLE + 1)));
    }

    public function test_the_row_is_written_at_most_once_a_minute(): void
    {
        $issued = Carbon::parse('2026-10-30 13:00:00 UTC');
        [, $session, $registry] = $this->sessionAt($issued);

        $registry->recordActivity($session, $issued->copy()->addMinutes(10));
        $registry->recordActivity($session, $issued->copy()->addMinutes(10)->addSeconds(30));
        $this->assertSame($issued->copy()->addMinutes(10)->toIso8601String(), $session->refresh()->last_activity_at->toIso8601String());

        $registry->recordActivity($session, $issued->copy()->addMinutes(11)->addSeconds(1));
        $this->assertSame($issued->copy()->addMinutes(11)->addSeconds(1)->toIso8601String(), $session->refresh()->last_activity_at->toIso8601String());
    }

    public function test_the_deadline_never_passes_the_absolute_limit(): void
    {
        $issued = Carbon::parse('2026-10-30 13:00:00 UTC');
        [$user, $session, $registry] = $this->sessionAt($issued, $issued->copy()->addHours(3));

        $registry->recordActivity($session, $issued->copy()->addHours(2));
        $session->refresh();

        $this->assertSame($issued->copy()->addHours(3)->toIso8601String(), $session->idle_expires_at->toIso8601String());
        $this->assertFalse($registry->isCurrent($user, $session, $issued->copy()->addHours(3)->addSecond()));
    }

    public function test_the_deadline_never_moves_backwards(): void
    {
        $issued = Carbon::parse('2026-10-30 13:00:00 UTC');
        [, $session, $registry] = $this->sessionAt($issued);
        $registry->recordActivity($session, $issued->copy()->addMinutes(5));
        $far = $session->refresh()->idle_expires_at->copy();

        config()->set('security.canonical_session.unmanaged_idle_seconds', 600);
        $registry->recordActivity($session, $issued->copy()->addMinutes(20));

        $this->assertSame($far->toIso8601String(), $session->refresh()->idle_expires_at->toIso8601String());
    }

    public function test_a_revoked_session_is_never_revived_by_activity(): void
    {
        $issued = Carbon::parse('2026-10-30 13:00:00 UTC');
        [$user, $session, $registry] = $this->sessionAt($issued);
        $registry->revoke($user, (string) $session->getKey(), 'logout', $issued->copy()->addMinutes(5));

        $registry->recordActivity($session, $issued->copy()->addMinutes(10));
        $session->refresh();

        $this->assertNotNull($session->revoked_at);
        $this->assertSame($issued->copy()->addHour()->toIso8601String(), $session->idle_expires_at->toIso8601String());
        $this->assertFalse($registry->isCurrent($user, $session, $issued->copy()->addMinutes(10)));
    }

    public function test_real_requests_keep_a_working_member_signed_in_past_the_old_one_hour_cut_off(): void
    {
        Route::middleware('web')->get('/idle-sliding-probe', fn () => response()->noContent());
        $this->actingAsCanonicalFixture('SLIDE-001', 'Sliding Test', 'Lieutenant');
        $signedInAt = Carbon::now();

        $this->getJson('/idle-sliding-probe')->assertNoContent();
        $this->travel(40)->minutes();
        $this->getJson('/idle-sliding-probe')->assertNoContent();
        $this->travel(40)->minutes(); // 80 minutes after sign-in: the old fixed deadline passed 20 minutes ago
        $this->getJson('/idle-sliding-probe')->assertNoContent();
        $this->travel(40)->minutes();
        $this->getJson('/idle-sliding-probe')->assertNoContent();

        $session = AuthenticationSession::query()->sole();
        $this->assertNull($session->revoked_at);
        $this->assertTrue($session->idle_expires_at->greaterThan($signedInAt->copy()->addHours(5)), 'the deadline moved well past sign-in plus one hour');
    }

    public function test_a_session_with_no_requests_for_the_idle_window_is_ended_and_recorded(): void
    {
        Route::middleware('web')->get('/idle-sliding-probe', fn () => response()->noContent());
        $this->actingAsCanonicalFixture('SLIDE-002', 'Sliding Test C', 'Lieutenant');

        $this->getJson('/idle-sliding-probe')->assertNoContent();
        $this->travel(self::IDLE + 120)->seconds();
        $this->getJson('/idle-sliding-probe')->assertUnauthorized();

        $this->assertSame('canonical session no longer current', AuthenticationSession::query()->latest('id')->value('revoked_reason'));
    }
}
