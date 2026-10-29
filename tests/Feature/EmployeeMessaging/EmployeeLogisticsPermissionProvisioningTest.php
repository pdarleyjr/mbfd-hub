<?php

declare(strict_types=1);

namespace Tests\Feature\EmployeeMessaging;

use App\Models\EmployeeMessaging\EmployeeLogisticsMessage;
use App\Models\EmployeeMessaging\EmployeeLogisticsThread;
use App\Models\User;
use App\Services\Security\EmployeeLogisticsMessagingAccess as Access;
use App\Support\ApplicationAccessRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The migration creates the two capabilities and the two tables, grants nothing to anybody (the
 * production deploy gate refuses role or permission changes on established accounts), and refuses
 * to drop conversations outside local/testing.
 */
final class EmployeeLogisticsPermissionProvisioningTest extends TestCase
{
    use BuildsEmployeeMessagingFixtures;
    use RefreshDatabase;

    private const MIGRATION = '2026_10_29_120000_create_employee_logistics_messaging_tables.php';

    private const NAMES = ['admin.employee_messages.view', 'admin.employee_messages.reply'];

    private function migration(): Migration
    {
        return require database_path('migrations/'.self::MIGRATION);
    }

    private function dropMessagingTables(): void
    {
        Schema::dropIfExists('employee_logistics_messages');
        Schema::dropIfExists('employee_logistics_threads');
    }

