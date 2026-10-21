<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\SecurityActionEvent;
use App\Models\User;
use App\Services\Personnel\PersonnelTableService;
use App\Services\Security\ApplicationAccessService;
use App\Support\ApplicationAccessRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class PersonnelCapabilityAdministrationTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Personnel-access-test-password!';

    private const CAPABILITIES = [
        'personnel.directory.view', 'personnel.directory.edit', 'personnel.directory.export',
        'personnel.tables.view', 'personnel.tables.create', 'personnel.tables.edit',
        'personnel.tables.share', 'personnel.tables.export', 'personnel.tables.archive',
    ];

    public function test_all_nine_capabilities_can_be_granted_and_revoked_with_audit_and_separate_panel_access(): void
    {
        [$actor, $target] = $this->members();
        $target->givePermissionTo(Permission::findOrCreate('app.bid.access', 'web'));
        $identity = $target->fresh()->getRawOriginal();
        $service = app(ApplicationAccessService::class);
        $options = app(ApplicationAccessRegistry::class)->capabilityOptions();
        self::assertSame(self::CAPABILITIES, array_values(array_filter(array_keys($options), fn (string $name): bool => str_starts_with($name, 'personnel.'))));

        $service->syncAdministrationCapabilities($actor, $target, self::CAPABILITIES, self::PASSWORD, 'Personnel coordination duties');
        $current = $target->fresh();
        foreach (self::CAPABILITIES as $permission) {
            self::assertTrue($current->hasDirectWebPermission($permission));
        }
        self::assertFalse($current->hasCurrentAdminPanelEntitlement());
        self::assertFalse(app(PersonnelTableService::class)->canViewDirectory($current));
        self::assertFalse($current->hasDirectWebPermission('admin.members.manage'));
        self::assertFalse($current->hasDirectWebPermission('admin.personnel.manage'));
        self::assertTrue($current->hasCurrentBidEntitlement());

        $service->syncApplications($actor, $target, ['admin', 'bid'], self::PASSWORD, 'Separate Hub administration admission');
        self::assertTrue(app(PersonnelTableService::class)->canViewDirectory($target->fresh()));
        self::assertTrue(app(PersonnelTableService::class)->canCreate($target->fresh()));
        $service->syncAdministrationCapabilities($actor, $target, [], self::PASSWORD, 'Personnel coordination duties ended');
        $current = $target->fresh();
        foreach (self::CAPABILITIES as $permission) {
            self::assertFalse($current->hasDirectWebPermission($permission));
        }
        self::assertTrue($current->hasCurrentAdminPanelEntitlement());
        self::assertTrue($current->hasCurrentBidEntitlement());
        self::assertFalse(app(PersonnelTableService::class)->canViewDirectory($current));
        self::assertSame($identity, $current->getRawOriginal());
        $events = SecurityActionEvent::query()->where('action', 'change_admin_capabilities')->orderBy('id')->get();
        self::assertCount(2, $events);
        self::assertSame('allowed', $events[0]->result);
        $grantMetadata = json_decode($events[0]->getRawOriginal('metadata'), true, flags: JSON_THROW_ON_ERROR);
        $revokeMetadata = json_decode($events[1]->getRawOriginal('metadata'), true, flags: JSON_THROW_ON_ERROR);
        self::assertEqualsCanonicalizing(self::CAPABILITIES, $grantMetadata['granted']);
        self::assertEqualsCanonicalizing(self::CAPABILITIES, $revokeMetadata['revoked']);
        self::assertSame('Personnel coordination duties ended', $events[1]->reason);
    }

    public function test_view_grants_do_not_automatically_enable_other_personnel_actions(): void
    {
        [$actor, $target] = $this->members();
        $access = app(ApplicationAccessService::class);
        $access->syncApplications($actor, $target, ['admin'], self::PASSWORD, 'Hub admission only');
        $service = app(PersonnelTableService::class);
        self::assertFalse($service->canViewDirectory($target));
        self::assertFalse($service->canViewTables($target));
        $access->syncAdministrationCapabilities($actor, $target, ['personnel.directory.view', 'personnel.tables.view'], self::PASSWORD, 'View personnel only');
        $master = $service->master($actor);
        $shared = $service->create($actor, ['name' => 'Shared tracking', 'visibility' => 'shared']);
        self::assertTrue($service->canView($target, $master));
        self::assertTrue($service->canView($target, $shared));
        self::assertFalse($service->canCreate($target));
        foreach ([$master, $shared] as $table) {
            self::assertFalse($service->canManage($target, $table));
            self::assertFalse($service->canExport($target, $table));
        }
        self::assertFalse($service->canShare($target, $shared));
        self::assertFalse($service->canArchive($target, $shared));
        self::assertSame(2, $target->permissions()->where('name', 'like', 'personnel.%')->count());
    }

    public function test_wrong_password_and_invalid_reason_deny_personnel_grants_and_are_audited_without_passwords(): void
    {
        [$actor, $target] = $this->members();
        $target->givePermissionTo('personnel.directory.view');
        foreach ([['Never-retain-this-wrong-password', 'New duty'], [self::PASSWORD, ''], [self::PASSWORD, '   '], [self::PASSWORD, str_repeat('x', 501)]] as [$password, $reason]) {
            try {
                app(ApplicationAccessService::class)->syncAdministrationCapabilities($actor, $target, ['personnel.tables.create'], $password, $reason);
                self::fail('Protected personnel grants must reject invalid administrator proof.');
            } catch (AuthorizationException) {
                self::assertTrue($target->fresh()->hasDirectWebPermission('personnel.directory.view'));
                self::assertFalse($target->fresh()->hasDirectWebPermission('personnel.tables.create'));
            }
        }
        self::assertSame(4, SecurityActionEvent::query()->where('action', 'change_admin_capabilities')->where('result', 'denied')->count());
        self::assertStringNotContainsString('Never-retain-this-wrong-password', SecurityActionEvent::all()->toJson());
        self::assertStringNotContainsString(self::PASSWORD, SecurityActionEvent::all()->toJson());
    }

    public function test_stale_or_non_super_administrators_cannot_delegate_personnel_capabilities(): void
    {
        [$actor, $target] = $this->members();
        $actor->load('roles');
        User::query()->whereKey($actor->id)->update(['account_status' => 'disabled']);
        $this->assertGrantDenied($actor, $target);
        User::query()->whereKey($actor->id)->update(['account_status' => 'active']);
        $actor->roles()->detach();
        $this->assertGrantDenied($actor, $target);
        $actor->givePermissionTo(Permission::findOrCreate('admin.members.manage', 'web'));
        $this->assertGrantDenied($actor, $target);
        self::assertSame(3, SecurityActionEvent::query()->where('action', 'change_admin_capabilities')->where('result', 'denied')->count());
    }

    public function test_self_and_super_administrator_targets_remain_protected(): void
    {
        [$actor, $target] = $this->members();
        $this->assertGrantDenied($actor, $actor);
        $target->assignRole(Role::findOrCreate('super_admin', 'web'));
        $this->assertGrantDenied($actor, $target);
        self::assertSame(2, SecurityActionEvent::query()->where('action', 'change_admin_capabilities')->where('result', 'denied')->count());
    }

    private function assertGrantDenied(User $actor, User $target): void
    {
        try {
            app(ApplicationAccessService::class)->syncAdministrationCapabilities($actor, $target, self::CAPABILITIES, self::PASSWORD, 'Forbidden personnel delegation');
            self::fail('Personnel delegation must retain the established access protections.');
        } catch (AuthorizationException) {
            self::assertSame(0, $target->permissions()->where('name', 'like', 'personnel.%')->count());
        }
    }

    /** @return array{User, User} */
    private function members(): array
    {
        $actor = User::factory()->create(['password' => self::PASSWORD, 'account_status' => 'active']);
        $actor->assignRole(Role::findOrCreate('super_admin', 'web'));

        return [$actor, User::factory()->create(['account_status' => 'active'])];
    }
}
