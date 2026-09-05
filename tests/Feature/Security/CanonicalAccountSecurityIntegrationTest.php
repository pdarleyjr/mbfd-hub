<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Enums\AccountStatus;
use App\Enums\SessionContextClass;
use App\Models\User;
use App\Services\Identity\SessionRegistry;
use App\Services\Security\AccountSecurityService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class CanonicalAccountSecurityIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_disable_revokes_sessions_and_reactivation_does_not_restore_them(): void
    {
        $this->confirmRecentAuthentication();
        config()->set('security.account_security.allowed_administrative_actions', ['disable', 'enable']);
        $actor = $this->superAdmin();
        $target = User::factory()->create(['account_status' => AccountStatus::Active]);
        $originalPassword = $target->password;
        $at = CarbonImmutable::parse('2026-08-31T12:00:00Z');
        $session = app(SessionRegistry::class)->register(
            $target,
            'canonical-session-to-revoke',
            SessionContextClass::UnmanagedBrowser,
            $at,
            $at->addHour(),
            $at->addDay(),
        );

        app(AccountSecurityService::class)->disable($actor, $target, 'security incident', $at);

        $disabled = $target->fresh();
        self::assertSame(AccountStatus::Disabled, $disabled->account_status);
        self::assertSame(2, $disabled->security_version);
        self::assertSame($originalPassword, $disabled->password);
        self::assertNotNull($session->fresh()->revoked_at);
        self::assertFalse(app(SessionRegistry::class)->isCurrent($disabled, $session->fresh(), $at->addMinute()));
        $this->assertDatabaseHas('security_action_events', [
            'actor_user_id' => $actor->id,
            'target_user_id' => $target->id,
            'action' => 'disable',
            'result' => 'allowed',
            'reason' => 'security incident',
        ]);

        app(AccountSecurityService::class)->enable($actor, $disabled, 'owner-approved reactivation', $at->addHour());

        $reactivated = $target->fresh();
        self::assertSame(AccountStatus::Active, $reactivated->account_status);
        self::assertSame(3, $reactivated->security_version);
        self::assertNotNull($session->fresh()->revoked_at);
        self::assertFalse(app(SessionRegistry::class)->isCurrent($reactivated, $session->fresh(), $at->addHours(2)));
    }

    public function test_owner_approved_default_policy_allows_reactivation(): void
    {
        $this->confirmRecentAuthentication();
        $actor = $this->superAdmin();
        $target = User::factory()->create(['account_status' => AccountStatus::Disabled]);

        app(AccountSecurityService::class)->enable(
            $actor,
            $target,
            'owner-approved default policy',
            CarbonImmutable::parse('2026-08-31T12:00:00Z'),
        );

        self::assertSame(AccountStatus::Active, $target->fresh()->account_status);
    }

    public function test_critical_administrator_cannot_be_disabled_even_when_the_action_is_enabled(): void
    {
        $this->confirmRecentAuthentication();
        config()->set('security.account_security.allowed_administrative_actions', ['disable']);
        $actor = $this->superAdmin();
        $actor->forceFill(['account_status' => AccountStatus::Disabled])->save();
        $target = $this->superAdmin();

        $this->expectException(AuthorizationException::class);

        try {
            app(AccountSecurityService::class)->disable(
                $actor,
                $target,
                'attempted critical-account disable',
                CarbonImmutable::parse('2026-08-31T12:00:00Z'),
            );
        } finally {
            self::assertSame(AccountStatus::Active, $target->fresh()->account_status);
            $this->assertDatabaseHas('security_action_events', [
                'actor_user_id' => $actor->id,
                'target_user_id' => $target->id,
                'action' => 'disable',
                'result' => 'denied',
            ]);
        }
    }

    private function superAdmin(): User
    {
        Role::findOrCreate('super_admin', 'web');
        $user = User::factory()->create(['account_status' => AccountStatus::Active]);
        $user->assignRole('super_admin');

        return $user;
    }

    private function confirmRecentAuthentication(): void
    {
        session()->put((string) config('security.recent_authentication.session_key'), time());
    }
}
