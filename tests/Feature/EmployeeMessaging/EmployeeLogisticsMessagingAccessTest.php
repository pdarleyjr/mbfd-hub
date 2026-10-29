<?php

declare(strict_types=1);

namespace Tests\Feature\EmployeeMessaging;

use App\Enums\AccountStatus;
use App\Models\User;
use App\Services\Security\EmployeeLogisticsMessagingAccess as Access;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** docs/employee-logistics-messaging.md section 3: who may use which side of the inbox. */
final class EmployeeLogisticsMessagingAccessTest extends TestCase
{
    use BuildsEmployeeMessagingFixtures;
    use RefreshDatabase;

    // ------------------------------------------------------------ member eligibility

    public function test_an_active_member_with_a_current_linked_profile_can_use_the_member_inbox(): void
    {
        $member = $this->messagingMember();

        $this->assertTrue($this->access()->canUseMemberInbox($member));
        $this->assertSame($member->employee_profile_id, $this->access()->memberEmployee($member)?->getKey());
    }

    public function test_a_missing_actor_has_no_access_of_any_kind(): void
    {
        $this->assertFalse($this->access()->canUseMemberInbox(null));
        $this->assertFalse($this->access()->canViewLogisticsInbox(null));
        $this->assertFalse($this->access()->canReplyAsLogistics(null));
        $this->assertNull($this->access()->memberEmployee(null));
    }

    public function test_an_account_without_a_personnel_profile_cannot_use_the_member_inbox(): void
    {
        $user = User::factory()->create(['account_status' => AccountStatus::Active]);

        $this->assertTrue($user->isAuthenticationAllowed(), 'Precondition: the account itself is usable.');
        $this->assertFalse($this->access()->canUseMemberInbox($user));
    }

    public function test_a_departed_roster_member_cannot_use_the_member_inbox(): void
    {
        $member = $this->messagingMember('Gone Member', ['roster_status' => 'departed']);

        $this->assertFalse($this->access()->canUseMemberInbox($member));
    }

    public function test_other_roster_statuses_still_count_as_current(): void
    {
        foreach (['active', 'leave', 'suspended'] as $status) {
            $member = $this->messagingMember('Roster '.$status, ['roster_status' => $status]);

            $this->assertTrue($this->access()->canUseMemberInbox($member), $status);
        }
    }

    public function test_a_member_whose_profile_employee_number_no_longer_matches_is_refused(): void
    {
        $member = $this->messagingMember();
        DB::table('employees')->where('id', $member->employee_profile_id)->update(['employee_id' => 'CHANGED-9999']);

        $this->assertFalse($this->access()->canUseMemberInbox($member->fresh()));
    }

    public function test_a_member_whose_account_employee_number_is_mismatched_is_refused(): void
    {
        $member = $this->messagingMember();
        DB::table('users')->where('id', $member->id)->update(['employee_id' => 'SOMEONE-ELSE']);

        $this->assertFalse($this->access()->canUseMemberInbox($member->fresh()));
    }

    public function test_a_disabled_account_cannot_use_the_member_inbox(): void
    {
        $this->assertFalse($this->access()->canUseMemberInbox(
            $this->messagingMember('Disabled', [], ['account_status' => AccountStatus::Disabled]),
        ));
    }

    public function test_an_account_pending_activation_cannot_use_the_member_inbox(): void
    {
        $this->assertFalse($this->access()->canUseMemberInbox(
            $this->messagingMember('Pending', [], ['account_status' => AccountStatus::PendingActivation]),
        ));
    }

    public function test_a_member_who_must_change_their_password_cannot_use_the_member_inbox(): void
    {
        $member = $this->messagingMember('Must Change', [], ['must_change_password' => true]);

        $this->assertTrue($member->isAuthenticationAllowed(), 'Precondition: only the password rule blocks them.');
        $this->assertFalse($this->access()->canUseMemberInbox($member));
    }

    public function test_member_eligibility_follows_the_current_state_not_a_cached_decision(): void
    {
        $member = $this->messagingMember();
        $this->assertTrue($this->access()->canUseMemberInbox($member));

        DB::table('employees')->where('id', $member->employee_profile_id)->update(['roster_status' => 'departed']);

        $this->assertFalse($this->access()->canUseMemberInbox($member), 'A departure takes effect on the next check.');
    }