    /** @return list<int> */
    private function permissionIds(): array
    {
        return Permission::query()->whereIn('name', self::NAMES)->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    public function test_after_migrating_both_permissions_exist_on_the_web_guard(): void
    {
        foreach (self::NAMES as $name) {
            $this->assertSame(1, Permission::query()->where('name', $name)->where('guard_name', 'web')->count(), $name);
            $this->assertSame(0, Permission::query()->where('name', $name)->where('guard_name', '!=', 'web')->count(), $name.' on another guard');
        }
        $this->assertSame([Access::PERMISSION_VIEW, Access::PERMISSION_REPLY], self::NAMES, 'The class constants and the migration agree.');
    }

    public function test_the_capability_editor_lists_both_with_their_labels(): void
    {
        $options = app(ApplicationAccessRegistry::class)->capabilityOptions();

        $this->assertSame("Employee messages \u{2014} view the Logistics inbox", $options[Access::PERMISSION_VIEW]);
        $this->assertSame("Employee messages \u{2014} view and reply as Logistics", $options[Access::PERMISSION_REPLY]);
    }

    public function test_every_capability_option_has_a_permission_the_editor_can_grant(): void
    {
        foreach (self::NAMES as $name) {
            $this->assertArrayHasKey($name, app(ApplicationAccessRegistry::class)->capabilityOptions());
            $this->assertTrue(Permission::query()->where('name', $name)->where('guard_name', 'web')->exists(), $name);
        }
    }

    public function test_the_migration_grants_the_permissions_to_nobody(): void
    {
        $this->assertSame([], DB::table('role_has_permissions')->whereIn('permission_id', $this->permissionIds())->get()->all());
        $this->assertSame([], DB::table('model_has_permissions')->whereIn('permission_id', $this->permissionIds())->get()->all());
    }

    public function test_re_running_the_migration_is_idempotent_and_grants_nothing(): void
    {
        $superAdmin = $this->messagingAdmin(['super_admin']);
        $logistics = $this->messagingAdmin(['logistics_admin'], ['admin.access']);
        $viewer = $this->logisticsViewer();
        $plain = $this->messagingAdmin([], ['admin.access']);
        $roleGrants = DB::table('role_has_permissions')->count();
        $modelGrants = DB::table('model_has_permissions')->count();
        $permissionRows = DB::table('permissions')->count();
        $roleAssignments = DB::table('model_has_roles')->count();

        $migration = $this->migration();
        $this->dropMessagingTables();
        $migration->up();
        $this->dropMessagingTables();
        $this->migration()->up();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertSame($permissionRows, DB::table('permissions')->count(), 'No duplicate permission rows.');
        foreach (self::NAMES as $name) {
            $this->assertSame(1, Permission::query()->where('name', $name)->where('guard_name', 'web')->count(), $name);
        }
        $this->assertSame($roleGrants, DB::table('role_has_permissions')->count());
        $this->assertSame($modelGrants, DB::table('model_has_permissions')->count(), 'No account gains a permission from the migration.');
        $this->assertSame($roleAssignments, DB::table('model_has_roles')->count());
        $this->assertSame(
            [$viewer->id],
            DB::table('model_has_permissions')->whereIn('permission_id', $this->permissionIds())->pluck('model_id')->map(fn ($id): int => (int) $id)->all(),
            'Only the deliberate capability-editor grant made before the re-run references the permissions.',
        );
        $this->assertSame([], DB::table('role_has_permissions')->whereIn('permission_id', $this->permissionIds())->get()->all());

        $this->assertTrue($this->access()->canReplyAsLogistics($superAdmin->fresh()), 'Super administrators keep their access.');
        $this->assertTrue($this->access()->canReplyAsLogistics($logistics->fresh()), 'logistics_admin keeps its access.');
        $this->assertTrue($this->access()->canViewLogisticsInbox($viewer->fresh()), 'An existing grant is not removed.');
        foreach (self::NAMES as $name) {
            $this->assertFalse($plain->fresh()->hasDirectWebPermission($name), 'An established administrator gains nothing.');
        }
    }

    public function test_re_running_up_over_existing_permissions_keeps_existing_grants(): void
    {
        $viewer = $this->logisticsViewer();
        $grantsBefore = DB::table('model_has_permissions')->count();

        $this->dropMessagingTables();
        $this->migration()->up();

        $this->assertSame($grantsBefore, DB::table('model_has_permissions')->count());
        $this->assertTrue($viewer->fresh()->hasDirectWebPermission(Access::PERMISSION_VIEW));
    }

    public function test_the_migration_source_creates_permissions_without_assigning_them(): void
    {
        $source = (string) file_get_contents(database_path('migrations/'.self::MIGRATION));

        $this->assertDoesNotMatchRegularExpression('/->\s*(givePermissionTo|assignRole|syncPermissions|syncRoles)\s*\(/', $source);
        $this->assertDoesNotMatchRegularExpression(
            '/table\(\s*[\'"](model_has_permissions|model_has_roles|role_has_permissions)[\'"]\s*\)\s*->\s*(insert|insertOrIgnore|insertUsing|upsert|updateOrInsert)\b/',
            $source,
        );
    }

    public function test_down_refuses_to_run_outside_local_and_testing(): void
    {
        $member = $this->messagingMember();
        $this->memberSends($member);
        $original = $this->app['env'];

        try {
            foreach (['production', 'staging'] as $environment) {
                $this->app['env'] = $environment;
                $this->assertFailsWith(RuntimeException::class, fn () => $this->migration()->down());
            }
        } finally {
            $this->app['env'] = $original;
        }

        $this->assertSame('testing', app()->environment());
        $this->assertTrue(Schema::hasTable('employee_logistics_threads'));
        $this->assertTrue(Schema::hasTable('employee_logistics_messages'));
        $this->assertSame(1, EmployeeLogisticsThread::query()->count(), 'Conversations survive a refused rollback.');
        $this->assertSame(1, EmployeeLogisticsMessage::query()->count());
        $this->assertCount(2, $this->permissionIds());
    }

    public function test_the_refusal_explains_that_department_records_are_kept(): void
    {
        $original = $this->app['env'];
        $this->app['env'] = 'production';

        try {
            $exception = $this->assertFailsWith(RuntimeException::class, fn () => $this->migration()->down());
        } finally {
            $this->app['env'] = $original;
        }

        $this->assertStringContainsString('never dropped outside local or testing', $exception->getMessage());
    }

    public function test_down_in_the_testing_environment_removes_the_tables_and_every_grant_of_the_permissions(): void
    {
        $viewer = $this->logisticsViewer();
        $role = Role::findOrCreate('custom_reply_role', 'web');
        $role->givePermissionTo(Permission::findOrCreate(Access::PERMISSION_REPLY, 'web'));
        $other = $this->messagingAdmin([], ['admin.access']);
        $this->assertSame('testing', app()->environment());

        $this->migration()->down();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertFalse(Schema::hasTable('employee_logistics_messages'));
        $this->assertFalse(Schema::hasTable('employee_logistics_threads'));
        $this->assertSame(0, Permission::query()->whereIn('name', self::NAMES)->count());
        $this->assertSame(1, DB::table('model_has_permissions')->where('model_id', $viewer->id)->where('model_type', User::class)->count(), 'Only the unrelated admin.access grant remains.');
        $this->assertTrue($other->fresh()->hasDirectWebPermission('admin.access'), 'Unrelated grants are untouched.');
        $this->assertTrue($viewer->fresh()->hasDirectWebPermission('admin.access'));
        $this->assertFalse($viewer->fresh()->hasDirectWebPermission(Access::PERMISSION_VIEW));
        $this->assertSame(0, DB::table('role_has_permissions')->where('role_id', $role->id)->count());
    }

    public function test_up_after_down_restores_the_schema_and_permissions(): void
    {
        $this->migration()->down();
        $this->migration()->up();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertTrue(Schema::hasTable('employee_logistics_threads'));
        $this->assertTrue(Schema::hasTable('employee_logistics_messages'));
        $this->assertCount(2, $this->permissionIds());
        $this->assertNotNull($this->memberSends($this->messagingMember())->getKey(), 'The feature works on the re-created tables.');
    }

    // ------------------------------------------------------------ schema

    public function test_the_tables_have_the_documented_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('employee_logistics_threads', [
            'id', 'member_user_id', 'employee_profile_id', 'member_name', 'member_employee_number', 'member_rank',
            'member_station', 'subject', 'last_message_preview', 'last_message_side', 'last_message_at',
            'last_member_message_id', 'last_logistics_message_id', 'member_read_message_id',
            'logistics_read_message_id', 'logistics_read_by_user_id', 'logistics_read_at', 'message_count',
            'created_at', 'updated_at',
        ]));
        $this->assertTrue(Schema::hasColumns('employee_logistics_messages', [
            'id', 'thread_id', 'author_user_id', 'author_side', 'author_name', 'body', 'client_request_id',
            'created_at', 'updated_at',
        ]));
    }

    public function test_the_unique_index_covers_the_author_and_request_id_only(): void
    {
        $member = $this->messagingMember();
        $first = $this->memberSends($member);
        $row = static fn (int $authorId, int $threadId, string $requestId): array => [
            'thread_id' => $threadId, 'author_user_id' => $authorId, 'author_side' => 'member', 'author_name' => 'x',
            'body' => 'y', 'client_request_id' => $requestId, 'created_at' => now(), 'updated_at' => now(),
        ];
        $existingRequest = (string) DB::table('employee_logistics_messages')->where('id', $first->id)->value('client_request_id');
        $other = $this->messagingMember('Other Member');

        DB::table('employee_logistics_messages')->insert($row($other->id, $first->thread_id, $existingRequest));
        $this->assertFailsWith(UniqueConstraintViolationException::class, fn () => DB::table('employee_logistics_messages')->insert($row($member->id, $first->thread_id, $existingRequest)));
    }

    public function test_deleting_a_thread_removes_its_messages_and_deleting_an_account_keeps_the_conversation(): void
    {
        $member = $this->messagingMember();
        $first = $this->memberSends($member);
        $second = $this->memberSends($this->messagingMember('Other Member'));

        DB::table('employee_logistics_threads')->where('id', $second->thread_id)->delete();
        $this->assertSame(0, EmployeeLogisticsMessage::query()->where('thread_id', $second->thread_id)->count());

        DB::table('users')->where('id', $member->id)->delete();
        $thread = $this->storedThread($first);
        $this->assertNull($thread->member_user_id, 'The record outlives the account.');
        $this->assertNull(EmployeeLogisticsMessage::query()->findOrFail($first->id)->author_user_id);
        $this->assertSame('Pat Member', $thread->member_name);
    }

    public function test_no_attribute_of_either_model_is_mass_assignable(): void
    {
        $thread = new EmployeeLogisticsThread;
        $message = new EmployeeLogisticsMessage;

        foreach (['member_user_id', 'member_name', 'last_member_message_id', 'member_read_message_id', 'logistics_read_message_id', 'message_count'] as $column) {
            $this->assertFalse($thread->isFillable($column), $column);
        }
        foreach (['thread_id', 'author_user_id', 'author_side', 'author_name', 'body', 'client_request_id'] as $column) {
            $this->assertFalse($message->isFillable($column), $column);
        }
    }
}
