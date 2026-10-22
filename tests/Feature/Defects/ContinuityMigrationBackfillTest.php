<?php

declare(strict_types=1);

namespace Tests\Feature\Defects;

use App\Models\Apparatus;
use App\Models\ApparatusInspection;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Runs the continuity migrations against populated tables. SQLite rebuilds a table to add a
 * foreign key, which needs foreign_keys=OFF and therefore no wrapping transaction, so this
 * class uses migrate:fresh instead of RefreshDatabase's transaction.
 */
final class ContinuityMigrationBackfillTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
    }

    public function test_backfill_migration_populates_existing_rows_and_the_down_migration_restores_the_legacy_index(): void
    {
        $apparatus = $this->apparatus();
        $reporter = User::factory()->create();
        $inspection = $this->inspection($apparatus, [], $reporter);
        $this->artisan('migrate:rollback', ['--path' => 'database/migrations/2026_10_22_100100_add_continuity_columns_to_apparatus_defects.php', '--force' => true]);
        $this->assertFalse(Schema::hasColumn('apparatus_defects', 'action_status'));
        $this->assertTrue(Schema::hasIndex('apparatus_defects', 'defect_dedup'));

        $base = ['apparatus_id' => $apparatus->id, 'compartment' => 'Cab', 'item' => 'Radio', 'issue_type' => 'missing', 'resolved' => false, 'created_at' => '2026-10-01 10:00:00', 'updated_at' => '2026-10-01 10:00:00'];
        $open = DB::table('apparatus_defects')->insertGetId($base + ['status' => 'in_progress', 'apparatus_inspection_id' => $inspection->id]);
        $closed = DB::table('apparatus_defects')->insertGetId(array_merge($base, ['item' => 'Light', 'resolved' => true, 'status' => 'resolved']));
        DB::table('apparatus_defect_observations')->insert([
            ['apparatus_defect_id' => $open, 'apparatus_inspection_id' => $inspection->id, 'actor_user_id' => $reporter->id, 'observation' => 'first_reported', 'created_at' => '2026-10-02 08:00:00', 'updated_at' => '2026-10-02 08:00:00'],
        ]);

        $this->artisan('migrate', ['--path' => 'database/migrations/2026_10_22_100100_add_continuity_columns_to_apparatus_defects.php', '--force' => true]);

        $row = DB::table('apparatus_defects')->find($open);
        $this->assertSame('action_pending', $row->action_status);
        $this->assertSame($inspection->id, (int) $row->first_reported_inspection_id);
        $this->assertSame($reporter->id, (int) $row->first_reported_by_user_id);
        $this->assertSame('2026-10-02 08:00:00', $row->first_reported_at);
        $this->assertSame($inspection->id, (int) $row->last_observed_inspection_id);
        $this->assertSame('routine', $row->severity);
        $closedRow = DB::table('apparatus_defects')->find($closed);
        $this->assertSame('resolved', $closedRow->action_status);
        $this->assertSame('2026-10-01 10:00:00', $closedRow->first_reported_at);
        $this->assertNull($closedRow->last_observed_inspection_id);
        $this->assertFalse(Schema::hasIndex('apparatus_defects', 'defect_dedup'));
        $this->assertTrue(Schema::hasIndex('apparatus_defects', 'apparatus_defects_open_name_unique'));
    }

    public function test_inspection_backfill_sets_the_processed_apparatus_for_processed_rows_only(): void
    {
        $apparatus = $this->apparatus();
        $other = $this->apparatus('E7');
        $path = 'database/migrations/2026_10_22_100000_add_continuity_columns_to_apparatus_inspections.php';
        $this->artisan('migrate:rollback', ['--path' => $path, '--force' => true]);
        $row = ['apparatus_id' => $apparatus->id, 'operator_name' => 'T', 'rank' => 'Captain', 'review_status' => 'approved', 'created_at' => now(), 'updated_at' => now()];
        $processed = DB::table('apparatus_inspections')->insertGetId($row + ['processing_status' => 'accepted']);
        $pending = DB::table('apparatus_inspections')->insertGetId($row + ['processing_status' => 'pending_vehicle_swap']);
        $legacy = DB::table('apparatus_inspections')->insertGetId($row);
        $swapped = DB::table('apparatus_inspections')->insertGetId($row + ['processing_status' => 'accepted_with_exception']);
        DB::table('apparatus_vehicle_swap_requests')->insert([
            'apparatus_inspection_id' => $swapped, 'apparatus_id' => $apparatus->id, 'confirmed_apparatus_id' => $other->id,
            'reporter_name' => 'T', 'reported_vehicle_number' => 'X', 'answer' => 'different', 'status' => 'approved',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->artisan('migrate', ['--path' => $path, '--force' => true]);

        $this->assertSame($apparatus->id, (int) DB::table('apparatus_inspections')->where('id', $processed)->value('processed_apparatus_id'));
        $this->assertNull(DB::table('apparatus_inspections')->where('id', $pending)->value('processed_apparatus_id'));
        $this->assertNull(DB::table('apparatus_inspections')->where('id', $legacy)->value('processed_apparatus_id'));
        $this->assertSame($other->id, (int) DB::table('apparatus_inspections')->where('id', $swapped)->value('processed_apparatus_id'));
    }

    private function apparatus(string $unit = 'E2'): Apparatus
    {
        Queue::fake();

        return Apparatus::create(['name' => 'Engine '.$unit, 'unit_id' => $unit, 'designation' => $unit, 'type' => 'Engine', 'vehicle_number' => 'TEST-'.$unit, 'status' => 'In Service']);
    }

    /** @param list<array<string, mixed>> $results */
    private function inspection(Apparatus $apparatus, array $results, ?User $actor = null): ApparatusInspection
    {
        return ApparatusInspection::create([
            'apparatus_id' => $apparatus->id, 'actor_user_id' => $actor?->id, 'operator_name' => 'Test Member', 'rank' => 'Captain',
            'review_status' => 'pending_review', 'results' => $results,
        ]);
    }
}
