<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\StationInspectionResource\Pages\ListStationInspections;
use App\Filament\Resources\StationInspectionResource\Pages\ViewStationInspection;
use App\Models\Station;
use App\Models\StationInspection;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use LogicException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class StationInspectionReviewWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_api_cannot_rewrite_or_delete_submitted_evidence(): void
    {
        $inspection = $this->inspection();
        $this->actingAs($this->stationAdmin(manage: true));

        $this->patchJson("/api/admin/station-inspections/{$inspection->id}", [
            'notes' => 'Rewritten evidence',
            'form_data' => ['checklist' => []],
        ])->assertStatus(405);
        $this->deleteJson("/api/admin/station-inspections/{$inspection->id}")->assertStatus(405);
        $this->postJson('/api/admin/station-inspections', ['station_id' => $inspection->station_id])->assertStatus(405);

        self::assertSame('Original evidence', $inspection->fresh()->notes);
        self::assertSame('app_doors', $inspection->fresh()->form_data['checklist'][0]['id']);
    }

    public function test_review_action_preserves_evidence_and_follow_up_note(): void
    {
        $inspection = $this->inspection();
        $reviewer = $this->stationAdmin(manage: true);
        $this->actingAs($reviewer);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->withoutVite();

        Livewire::test(ViewStationInspection::class, ['record' => $inspection->getRouteKey()])
            ->assertSuccessful()
            ->assertActionVisible('needsFollowUp')
            ->callAction('needsFollowUp', data: ['review_note' => 'Repair the door and document the follow-up.'])
            ->assertHasNoActionErrors()
            ->assertActionHidden('needsFollowUp');

        $reviewed = $inspection->fresh();
        self::assertSame('needs_follow_up', $reviewed->review_status);
        self::assertSame($reviewer->id, $reviewed->reviewed_by);
        self::assertSame('Repair the door and document the follow-up.', $reviewed->review_note);
        self::assertNotNull($reviewed->reviewed_at);
        self::assertSame('Original evidence', $reviewed->notes);
        self::assertSame('app_doors', $reviewed->form_data['checklist'][0]['id']);

        $this->getJson("/api/public/stations/{$reviewed->station_id}/inspections")
            ->assertOk()
            ->assertJsonPath('inspections.0.review_status', 'needs_follow_up')
            ->assertJsonMissing(['review_note' => $reviewed->review_note]);
    }

    public function test_submitted_evidence_and_terminal_review_cannot_change_through_model(): void
    {
        $inspection = $this->inspection();
        $reviewer = $this->stationAdmin(manage: true);

        try {
            $inspection->update(['notes' => 'Rewritten evidence']);
            self::fail('Submitted evidence must be immutable.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }

        app(\App\Services\StationInspectionReviewService::class)->review($inspection->id, $reviewer, 'reviewed');

        try {
            $inspection->fresh()->update(['review_status' => 'needs_follow_up']);
            self::fail('A terminal review decision must be immutable.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }

        try {
            $inspection->fresh()->delete();
            self::fail('Submitted evidence must not be deleted.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_view_only_station_admin_cannot_review(): void
    {
        $inspection = $this->inspection();
        $this->actingAs($this->stationAdmin(manage: false));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->withoutVite();

        Livewire::test(ViewStationInspection::class, ['record' => $inspection->getRouteKey()])
            ->assertSuccessful()
            ->assertActionHidden('acknowledgeInspection')
            ->assertActionHidden('needsFollowUp')
            ->assertActionHidden('archive')
            ->assertActionHidden('restore');
    }

    public function test_member_submission_checklist_signature_and_review_survive_archive_and_restore(): void
    {
        $station = Station::query()->create([
            'station_number' => 91, 'address' => 'QA Test Station', 'is_active' => true,
        ]);
        $member = $this->actingAsCanonicalFixture('QA-STATION-EVIDENCE');
        $signature = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aOioAAAAASUVORK5CYII=';
        $submission = $this->postJson('/api/public/station_inspection', [
            'station' => 'Station 91',
            'inspection_type' => '[QA TEST] Station inspection',
            'date' => today()->toDateString(),
            'checklist' => [[
                'id' => 'app_doors', 'label' => 'Apparatus Doors', 'category' => 'Apparatus Area', 'status' => 'pass',
            ]],
            'signature' => $signature,
            'notes' => '[QA TEST] Original submitted evidence',
            'sog_mandate_acknowledged' => true,
        ])->assertCreated();
        $inspection = StationInspection::findOrFail($submission->json('id'));
        self::assertSame($member->id, $inspection->inspector_id);

        $admin = $this->stationAdmin(manage: true);
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->withoutVite();
        $this->get("/admin/station-inspections/{$inspection->id}")
            ->assertOk()->assertSee('Apparatus Doors')->assertSee('src="'.$signature.'"', false);

        Livewire::test(ViewStationInspection::class, ['record' => $inspection->id])
            ->callAction('acknowledgeInspection', data: ['review_note' => '[QA TEST] Acknowledged'])
            ->assertHasNoActionErrors()
            ->callAction('archive', data: ['archive_reason' => '[QA TEST] Complete review'])
            ->assertHasNoActionErrors()
            ->assertActionVisible('restore');

        $inspection->refresh();
        self::assertSame($admin->id, $inspection->archived_by);
        self::assertSame('reviewed', $inspection->review_status);
        self::assertSame($signature, $inspection->inspector_signature);
        self::assertSame('[QA TEST] Original submitted evidence', $inspection->notes);

        Livewire::test(ListStationInspections::class)
            ->call('loadTable')
            ->assertCanNotSeeTableRecords([$inspection])
            ->filterTable('archive_state', 'archived')
            ->assertCanSeeTableRecords([$inspection])
            ->callTableAction('restore', $inspection)->assertHasNoTableActionErrors()
            ->filterTable('archive_state', 'active')
            ->assertCanSeeTableRecords([$inspection]);
        self::assertNull($inspection->fresh()->archived_at);
        self::assertSame('[QA TEST] Acknowledged', $inspection->fresh()->review_note);

        $this->expectException(LogicException::class);
        $inspection->fresh()->delete();
    }

    public function test_migration_preserves_and_backfills_historical_reviewed_evidence(): void
    {
        $inspection = $this->inspection();
        $reviewer = User::factory()->create();
        DB::table('station_inspections')->where('id', $inspection->id)->update([
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
        ]);
        $original = DB::table('station_inspections')->where('id', $inspection->id)->first();

        Schema::table('station_inspections', function (Blueprint $table): void {
            $table->dropIndex('station_inspections_review_index');
            $table->dropColumn(['review_status', 'review_note']);
        });

        (require database_path('migrations/2026_09_27_120000_add_station_inspection_review_lifecycle.php'))->up();

        $historical = DB::table('station_inspections')->where('id', $inspection->id)->first();
        self::assertSame($original->form_data, $historical->form_data);
        self::assertSame($original->notes, $historical->notes);
        self::assertSame($reviewer->id, $historical->reviewed_by);
        self::assertSame('reviewed', $historical->review_status);
    }

    public function test_station_parent_deletion_cannot_cascade_archived_inspection_evidence(): void
    {
        $inspection = $this->inspection();
        app(\App\Services\OperationalEvidenceArchiveService::class)->archive($inspection, $this->stationAdmin(manage: true), '[QA TEST] retained');
        try {
            $inspection->station->delete();
            self::fail('A station cannot erase archived inspection evidence through cascade deletion.');
        } catch (LogicException $exception) {
            self::assertStringContainsString('cannot be deleted', $exception->getMessage());
        }
        $this->assertDatabaseHas('station_inspections', ['id' => $inspection->id, 'notes' => 'Original evidence']);
        $this->assertDatabaseHas('stations', ['id' => $inspection->station_id]);
        $inspection->station->update(['is_active' => false]);
        self::assertNotNull($inspection->fresh()->archived_at);
    }

    private function inspection(): StationInspection
    {
        $station = Station::query()->create([
            'station_number' => 93,
            'name' => 'Station 93',
            'address' => '93 Test Street',
            'is_active' => true,
        ]);

        return StationInspection::query()->create([
            'station_id' => $station->id,
            'inspector_id' => User::factory()->create()->id,
            'inspection_date' => '2026-09-27',
            'inspection_type' => 'Station inspection',
            'form_data' => ['checklist' => [['id' => 'app_doors', 'status' => 'fail']]],
            'overall_status' => 'fail',
            'notes' => 'Original evidence',
        ]);
    }

    private function stationAdmin(bool $manage): User
    {
        $user = User::factory()->create();
        $permissions = ['admin.access', 'admin.stations.view'];
        if ($manage) {
            $permissions[] = 'admin.stations.manage';
        }

        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }

        return $user;
    }
}
