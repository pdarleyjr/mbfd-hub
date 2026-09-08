<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Enums\AccountStatus;
use App\Exceptions\CurrentPasswordMismatch;
use App\Models\SecurityActionEvent;
use App\Models\User;
use App\Models\Workgroup;
use App\Models\WorkgroupMember;
use App\Models\WorkgroupNote;
use App\Services\Security\EmployeeWorkgroupAdministration;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class EmployeeWorkgroupAdministrationTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Workgroup-admin-current-password!';

    public function test_multiple_roles_and_deactivation_preserve_membership_ids_and_history(): void
    {
        [$actor, $target, $first, $second] = $this->fixtures();
        $old = WorkgroupMember::create(['user_id' => $target->id, 'workgroup_id' => $first->id, 'role' => 'admin', 'is_active' => true, 'count_evaluations' => true]);
        $note = WorkgroupNote::create(['workgroup_member_id' => $old->id, 'title' => 'History retained', 'content' => 'Existing notes']);
        $sessionId = DB::table('workgroup_sessions')->insertGetId(['workgroup_id' => $first->id, 'name' => 'Existing session', 'start_date' => '2026-09-08', 'end_date' => '2026-09-09']);
        $categoryId = DB::table('evaluation_categories')->insertGetId(['name' => 'Existing category']);
        $productId = DB::table('candidate_products')->insertGetId(['workgroup_session_id' => $sessionId, 'category_id' => $categoryId, 'name' => 'Existing evaluated product']);
        $submissionId = DB::table('evaluation_submissions')->insertGetId(['workgroup_member_id' => $old->id, 'candidate_product_id' => $productId, 'status' => 'submitted']);
        DB::table('session_workgroup_member_attendance')->insert(['workgroup_member_id' => $old->id, 'workgroup_session_id' => $sessionId]);
        $identity = $target->fresh()->getAttributes();
        $service = app(EmployeeWorkgroupAdministration::class);
        $service->sync($actor, $target, [$this->row($first, 'facilitator'), $this->row($second, 'admin')], self::PASSWORD, 'Approved training assignments');
        self::assertSame('facilitator', $old->fresh()->role);
        self::assertSame(2, WorkgroupMember::where('user_id', $target->id)->count());
        $service->sync($actor, $target, [$this->row($second, 'member', false)], self::PASSWORD, 'Deactivate prior training assignments');
        self::assertFalse($old->fresh()->is_active);
        self::assertSame('facilitator', $old->fresh()->role);
        self::assertSame($old->id, $note->fresh()->workgroup_member_id);
        $this->assertDatabaseHas('evaluation_submissions', ['id' => $submissionId, 'workgroup_member_id' => $old->id, 'status' => 'submitted']);
        $this->assertDatabaseHas('session_workgroup_member_attendance', ['workgroup_member_id' => $old->id, 'workgroup_session_id' => $sessionId]);
        self::assertSame($identity, $target->fresh()->getAttributes());
        self::assertSame(['member'], $target->fresh()->getRoleNames()->all());
        $audit = SecurityActionEvent::where('action', 'change_employee_workgroups')->latest('id')->firstOrFail();
        self::assertSame('allowed', $audit->result);
        self::assertCount(2, $audit->metadata['before']);
        self::assertCount(2, $audit->metadata['after']);
        self::assertStringNotContainsString(self::PASSWORD, json_encode($audit->metadata));
    }

    public function test_invalid_group_duplicate_role_or_forged_field_is_atomic_and_audited(): void
    {
        [$actor, $target, $first, $second] = $this->fixtures();
        foreach ([
            [$this->row($first), [...$this->row($first), 'workgroup_id' => (string) $first->id]],
            [$this->row($first), [...$this->row($second), 'workgroup_id' => 999999]],
            [[...$this->row($first), 'role' => 'super_admin']],
            [[...$this->row($first), 'user_id' => $actor->id]],
        ] as $rows) {
            try {
                app(EmployeeWorkgroupAdministration::class)->sync($actor, $target, $rows, self::PASSWORD, 'Invalid submitted selection');
                self::fail('Invalid selections must be rejected.');
            } catch (ValidationException) {
                $this->assertDatabaseCount('workgroup_members', 0);
            }
        }
        self::assertSame(4, SecurityActionEvent::where('result', 'denied')->count());
    }

    public function test_stale_actor_password_authority_self_and_blank_reason_fail_closed(): void
    {
        [$actor, $target, $first] = $this->fixtures();
        $service = app(EmployeeWorkgroupAdministration::class);
        foreach (['disabled', 'permission', 'password', 'self', 'reason'] as $case) {
            $actor = $actor->fresh();
            $actor->forceFill(['account_status' => AccountStatus::Active])->save();
            $actor->givePermissionTo('admin.workgroups.manage');
            $actor->load('permissions');
            if ($case === 'disabled') {
                DB::table('users')->where('id', $actor->id)->update(['account_status' => 'disabled']);
            } elseif ($case === 'permission') {
                $actor->fresh()->revokePermissionTo('admin.workgroups.manage');
            }
            try {
                $service->sync($actor, $case === 'self' ? $actor : $target, [$this->row($first)], $case === 'password' ? 'wrong' : self::PASSWORD, $case === 'reason' ? ' ' : 'Approved selection');
                self::fail('Fresh authority, current password, other target, and reason required.');
            } catch (AuthorizationException|ValidationException $exception) {
                if ($case === 'password') {
                    self::assertInstanceOf(CurrentPasswordMismatch::class, $exception);
                }
                $this->assertDatabaseCount('workgroup_members', 0);
            }
        }
        self::assertSame(5, SecurityActionEvent::where('result', 'denied')->count());
    }

    public function test_super_admin_can_reactivate_the_same_membership_and_control_evaluation_counting(): void
    {
        [$actor, $target, $first] = $this->fixtures();
        $actor->revokePermissionTo('admin.workgroups.manage');
        $actor->assignRole(Role::findOrCreate('super_admin', 'web'));
        $old = WorkgroupMember::create(['user_id' => $target->id, 'workgroup_id' => $first->id, 'role' => 'member', 'is_active' => false, 'count_evaluations' => true]);
        app(EmployeeWorkgroupAdministration::class)->sync($actor, $target, [[...$this->row($first, 'admin'), 'count_evaluations' => false]], self::PASSWORD, 'Approved facilitator responsibility');
        self::assertTrue($old->fresh()->is_active);
        self::assertFalse($old->fresh()->count_evaluations);
        self::assertSame('admin', $old->fresh()->role);
        $this->assertDatabaseCount('workgroup_members', 1);
    }

    public function test_write_failure_rolls_back_earlier_role_change_and_only_records_failed_audit(): void
    {
        [$actor, $target, $first, $second] = $this->fixtures();
        $old = WorkgroupMember::create(['user_id' => $target->id, 'workgroup_id' => $first->id, 'role' => 'member', 'is_active' => true, 'count_evaluations' => true]);
        $before = $old->fresh()->getAttributes();
        DB::unprepared("CREATE TRIGGER deny_new_test_membership BEFORE INSERT ON workgroup_members BEGIN SELECT RAISE(ABORT, 'synthetic membership failure'); END");
        try {
            app(EmployeeWorkgroupAdministration::class)->sync($actor, $target, [$this->row($first, 'admin'), $this->row($second)], self::PASSWORD, 'Approved bulk membership change');
            self::fail('The injected write error must propagate.');
        } catch (\Illuminate\Database\QueryException) {
            self::assertSame($before, $old->fresh()->getAttributes());
            $this->assertDatabaseCount('workgroup_members', 1);
            $this->assertDatabaseMissing('security_action_events', ['result' => 'allowed']);
            $this->assertDatabaseHas('security_action_events', ['result' => 'failed']);
        } finally {
            DB::unprepared('DROP TRIGGER deny_new_test_membership');
        }
    }

    public function test_empty_selection_deactivates_all_without_deleting_memberships(): void
    {
        [$actor, $target, $first, $second] = $this->fixtures();
        $service = app(EmployeeWorkgroupAdministration::class);
        $service->sync($actor, $target, [$this->row($first), $this->row($second)], self::PASSWORD, 'Approved initial memberships');
        $ids = WorkgroupMember::where('user_id', $target->id)->orderBy('id')->pluck('id')->all();
        $service->sync($actor, $target, [], self::PASSWORD, 'Deactivate all workgroup memberships');
        self::assertSame($ids, WorkgroupMember::where('user_id', $target->id)->orderBy('id')->pluck('id')->all());
        self::assertSame(0, WorkgroupMember::where('user_id', $target->id)->where('is_active', true)->count());
    }

    private function fixtures(): array
    {
        $actor = User::factory()->create(['account_status' => AccountStatus::Active, 'password' => self::PASSWORD]);
        Permission::findOrCreate('admin.workgroups.manage', 'web');
        $actor->givePermissionTo('admin.workgroups.manage');
        $target = User::factory()->create(['account_status' => AccountStatus::Active]);
        $target->assignRole(Role::findOrCreate('member', 'web'));
        $first = Workgroup::create(['name' => 'First test group', 'created_by' => $actor->id]);
        $second = Workgroup::create(['name' => 'Second test group', 'created_by' => $actor->id]);

        return [$actor, $target, $first, $second];
    }

    private function row(Workgroup $group, string $role = 'member', bool $active = true): array
    {
        return ['workgroup_id' => $group->id, 'role' => $role, 'is_active' => $active, 'count_evaluations' => true];
    }
}
