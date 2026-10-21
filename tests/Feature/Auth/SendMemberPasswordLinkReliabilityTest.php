<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\AccountStatus;
use App\Jobs\SendMemberPasswordLink;
use App\Models\CloudflareUsageBudget;
use App\Models\Employee;
use App\Models\OutboundEmail;
use App\Models\User;
use App\Services\Communications\CloudflareEmailDispatcher;
use App\Services\Identity\CityEmailVerificationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

final class SendMemberPasswordLinkReliabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('identity.credential_authority', 'local');
        config()->set('communications.cloudflare.account_id', str_repeat('a', 32));
        config()->set('communications.cloudflare.api_token', 'password-link-test-token');
        $now = CarbonImmutable::now();
        CloudflareUsageBudget::query()->create([
            'provider_account_id' => str_repeat('a', 32),
            'cycle_start' => $now->startOfMonth(),
            'cycle_end' => $now->addMonth()->startOfMonth(),
            'provider_chargeable_used' => 0,
            'provider_daily_quota' => 100,
            'provider_daily_used' => 0,
            'hub_safe_ceiling' => 100,
            'worker_request_threshold' => 9_000_000,
            'worker_cpu_ms_threshold' => 27_000_000,
            'reconciled_at' => $now,
            'provider_daily_reconciled_at' => $now,
            'worker_requests_used' => 0,
            'worker_cpu_ms_used' => 0,
        ]);
        $this->fakeProvider(fn (Request $request) => Http::response([
            'success' => true,
            'result' => ['message_id' => 'password-link-test-message', 'delivered' => $request['to']],
        ]));
    }

    public function test_temporary_budget_refusal_releases_the_job_and_retries_with_a_fresh_link(): void
    {
        $member = $this->member('BUDGET');
        $job = (new SendMemberPasswordLink($member->id, $member->security_version))->withFakeQueueInteractions();
        config()->set('communications.cloudflare.max_recipient_units_per_minute', 0);

        $this->runJob($job);

        $job->assertReleased(61);
        self::assertSame('failed_pre_acceptance', OutboundEmail::query()->sole()->status);
        self::assertDatabaseCount('password_reset_tokens', 0);
        Http::assertNothingSent();

        config()->set('communications.cloudflare.max_recipient_units_per_minute', 5);
        $this->travel(61)->seconds();
        $this->runJob($job);

        Http::assertSentCount(1);
        self::assertDatabaseCount('outbound_emails', 2);
        self::assertTrue(Password::broker()->tokenExists($member->fresh(), $this->sentToken()));
    }

    public function test_permanent_budget_refusal_does_not_release_or_leave_an_unsent_token(): void
    {
        $member = $this->member('CEILING');
        $job = (new SendMemberPasswordLink($member->id, $member->security_version))->withFakeQueueInteractions();
        config()->set('communications.cloudflare.safe_email_ceiling', 0);

        $this->runJob($job);

        $job->assertNotReleased();
        self::assertDatabaseCount('password_reset_tokens', 0);
        Http::assertNothingSent();
    }

    public function test_unknown_provider_acceptance_preserves_the_link_and_blocks_automatic_resends(): void
    {
        $member = $this->member('UNKNOWN');
        $job = (new SendMemberPasswordLink($member->id, $member->security_version))->withFakeQueueInteractions();
        $token = null;
        $submissions = 0;
        $this->fakeProvider(function (Request $request) use (&$token, &$submissions) {
            $token = $this->tokenFrom((string) $request['text']);
            $submissions++;
            throw new ConnectionException('Provider response was lost.');
        });

        $this->runJob($job);

        self::assertSame('acceptance_unknown', OutboundEmail::query()->sole()->status);
        self::assertTrue(Password::broker()->tokenExists($member->fresh(), $token));
        $job->assertNotReleased();
        $this->travel(61)->seconds();
        $this->runJob($job);
        $this->runJob(new SendMemberPasswordLink($member->id, $member->security_version));

        self::assertSame(1, $submissions);
        self::assertDatabaseCount('outbound_emails', 1);
        self::assertTrue(Password::broker()->tokenExists($member->fresh(), $token));
    }

    public function test_worker_reentry_after_success_does_not_replace_the_delivered_link(): void
    {
        $member = $this->member('REENTRY');
        $job = new SendMemberPasswordLink($member->id, $member->security_version);
        $this->runJob($job);
        $token = $this->sentToken();

        $this->travel(61)->seconds();
        $this->runJob($job);

        Http::assertSentCount(1);
        self::assertTrue(Password::broker()->tokenExists($member->fresh(), $token));
    }

    public function test_a_new_request_can_replace_an_uncertain_link_only_after_its_expiry(): void
    {
        $member = $this->member('EXPIRED-UNKNOWN');
        $job = new SendMemberPasswordLink($member->id, $member->security_version);
        $this->fakeProvider(fn () => throw new ConnectionException('Provider response was lost.'));
        $this->runJob($job);

        $this->travel(61)->minutes();
        CloudflareUsageBudget::query()->update(['reconciled_at' => now(), 'provider_daily_reconciled_at' => now()]);
        $this->fakeProvider(fn (Request $request) => Http::response([
            'success' => true,
            'result' => ['message_id' => 'replacement-test-message', 'delivered' => $request['to']],
        ]));
        $this->runJob($job);
        self::assertDatabaseCount('outbound_emails', 1);

        $this->runJob(new SendMemberPasswordLink($member->id, $member->security_version));

        self::assertDatabaseCount('outbound_emails', 2);
        self::assertSame('delivered', OutboundEmail::query()->orderByDesc('id')->first()->status);
    }

    public function test_a_legacy_queued_job_without_new_fields_uses_its_envelope_and_does_not_resend(): void
    {
        $member = $this->member('LEGACY');
        $createdAt = CarbonImmutable::now()->getTimestamp();
        $legacyJob = $this->legacyJob($member, $createdAt);
        $deadline = $legacyJob->retryUntil()->getTimestamp();
        $this->runJob($legacyJob);
        $token = $this->sentToken();

        $this->travel(61)->seconds();
        $retried = $this->legacyJob($member, $createdAt);
        $this->runJob($retried);

        self::assertSame($createdAt + 172800, $deadline);
        self::assertSame($deadline, $retried->retryUntil()->getTimestamp());
        Http::assertSentCount(1);
        self::assertTrue(Password::broker()->tokenExists($member->fresh(), $token));
    }

    public function test_cleanup_of_a_budget_refusal_cannot_delete_a_newer_token(): void
    {
        $member = $this->member('NEWER');
        $job = (new SendMemberPasswordLink($member->id, $member->security_version))->withFakeQueueInteractions();
        $newerToken = null;
        OutboundEmail::created(function (OutboundEmail $email) use ($member, &$newerToken): void {
            if ($email->source_type === 'password_reset') {
                $newerToken = Password::broker()->createToken($member);
            }
        });
        config()->set('communications.cloudflare.max_recipient_units_per_minute', 0);

        $this->runJob($job);

        self::assertNotNull($newerToken);
        self::assertTrue(Password::broker()->tokenExists($member->fresh(), $newerToken));
        $job->assertNotReleased();
        Http::assertNothingSent();
    }

    public function test_confirmed_provider_rejection_discards_only_the_unsent_link(): void
    {
        $member = $this->member('REJECTED');
        $job = (new SendMemberPasswordLink($member->id, $member->security_version))->withFakeQueueInteractions();
        $this->fakeProvider(fn (Request $request) => Http::response([
            'success' => true,
            'result' => ['message_id' => 'rejected-test-message', 'suppressed_recipients' => $request['to']],
        ]));

        $this->runJob($job);

        self::assertSame('rejected', OutboundEmail::query()->sole()->status);
        self::assertDatabaseCount('password_reset_tokens', 0);
        $job->assertNotReleased();
    }

    public function test_changed_security_version_or_credential_authority_prevents_queued_delivery(): void
    {
        $member = $this->member('STALE');
        $job = new SendMemberPasswordLink($member->id, $member->security_version);
        $member->forceFill(['security_version' => $member->security_version + 1])->save();
        $this->runJob($job);

        $currentJob = new SendMemberPasswordLink($member->id, $member->fresh()->security_version);
        config()->set('identity.credential_authority', 'authentik');
        $this->runJob($currentJob);

        Http::assertNothingSent();
        self::assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_worker_uses_the_current_recovery_address_on_record(): void
    {
        $member = $this->member('ADDRESS');
        $job = new SendMemberPasswordLink($member->id, $member->security_version);
        $member->employeeProfile->forceFill(['city_email' => 'updated-address@miamibeachfl.gov'])->save();

        $this->runJob($job);

        self::assertSame(['updated-address@miamibeachfl.gov'], OutboundEmail::query()->sole()->to_recipients);
    }

    public function test_password_links_share_the_onboarding_queue_limit_and_have_a_fixed_retry_deadline(): void
    {
        $jobs = [];
        for ($index = 0; $index < 5; $index++) {
            $member = $this->member('LIMIT-'.$index);
            $jobs[] = (new SendMemberPasswordLink($member->id, $member->security_version))->withFakeQueueInteractions();
        }
        $deadline = $jobs[0]->retryUntil()->getTimestamp();
        foreach ($jobs as $job) {
            $job->middleware()[0]->handle($job, fn (SendMemberPasswordLink $queued) => $this->runJob($queued));
        }

        Http::assertSentCount(4);
        $jobs[4]->assertReleased();
        $this->travel(1)->day();
        self::assertSame($deadline, $jobs[0]->retryUntil()->getTimestamp());
        self::assertSame(172800, $jobs[0]->uniqueFor);
    }

    private function member(string $suffix): User
    {
        $address = strtolower($suffix).'@miamibeachfl.gov';
        $employee = Employee::query()->create([
            'employee_id' => 'PASSWORD-LINK-'.$suffix,
            'name' => 'Password Link '.$suffix,
            'roster_status' => 'active',
            'city_email' => $address,
            'password' => 'unused-legacy-password',
        ]);

        return User::factory()->create([
            'employee_profile_id' => $employee->id,
            'employee_id' => $employee->employee_id,
            'email' => $address,
            'account_status' => AccountStatus::Active,
            'security_version' => 1,
        ]);
    }

    private function runJob(SendMemberPasswordLink $job): void
    {
        $job->handle(app(CityEmailVerificationService::class), app(CloudflareEmailDispatcher::class));
    }

    private function fakeProvider(callable $callback): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake($callback);
    }

    private function legacyJob(User $member, int $createdAt): SendMemberPasswordLink
    {
        $job = (new \ReflectionClass(SendMemberPasswordLink::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($job, 'userId'))->setValue($job, $member->id);
        (new \ReflectionProperty($job, 'expectedSecurityVersion'))->setValue($job, $member->security_version);
        $job->setJob(new SyncJob($this->app, json_encode(['createdAt' => $createdAt], JSON_THROW_ON_ERROR), 'database', 'notifications'));

        return $job;
    }

    private function sentToken(): string
    {
        return $this->tokenFrom((string) Http::recorded()->sole()[0]['text']);
    }

    private function tokenFrom(string $text): string
    {
        self::assertSame(1, preg_match('#/reset-password/([^?\s]+)\?employee_id=#', $text, $matches));

        return rawurldecode($matches[1]);
    }
}
