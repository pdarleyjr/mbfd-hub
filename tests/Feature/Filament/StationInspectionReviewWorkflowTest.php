<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\StationInspectionResource\Pages\ViewStationInspection;
use App\Models\Station;
use App\Models\StationInspection;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use LogicException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class StationInspectionReviewWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_station_admin_reviews_submitted_evidence_without_rewriting_it(): void
    {
        $inspection = $this->inspection();
        $reviewer = $this->stationAdmin(manage: true);

        $this->actingAs($reviewer);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->withoutVite();

        Livewire::test(ViewStationInspection::class, ['record' => $inspection->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('Pending Review')
            ->assertSee('Inspector Signature')
            ->assertActionVisible('acknowledgeInspection')
            ->assertActionVisible('needsFollowUp')
            ->callAction('needsFollowUp', data: ['review_note' => 'Repair the bay-door interlock and document the follow-up.'])
            ->assertHasNoActionErrors()
            ->assertActionHidden('acknowledgeInspection')
            ->assertActionHidden('needsFollowUp');

        $reviewed = $inspection->fresh();
        self::assertSame('needs_follow_up', $reviewed->review_status);
        self::assertSame($reviewer->id, $reviewed->reviewed_by);
        self::assertSame('Repair the bay-door interlock and document the follow-up.', $reviewed->review_note);
        self::assertNotNull($reviewed->reviewed_at);
        self::assertSame('2026-09-06', $reviewed->inspection_date->format('Y-m-d'));
        self::assertSame('data:image/png;base64,immutable-signature', $reviewed->inspector_signature);
        self::assertSame(['checklist' => [['id' => 'bay-door', 'status' => 'fail']]], $reviewed->form_data);

        $this->getJson("/api/public/stations/{$reviewed->station_id}/inspections")
            ->assertOk()
            ->assertJsonPath('inspections.0.review_status', 'needs_follow_up')
            ->assertJsonMissing(['review_note' => $reviewed->review_note])
            ->assertJsonMissing(['inspector_signature' => $reviewed->inspector_signature]);
    }

    public function test_station_inspection_evidence_and_terminal_review_decision_are_immutable(): void
    {
        $inspection = $this->inspection();
        $reviewer = $this->stationAdmin(manage: true);

        app(\App\Services\StationInspectionReviewService::class)->review(
            $inspection->id,
            $reviewer,
            'reviewed',
            'Evidence acknowledged.',
        );

        try {
            $inspection->fresh()->update(['notes' => 'Rewritten evidence']);
            self::fail('Submitted evidence must not be mutable.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }

        try {
            $inspection->fresh()->update(['review_status' => 'needs_follow_up']);
            self::fail('A terminal review decision must not be mutable.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }

        try {
            $inspection->fresh()->delete();
            self::fail('Submitted evidence must not be deletable.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_station_viewer_cannot_use_review_actions(): void
    {
        $inspection = $this->inspection();
        $viewer = $this->stationAdmin(manage: false);

        $this->actingAs($viewer);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->withoutVite();

        Livewire::test(ViewStationInspection::class, ['record' => $inspection->getRouteKey()])
            ->assertSuccessful()
            ->assertActionHidden('acknowledgeInspection')
            ->assertActionHidden('needsFollowUp');

        self::assertSame('pending_review', $inspection->fresh()->review_status);
    }

    private function inspection(): StationInspection
    {
        $station = Station::query()->create([
            'station_number' => 93,
            'name' => 'Station 93',
            'address' => '93 Workflow Way',
            'is_active' => true,
        ]);
        $inspector = User::factory()->create();

        return StationInspection::query()->create([
            'station_id' => $station->id,
            'inspector_id' => $inspector->id,
            'inspection_date' => '2026-09-06',
            'inspection_type' => 'Saturday Station Inspection',
            'form_data' => ['checklist' => [['id' => 'bay-door', 'status' => 'fail']]],
            'overall_status' => 'fail',
            'inspector_signature' => 'data:image/png;base64,immutable-signature',
            'notes' => 'Original submitted note.',
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
