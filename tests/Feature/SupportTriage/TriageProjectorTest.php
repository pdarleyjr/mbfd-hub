<?php

declare(strict_types=1);

namespace Tests\Feature\SupportTriage;

use App\Jobs\SupportTriage\ReapplyTriagePolicy;
use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusInspection;
use App\Models\Station;
use App\Models\StationRequest;
use App\Models\StationSupplyRequest;
use App\Models\SupportTriage\SupportEquipmentRule;
use App\Models\SupportTriage\SupportTriageLevel;
use App\Models\User;
use App\Services\StationRequestSideEffectService;
use App\Services\SupportTriage\Contracts\EquipmentClassifier;
use App\Services\SupportTriage\EquipmentClassification as C;
use App\Services\SupportTriage\EquipmentRuleService;
use App\Services\SupportTriage\TriageProjector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Dashboards\VehicleDashboardFixtures;
use Tests\Feature\Defects\Concerns\BuildsContinuityFixtures;
use Tests\TestCase;

final class TriageProjectorTest extends TestCase
{
    use BuildsContinuityFixtures;
    use RefreshDatabase;
    use VehicleDashboardFixtures;

    private TriageProjector $projector;

    private Apparatus $rig;

    private User $reporter;

    private ApparatusInspection $inspection;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpVehicleDashboard();
        app(EquipmentRuleService::class)->seedDefaults();
        $this->projector = app(TriageProjector::class);
        $this->rig = $this->rig('Engine 1', 'E1');
        $this->rig->update(['type' => 'Engine', 'daily_checkout_template' => 'engine']);
        $this->reporter = $this->member('Riley Reporter');
        $this->inspection = $this->inspectionBy($this->rig, $this->reporter, '2026-10-30 12:00:00');
        $this->staff = $this->personas()['admin'];
    }

    private function defect(string $item, array $attributes = []): ApparatusDefect
    {
        $defect = $this->openDefect($this->rig, $this->inspection, $this->reporter, $item);
        // Checkouts project through ApparatusInspectionProcessingService; these fixtures are created directly.
        app(TriageProjector::class)->refreshDefect($defect);
        if ($attributes !== []) {
            $defect->forceFill($attributes)->save();
        }

        return $defect->refresh();
    }

    private function level(ApparatusDefect $defect): ?string
    {
        return $this->projector->levelFor('apparatus_defect', (int) $defect->id);
    }

    private function stationRequest(array $attributes = []): StationRequest
    {
        $station = Station::query()->firstOrCreate(['station_number' => 1], ['name' => 'Station 1', 'address' => '1 Test Street', 'is_active' => true]);

        return StationRequest::query()->create(array_merge([
            'station_id' => $station->id,
            'requester_name_snapshot' => 'Pat Requester',
            'request_type' => 'repair_service',
            'subject_type' => 'kitchen',
            'title' => 'Dayroom A/C is not working',
            'description' => 'It is hot in here.',
            'priority' => 'normal',
            'status' => 'pending',
        ], $attributes))->refresh();
    }

    public function test_a_new_equipment_issue_gets_its_level_from_the_catalog(): void
    {
        $litter = $this->defect('Kitty Litter');
        $meter = $this->defect('Gas Meter');
        $scba = $this->defect('SCBA #1');

        $this->assertSame(C::LOW, $this->level($litter));
        $this->assertSame(C::MEDIUM, $this->level($meter));
        $this->assertSame(C::HIGH, $this->level($scba));
        $row = SupportTriageLevel::query()->where('subject_id', $scba->id)->firstOrFail();
        $this->assertSame('scba_unit', $row->category);
        $this->assertSame('air_tech', $row->handler);
        $this->assertFalse($row->unclassified);
        $this->assertSame(app(EquipmentClassifier::class)->policyVersion(), $row->policy_version);
    }

    public function test_the_levels_use_the_unit_checklist_for_scoped_rules(): void
    {
        $service = app(EquipmentRuleService::class);
        $service->create(['name' => 'Kitty Litter', 'level' => C::MEDIUM, 'category' => 'consumable', 'apparatus_scope' => 'engine'], $this->staff);

        $this->assertSame(C::MEDIUM, $this->level($this->defect('Kitty Litter')));
    }

    public function test_a_significant_flag_always_reads_high_and_clearing_it_goes_back(): void
    {
        $defect = $this->defect('Kitty Litter');
        $this->assertSame(C::LOW, $this->level($defect));

        $defect->forceFill(['severity' => 'significant'])->save();
        $this->assertSame(C::HIGH, $this->level($defect));

        $this->projector->setOverride('apparatus_defect', (int) $defect->id, C::LOW, $this->staff);
        $row = SupportTriageLevel::query()->where('subject_id', $defect->id)->firstOrFail();
        $this->assertSame(C::HIGH, $row->level, 'a flag wins over an override');
        $this->assertSame(C::LOW, $row->override_level, 'but the override is remembered');

        $defect->forceFill(['severity' => 'routine'])->save();
        $this->assertSame(C::LOW, $this->level($defect), 'with the flag gone, the override applies again');
    }

    public function test_out_of_service_reads_high(): void
    {
        $defect = $this->defect('Kitty Litter');

        $defect->forceFill(['operational_impact' => 'out_of_service'])->save();

        $this->assertSame(C::HIGH, $this->level($defect));
        $this->assertContains('out_of_service', SupportTriageLevel::query()->where('subject_id', $defect->id)->firstOrFail()->reasons);
    }

    public function test_a_staff_override_can_be_set_and_cleared(): void
    {
        $defect = $this->defect('Kitty Litter');

        $row = $this->projector->setOverride('apparatus_defect', (int) $defect->id, C::HIGH, $this->staff);
        $this->assertSame(C::HIGH, $row->level);
        $this->assertSame(C::LOW, $row->auto_level);
        $this->assertSame($this->staff->id, $row->override_by_user_id);
        $this->assertNotNull($row->override_at);

        $cleared = $this->projector->setOverride('apparatus_defect', (int) $defect->id, null, $this->staff);
        $this->assertSame(C::LOW, $cleared->level);
        $this->assertNull($cleared->override_level);
        $this->assertNull($cleared->override_by_user_id);

        $this->expectException(ValidationException::class);
        $this->projector->setOverride('apparatus_defect', (int) $defect->id, 'urgent', $this->staff);
    }

    public function test_an_admin_change_is_previewed_read_only_then_reapplied_keeping_overrides(): void
    {
        $service = app(EquipmentRuleService::class);
        $admin = $this->staff;
        $plain = $this->defect('Pike Pole');
        $other = $this->defect('Shovel');
        $kept = $this->defect('Halligan');
        $this->projector->setOverride('apparatus_defect', (int) $kept->id, C::HIGH, $admin);
        $this->assertSame([C::LOW, C::LOW, C::HIGH], [$this->level($plain), $this->level($other), $this->level($kept)]);

        $service->setLevel(SupportEquipmentRule::query()->where('name', 'Pike poles')->firstOrFail(), C::MEDIUM, $admin);
        $service->setLevel(SupportEquipmentRule::query()->where('name', 'Forcible entry hand tools')->firstOrFail(), C::MEDIUM, $admin);

        $before = DB::table('support_triage_levels')->orderBy('id')->get()->all();
        $preview = $this->projector->previewReapply();

        $this->assertSame(3, $preview['open_records']);
        $this->assertSame(1, $preview['changes'], 'only the pike pole moves; the Halligan keeps its override');
        $this->assertSame(1, $preview['overridden_kept']);
        $this->assertSame(['low' => 2, 'medium' => 0, 'high' => 1, 'none' => 0], $preview['by_level_before']);
        $this->assertSame(['low' => 1, 'medium' => 1, 'high' => 1], $preview['by_level_after']);
        $this->assertEquals($before, DB::table('support_triage_levels')->orderBy('id')->get()->all(), 'the preview writes nothing');

        $changed = $this->projector->reapply();

        $this->assertGreaterThanOrEqual(1, $changed);
        $this->assertSame(C::MEDIUM, $this->level($plain));
        $this->assertSame(C::LOW, $this->level($other));
        $this->assertSame(C::HIGH, $this->level($kept), 'the override survives');
        $this->assertSame(C::HIGH, SupportTriageLevel::query()->where('subject_id', $kept->id)->firstOrFail()->override_level);
        $this->assertSame(app(EquipmentClassifier::class)->policyVersion(), SupportTriageLevel::query()->where('subject_id', $plain->id)->firstOrFail()->policy_version);
    }

    public function test_reapply_only_looks_at_open_records_and_sends_nothing(): void
    {
        Notification::fake();
        Queue::fake();
        Bus::fake();
        $open = $this->defect('Pike Pole');
        $closed = $this->defect('Shovel');
        $closed->forceFill(['resolved' => true])->save();
        SupportTriageLevel::query()->where('subject_id', $closed->id)->delete();

        $this->projector->reapply();

        $this->assertNotNull($this->level($open));
        $this->assertNull($this->level($closed), 'a closed issue is not projected again');
        Notification::assertNothingSent();
        Queue::assertNothingPushed();
        Bus::assertNothingDispatched();
    }

    public function test_the_preview_counts_unprojected_open_records(): void
    {
        $defect = $this->defect('Pike Pole');
        SupportTriageLevel::query()->where('subject_id', $defect->id)->delete();

        $preview = $this->projector->previewReapply();

        $this->assertSame(1, $preview['by_level_before']['none']);
        $this->assertSame(1, $preview['changes']);
    }

    public function test_a_station_request_is_projected_before_its_alert_and_keeps_the_members_priority(): void
    {
        $request = $this->stationRequest(['priority' => 'normal']);
        $this->assertNull($this->projector->levelFor('station_request', $request->id), 'not projected until the submission side effects run');

        app(StationRequestSideEffectService::class)->requestCreated($request);

        $this->assertSame(C::MEDIUM, $this->projector->levelFor('station_request', $request->id));
        $this->assertSame('normal', $request->fresh()->priority, 'the member priority is untouched');
        $this->assertSame('station_facility', SupportTriageLevel::query()->where('subject_type', 'station_request')->firstOrFail()->category);

        $urgent = $this->stationRequest(['title' => 'Need paper towels', 'description' => 'Out of them', 'priority' => 'high']);
        app(StationRequestSideEffectService::class)->requestCreated($urgent);
        $this->assertSame(C::HIGH, $this->projector->levelFor('station_request', $urgent->id));
        $this->assertContains('member_priority', SupportTriageLevel::query()->where('subject_id', $urgent->id)->where('subject_type', 'station_request')->firstOrFail()->reasons);

        $routine = $this->stationRequest(['title' => 'Need paper towels', 'description' => 'Out of them']);
        app(StationRequestSideEffectService::class)->requestCreated($routine);
        $this->assertSame(C::LOW, $this->projector->levelFor('station_request', $routine->id));
    }

    public function test_station_request_items_count_as_text_and_hazards_are_high(): void
    {
        $request = $this->stationRequest(['title' => 'Supplies', 'description' => 'Please send']);
        $request->items()->create(['item_name' => 'Bunker coat', 'category' => 'ppe', 'quantity' => 1]);
        $this->assertSame(C::HIGH, $this->projector->refreshStationRequest($request->refresh())->level);

        $sparks = $this->stationRequest(['title' => 'Outlet is sparking', 'description' => 'Kitchen']);
        $this->assertSame(C::HIGH, $this->projector->refreshStationRequest($sparks)->level);
    }

    public function test_a_supply_request_is_projected_and_can_be_overridden(): void
    {
        $station = Station::query()->firstOrCreate(['station_number' => 2], ['name' => 'Station 2', 'address' => '2 Test Street', 'is_active' => true]);
        $supply = StationSupplyRequest::query()->create(['station_id' => $station->id, 'request_text' => 'We need more dish soap', 'status' => 'open', 'created_by_name' => 'Pat', 'created_by_shift' => 'A']);

        $row = $this->projector->refreshSupplyRequest($supply);
        $this->assertSame(C::LOW, $row->level);

        $overridden = $this->projector->setOverride('station_supply_request', $supply->id, C::MEDIUM, $this->staff);
        $this->assertSame(C::MEDIUM, $overridden->level);
        $this->assertSame(C::MEDIUM, $this->projector->levelFor('station_supply_request', $supply->id));
    }

    public function test_setting_an_override_projects_the_record_first_when_needed(): void
    {
        $request = $this->stationRequest(['title' => 'Need paper towels', 'description' => 'Out']);

        $row = $this->projector->setOverride('station_request', $request->id, C::HIGH, $this->staff);

        $this->assertSame(C::LOW, $row->auto_level);
        $this->assertSame(C::HIGH, $row->level);
    }

    public function test_unknown_subject_types_are_refused(): void
    {
        $this->expectException(ValidationException::class);
        $this->projector->setOverride('inspection', 1, C::LOW, $this->staff);
    }

    public function test_backfill_command_previews_then_projects_open_records(): void
    {
        $defect = $this->defect('Pike Pole');
        SupportTriageLevel::query()->delete();
        SupportEquipmentRule::query()->delete();

        $this->artisan('support-triage:backfill', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame(0, SupportTriageLevel::query()->count());

        $this->artisan('support-triage:backfill')->assertSuccessful();
        $this->assertSame(C::LOW, $this->level($defect), 'the command seeds the catalog if it is empty, then projects');
    }

    public function test_the_reapply_job_runs_on_the_backlog_queue(): void
    {
        Queue::fake();
        ReapplyTriagePolicy::dispatch();
        Queue::assertPushedOn((string) config('ai_automation.queue.backlog'), ReapplyTriagePolicy::class);

        $defect = $this->defect('Pike Pole');
        SupportTriageLevel::query()->delete();
        (new ReapplyTriagePolicy)->handle($this->projector);
        $this->assertSame(C::LOW, $this->level($defect));
    }
}
