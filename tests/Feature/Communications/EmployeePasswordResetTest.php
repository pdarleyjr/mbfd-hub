<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Enums\AccountStatus;
use App\Models\CloudflareUsageBudget;
use App\Models\Employee;
use App\Models\OutboundEmail;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
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

    public function test_active_linked_member_can_reset_through_authoritative_city_email_and_revoke_security_state(): void
    {
        $now = CarbonImmutable::parse('2026-09-04T12:00:00Z');
        CarbonImmutable::setTestNow($now);
        config()->set('communications.cloudflare.account_id', str_repeat('a', 32));
        config()->set('communications.cloudflare.api_token', 'password-reset-test-token');
        CloudflareUsageBudget::query()->create([
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
                    'delivered' => ['member@miamibeachfl.gov'],
                    'queued' => [],
                    'permanent_bounces' => [],
                    'suppressed_recipients' => [],
                ],
            ]),
        ]);
        $employee = Employee::query()->create([
            'employee_id' => 'RESET-100',
            'name' => 'Password Reset Member',
            'city_email' => 'member@miamibeachfl.gov',
            'password' => 'unrelated-legacy-password',
        ]);
        $user = User::factory()->create([
            'employee_id' => $employee->employee_id,
            'employee_profile_id' => $employee->id,
            'account_status' => AccountStatus::Active,
            'password' => Hash::make('old-canonical-password'),
            'security_version' => 3,
        ]);

        $this->post('/forgot-password', ['employee_id' => $employee->employee_id])
            ->assertRedirect()
            ->assertSessionHas('status');

        $email = OutboundEmail::query()->sole();
        self::assertSame(['member@miamibeachfl.gov'], $email->to_recipients);
        self::assertSame('delivered', $email->status);
        self::assertMatchesRegularExpression('#/reset-password/[^?]+\?employee_id=RESET-100#', (string) $email->text_body);
        preg_match('#/reset-password/([^?]+)#', (string) $email->text_body, $matches);
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
        $this->assertDatabaseCount('password_reset_tokens', 0);
        CarbonImmutable::setTestNow();
    }
}
