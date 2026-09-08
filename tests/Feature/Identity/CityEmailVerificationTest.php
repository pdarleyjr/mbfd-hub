<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Enums\AccountStatus;
use App\Models\CityEmailVerification;
use App\Models\CloudflareUsageBudget;
use App\Models\Employee;
use App\Models\User;
use App\Models\Workgroup;
use App\Models\WorkgroupMember;
use App\Services\Identity\CanonicalCityEmailService;
use App\Services\Identity\CityEmailVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class CityEmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    private array $tokens = [];

    private bool $deliveryFails = false;

    protected function setUp(): void
    {
        parent::setUp();
        config(['communications.cloudflare.api_token' => 'test-secret', 'communications.cloudflare.account_id' => str_repeat('a', 32)]);
        CloudflareUsageBudget::query()->create([
            'provider_account_id' => str_repeat('a', 32),
            'cycle_start' => now()->startOfMonth(), 'cycle_end' => now()->addMonth()->startOfMonth(),
            'provider_chargeable_used' => 0, 'provider_daily_quota' => 100, 'provider_daily_used' => 0,
            'hub_safe_ceiling' => 2850, 'worker_request_threshold' => 9000000, 'worker_cpu_ms_threshold' => 27000000,
            'reconciled_at' => now(), 'provider_daily_reconciled_at' => now(), 'worker_requests_used' => 0, 'worker_cpu_ms_used' => 0,
        ]);
        Http::fake(function ($request) {
            if ($this->deliveryFails) {
                return Http::response([], 503);
            }
            if (preg_match('~/verify/([a-f0-9]{64})~', $request['text'] ?? '', $match)) {
                $this->tokens[] = $match[1];
            }

            return Http::response(['result' => ['message_id' => 'fixture-message', 'queued' => $request['to']]]);
        });
    }

    public function test_acknowledgement_preserves_authoritative_identity_and_only_mailbox_proof_changes_email(): void
    {
        [$employee, $user] = $this->identity();
        $user->assignRole(Role::findOrCreate('member', 'web'));
        $group = Workgroup::query()->create(['name' => 'Preserved membership', 'created_by' => $user->id]);
        $membership = WorkgroupMember::query()->create(['workgroup_id' => $group->id, 'user_id' => $user->id, 'role' => 'facilitator', 'is_active' => true]);
        $membershipBefore = $membership->fresh()->getAttributes();
        $before = [$user->id, $employee->id, $user->password, $employee->password, $user->employee_id, $user->security_version];
        $service = app(CityEmailVerificationService::class);
        self::assertSame('nicolasdalessandro@miamibeachfl.gov', $service->candidate($user));
        self::assertTrue($service->requiresReview($user));
        $service->acknowledge($user, '  Nick.DAlessandro@MiamiBeachFL.gov ');
        self::assertFalse($service->requiresReview($user));
        self::assertSame('employee-'.$employee->id.'@canonical.mbfdhub.invalid', $user->fresh()->email);
        self::assertNull($employee->fresh()->city_email);
        self::assertSame('queued', $service->issue($user)->delivery_status);
        $token = $this->tokens[0];
        self::assertStringNotContainsString($token, json_encode(CityEmailVerification::first()->toArray(), JSON_THROW_ON_ERROR));
        self::assertSame(hash('sha256', $token), CityEmailVerification::first()->token_hash);
        self::assertTrue($service->verify($user, $token));
        self::assertFalse($service->verify($user, $token));
        self::assertSame('nick.dalessandro@miamibeachfl.gov', $user->fresh()->email);
        self::assertSame($user->fresh()->email, $employee->fresh()->city_email);
        self::assertNotNull($user->fresh()->email_verified_at);
        self::assertSame('verified', $service->status($user)->delivery_status);
        self::assertSame($before, [$user->fresh()->id, $employee->id, $user->fresh()->password, $employee->fresh()->password, $user->fresh()->employee_id, $user->fresh()->security_version]);
        self::assertSame(['member'], $user->fresh()->getRoleNames()->all());
        self::assertSame($membershipBefore, $membership->fresh()->getAttributes());
    }

    public function test_resend_rotates_tokens_and_wrong_user_expiry_security_version_and_identity_swap_fail_closed(): void
    {
        [, $user] = $this->identity();
        [, $other] = $this->identity('other');
        $service = app(CityEmailVerificationService::class);
        $service->acknowledge($user, 'nick@miamibeachfl.gov');
        $service->issue($user);
        $service->issue($user);
        self::assertFalse($service->verify($user, $this->tokens[0]));
        self::assertFalse($service->verify($other, $this->tokens[1]));
        $this->travel(31)->minutes();
        self::assertFalse($service->verify($user, $this->tokens[1]));
        $this->travelBack();
        $service->issue($user);
        $user->forceFill(['security_version' => $user->security_version + 1])->save();
        self::assertFalse($service->verify($user, $this->tokens[2]));
        self::assertTrue($service->requiresReview($user));
        $service->acknowledge($user, 'nick@miamibeachfl.gov');
        $service->issue($user);
        $user->forceFill(['employee_profile_id' => null])->save();
        self::assertFalse($service->verify($user, $this->tokens[3]));
    }

    public function test_candidate_change_and_concurrent_authoritative_edit_invalidate_old_tokens(): void
    {
        [$employee, $user] = $this->identity();
        $service = app(CityEmailVerificationService::class);
        $service->acknowledge($user, 'first@miamibeachfl.gov');
        $service->issue($user);
        $service->acknowledge($user, 'second@miamibeachfl.gov');
        self::assertFalse($service->verify($user, $this->tokens[0]));
        $service->issue($user);
        $employee->update(['city_email' => 'admin.edit@miamibeachfl.gov']);
        self::assertFalse($service->verify($user, $this->tokens[1]));
        self::assertNull($service->status($user));
        self::assertFalse($service->requiresReview($user));
    }

    public function test_failed_delivery_keeps_acknowledgement_and_authoritative_email_usable(): void
    {
        [$employee, $user] = $this->identity();
        $service = app(CityEmailVerificationService::class);
        $service->acknowledge($user, 'nick@miamibeachfl.gov');
        $this->deliveryFails = true;
        $verification = $service->issue($user);
        self::assertSame('failed', $verification->delivery_status);
        self::assertNull($verification->token_hash);
        self::assertFalse($service->requiresReview($user));
        self::assertSame('employee-'.$employee->id.'@canonical.mbfdhub.invalid', $user->fresh()->email);
        self::assertNull($employee->fresh()->city_email);
    }

    public function test_existing_city_address_is_suggested_but_not_automatically_verified_and_real_edit_clears_proof(): void
    {
        [$employee, $user] = $this->identity();
        app(CanonicalCityEmailService::class)->sync($employee, $user, 'legacy@miamibeachfl.gov');
        $service = app(CityEmailVerificationService::class);
        self::assertSame('legacy@miamibeachfl.gov', $service->candidate($user));
        self::assertFalse($service->requiresReview($user));
        self::assertNull($user->fresh()->email_verified_at);
        $service->acknowledge($user, 'legacy@miamibeachfl.gov');
        $service->issue($user);
        self::assertTrue($service->verify($user, $this->tokens[0]));
        $user->refresh()->update(['email' => 'changed@miamibeachfl.gov']);
        self::assertNull($user->fresh()->email_verified_at);
        self::assertNull($service->status($user));
        self::assertFalse($service->requiresReview($user));
    }

    public function test_case_insensitive_collision_at_verification_preserves_both_identities(): void
    {
        [, $user] = $this->identity();
        [$otherEmployee, $other] = $this->identity('other');
        $service = app(CityEmailVerificationService::class);
        $service->acknowledge($user, 'claimed@miamibeachfl.gov');
        $service->issue($user);
        app(CanonicalCityEmailService::class)->sync($otherEmployee, $other, 'CLAIMED@miamibeachfl.gov');
        self::assertFalse($service->verify($user, $this->tokens[0]));
        self::assertSame('employee-'.$user->employee_profile_id.'@canonical.mbfdhub.invalid', $user->fresh()->email);
        self::assertSame('claimed@miamibeachfl.gov', $other->fresh()->email);
    }

    public function test_foreign_employee_pair_is_rejected_by_authoritative_sync(): void
    {
        [, $user] = $this->identity();
        [$otherEmployee] = $this->identity('other');
        $this->expectException(InvalidArgumentException::class);
        app(CanonicalCityEmailService::class)->sync($otherEmployee, $user, 'forged@miamibeachfl.gov');
    }

    public function test_casefold_unique_index_blocks_raw_duplicate_email(): void
    {
        [, $user] = $this->identity();
        [, $other] = $this->identity('other');
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('users')->where('id', $other->id)->update(['email' => strtoupper($user->email)]);
    }

    public function test_completed_mailbox_proof_survives_password_version_change_and_identical_authoritative_sync(): void
    {
        [$employee, $user] = $this->identity();
        $service = app(CityEmailVerificationService::class);
        $service->acknowledge($user, 'nick@miamibeachfl.gov');
        $service->issue($user);
        self::assertTrue($service->verify($user, $this->tokens[0]));
        $user->refresh()->forceFill(['security_version' => $user->security_version + 1])->save();
        app(CanonicalCityEmailService::class)->sync($employee, $user, 'nick@miamibeachfl.gov');
        self::assertFalse($service->requiresReview($user));
        self::assertSame('verified', $service->status($user)->delivery_status);
        self::assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_old_verified_address_is_preserved_until_proof_then_receives_change_notice(): void
    {
        [$employee, $user] = $this->identity();
        $service = app(CityEmailVerificationService::class);
        $service->acknowledge($user, 'old@miamibeachfl.gov');
        $service->issue($user);
        self::assertTrue($service->verify($user, $this->tokens[0]));
        $service->acknowledge($user, 'new@miamibeachfl.gov');
        $service->issue($user);
        self::assertSame('old@miamibeachfl.gov', $user->fresh()->email);
        self::assertSame('old@miamibeachfl.gov', $employee->fresh()->city_email);
        self::assertTrue($service->verify($user, $this->tokens[1]));
        Http::assertSent(fn ($request) => $request['subject'] === 'Your MBFD Hub city email changed' && $request['to'] === ['old@miamibeachfl.gov']);
    }

    public function test_budget_failure_does_not_consume_acknowledgement_or_send(): void
    {
        [, $user] = $this->identity();
        CloudflareUsageBudget::query()->update(['provider_daily_used' => 100]);
        $service = app(CityEmailVerificationService::class);
        $service->acknowledge($user, 'nick@miamibeachfl.gov');
        self::assertSame('failed', $service->issue($user)->delivery_status);
        self::assertFalse($service->requiresReview($user));
        Http::assertNothingSent();
    }

    public function test_disabled_account_cannot_use_issued_proof(): void
    {
        [, $user] = $this->identity();
        $service = app(CityEmailVerificationService::class);
        $service->acknowledge($user, 'nick@miamibeachfl.gov');
        $service->issue($user);
        $user->forceFill(['account_status' => AccountStatus::Disabled])->save();
        self::assertFalse($service->verify($user, $this->tokens[0]));
    }

    public function test_only_the_exact_city_domain_is_accepted(): void
    {
        [, $user] = $this->identity();
        $this->expectException(InvalidArgumentException::class);
        app(CityEmailVerificationService::class)->acknowledge($user, 'nick@miamibeachfl.gov.attacker.test');
    }

    public function test_migration_refuses_existing_case_collisions_without_deleting_records(): void
    {
        [, $user] = $this->identity();
        [, $other] = $this->identity('other');
        DB::statement('DROP INDEX users_email_casefold_unique');
        DB::table('users')->where('id', $other->id)->update(['email' => strtoupper($user->email)]);
        $migration = require database_path('migrations/2026_09_08_120000_create_city_email_verifications.php');
        try {
            $migration->up();
            self::fail('Existing case collisions must block migration.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('collisions require identity review', $exception->getMessage());
        }
        self::assertSame(2, User::query()->count());
        self::assertSame(strtoupper($user->email), $other->fresh()->email);
    }

    public function test_direct_employee_city_email_change_invalidates_existing_proof(): void
    {
        [$employee, $user] = $this->identity();
        $service = app(CityEmailVerificationService::class);
        $service->acknowledge($user, 'nick@miamibeachfl.gov');
        $service->issue($user);
        self::assertTrue($service->verify($user, $this->tokens[0]));
        $employee->refresh()->update(['city_email' => 'admin@miamibeachfl.gov']);
        self::assertNull($user->fresh()->email_verified_at);
        self::assertNull($service->status($user));
        self::assertFalse($service->requiresReview($user));
    }

    public function test_connected_email_policy_rejects_internal_reserved_and_unusable_addresses_without_dns_or_mail(): void
    {
        [, $user] = $this->identity();
        $service = app(CityEmailVerificationService::class);
        foreach ([
            'employee-1@canonical.mbfdhub.invalid', 'member@example.test', 'member@docs.example',
            'member@host.localhost', 'member@localhost', 'member@example.com', 'member@example.org',
            'member@example.net', 'member@[127.0.0.1]', 'not-an-email',
            'member@staff.example.com', 'member@staff.example.org', 'member@staff.example.net',
        ] as $email) {
            $user->forceFill(['email' => $email])->save();
            self::assertNull($service->connectedEmail($user), $email);
            self::assertTrue($service->requiresReview($user), $email);
        }
        $user->forceFill(['email' => 'member@exampleservices.com'])->save();
        self::assertSame('member@exampleservices.com', $service->connectedEmail($user));
        self::assertFalse($service->requiresReview($user));
        Http::assertNothingSent();
        self::assertSame(0, CityEmailVerification::query()->count());
        self::assertNull($user->fresh()->email_verified_at);
    }

    public function test_existing_roster_address_and_optional_pending_change_are_exempt_without_marking_mailbox_verified(): void
    {
        [$employee, $user] = $this->identity();
        $employee->update(['city_email' => 'existing.roster@outlook.com']);
        $service = app(CityEmailVerificationService::class);
        self::assertSame('existing.roster@outlook.com', $service->connectedEmail($user));
        self::assertFalse($service->requiresReview($user));
        $service->acknowledge($user, 'replacement@miamibeachfl.gov');
        self::assertFalse($service->requiresReview($user));
        self::assertSame('pending', $service->status($user)->delivery_status);
        self::assertSame('existing.roster@outlook.com', $employee->fresh()->city_email);
        self::assertNull($user->fresh()->email_verified_at);
        $user->forceFill(['security_version' => $user->security_version + 1])->save();
        self::assertNull($service->status($user));
        self::assertFalse($service->requiresReview($user));
        Http::assertNothingSent();
    }

    /** @return array{Employee, User} */
    private function identity(string $suffix = ''): array
    {
        $employee = Employee::query()->create(['employee_id' => 'test-'.$suffix, 'name' => 'Nicolas D’Alessandro', 'password' => 'employee-fixture', 'must_change_password' => false]);
        $user = User::factory()->create(['name' => $employee->name, 'employee_id' => $employee->employee_id, 'employee_profile_id' => $employee->id, 'account_status' => AccountStatus::Active, 'email' => 'employee-'.$employee->id.'@canonical.mbfdhub.invalid', 'email_verified_at' => null]);

        return [$employee, $user];
    }
}
