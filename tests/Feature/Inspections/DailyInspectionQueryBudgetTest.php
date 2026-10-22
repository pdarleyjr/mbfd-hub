<?php

declare(strict_types=1);

namespace Tests\Feature\Inspections;

use App\Filament\Widgets\StationOperationsHubWidget;
use App\Models\ApparatusDefect;
use App\Models\ApparatusInspection;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Feature\Inspections\Concerns\BuildsDailyScenarioWorld;
use Tests\TestCase;

/**
 * N+1 and heavy-query guard (Agent F). Each surface is measured with 2 and
 * then with 10 open findings on the same vehicle. A constant overhead is fine;
 * a cost that grows with the number of findings is not.
 */
final class DailyInspectionQueryBudgetTest extends TestCase
{
    use BuildsDailyScenarioWorld;
    use RefreshDatabase;

    /** Extra queries tolerated when going from 2 to 10 findings (cache warm-up jitter, never per-finding cost). */
    private const GROWTH_TOLERANCE = 6;

    private User $alpha;

    private User $beta;

    private User $reviewer;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Notification::fake();
        $this->buildWorld();
        $this->alpha = $this->newMember('Alpha Member');
        $this->beta = $this->newMember('Beta Member');
        $this->reviewer = $this->newReviewer('Logistics Reviewer', ['view_any_defect', 'view_defect']);
    }

    public function test_the_canonical_page_member_page_checklist_widget_and_submission_do_not_grow_with_the_number_of_findings(): void
    {
        // Baseline: a routine inspection with no findings.
        $this->actAs($this->alpha);
        $this->submit($this->payload(null, 1010, 401.0))->assertCreated();
        $baseline = $this->measureSurfaces(ApparatusInspection::query()->latest('id')->firstOrFail());

        // Alpha reports 2 findings.
        $this->travel(1)->days();
        $this->actAs($this->alpha);
        $small = $this->findingsPayload(2, 1020, 402.0);
        $smallSubmitQueries = $this->queryCount(fn () => $this->submit($small)->assertCreated());
        $smallInspection = ApparatusInspection::query()->latest('id')->firstOrFail();
        $this->assertSame(2, ApparatusDefect::query()->unresolved()->count());

        $smallMetrics = $this->measureSurfaces($smallInspection);

        // Beta reports 10 findings (the same 2 plus 8 new ones).
        $this->travel(1)->days();
        $this->actAs($this->beta);
        $large = $this->findingsPayload(10, 1050, 405.0);
        $largeSubmitQueries = $this->queryCount(fn () => $this->submit($large)->assertCreated());
        $largeInspection = ApparatusInspection::query()->latest('id')->firstOrFail();
        $this->assertSame(10, ApparatusDefect::query()->unresolved()->count());
        $this->assertSame(10, $largeInspection->defects()->count() + $this->observedElsewhere($largeInspection));

        $largeMetrics = $this->measureSurfaces($largeInspection);

        $report = json_encode(['baseline' => $baseline, 'small' => $smallMetrics, 'large' => $largeMetrics, 'submit' => [$smallSubmitQueries, $largeSubmitQueries]], JSON_THROW_ON_ERROR);
        fwrite(STDERR, "\n[query-budget] ".$report."\n");
        // The member page is covered by DailyInspectionKnownDefectsTest (it issues queries per finding).
        foreach (['canonical_admin_page', 'checklist_open_findings', 'dashboard_widget'] as $surface) {
            $this->assertLessThanOrEqual(
                $smallMetrics[$surface] + self::GROWTH_TOLERANCE,
                $largeMetrics[$surface],
                "{$surface} grows with the number of findings (N+1): {$report}",
            );
        }
        // Writes legitimately grow with findings (defect, observation, event, participant), but must stay linear and small.
        $perExtraFinding = ($largeSubmitQueries - $smallSubmitQueries) / 8;
        $this->assertLessThanOrEqual(12, $perExtraFinding, "Submission costs {$perExtraFinding} queries per extra finding: {$report}");
        // Whatever the framework spends to boot an admin page, ten findings may add only a bounded amount.
        $this->assertLessThanOrEqual($baseline['canonical_admin_page'] + 40, $largeMetrics['canonical_admin_page'], $report);
        $this->assertLessThanOrEqual(40, $largeMetrics['checklist_open_findings'], $report);
        $this->assertLessThanOrEqual(80, $largeMetrics['dashboard_widget'], $report);
    }

    /** @return array{canonical_admin_page: int, member_page: int, checklist_open_findings: int, dashboard_widget: int} */
    private function measureSurfaces(ApparatusInspection $inspection): array
    {
        $owner = User::query()->findOrFail($inspection->actor_user_id);

        $this->actAs($this->reviewer);
        $this->get('/admin/inspections/'.$inspection->id)->assertOk(); // warm-up: permission and config caches
        $admin = $this->queryCount(fn () => $this->get('/admin/inspections/'.$inspection->id)->assertOk());

        $this->actAs($owner);
        $this->get('/employee/my-vehicle-inspections/'.$inspection->id)->assertOk();
        $member = $this->queryCount(fn () => $this->get('/employee/my-vehicle-inspections/'.$inspection->id)->assertOk());

        $this->getJson($this->checklistUrl())->assertOk();
        $checklist = $this->queryCount(fn () => $this->getJson($this->checklistUrl())->assertOk());

        $this->actAs($this->reviewer);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(StationOperationsHubWidget::class)->assertSuccessful();
        $widget = $this->queryCount(fn () => Livewire::test(StationOperationsHubWidget::class)->assertSuccessful());

        return [
            'canonical_admin_page' => $admin,
            'member_page' => $member,
            'checklist_open_findings' => $checklist,
            'dashboard_widget' => $widget,
        ];
    }

    /** Defects this inspection confirmed that were first reported by an earlier inspection. */
    private function observedElsewhere(ApparatusInspection $inspection): int
    {
        return (int) DB::table('apparatus_defect_observations')
            ->where('apparatus_inspection_id', $inspection->id)
            ->where('observation', 'confirmed_again')
            ->count();
    }
}