    // ------------------------------------------------------------ Logistics side

    /**
     * @return array<string, array{0: list<string>, 1: list<string>, 2: string, 3: bool, 4: bool, 5: bool}>
     */
    public static function logisticsMatrix(): array
    {
        $view = Access::PERMISSION_VIEW;
        $reply = Access::PERMISSION_REPLY;

        // [roles, direct permissions, account status, must change password, may view, may reply]
        return [
            'super_admin' => [['super_admin'], [], 'active', false, true, true],
            'super_admin with admin.access' => [['super_admin'], ['admin.access'], 'active', false, true, true],
            'logistics_admin + admin.access' => [['logistics_admin'], ['admin.access'], 'active', false, true, true],
            'logistics_admin without admin.access' => [['logistics_admin'], [], 'active', false, false, false],
            'admin.access + reply capability' => [[], ['admin.access', $reply], 'active', false, true, true],
            'admin.access + view capability' => [[], ['admin.access', $view], 'active', false, true, false],
            'admin.access + view + reply capabilities' => [[], ['admin.access', $view, $reply], 'active', false, true, true],
            'reply capability without admin.access' => [[], [$reply], 'active', false, false, false],
            'view capability without admin.access' => [[], [$view], 'active', false, false, false],
            'admin.access + communications.view' => [[], ['admin.access', 'admin.communications.view'], 'active', false, false, false],
            'admin.access + communications.send' => [[], ['admin.access', 'admin.communications.send'], 'active', false, false, false],
            'admin.access + all communications' => [[], ['admin.access', 'admin.communications.view', 'admin.communications.send'], 'active', false, false, false],
            'admin.access only' => [[], ['admin.access'], 'active', false, false, false],
            'admin role + admin.access' => [['admin'], ['admin.access'], 'active', false, false, false],
            'training_admin + admin.access' => [['training_admin'], ['admin.access'], 'active', false, false, false],
            'plain account, no roles' => [[], [], 'active', false, false, false],
            'disabled super_admin' => [['super_admin'], ['admin.access'], 'disabled', false, false, false],
            'pending super_admin' => [['super_admin'], [], 'pending_activation', false, false, false],
            'disabled logistics_admin + admin.access' => [['logistics_admin'], ['admin.access'], 'disabled', false, false, false],
            'disabled reply capability' => [[], ['admin.access', $reply], 'disabled', false, false, false],
            'super_admin who must change password' => [['super_admin'], [], 'active', true, false, false],
            'logistics_admin who must change password' => [['logistics_admin'], ['admin.access'], 'active', true, false, false],
            'reply capability who must change password' => [[], ['admin.access', $reply], 'active', true, false, false],
        ];
    }

    /**
     * @param  list<string>  $roles
     * @param  list<string>  $permissions
     */
    #[DataProvider('logisticsMatrix')]
    public function test_the_logistics_matrix(array $roles, array $permissions, string $status, bool $mustChange, bool $mayView, bool $mayReply): void
    {
        $user = $this->messagingAdmin($roles, $permissions, [
            'account_status' => AccountStatus::from($status),
            'must_change_password' => $mustChange,
        ]);

        $this->assertSame($mayView, $this->access()->canViewLogisticsInbox($user), 'view');
        $this->assertSame($mayReply, $this->access()->canReplyAsLogistics($user), 'reply');
        $this->assertFalse($this->access()->canUseMemberInbox($user), 'An administrator without a personnel profile has no member inbox.');
    }

    public function test_replying_always_implies_viewing(): void
    {
        foreach ($this->matrixUsers() as $label => $user) {
            if ($this->access()->canReplyAsLogistics($user)) {
                $this->assertTrue($this->access()->canViewLogisticsInbox($user), $label);
            }
        }
    }

    public function test_a_plain_member_has_no_logistics_access(): void
    {
        $member = $this->messagingMember();

        $this->assertFalse($this->access()->canViewLogisticsInbox($member));
        $this->assertFalse($this->access()->canReplyAsLogistics($member));
    }

