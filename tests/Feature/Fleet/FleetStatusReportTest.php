<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet;

use App\Models\Apparatus;
use App\Models\ApparatusChangeout;
use App\Models\ApparatusOperationalStatusEvent;
use App\Models\Station;
use App\Models\UnitMasterVehicle;
use App\Models\UnitMasterVehicleChange;
use App\Services\Display\DisplaySnapshotService;
use App\Services\Fleet\FleetStatusReportReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * The fixtures reproduce the production rows the 2026-10-30 report names, so the shipped file must
 * produce exactly the differences Fleet expects and nothing else.
 */
final class FleetStatusReportTest extends TestCase
{
    use RefreshDatabase;

    private const STATION_IDS = [1 => 29, 2 => 30, 3 => 31, 4 => 32];

    /** Not in the report. Its number also looks like a report number without the zeros. */
    private const NOT_IN_REPORT = '2-14';

    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Queue::fake();
        config()->set('google_sheets.apparatus_sync_enabled', false);
        $this->seedStations();
        $this->seedTrucks();
        $this->beforeApplicationDestroyed(function (): void {
            foreach ($this->temporaryFiles as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        });
    }

    private function seedStations(): void
    {
        foreach (self::STATION_IDS as $number => $id) {
            (new Station)->forceFill([
                'id' => $id, 'station_number' => (string) $number, 'name' => "Station {$number}",
                'address' => "{$number} Test Way", 'is_active' => true,
            ])->save();
        }
    }

