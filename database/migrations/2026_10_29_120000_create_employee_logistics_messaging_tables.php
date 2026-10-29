<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * Employee <-> Logistics messaging (docs/employee-logistics-messaging.md). Additive only.
 *
 * Two dedicated tables (never shared with email, 300 Command, support tickets or inspection
 * issue messages) plus the two admin capabilities, granted to nobody: the production deploy gate
 * refuses any role or permission change on established accounts, so access beyond super
 * administrators and the logistics_admin role is given in the Admin capability editor.
 *
 * Read markers are message ids, not timestamps, so a reply arriving in the same second as a
 * view can never be swallowed.
 */
return new class extends Migration
{
    private const PERMISSIONS = ['admin.employee_messages.view', 'admin.employee_messages.reply'];

    public function up(): void
    {
        Schema::create('employee_logistics_threads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('member_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('employee_profile_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('member_name', 160);
            $table->string('member_employee_number', 32)->nullable();
            $table->string('member_rank', 80)->nullable();
            $table->string('member_station', 80)->nullable();
            $table->string('subject', 120)->nullable();
            $table->string('last_message_preview', 200)->nullable();
            $table->string('last_message_side', 16)->nullable();
            $table->timestampTz('last_message_at')->nullable();
            $table->unsignedBigInteger('last_member_message_id')->nullable();
            $table->unsignedBigInteger('last_logistics_message_id')->nullable();
            $table->unsignedBigInteger('member_read_message_id')->nullable();
            $table->unsignedBigInteger('logistics_read_message_id')->nullable();
            $table->foreignId('logistics_read_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('logistics_read_at')->nullable();
            $table->unsignedInteger('message_count')->default(0);
            $table->timestampsTz();

            $table->index(['member_user_id', 'last_message_at'], 'emp_log_threads_member_recent');
            $table->index('last_message_at', 'emp_log_threads_recent');
        });

        Schema::create('employee_logistics_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('thread_id')->constrained('employee_logistics_threads')->cascadeOnDelete();
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('author_side', 16);
            $table->string('author_name', 160);
            $table->text('body');
            $table->string('client_request_id', 64);
            $table->timestampsTz();

            $table->unique(['author_user_id', 'client_request_id'], 'emp_log_messages_author_request_unique');
            $table->index(['thread_id', 'id'], 'emp_log_messages_thread_order');
        });

        $now = now();
        foreach (self::PERMISSIONS as $permission) {
            DB::table('permissions')->insertOrIgnore([
                'name' => $permission, 'guard_name' => 'web', 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('Employee messages are department records and are never dropped outside local or testing.');
        }

        Schema::dropIfExists('employee_logistics_messages');
        Schema::dropIfExists('employee_logistics_threads');

        $ids = DB::table('permissions')->where('guard_name', 'web')->whereIn('name', self::PERMISSIONS)->pluck('id');
        DB::table('model_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
