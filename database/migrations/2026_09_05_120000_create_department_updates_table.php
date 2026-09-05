<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('department_updates', function (Blueprint $table): void {
            $table->id();
            $table->string('title', 180);
            $table->text('body');
            $table->enum('category', ['general', 'training', 'operations', 'it', 'administration', 'urgent']);
            $table->enum('priority', ['normal', 'important', 'critical'])->default('normal');
            $table->enum('status', ['draft', 'published', 'archived'])->default('draft');
            $table->boolean('is_pinned')->default(false);
            $table->timestampTz('publish_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->string('cta_label', 80)->nullable();
            $table->string('cta_url', 2048)->nullable();
            $table->string('image_path', 1024)->nullable();
            $table->string('image_name', 255)->nullable();
            $table->string('attachment_path', 1024)->nullable();
            $table->string('attachment_name', 255)->nullable();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('send_in_app')->default(false);
            $table->boolean('send_web_push')->default(false);
            $table->enum('audience', ['everyone', 'officers', 'driver_engineers', 'firefighters', 'administration', 'selected'])
                ->default('everyone');
            $table->json('audience_user_ids')->nullable();
            $table->timestampTz('notification_sent_at')->nullable();
            $table->timestampTz('notification_prepared_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['status', 'publish_at'], 'department_updates_status_publish_idx');
            $table->index(['status', 'expires_at'], 'department_updates_status_expire_idx');
            $table->index(['is_pinned', 'publish_at'], 'department_updates_pin_publish_idx');
            $table->index('notification_sent_at');
            $table->index('notification_prepared_at');
        });

        Schema::create('department_update_notification_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('department_update_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 64);
            $table->uuid('notification_id')->unique();
            $table->timestampTz('delivered_at')->nullable()->index();
            $table->timestampsTz();
            $table->unique(['department_update_id', 'user_id', 'channel'], 'department_update_delivery_unique');
        });

        $now = now();
        DB::table('users')->orderBy('id')->pluck('id')->each(function (int $userId) use ($now): void {
            DB::table('user_notification_subscriptions')->insertOrIgnore([
                'user_id' => $userId,
                'event_key' => 'department_updates',
                'database_enabled' => true,
                'webpush_enabled' => true,
                'email_enabled' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });

        $adminAccessPermissionId = DB::table('permissions')
            ->where('guard_name', 'web')
            ->where('name', 'admin.access')
            ->value('id');
        $communicationsPermissionIds = DB::table('permissions')
            ->where('guard_name', 'web')
            ->whereIn('name', ['admin.communications.view', 'admin.communications.send'])
            ->pluck('id');
        if ($adminAccessPermissionId !== null && $communicationsPermissionIds->count() === 2) {
            $adminUserIds = DB::table('model_has_permissions')
                ->where('permission_id', $adminAccessPermissionId)
                ->where('model_type', 'App\\Models\\User')
                ->pluck('model_id');
            $adminUserIds->each(function (int $userId) use ($communicationsPermissionIds): void {
                $communicationsPermissionIds->each(function (int $permissionId) use ($userId): void {
                    DB::table('model_has_permissions')->insertOrIgnore([
                        'permission_id' => $permissionId,
                        'model_type' => 'App\\Models\\User',
                        'model_id' => $userId,
                    ]);
                });
            });
        }
    }

    public function down(): void
    {
        DB::table('user_notification_subscriptions')->where('event_key', 'department_updates')->delete();
        Schema::dropIfExists('department_update_notification_deliveries');
        Schema::dropIfExists('department_updates');
    }
};
