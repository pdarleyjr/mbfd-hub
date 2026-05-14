<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\NotificationTracking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Regression test for the schema/code mismatch that crashed CheckOverdueProjects,
 * SendMilestoneReminders, AnalyzeProjectPriorities, and NotificationService
 * on every scheduler tick. Production log:
 *
 *   SQLSTATE[42703]: Undefined column ... column "notifiable_type" does not exist
 *
 * The polymorphic columns must exist and the model must persist/lookup them correctly.
 */
class NotificationTrackingTest extends TestCase
{
    use RefreshDatabase;

    public function test_polymorphic_columns_exist_on_notification_tracking(): void
    {
        $this->assertTrue(
            Schema::hasColumn('notification_tracking', 'notifiable_type'),
            'notification_tracking.notifiable_type column must exist (see CheckOverdueProjects.php)',
        );
        $this->assertTrue(
            Schema::hasColumn('notification_tracking', 'notifiable_id'),
            'notification_tracking.notifiable_id column must exist (see CheckOverdueProjects.php)',
        );
    }

    public function test_project_id_is_nullable_after_migration(): void
    {
        $user = User::factory()->create();

        // Callers in CheckOverdueProjects / NotificationService no longer pass project_id;
        // the migration must allow inserts without it.
        $tracking = NotificationTracking::create([
            'user_id' => $user->id,
            'notifiable_type' => 'App\\Models\\CapitalProject',
            'notifiable_id' => 42,
            'notification_type' => 'overdue_project',
        ]);

        $this->assertNotNull($tracking->id);
        $this->assertNull($tracking->project_id);
    }

    public function test_sent_at_is_auto_filled_by_model(): void
    {
        $user = User::factory()->create();

        $tracking = NotificationTracking::create([
            'user_id' => $user->id,
            'notifiable_type' => 'App\\Models\\CapitalProject',
            'notifiable_id' => 1,
            'notification_type' => 'overdue_project',
        ]);

        // sent_at is NOT NULL in the schema; callers never set it, so the model
        // booted() hook must populate it or the INSERT would fail with NOT NULL.
        $this->assertNotNull($tracking->fresh()->sent_at);
    }

    public function test_polymorphic_lookup_matches_inserted_row(): void
    {
        $user = User::factory()->create();

        NotificationTracking::create([
            'user_id' => $user->id,
            'notifiable_type' => 'App\\Models\\CapitalProject',
            'notifiable_id' => 99,
            'notification_type' => 'overdue_project',
        ]);

        // Mirrors the WHERE clause used by CheckOverdueProjects::notifyOverdueProject().
        $exists = NotificationTracking::query()
            ->where('user_id', $user->id)
            ->where('notifiable_type', 'App\\Models\\CapitalProject')
            ->where('notifiable_id', 99)
            ->where('notification_type', 'overdue_project')
            ->where('created_at', '>=', now()->startOfDay())
            ->exists();

        $this->assertTrue($exists);
    }
}
