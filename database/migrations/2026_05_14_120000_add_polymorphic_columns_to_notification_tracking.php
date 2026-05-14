<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds polymorphic notifiable_type/notifiable_id columns to notification_tracking.
 *
 * All application code (NotificationService, CheckOverdueProjects, SendMilestoneReminders,
 * AnalyzeProjectPriorities) was written assuming a polymorphic schema, but the table was
 * originally created with a concrete project_id only. Every scheduled run crashed with
 * SQLSTATE[42703] "column notifiable_type does not exist".
 *
 * This migration is additive: project_id is kept (nullable) so historical rows and the
 * CapitalProject::notificationTracking() hasMany relation continue to work.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_tracking', function (Blueprint $table) {
            $table->string('notifiable_type')->nullable()->after('user_id');
            $table->unsignedBigInteger('notifiable_id')->nullable()->after('notifiable_type');
        });

        // Backfill existing rows so the new polymorphic lookups still find them.
        DB::table('notification_tracking')
            ->whereNull('notifiable_type')
            ->whereNotNull('project_id')
            ->update([
                'notifiable_type' => 'App\\Models\\CapitalProject',
                'notifiable_id' => DB::raw('project_id'),
            ]);

        Schema::table('notification_tracking', function (Blueprint $table) {
            // project_id was NOT NULL — callers no longer pass it explicitly, so relax it.
            $table->unsignedBigInteger('project_id')->nullable()->change();

            // Composite index matches the WHERE clauses used by all callers:
            //   where notifiable_type = ? and notifiable_id = ? and notification_type = ?
            //     and created_at >= ?
            $table->index(
                ['notifiable_type', 'notifiable_id', 'notification_type', 'created_at'],
                'notif_tracking_polymorphic_lookup_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('notification_tracking', function (Blueprint $table) {
            $table->dropIndex('notif_tracking_polymorphic_lookup_index');
            $table->dropColumn(['notifiable_type', 'notifiable_id']);
        });

        // Restore NOT NULL on project_id only if every row has a value.
        if (DB::table('notification_tracking')->whereNull('project_id')->doesntExist()) {
            Schema::table('notification_tracking', function (Blueprint $table) {
                $table->unsignedBigInteger('project_id')->nullable(false)->change();
            });
        }
    }
};
