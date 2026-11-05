<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Enums\AccountStatus;
use App\Enums\SessionContextClass;
use App\Models\AuthenticationSession;
use App\Models\User;
use App\Services\Identity\EstablishedAccountIntegritySnapshot;
use App\Services\Identity\SessionRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Members keep using the Hub while a release is checked. Their own sign-ins, sign-outs and sessions that
 * simply ran out are reported as session activity; any other change to a session still fails the release.
 */
final class EstablishedAccountSessionActivityTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_member_signing_in_between_the_snapshots_is_activity_not_a_change(): void
    {
        $member = $this->member();
        $before = $this->snapshots()->capture();

        $this->signIn($member);

        $result = $this->snapshots()->compare($before, $this->snapshots()->capture());
        self::assertSame(0, $result['unexpected_changes']);
        self::assertSame(1, $result['session_activity']);
        self::assertSame([], $result['changed_user_ids']);
    }

    public function test_a_member_signing_out_is_activity_not_a_change(): void
    {
        $member = $this->member();
        $session = $this->signIn($member);
        $before = $this->snapshots()->capture();

        $this->registry()->revoke($member, (string) $session->getKey(), 'logout', CarbonImmutable::now());

        $result = $this->snapshots()->compare($before, $this->snapshots()->capture());
        self::assertSame(0, $result['unexpected_changes']);
        self::assertSame(1, $result['session_activity']);
    }

    public function test_a_session_that_ran_out_and_was_closed_on_the_next_request_is_activity(): void
    {
        $member = $this->member();
        $session = $this->signIn($member, idleSeconds: 60);
        $before = $this->snapshots()->capture();

        $this->registry()->revoke($member, (string) $session->getKey(), 'canonical session no longer current', CarbonImmutable::now()->addMinutes(5));

        $result = $this->snapshots()->compare($before, $this->snapshots()->capture());
        self::assertSame(0, $result['unexpected_changes']);
        self::assertSame(1, $result['session_activity']);
    }

    public function test_a_live_session_rejected_by_the_new_release_is_a_change(): void
    {
        // A release that breaks session checks closes sessions that had not run out: never member activity.
        $member = $this->member();
        $session = $this->signIn($member);
        $before = $this->snapshots()->capture();

        $this->registry()->revoke($member, (string) $session->getKey(), 'canonical session no longer current', CarbonImmutable::now());

        $result = $this->snapshots()->compare($before, $this->snapshots()->capture());
        self::assertSame(1, $result['unexpected_changes']);
        self::assertSame(0, $result['session_activity']);
        self::assertSame([$member->id], $result['changed_user_ids']);
    }

    public function test_sessions_closed_for_any_other_reason_fail_the_release(): void
    {
        $first = $this->member();
        $second = $this->member();
        $sessions = [$this->signIn($first), $this->signIn($second)];
        $before = $this->snapshots()->capture();

        foreach ([$first, $second] as $index => $member) {
            $this->registry()->revoke($member, (string) $sessions[$index]->getKey(), 'administrative revocation', CarbonImmutable::now());
        }

        $result = $this->snapshots()->compare($before, $this->snapshots()->capture());
        self::assertSame(2, $result['unexpected_changes']);
        self::assertSame(0, $result['session_activity']);
    }

    public function test_an_existing_session_changed_in_place_fails_the_release(): void
    {
        $member = $this->member();
        $session = $this->signIn($member);
        $before = $this->snapshots()->capture();

        AuthenticationSession::query()->whereKey($session->getKey())->update(['security_version' => $member->security_version + 1]);

        $result = $this->snapshots()->compare($before, $this->snapshots()->capture());
        self::assertSame(1, $result['unexpected_changes']);
    }

    public function test_a_session_row_that_disappears_fails_the_release(): void
    {
        $member = $this->member();
        $session = $this->signIn($member);
        $before = $this->snapshots()->capture();

        AuthenticationSession::query()->whereKey($session->getKey())->delete();

        $result = $this->snapshots()->compare($before, $this->snapshots()->capture());
        self::assertSame(1, $result['unexpected_changes']);
    }

    public function test_a_new_session_that_does_not_match_the_account_fails_the_release(): void
    {
        $member = $this->member();
        $before = $this->snapshots()->capture();

        $session = $this->signIn($member);
        AuthenticationSession::query()->whereKey($session->getKey())->update(['security_version' => $member->security_version + 7]);

        $result = $this->snapshots()->compare($before, $this->snapshots()->capture());
        self::assertSame(1, $result['unexpected_changes']);
    }

    public function test_a_sign_in_never_hides_a_real_account_change(): void
    {
        $member = $this->member();
        $before = $this->snapshots()->capture();

        $this->signIn($member);
        $member->forceFill(['email' => 'changed-'.Str::random(6).'@miamibeachfl.gov'])->save();

        $result = $this->snapshots()->compare($before, $this->snapshots()->capture());
        self::assertSame(1, $result['unexpected_changes']);
        self::assertSame(1, $result['email_changes']);
    }

    public function test_a_baseline_written_before_this_change_is_still_compared_strictly(): void
    {
        $member = $this->member();
        $before = $this->snapshots()->capture();
        foreach ($before['accounts'] as $index => $account) {
            unset($before['accounts'][$index]['sessions'], $before['accounts'][$index]['persistent_logins_fingerprint']);
        }

        $this->signIn($member);

        $result = $this->snapshots()->compare($before, $this->snapshots()->capture());
        self::assertSame(1, $result['unexpected_changes']);
    }

    public function test_the_command_passes_a_sign_in_during_the_window_and_reports_it(): void
    {
        $member = $this->member();
        $baseline = storage_path('framework/testing/established-baseline-'.uniqid().'.json');
        $current = storage_path('framework/testing/established-current-'.uniqid().'.json');
        self::assertSame(Command::SUCCESS, Artisan::call('identity:established-account-integrity', ['--write' => $baseline]), Artisan::output());

        $this->signIn($member);

        $exit = Artisan::call('identity:established-account-integrity', ['--write' => $current, '--compare' => $baseline]);
        $output = Artisan::output();
        self::assertSame(Command::SUCCESS, $exit, $output);
        self::assertStringContainsString('ESTABLISHED_ACCOUNT_UNEXPECTED_CHANGES=0', $output);
        self::assertStringContainsString('ESTABLISHED_SESSION_ACTIVITY=1', $output);
    }

    private function member(): User
    {
        return User::factory()->create(['account_status' => AccountStatus::Active, 'must_change_password' => false]);
    }

    private function signIn(User $member, int $idleSeconds = 14400): AuthenticationSession
    {
        $now = CarbonImmutable::now();

        return $this->registry()->register(
            $member->fresh(),
            Str::random(40),
            SessionContextClass::UnmanagedBrowser,
            $now,
            $now->addSeconds($idleSeconds),
            $now->addHours(12),
        );
    }

    private function snapshots(): EstablishedAccountIntegritySnapshot
    {
        return app(EstablishedAccountIntegritySnapshot::class);
    }

    private function registry(): SessionRegistry
    {
        return app(SessionRegistry::class);
    }
}