    private function seedTrucks(): void
    {
        $reserve = [
            ['002-16', 'E 21', 'Station 2', 2, 'Available'],
            ['002-18', 'E 11', 'Station 2', 2, 'Available'],
            ['002-14', 'E 31', 'Station 1', 1, 'Available'],
            ['002-6', 'L 11', 'Station 2', 2, 'Available'],
            ['1033', null, 'Station 2', 2, 'Available'],
            ['1034', null, 'Station 2', 2, 'Available'],
            ['1035', null, 'Station 1', 1, 'Available'],
            ['1036', null, 'Station 2', 2, 'In Service'],
            ['14500', null, 'Fire Fleet', 2, 'Available'],
        ];
        foreach ($reserve as [$number, $designation, $location, $station, $status]) {
            $this->truck($number, $designation ?? 'Reserve', 'Reserve', $location, $station, $status, [
                'daily_checkout_requirement' => 'reserve',
                'daily_checkout_template' => 'pending',
            ]);
        }

        $this->truck('14501', 'R 3 Replacement', 'Reserve', 'Station 3', 3, 'In Service', [
            'name' => 'RESCUE 14501 (R3 replacement)',
            'daily_checkout_requirement' => 'required',
            'daily_checkout_template' => 'rescue',
        ]);

        $frontline = [
            ['20503', 'E 1', 1], ['002-12', 'L 1', 1], ['16508', 'R 1', 1], ['19502', 'R 11', 1],
            ['002-20', 'A 1', 2], ['18500', 'A 2', 2], ['24509', 'E 2', 2], ['16507', 'R 2', 2], ['19503', 'R 22', 2],
            ['002-22', 'E 3', 3], ['17505', 'L 3', 3],
            ['17503', 'R 44', 4], ['20504', 'E 4', 4], ['17502', 'R 4', 4],
        ];
        foreach ($frontline as [$number, $designation, $station]) {
            $this->truck($number, $designation, "Station {$station}", "Station {$station}", $station, 'In Service');
        }
        $this->truck('17501', 'R 3', 'Station 3', 'Fire Fleet', 3, 'Out of Service');

        $this->truck(self::NOT_IN_REPORT, 'Reserve', 'Reserve', 'Fire Fleet', 1, 'Out of Service', [
            'daily_checkout_requirement' => 'reserve',
            'daily_checkout_template' => 'pending',
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function truck(string $number, string $designation, string $assignment, string $location, int $station, string $status, array $overrides = []): Apparatus
    {
        $isPlainReserve = $assignment === 'Reserve' && str_contains($number, '-') === false;
        $unitId = $isPlainReserve ? 'R-'.$number : str_replace(' ', '', $designation).($assignment === 'Reserve' ? '-reserve' : '');

        return Apparatus::query()->create([
            'vehicle_number' => $number,
            'vin' => 'VIN-'.$number,
            'unit_id' => $unitId,
            'slug' => Str::slug($isPlainReserve ? 'r'.$number : $unitId),
            'designation' => $designation,
            'name' => $isPlainReserve ? 'RESCUE '.$number : $designation,
            'type' => 'Rescue',
            'assignment' => $assignment,
            'current_location' => $location,
            'station_id' => self::STATION_IDS[$station],
            'status' => $status,
            'daily_checkout_requirement' => 'required',
            'daily_checkout_template' => 'default',
            ...$overrides,
        ]);
    }

    private function shippedFile(): string
    {
        return base_path('database/data/fleet-status-report-2026-10-30.json');
    }

    private function reconciler(): FleetStatusReportReconciler
    {
        return app(FleetStatusReportReconciler::class);
    }

    /** @return array<string, mixed> */
    private function shippedPlan(): array
    {
        return $this->reconciler()->plan($this->reconciler()->parse($this->shippedFile()));
    }

    /** @return array<string, mixed> */
    private function applyShipped(): array
    {
        $result = $this->reconciler()->apply($this->shippedPlan(), $this->shippedFile());
        if ($result['saved_to'] !== null) {
            $this->temporaryFiles[] = $result['saved_to'];
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>|null
     */
    private function planRow(array $plan, string $number): ?array
    {
        return collect($plan['rows'])->firstWhere('vehicle_number', $number);
    }

    private function row(string $number): Apparatus
    {
        return Apparatus::query()->where('vehicle_number', $number)->sole();
    }

    /** @return list<array<string, mixed>> */
    private function table(): array
    {
        return DB::table('apparatuses')->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all();
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        return [
            'status_events' => ApparatusOperationalStatusEvent::query()->count(),
            'vehicle_history' => UnitMasterVehicleChange::query()->count(),
            'masters' => UnitMasterVehicle::query()->count(),
            'apparatuses' => Apparatus::query()->count(),
        ];
    }

    /** @return list<string> */
    private function savedFiles(): array
    {
        return glob(storage_path(FleetStatusReportReconciler::SAVE_DIRECTORY.'/*.json')) ?: [];
    }

    /** @param list<array<string, mixed>> $rows */
    private function reportFile(array $rows, string $date = '2026-10-30'): string
    {
        $path = tempnam(sys_get_temp_dir(), 'fleet-report-');
        $this->temporaryFiles[] = $path;
        file_put_contents($path, json_encode(['report' => 'Test', 'report_date' => $date, 'rows' => $rows], JSON_THROW_ON_ERROR));

        return $path;
    }

    /** @return array<string, mixed> */
    private function reportRow(string $number, ?string $designation, string $assignment, string $location, string $status, ?string $note = null): array
    {
        return ['vehicle_number' => $number, 'designation' => $designation, 'assignment' => $assignment, 'location' => $location, 'status' => $status, 'note' => $note];
    }

    private function statusEvent(string $number, string $changedAt): void
    {
        ApparatusOperationalStatusEvent::query()->create([
            'apparatus_id' => $this->row($number)->id,
            'previous_status' => 'Available',
            'status' => 'Out of Service',
            'changed_at' => $changedAt,
        ]);
    }

    public function test_the_shipped_file_produces_exactly_the_expected_differences(): void
    {
        $plan = $this->shippedPlan();

        $changes = [];
        foreach ($plan['rows'] as $row) {
            $this->assertSame([], $row['discrepancies'], $row['vehicle_number'].' discrepancies');
            if ($row['outcome'] === FleetStatusReportReconciler::OUTCOME_CHANGE) {
                $changes[$row['vehicle_number']] = array_map(static fn (array $change): array => [$change['old'], $change['new']], $row['changes']);
            } else {
                $this->assertSame(FleetStatusReportReconciler::OUTCOME_NO_CHANGE, $row['outcome'], $row['vehicle_number']);
            }
        }
        unset($changes['1034']['notes']);

        $this->assertSame([
            '002-14' => ['current_location' => ['Station 1', 'Station 2'], 'station_id' => [29, 30]],
            '1034' => ['status' => ['Available', 'In Service']],
            '1035' => ['current_location' => ['Station 1', 'Station 3'], 'station_id' => [29, 31]],
            '1036' => ['status' => ['In Service', 'Available']],
            '14500' => ['current_location' => ['Fire Fleet', 'Station 2']],
            '14501' => [
                'designation' => ['R 3 Replacement', 'Reserve'],
                'name' => ['RESCUE 14501 (R3 replacement)', 'RESCUE 14501'],
                'daily_checkout_requirement' => ['required', 'reserve'],
                'daily_checkout_template' => ['rescue', 'pending'],
                'status' => ['In Service', 'Available'],
                'current_location' => ['Station 3', 'Station 2'],
                'station_id' => [31, 30],
            ],
            '17501' => ['status' => ['Out of Service', 'In Service'], 'current_location' => ['Fire Fleet', 'Station 3']],
        ], $changes);
        $this->assertSame(['change' => 7, 'discrepancy' => 0, 'no-change' => 18], $plan['totals']);
        $this->assertCount(25, $plan['rows']);
    }

    public function test_dry_run_writes_nothing_and_prints_each_truck(): void
    {
        $table = $this->table();
        $counts = $this->counts();
        $files = $this->savedFiles();

        $exit = Artisan::call('fleet:apply-status-report', ['--file' => $this->shippedFile(), '--dry-run' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertSame($table, $this->table());
        $this->assertSame($counts, $this->counts());
        $this->assertSame($files, $this->savedFiles());
        $this->assertStringContainsString('DRY RUN', $output);
        $this->assertStringContainsString('status: Available -> In Service', $output);
        $this->assertStringContainsString('no-change', $output);
        $this->assertStringContainsString('Change: 7 | Discrepancy: 0 | No change: 18', $output);
    }

    public function test_no_mode_flag_is_a_dry_run(): void
    {
        $table = $this->table();

        $this->assertSame(0, Artisan::call('fleet:apply-status-report', ['--file' => $this->shippedFile()]));
        $this->assertSame($table, $this->table());
    }

    public function test_dry_run_and_apply_together_are_refused(): void
    {
        $table = $this->table();

        $this->assertSame(1, Artisan::call('fleet:apply-status-report', ['--file' => $this->shippedFile(), '--dry-run' => true, '--apply' => true]));
        $this->assertSame($table, $this->table());
    }

    public function test_apply_through_the_command_writes_the_plan_and_the_json_report(): void
    {
        $json = tempnam(sys_get_temp_dir(), 'fleet-json-');
        $this->temporaryFiles[] = $json;
        $files = $this->savedFiles();

        $exit = Artisan::call('fleet:apply-status-report', ['--file' => $this->shippedFile(), '--apply' => true, '--json' => $json]);
        $this->temporaryFiles = [...$this->temporaryFiles, ...array_diff($this->savedFiles(), $files)];

        $this->assertSame(0, $exit, Artisan::output());
        $this->assertSame('Station 2', $this->row('002-14')->current_location);
        $this->assertSame(30, $this->row('002-14')->station_id);
        $this->assertSame(31, $this->row('1035')->station_id);
        $this->assertSame('Available', $this->row('1036')->status);
        $this->assertSame('Station 2', $this->row('14500')->current_location);
        $this->assertSame(30, $this->row('14500')->station_id);
        $this->assertSame('In Service', $this->row('17501')->status);
        $this->assertSame('Station 3', $this->row('17501')->current_location);
        $this->assertSame(31, $this->row('17501')->station_id, 'a frontline row keeps its station');

        $report = json_decode((string) file_get_contents($json), true, 32, JSON_THROW_ON_ERROR);
        $this->assertSame('applied', $report['mode']);
        $this->assertSame(7, $report['apply_result']['rows_written']);
        Notification::assertNothingSent();
    }

    public function test_status_writes_reach_the_ledger_and_location_only_writes_do_not(): void
    {
        $this->applyShipped();

        $this->assertSame(4, ApparatusOperationalStatusEvent::query()->count());
        foreach (['1034', '1036', '14501', '17501'] as $number) {
            $this->assertSame(1, ApparatusOperationalStatusEvent::query()->where('apparatus_id', $this->row($number)->id)->count(), $number);
        }
    }

    public function test_leading_zeros_and_hyphens_are_preserved_and_never_loosely_matched(): void
    {
        $lookalike = $this->row(self::NOT_IN_REPORT)->getAttributes();

        $this->applyShipped();

        $this->assertSame(30, $this->row('002-14')->station_id);
        $this->assertSame('002-14', $this->row('002-14')->vehicle_number);
        $this->assertSame('002-6', $this->row('002-6')->vehicle_number);
        $this->assertSame($lookalike, $this->row(self::NOT_IN_REPORT)->getAttributes(), '2-14 is not 002-14');
    }

    public function test_a_vehicle_number_sent_as_a_json_number_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->reconciler()->parse($this->reportFile([['vehicle_number' => 1033, 'designation' => null, 'assignment' => 'Reserve', 'location' => 'Station 2', 'status' => 'Available']]));
    }

    public function test_truck_1034_stays_a_reserve_and_goes_in_service_with_the_detail_note(): void
    {
        DB::table('apparatuses')->where('vehicle_number', '1034')->update(['notes' => 'Spare keys in cab']);

        $this->applyShipped();

        $truck = $this->row('1034');
        $this->assertSame('In Service', $truck->status);
        $this->assertSame('Reserve', $truck->assignment);
        $this->assertSame('Reserve', $truck->designation);
        $this->assertSame("Spare keys in cab\nDetail (Fleet status report 2026-10-30)", $truck->notes);

        $this->applyShipped();
        $this->assertSame("Spare keys in cab\nDetail (Fleet status report 2026-10-30)", $this->row('1034')->fresh()->notes);
    }

    public function test_a_blank_designation_invents_nothing_and_deletes_nothing(): void
    {
        DB::table('apparatuses')->where('vehicle_number', '1033')->update(['designation' => null]);

        $this->applyShipped();

        $this->assertNull($this->row('1033')->designation, 'no designation stays no designation');
        foreach (['1034', '1035', '1036', '14500'] as $number) {
            $truck = $this->row($number);
            $this->assertSame('Reserve', $truck->designation, $number);
            $this->assertSame('RESCUE '.$number, $truck->name, $number);
            $this->assertSame('reserve', $truck->getRawOriginal('daily_checkout_requirement'), $number);
            $this->assertSame('pending', $truck->getRawOriginal('daily_checkout_template'), $number);
        }
    }

    public function test_the_replacement_reserve_returns_to_its_reserve_identity(): void
    {
        $this->applyShipped();

        $truck = $this->row('14501');
        $this->assertSame('Reserve', $truck->designation);
        $this->assertSame('RESCUE 14501', $truck->name);
        $this->assertSame('reserve', $truck->getRawOriginal('daily_checkout_requirement'));
        $this->assertSame('pending', $truck->getRawOriginal('daily_checkout_template'));
        $this->assertSame('Available', $truck->status);
        $this->assertSame('Station 2', $truck->current_location);
        $this->assertSame(30, $truck->station_id);
        $this->assertSame('R-14501', $truck->unit_id);
        $this->assertSame('r14501', $truck->slug);
        $this->assertSame('Reserve', $truck->assignment);
    }

    public function test_a_frontline_identity_mismatch_is_listed_and_not_written(): void
    {
        DB::table('apparatuses')->where('vehicle_number', '20503')->update(['designation' => 'E 11', 'status' => 'Available']);
        $before = $this->row('20503')->getAttributes();

        $plan = $this->shippedPlan();
        $row = $this->planRow($plan, '20503');

        $this->assertSame(FleetStatusReportReconciler::OUTCOME_DISCREPANCY, $row['outcome']);
        $this->assertStringContainsString('real changeout', $row['discrepancies'][0]);

        $this->temporaryFiles[] = (string) $this->reconciler()->apply($plan)['saved_to'];
        $this->assertSame($before, $this->row('20503')->getAttributes());
    }

    public function test_a_blank_report_row_does_not_strip_a_frontline_designation(): void
    {
        $plan = $this->reconciler()->plan($this->reconciler()->parse($this->reportFile([
            $this->reportRow('16508', null, 'Reserve', 'Station 2', 'Available'),
        ])));

        $row = $plan['rows'][0];
        $this->assertSame(FleetStatusReportReconciler::OUTCOME_DISCREPANCY, $row['outcome']);
        $this->assertSame([], $row['changes']);
    }

    public function test_a_newer_status_event_leaves_the_row_alone_with_the_reason(): void
    {
        $this->statusEvent('1036', '2026-10-31 04:00:00');

        $plan = $this->shippedPlan();
        $row = $this->planRow($plan, '1036');
        $this->assertSame(FleetStatusReportReconciler::OUTCOME_DISCREPANCY, $row['outcome']);
        $this->assertStringContainsString('Newer data: status changed to Out of Service at 2026-10-31 04:00:00 UTC', $row['discrepancies'][0]);

        $result = $this->reconciler()->apply($plan);
        $this->temporaryFiles[] = (string) $result['saved_to'];
        $this->assertSame('In Service', $this->row('1036')->status);
        $this->assertSame(6, $result['rows_written']);
    }

    public function test_an_event_before_the_report_day_in_new_york_is_not_newer(): void
    {
        // 23:59:59 on Oct 29 in New York is 03:59:59 UTC on Oct 30.
        $this->statusEvent('1036', '2026-10-30 03:59:59');

        $this->assertSame(FleetStatusReportReconciler::OUTCOME_CHANGE, $this->planRow($this->shippedPlan(), '1036')['outcome']);
    }

    public function test_a_status_change_on_the_report_day_is_listed_and_not_overwritten(): void
    {
        // Midnight starting Oct 30 in New York is 04:00:00 UTC. The report has no time, so this may be newer than it.
        $this->statusEvent('1036', '2026-10-30 04:00:00');

        $plan = $this->shippedPlan();
        $row = $this->planRow($plan, '1036');
        $this->assertSame(FleetStatusReportReconciler::OUTCOME_DISCREPANCY, $row['outcome']);
        $this->assertSame([], $row['changes']);
        $this->assertStringContainsString('Newer data: status changed to Out of Service at 2026-10-30 04:00:00 UTC', $row['discrepancies'][0]);

        $this->temporaryFiles[] = (string) $this->reconciler()->apply($plan)['saved_to'];
        $this->assertSame('In Service', $this->row('1036')->status);
    }

    public function test_a_row_matching_the_report_stays_no_change_despite_a_report_day_event(): void
    {
        $this->statusEvent('1033', '2026-10-30 15:00:00');

        $row = $this->planRow($this->shippedPlan(), '1033');

        $this->assertSame(FleetStatusReportReconciler::OUTCOME_NO_CHANGE, $row['outcome']);
        $this->assertSame([], $row['discrepancies']);
    }

    public function test_the_change_history_labels_the_source(): void
    {
        $this->applyShipped();
        $changes = UnitMasterVehicleChange::query()->where('source', FleetStatusReportReconciler::SOURCE)->get();

        $html = view('filament.fleet.vehicle-change-history', [
            'changes' => $changes,
            'valueLabel' => static fn (UnitMasterVehicleChange $change, ?string $value): string => (string) $value,
        ])->render();

        $this->assertStringContainsString('<td>Fleet status report</td>', $html);
        $this->assertStringNotContainsString(FleetStatusReportReconciler::SOURCE, $html);
    }

    public function test_a_newer_changeout_leaves_the_row_alone(): void
    {
        ApparatusChangeout::query()->create([
            'client_submission_id' => (string) Str::uuid(), 'payload_hash' => str_repeat('a', 64),
            'unit_slug' => 'r1035', 'unit_designation' => 'Reserve', 'from_apparatus_id' => $this->row('1035')->id,
            'to_vehicle_number' => '1036', 'status' => ApparatusChangeout::STATUS_COMPLETED,
            'handoff_status' => ApparatusChangeout::HANDOFF_COMPLETE, 'submitted_by_name' => 'Test Member',
            'effective_at' => '2026-10-31 12:00:00',
        ]);

        $plan = $this->shippedPlan();

        $this->assertStringContainsString('changeout', $this->planRow($plan, '1035')['discrepancies'][0]);
        $this->assertSame([], $this->planRow($plan, '1035')['changes']);
    }

    public function test_a_meter_update_is_not_evidence(): void
    {
        $truck = $this->row('1036');
        $truck->forceFill(['current_miles' => 12345])->save();

        $this->assertSame(FleetStatusReportReconciler::OUTCOME_CHANGE, $this->planRow($this->shippedPlan(), '1036')['outcome']);
    }

    public function test_a_row_changed_between_plan_and_write_is_skipped_and_listed(): void
    {
        $plan = $this->shippedPlan();
        DB::table('apparatuses')->where('vehicle_number', '1035')->update(['current_location' => 'Station 4']);

        $result = $this->reconciler()->apply($plan);
        $this->temporaryFiles[] = (string) $result['saved_to'];

        $this->assertSame(6, $result['rows_written']);
        $this->assertCount(1, $result['skipped']);
        $this->assertSame('1035', $result['skipped'][0]['vehicle_number']);
        $this->assertStringContainsString('changed after the plan', $result['skipped'][0]['reason']);
        $this->assertSame('Station 4', $this->row('1035')->current_location);
        $this->assertSame(29, $this->row('1035')->station_id, 'nothing on the skipped row was written');
        $this->assertSame('Station 2', $this->row('002-14')->current_location);

        $report = $this->reconciler()->report($plan, $result, $this->shippedFile());
        $this->assertSame(FleetStatusReportReconciler::OUTCOME_DISCREPANCY, $this->planRow($report, '1035')['outcome']);
    }

    public function test_a_second_apply_writes_nothing_and_saves_nothing(): void
    {
        $this->applyShipped();
        $table = $this->table();
        $counts = $this->counts();
        $files = $this->savedFiles();

        $plan = $this->shippedPlan();
        $result = $this->reconciler()->apply($plan);

        $this->assertSame(0, $result['rows_written']);
        $this->assertSame(0, $result['fields_written']);
        $this->assertNull($result['saved_to']);
        $this->assertSame(['change' => 0, 'discrepancy' => 0, 'no-change' => 25], $plan['totals']);
        $this->assertSame($table, $this->table());
        $this->assertSame($counts, $this->counts());
        $this->assertSame($files, $this->savedFiles());
    }

    public function test_revert_restores_only_fields_still_holding_the_applied_value(): void
    {
        $original = [
            '14501' => $this->row('14501')->getAttributes(),
            '1034' => $this->row('1034')->getAttributes(),
        ];
        $saved = $this->applyShipped()['saved_to'];
        $this->assertFileExists((string) $saved);
        $this->assertStringContainsString('fleet-status-reports', (string) $saved);
        DB::table('apparatuses')->where('vehicle_number', '1035')->update(['current_location' => 'Station 4']);

        $exit = Artisan::call('fleet:apply-status-report', ['--revert' => $saved]);
        $output = Artisan::output();

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('Left alone: 1035 current_location', $output);
        $this->assertSame('Station 4', $this->row('1035')->current_location, 'a later value is kept');
        $this->assertSame(29, $this->row('1035')->station_id, 'a field still holding the applied value goes back');
        $this->assertSame('In Service', $this->row('1036')->status);
        $this->assertSame('Out of Service', $this->row('17501')->status);
        $this->assertSame('Fire Fleet', $this->row('14500')->current_location);

        foreach ($original as $number => $attributes) {
            $now = $this->row((string) $number)->getAttributes();
            foreach (['status', 'designation', 'name', 'daily_checkout_requirement', 'daily_checkout_template', 'current_location', 'station_id', 'notes'] as $field) {
                $this->assertSame($attributes[$field] ?? null, $now[$field] ?? null, "{$number} {$field}");
            }
        }

        $table = $this->table();
        $history = UnitMasterVehicleChange::query()->count();
        $this->assertSame(0, Artisan::call('fleet:apply-status-report', ['--revert' => $saved]));
        $this->assertSame($table, $this->table(), 'a second revert writes nothing');
        $this->assertSame($history, UnitMasterVehicleChange::query()->count());
    }

    public function test_a_saved_file_cannot_name_a_field_this_command_may_not_write(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'fleet-saved-');
        $this->temporaryFiles[] = $path;
        file_put_contents($path, json_encode([
            'source' => FleetStatusReportReconciler::SOURCE,
            'report_date' => '2026-10-30',
            'rows' => [['apparatus_id' => $this->row('1033')->id, 'vehicle_number' => '1033', 'before' => ['vehicle_number' => '1033'], 'after' => ['vehicle_number' => '9999']]],
        ], JSON_THROW_ON_ERROR));
        $table = $this->table();

        $this->assertSame(1, Artisan::call('fleet:apply-status-report', ['--revert' => $path]));
        $this->assertSame($table, $this->table());
    }

    public function test_master_links_stay_put_and_unique_with_one_attributed_history_row_per_changed_truck(): void
    {
        $links = Apparatus::query()->orderBy('id')->pluck('unit_master_vehicle_id', 'vehicle_number')->all();
        $this->assertNotContains(null, $links);
        $history = UnitMasterVehicleChange::query()->count();
        $masters = UnitMasterVehicle::query()->count();

        $this->applyShipped();

        $after = Apparatus::query()->orderBy('id')->pluck('unit_master_vehicle_id', 'vehicle_number')->all();
        $this->assertSame($links, $after);
        $this->assertSame(count($after), count(array_unique($after)));
        $this->assertSame($masters, UnitMasterVehicle::query()->count());

        $added = UnitMasterVehicleChange::query()->where('source', FleetStatusReportReconciler::SOURCE)->get();
        $this->assertCount(7, $added);
        $this->assertSame($history + 7, UnitMasterVehicleChange::query()->count());
        $this->assertSame(7, $added->pluck('unit_master_vehicle_id')->unique()->count());

        $entry = $added->firstWhere('unit_master_vehicle_id', $links['17501']);
        $this->assertSame('record', $entry->field);
        $this->assertSame(UnitMasterVehicleChange::STATUS_APPLIED, $entry->status);
        $this->assertSame('Fleet status report 2026-10-30', $entry->source_reference);
        $this->assertStringContainsString('2026-10-30', $entry->note);
        $this->assertStringContainsString('status: Out of Service -> In Service', $entry->note);
        $this->assertStringContainsString('location: Fire Fleet -> Station 3', $entry->note);
    }

    public function test_a_truck_missing_from_the_file_is_untouched(): void
    {
        $before = DB::table('apparatuses')->where('vehicle_number', self::NOT_IN_REPORT)->first();
        $masterId = $this->row(self::NOT_IN_REPORT)->unit_master_vehicle_id;
        $history = UnitMasterVehicleChange::query()->where('unit_master_vehicle_id', $masterId)->count();

        $plan = $this->shippedPlan();
        $this->applyShipped();

        $this->assertNull($this->planRow($plan, self::NOT_IN_REPORT));
        $this->assertEquals($before, DB::table('apparatuses')->where('vehicle_number', self::NOT_IN_REPORT)->first());
        $this->assertSame($history, UnitMasterVehicleChange::query()->where('unit_master_vehicle_id', $masterId)->count());
    }

    public function test_unmatched_rows_and_duplicate_holders_are_listed_and_not_written(): void
    {
        $this->truck('1033', 'Reserve', 'Reserve', 'Station 2', 2, 'Available', ['unit_id' => 'R-1033-B', 'slug' => 'r1033-b']);
        $table = $this->table();
        $counts = $this->counts();

        $plan = $this->reconciler()->plan($this->reconciler()->parse($this->reportFile([
            $this->reportRow('99999', 'E 9', 'Station 9', 'Station 9', 'In Service'),
            $this->reportRow('1033', null, 'Reserve', 'Station 3', 'In Service'),
            $this->reportRow('1035', null, 'Reserve', 'Station 3', 'Available'),
            $this->reportRow('1035', null, 'Reserve', 'Station 4', 'Available'),
        ])));
        $result = $this->reconciler()->apply($plan);

        $this->assertSame(['change' => 0, 'discrepancy' => 4, 'no-change' => 0], $plan['totals']);
        $this->assertStringContainsString('No apparatus row', $plan['rows'][0]['discrepancies'][0]);
        $this->assertStringContainsString('More than one apparatus row', $plan['rows'][1]['discrepancies'][0]);
        $this->assertStringContainsString('more than once in the report', $plan['rows'][2]['discrepancies'][0]);
        $this->assertSame(0, $result['rows_written']);
        $this->assertSame($table, $this->table());
        $this->assertSame($counts, $this->counts());
    }

    public function test_a_non_station_location_never_changes_the_station_id(): void
    {
        $plan = $this->reconciler()->plan($this->reconciler()->parse($this->reportFile([
            $this->reportRow('1033', null, 'Reserve', 'Fire Fleet', 'Available'),
        ])));

        $this->assertSame(['current_location'], array_keys($plan['rows'][0]['changes']));
    }

    public function test_display_caches_clear_after_a_location_only_change(): void
    {
        Cache::put(DisplaySnapshotService::SNAPSHOT_CACHE_KEY, 'stale');
        Cache::put(DisplaySnapshotService::STATIONS_CACHE_KEY, 'stale');

        $result = $this->reconciler()->apply($this->reconciler()->plan($this->reconciler()->parse($this->reportFile([
            $this->reportRow('14500', null, 'Reserve', 'Station 2', 'Available'),
        ]))));
        $this->temporaryFiles[] = (string) $result['saved_to'];

        $this->assertSame(1, $result['rows_written']);
        $this->assertFalse(Cache::has(DisplaySnapshotService::SNAPSHOT_CACHE_KEY));
        $this->assertFalse(Cache::has(DisplaySnapshotService::STATIONS_CACHE_KEY));
    }
}