    public function test_a_member_who_is_also_a_logistics_responder_has_both_sides(): void
    {
        $member = $this->messagingMember('Dual Role');
        $member->assignRole(Role::findOrCreate('logistics_admin', 'web'));
        $member->givePermissionTo(Permission::findOrCreate('admin.access', 'web'));
        $member = $member->fresh();

        $this->assertTrue($this->access()->canUseMemberInbox($member));
        $this->assertTrue($this->access()->canViewLogisticsInbox($member));
        $this->assertTrue($this->access()->canReplyAsLogistics($member));
    }

    public function test_capabilities_inherited_through_a_role_do_not_count_only_direct_ones_do(): void
    {
        // The capability editor writes direct permissions; role inheritance is never consulted.
        $role = Role::findOrCreate('custom_responders', 'web');
        $role->givePermissionTo(Permission::findOrCreate(Access::PERMISSION_REPLY, 'web'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = $this->messagingAdmin(['custom_responders'], ['admin.access']);

        $this->assertFalse($this->access()->canViewLogisticsInbox($user));
        $this->assertFalse($this->access()->canReplyAsLogistics($user));
    }

    public function test_admin_panel_entitlement_inherited_through_a_role_is_not_enough_for_logistics_admin(): void
    {
        $role = Role::findOrCreate('logistics_admin', 'web');
        $role->givePermissionTo(Permission::findOrCreate('admin.access', 'web'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = $this->messagingAdmin(['logistics_admin']);

        $this->assertFalse($user->hasCurrentAdminPanelEntitlement(), 'Precondition: entitlement is direct or super_admin only.');
        $this->assertFalse($this->access()->canViewLogisticsInbox($user));
    }

    public function test_removing_a_role_or_permission_revokes_access_immediately(): void
    {
        $responder = $this->logisticsResponder();
        $this->assertTrue($this->access()->canReplyAsLogistics($responder));

        $responder->removeRole('logistics_admin');
        $this->assertFalse($this->access()->canReplyAsLogistics($responder->fresh()));

        $capable = $this->messagingAdmin([], ['admin.access', Access::PERMISSION_REPLY]);
        $this->assertTrue($this->access()->canReplyAsLogistics($capable));

        $capable->revokePermissionTo(Access::PERMISSION_REPLY);
        $this->assertFalse($this->access()->canViewLogisticsInbox($capable->fresh()));

        $viewer = $this->logisticsViewer();
        $this->assertTrue($this->access()->canViewLogisticsInbox($viewer));
        $viewer->revokePermissionTo('admin.access');
        $this->assertFalse($this->access()->canViewLogisticsInbox($viewer->fresh()), 'Losing the Admin Panel entitlement ends inbox access.');
    }

    // ------------------------------------------------------------ No Gate abilities

    /**
     * The global Gate::before (AppServiceProvider) answers true for every super_admin, so a disabled
     * or must-change-password super administrator would pass any Gate ability. The decision is
     * therefore never exposed as a Gate: callers ask EmployeeLogisticsMessagingAccess directly.
     */
    public function test_messaging_decisions_are_not_gate_abilities_a_super_admin_bypass_could_skip(): void
    {
        foreach (['employee-logistics-messages-member', 'employee-logistics-messages-view', 'employee-logistics-messages-reply'] as $ability) {
            $this->assertFalse(Gate::has($ability), $ability.' must not be a defined Gate ability');
        }

        $disabled = $this->messagingAdmin(['super_admin'], [], ['account_status' => AccountStatus::Disabled]);
        $mustChange = $this->messagingAdmin(['super_admin'], [], ['must_change_password' => true]);
        foreach (['disabled super_admin' => $disabled, 'must-change-password super_admin' => $mustChange] as $label => $user) {
            $this->assertFalse($this->access()->canViewLogisticsInbox($user), $label.' view');
            $this->assertFalse($this->access()->canReplyAsLogistics($user), $label.' reply');
            $this->assertFalse($this->access()->canUseMemberInbox($user), $label.' member');
        }
    }

    /** @return array<string, User> */
    private function matrixUsers(): array
    {
        $users = [];
        foreach (self::logisticsMatrix() as $label => [$roles, $permissions, $status, $mustChange]) {
            $users[$label] = $this->messagingAdmin($roles, $permissions, [
                'account_status' => AccountStatus::from($status),
                'must_change_password' => $mustChange,
            ]);
        }

        return $users;
    }
}
