<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Enums\AccountStatus;
use App\Models\AuthenticationSession;
use App\Models\CloudflareUsageBudget;
use App\Models\Employee;
use App\Models\OutboundEmail;
use App\Models\User;
use App\Services\Identity\AccountSecurityService;
use App\Services\Identity\CityEmailVerificationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

final class EmployeePasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_unknown_employee_id_returns_the_same_generic_response_without_delivery(): void
    {
        Http::fake();

        $this->post('/forgot-password', ['employee_id' => 'UNKNOWN-100'])
            ->assertRedirect()
            ->assertSessionHas('status');

        Http::assertNothingSent();
        $this->assertDatabaseCount('outbound_emails', 0);
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('connectedAddresses')]
    public function test_active_linked_member_can_reset_through_authoritative_city_email_and_revoke_security_state(?string $employeeEmail, string $accountEmail, string $recipient): void
    {
        $now = CarbonImmutable::parse('2026-09-04T12:00:00Z');
        CarbonImmutable::setTestNow($now);
        config()->set('communications.cloudflare.account_id', str_repeat('a', 32));
        config()->set('communications.cloudflare.api_token', 'password-reset-test-token');
        CloudflareUsageBudget::query()->create([
            'provider_account_id' => str_repeat('a', 32),
            'cycle_start' => $now->startOfMonth(),
            'cycle_end' => $now->addMonth()->startOfMonth(),
            'provider_chargeable_used' => 0,
            'provider_daily_quota' => 100,
            'provider_daily_used' => 0,
            'hub_safe_ceiling' => 2850,
            'worker_request_threshold' => 9_000_000,
            'worker_cpu_ms_threshold' => 27_000_000,
            'reconciled_at' => $now,
            'provider_daily_reconciled_at' => $now,
            'worker_requests_used' => 0,
            'worker_cpu_ms_used' => 0,
        ]);
        Http::fake([
            'https://api.cloudflare.com/*' => Http::response([
                'success' => true,
                'result' => [
                    'message_id' => 'password-reset-provider-id',
                    'delivered' => [$recipient],
                    'queued' => [],
                    'permanent_bounces' => [],
                    'suppressed_recipients' => [],
                ],
            ]),
        ]);
        $employee = Employee::query()->create([
            'employee_id' => 'RESET-100',
            'name' => 'Password Reset Member',
            'city_email' => $employeeEmail,
            'password' => 'unrelated-legacy-password',
        ]);
        $user = User::factory()->create([
            'email' => $accountEmail,
            'employee_id' => $employee->employee_id,
            'employee_profile_id' => $employee->id,
            'account_status' => AccountStatus::Active,
            'password' => Hash::make('old-canonical-password'),
            'security_version' => 3,
        ]);
        config()->set('identity.mode', 'hybrid');
        config()->set('identity.credential_authority', 'local');
        $user->identityLinks()->create([
            'provider' => 'authentik',
            'subject' => 'password-reset-local-authority-subject',
            'provider_user_id' => 'password-reset-local-authority-user',
            'status' => 'active',
        ]);
        $originalSession = AuthenticationSession::factory()->create(['user_id' => $user->id, 'security_version' => 3]);
        app(CityEmailVerificationService::class)->acknowledge($user, 'pendingreplacement@miamibeachfl.gov');

        $this->post('/forgot-password', ['employee_id' => $employee->employee_id])
            ->assertRedirect()
            ->assertSessionHas('status');

        $email = OutboundEmail::query()->sole();
        self::assertSame([$recipient], $email->to_recipients);
        self::assertSame('delivered', $email->status);
        self::assertSame('[Sensitive account-security message omitted]', $email->text_body);
        self::assertNull($email->html_body);
        $providerRequest = Http::recorded()->sole()[0];
        self::assertMatchesRegularExpression('#/reset-password/[^?]+\?employee_id=RESET-100#', (string) $providerRequest['text']);
        preg_match('#/reset-password/([^?]+)#', (string) $providerRequest['text'], $matches);
        self::assertArrayHasKey(1, $matches);

        $this->post('/reset-password', [
            'employee_id' => $employee->employee_id,
            'token' => rawurldecode($matches[1]),
            'password' => 'replacement-canonical-password-2026',
            'password_confirmation' => 'replacement-canonical-password-2026',
        ])->assertRedirect('/login');

        $user->refresh();
        self::assertTrue(Hash::check('replacement-canonical-password-2026', $user->password));
        self::assertFalse($user->must_change_password);
        self::assertSame(4, $user->security_version);
        self::assertNotNull($originalSession->fresh()->revoked_at);
        self::assertSame('password changed', $originalSession->fresh()->revoked_reason);
        self::assertSame($accountEmail, $user->email);
        self::assertSame($employeeEmail, $employee->fresh()->city_email);
        self::assertFalse(Hash::check('old-canonical-password', $user->password));
        $this->assertDatabaseCount('password_reset_tokens', 0);
        $this->post('/reset-password', [
            'employee_id' => $employee->employee_id, 'token' => rawurldecode($matches[1]),
            'password' => 'replayed-password-2026', 'password_confirmation' => 'replayed-password-2026',
        ])->assertSessionHasErrors('employee_id');
        self::assertSame(4, $user->fresh()->security_version);
        $this->post('/login', ['employee_id' => $employee->employee_id, 'password' => 'old-canonical-password'])->assertSessionHasErrors('employee_id');
        $this->assertGuest('web');
        $this->post('/login', ['employee_id' => $employee->employee_id, 'password' => 'replacement-canonical-password-2026'])->assertRedirect('/');
        CarbonImmutable::setTestNow();
    }

    public static function connectedAddresses(): array
    {
        return [
            'employee address preferred' => ['member@miamibeachfl.gov', 'accountmember@miamibeachfl.gov', 'member@miamibeachfl.gov'],
            'account city address fallback' => [null, 'member@miamibeachfl.gov', 'member@miamibeachfl.gov'],
            'existing noncity address fallback' => [null, 'member@gmail.com', 'member@gmail.com'],
        ];
    }

    public function test_pending_proposal_without_a_connected_address_cannot_receive_a_password_reset(): void
    {
        Http::fake();
        $employee = Employee::query()->create(['employee_id' => 'RESET-PENDING', 'name' => 'Pending Member', 'password' => 'legacy-not-used']);
        $user = User::factory()->create([
            'employee_id' => $employee->employee_id, 'employee_profile_id' => $employee->id,
            'email' => 'employee-'.$employee->id.'@canonical.mbfdhub.invalid', 'account_status' => AccountStatus::Active,
        ]);
        app(CityEmailVerificationService::class)->acknowledge($user, 'pendingmember@miamibeachfl.gov');
        $this->post('/forgot-password', ['employee_id' => $employee->employee_id])->assertSessionHas('status');
        Http::assertNothingSent();
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('securityTransitions')]
    public function test_security_transition_invalidates_outstanding_password_reset(string $transition): void
    {
        $employee = Employee::query()->create(['employee_id' => 'RESET-SECURITY', 'name' => 'Security Member', 'password' => 'legacy-not-used']);
        $user = User::factory()->create([
            'employee_id' => $employee->employee_id, 'employee_profile_id' => $employee->id,
            'email' => 'securitymember@miamibeachfl.gov', 'account_status' => AccountStatus::Active,
        ]);
        $token = Password::broker()->createToken($user);
        $security = app(AccountSecurityService::class);
        match ($transition) {
            'disable' => $security->disable($user, 'test disable', now()),
            'revoke' => $security->revokeAll($user, 'test revoke', now()),
            'require password' => $security->forcePasswordChange($user, now()),
            'admin recovery' => $security->setAdministrativeRecoveryPassword(
                $user,
                Hash::make('admin-recovery-test-password'),
                app(\App\Services\Security\TemporaryCredentialFingerprint::class)->forPassword('admin-recovery-test-password'),
                now(),
            ),
        };
        $before = $user->fresh()->getRawOriginal();
        $this->post('/reset-password', [
            'employee_id' => $employee->employee_id, 'token' => $token,
            'password' => 'stale-recovery-password-2026', 'password_confirmation' => 'stale-recovery-password-2026',
        ])->assertSessionHasErrors('employee_id');
        self::assertSame($before['password'], $user->fresh()->getRawOriginal('password'));
        self::assertSame($before['security_version'], $user->fresh()->getRawOriginal('security_version'));
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public static function securityTransitions(): array
    {
        return [['disable'], ['revoke'], ['require password'], ['admin recovery']];
    }

    public function test_reset_cannot_clear_required_setup_by_reusing_the_temporary_password(): void
    {
        $employee = Employee::query()->create(['employee_id' => 'RESET-TEMP', 'name' => 'Temporary Member', 'password' => 'legacy-not-used']);
        $user = User::factory()->create([
            'employee_id' => $employee->employee_id, 'employee_profile_id' => $employee->id,
            'email' => 'temporarymember@miamibeachfl.gov', 'account_status' => AccountStatus::Active,
            'password' => Hash::make('temporary-password-2026'), 'must_change_password' => true,
        ]);
        $version = $user->security_version;
        $token = Password::broker()->createToken($user);
        $this->post('/reset-password', [
            'employee_id' => $employee->employee_id, 'token' => $token,
            'password' => 'temporary-password-2026', 'password_confirmation' => 'temporary-password-2026',
        ])->assertSessionHasErrors('password');
        self::assertTrue($user->fresh()->must_change_password);
        self::assertSame($version, $user->fresh()->security_version);
        $this->assertDatabaseCount('password_reset_tokens', 1);
    }
}
