<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet;

use App\Filament\Resources\TakeHomeVehicleInspectionResource\Pages\ListTakeHomeVehicleInspections;
use App\Models\TakeHomeVehicleInspection;
use App\Models\TakeHomeVehicleInspectionRevision;
use App\Models\UnitMasterVehicle;
use App\Models\User;
use App\Services\Dashboards\Sources\Vehicle\TakeHomeInspectionNoticeSource;
use App\Services\Fleet\TakeHomeInspectionArchiver;
use App\Support\Dashboards\IssueSourceRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Dashboards\VehicleDashboardFixtures;
use Tests\TestCase;

/**
 * A member corrects their own submitted take-home inspection (each save is a logged revision; the signed
 * original is kept), and Fleet clears a handled inspection (archived, restorable, off the dashboards).
 */
final class TakeHomeInspectionEditAndClearTest extends TestCase
{
    use RefreshDatabase;
    use VehicleDashboardFixtures;

    private const BASE = '/take-home-vehicle-inspections';

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-06 10:00:00', 'America/New_York'));
        Storage::fake('local');
        $this->withoutVite();
        $this->setUpVehicleDashboard();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_member_can_edit_their_inspection_and_every_change_is_logged_with_before_and_after(): void
    {
        $member = $this->plainMember();
        $inspection = $this->inspection($member);

        $this->actingAs($member)->get(self::BASE.'/'.$inspection->reference)->assertOk()->assertSee('Edit inspection');
        $this->actingAs($member)->get(self::BASE.'/'.$inspection->reference.'/edit')
            ->assertOk()
            ->assertSee('Edit your inspection')
            ->assertSee('Routine check.')
            ->assertSee('value="Morgan Member"', false);

        $this->actingAs($member)->put(self::BASE.'/'.$inspection->reference, $this->form($inspection, [
            'checks' => ['tires_wheels' => ['status' => 'issue', 'issue_description' => 'Rear tire is soft.', 'notes' => null]],
            'acceptable_for_use' => '0',
            'comments' => 'Tire found after signing.',
            'reason' => 'Missed the tire.',
        ]))->assertRedirect(self::BASE.'/'.$inspection->reference);

        $revision = TakeHomeVehicleInspectionRevision::query()->sole();
        $this->assertSame(1, $revision->revision_number);
        $this->assertSame($member->id, $revision->revised_by_user_id);
        $this->assertSame('Missed the tire.', $revision->reason);
        $this->assertTrue($revision->added_problem);
        $this->assertSame(['from' => 'good', 'to' => 'issue'], $revision->changed_fields['checks.tires_wheels.status']);
        $this->assertSame(['from' => null, 'to' => 'Rear tire is soft.'], $revision->changed_fields['checks.tires_wheels.issue_description']);
        $this->assertSame(['from' => true, 'to' => false], $revision->changed_fields['acceptable_for_use']);
        $this->assertSame(['from' => 'Routine check.', 'to' => 'Tire found after signing.'], $revision->changed_fields['comments']);
        $this->assertArrayNotHasKey('odometer', $revision->changed_fields, 'unchanged answers are not logged as changes');

        // The signed original is untouched.
        $stored = $inspection->fresh();
        $this->assertTrue($stored->acceptable_for_use);
        $this->assertFalse($stored->has_issues);
        $this->assertSame('Routine check.', $stored->comments);
        $this->assertSame('good', collect($stored->checks)->firstWhere('key', 'tires_wheels')['status']);

        // The report reads as corrected, says who edited it, and keeps the original viewable.
        $effective = $stored->load('latestRevision')->effective();
        $this->assertFalse($effective->acceptable_for_use);
        $this->assertTrue($effective->has_issues);
        $this->actingAs($member)->get(self::BASE.'/'.$inspection->reference)
            ->assertOk()
            ->assertSee('Edited by '.$member->name)
            ->assertSee('View original and changes')
            ->assertSee('Tires &amp; wheels: result', false)
            ->assertSee('Rear tire is soft.')
            ->assertSee('Reported not acceptable for use.');
        $pdf = $this->actingAs($member)->get(self::BASE.'/'.$inspection->reference.'/pdf')->assertOk();
        $this->assertStringStartsWith('%PDF', (string) $pdf->getContent());

        // A second edit builds on the first and is numbered after it.
        $this->actingAs($member)->put(self::BASE.'/'.$inspection->reference, $this->form($effective, ['odometer' => '12400']))->assertRedirect();
        $second = TakeHomeVehicleInspectionRevision::query()->where('revision_number', 2)->sole();
        $this->assertSame(['odometer' => ['from' => 12345, 'to' => 12400]], $second->changed_fields);
        $this->assertFalse($second->added_problem);
        $this->assertFalse($second->answers['acceptable_for_use'], 'the first correction is carried forward');
    }

    public function test_a_resubmitted_form_records_one_revision_and_an_unchanged_save_records_none(): void
    {
        $member = $this->plainMember();
        $inspection = $this->inspection($member);
        $form = $this->form($inspection, ['comments' => 'Corrected.']);

        $this->actingAs($member)->put(self::BASE.'/'.$inspection->reference, $form)->assertRedirect();
        $this->actingAs($member)->put(self::BASE.'/'.$inspection->reference, $form)->assertRedirect();
        $this->assertSame(1, TakeHomeVehicleInspectionRevision::query()->count());

        $current = $inspection->fresh()->load('latestRevision')->effective();
        $this->actingAs($member)->put(self::BASE.'/'.$inspection->reference, $this->form($current))
            ->assertRedirect(self::BASE.'/'.$inspection->reference)
            ->assertSessionHas('take_home_unchanged', $inspection->reference);
        $this->assertSame(1, TakeHomeVehicleInspectionRevision::query()->count());
    }

    public function test_nobody_else_can_edit_a_members_inspection(): void
    {
        $member = $this->plainMember();
        $inspection = $this->inspection($member);
        $form = $this->form($inspection, ['comments' => 'Tampered.']);

        $outsider = $this->plainMember();
        $this->actingAs($outsider)->get(self::BASE.'/'.$inspection->reference.'/edit')->assertNotFound();
        $this->actingAs($outsider)->put(self::BASE.'/'.$inspection->reference, $form)->assertNotFound();

        // Fleet can read it but it is not theirs to change.
        $fleet = $this->fleetViewer();
        $this->actingAs($fleet)->get(self::BASE.'/'.$inspection->reference)->assertOk()->assertDontSee('Edit inspection');
        $this->actingAs($fleet)->get(self::BASE.'/'.$inspection->reference.'/edit')->assertForbidden();
        $this->actingAs($fleet)->put(self::BASE.'/'.$inspection->reference, $form)->assertForbidden();

        $this->assertSame(0, TakeHomeVehicleInspectionRevision::query()->count());
    }

    public function test_answers_on_the_stored_inspection_stay_immutable_but_the_clear_columns_may_change(): void
    {
        $inspection = $this->inspection($this->plainMember());

        $inspection->forceFill(['archived_at' => now(), 'archive_reason' => 'Handled'])->save();
        $this->assertTrue($inspection->fresh()->isArchived());

        $this->expectException(LogicException::class);
        $inspection->fresh()->forceFill(['comments' => 'Rewritten'])->save();
    }

    public function test_fleet_clears_a_handled_inspection_off_the_active_list_and_can_restore_it(): void
    {
        $member = $this->plainMember();
        $handled = $this->inspection($member);
        $open = $this->inspection($member);
        $fleet = $this->fleetViewer();
        $this->actingAs($fleet);

        Livewire::test(ListTakeHomeVehicleInspections::class)->loadTable()
            ->assertCanSeeTableRecords([$handled, $open])
            ->callTableAction('clear', $handled, data: ['archive_reason' => 'Tire replaced.'])
            ->assertHasNoTableActionErrors();

        $cleared = $handled->fresh();
        $this->assertTrue($cleared->isArchived());
        $this->assertSame($fleet->id, $cleared->archived_by);
        $this->assertSame('Tire replaced.', $cleared->archive_reason);
        $this->assertSame('cleared', $cleared->archive_history[0]['event_type']);
        $this->assertSame($fleet->id, $cleared->archive_history[0]['user_id']);

        Livewire::test(ListTakeHomeVehicleInspections::class)->loadTable()
            ->assertCanSeeTableRecords([$open])
            ->assertCanNotSeeTableRecords([$handled])
            ->filterTable('archive_state', 'archived')
            ->assertCanSeeTableRecords([$handled])
            ->assertCanNotSeeTableRecords([$open])
            ->callTableAction('restore', $cleared)
            ->assertHasNoTableActionErrors();

        $restored = $handled->fresh();
        $this->assertFalse($restored->isArchived());
        $this->assertNull($restored->archive_reason);
        $this->assertSame(['cleared', 'restored'], array_column($restored->archive_history, 'event_type'));
        $this->assertSame('Tire replaced.', $restored->archive_history[1]['metadata']['archive_reason']);
    }

    public function test_a_cleared_inspection_cannot_be_edited_by_the_member_and_says_so(): void
    {
        $member = $this->plainMember();
        $inspection = $this->inspection($member);
        app(TakeHomeInspectionArchiver::class)->archive($inspection, $this->fleetViewer(), 'Handled.');

        $this->actingAs($member)->get(self::BASE.'/'.$inspection->reference)
            ->assertOk()
            ->assertSee('Cleared by Fleet')
            ->assertSee('can no longer be edited')
            ->assertDontSee('Edit inspection');
        $this->actingAs($member)->get(self::BASE.'/'.$inspection->reference.'/edit')->assertForbidden();
        $this->actingAs($member)->put(self::BASE.'/'.$inspection->reference, $this->form($inspection, ['comments' => 'Late change.']))->assertForbidden();
        $this->assertSame(0, TakeHomeVehicleInspectionRevision::query()->count());

        // Still in the member's own history.
        $this->actingAs($member)->get(self::BASE)->assertOk()->assertSee($inspection->reference)->assertSee('Cleared by Fleet');
    }

    public function test_only_fleet_viewers_can_clear_or_restore(): void
    {
        $member = $this->plainMember();
        $inspection = $this->inspection($member);
        $archiver = app(TakeHomeInspectionArchiver::class);

        foreach ([$member, $this->plainMember()] as $user) {
            try {
                $archiver->archive($inspection, $user);
                $this->fail('A member without Fleet access cleared an inspection.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        }
        $this->assertFalse($inspection->fresh()->isArchived());

        $archiver->archive($inspection, $this->fleetViewer());
        try {
            $archiver->restore($inspection, $member);
            $this->fail('A member without Fleet access restored an inspection.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertTrue($inspection->fresh()->isArchived());
    }

    public function test_clearing_from_the_dashboard_notice_removes_it_from_the_board(): void
    {
        $inspection = $this->inspection($this->plainMember(), ['submitted_at' => now()->subHours(2)]);
        $source = app(IssueSourceRegistry::class)->get(TakeHomeInspectionNoticeSource::TYPE);
        $fleet = $this->fleetViewer();
        $query = $this->boardQuery('2026-11-06 10:00:00');

        $detail = $source->detail($fleet, (string) $inspection->id);
        $this->assertSame(['clear'], array_map(static fn ($action): string => $action->key, $detail->actions));
        $this->assertSame('forbidden', $source->perform($this->plainMember(), (string) $inspection->id, 'clear', [], $detail->issue->version)->outcome);
        $this->assertCount(1, $source->rows($fleet, $query, 50));

        $result = $source->perform($fleet, (string) $inspection->id, 'clear', ['archive_reason' => 'Seen.'], $detail->issue->version);
        $this->assertTrue($result->succeeded());
        $this->assertSame('Seen.', $inspection->fresh()->archive_reason);
        $this->assertSame([], $source->rows($fleet, $query, 50));
        $this->assertSame(0, $source->counts($fleet, $query)['notices']);
        $this->assertSame([], $source->detail($fleet, (string) $inspection->id)->actions, 'nothing left to do on a cleared inspection');
    }

    public function test_an_edit_that_adds_a_problem_raises_the_notice_again_and_other_edits_do_not(): void
    {
        $member = $this->plainMember();
        $fleet = $this->fleetViewer();
        $source = app(IssueSourceRegistry::class)->get(TakeHomeInspectionNoticeSource::TYPE);
        $quiet = $this->inspection($member, ['submitted_at' => now()->subDays(3)]);
        $problem = $this->inspection($member, ['submitted_at' => now()->subDays(3)]);
        $this->assertSame([], $source->rows($fleet, $this->boardQuery('2026-11-06 10:00:00'), 50));

        $this->actingAs($member)->put(self::BASE.'/'.$quiet->reference, $this->form($quiet, ['comments' => 'Typo fixed.']))->assertRedirect();
        $this->actingAs($member)->put(self::BASE.'/'.$problem->reference, $this->form($problem, [
            'checks' => ['windows_mirrors' => ['status' => 'issue', 'issue_description' => 'Cracked mirror.', 'notes' => null]],
        ]))->assertRedirect();

        $rows = $source->rows($fleet, $this->boardQuery('2026-11-06 10:00:00'), 50);
        $this->assertSame(['take_home_inspection:'.$problem->id], array_map(static fn ($row): string => $row->ref(), $rows));
        $this->assertSame('Issue found: Windows & mirrors', $rows[0]->summary, 'the notice shows the corrected answers');
        $this->assertStringContainsString('By '.$member->name, collect($source->detail($fleet, (string) $problem->id)->facts)->firstWhere('label', 'Edited')['value']);
    }

    /** @param array<string, mixed> $overrides */
    private function inspection(User $inspector, array $overrides = []): TakeHomeVehicleInspection
    {
        $this->sequence++;
        $vehicle = UnitMasterVehicle::query()->create(['veh_number' => '225'.$this->sequence, 'make' => 'Ford', 'model' => 'Escape', 'year' => '2022']);
        Storage::disk('local')->put('take-home-inspections/sig-'.$this->sequence.'.png', (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='));
        $checks = [];
        foreach (TakeHomeVehicleInspection::CHECKS as $key => [$label]) {
            $checks[] = ['key' => $key, 'label' => $label, 'status' => 'good', 'issue_description' => null, 'notes' => null];
        }

        return TakeHomeVehicleInspection::query()->create(array_merge([
            'reference' => sprintf('THI-261106-EDIT%02d', $this->sequence),
            'client_submission_id' => (string) Str::uuid(),
            'unit_master_vehicle_id' => $vehicle->getKey(),
            'inspector_user_id' => $inspector->getKey(),
            'inspector_name' => (string) $inspector->name,
            'driver_name' => 'Morgan Member',
            'driver_employee_number' => '77777',
            'driver_verified' => false,
            'vehicle_snapshot' => ['veh_number' => $vehicle->veh_number, 'year' => '2022', 'make' => 'Ford', 'model' => 'Escape'],
            'odometer' => 12345,
            'checks' => $checks,
            'has_issues' => false,
            'has_not_checked' => false,
            'keys_present' => true,
            'acceptable_for_use' => true,
            'comments' => 'Routine check.',
            'acknowledged_at' => now(),
            'signature_path' => 'take-home-inspections/sig-'.$this->sequence.'.png',
            'signature_sha256' => str_repeat('a', 64),
            'submitted_at' => now()->subHour(),
        ], $overrides));
    }

    /**
     * The edit form as the browser posts it, prefilled with the inspection's current answers.
     *
     * @param  array<string, mixed>  $overrides  'checks' entries are merged per check key
     * @return array<string, mixed>
     */
    private function form(TakeHomeVehicleInspection $inspection, array $overrides = []): array
    {
        $checks = [];
        foreach ((array) $inspection->checks as $check) {
            $checks[$check['key']] = ['status' => $check['status'], 'issue_description' => $check['issue_description'], 'notes' => $check['notes']];
        }
        $checks = array_replace($checks, (array) ($overrides['checks'] ?? []));
        unset($overrides['checks']);
        $yesNo = fn (?bool $value): ?string => $value === null ? null : ($value ? '1' : '0');

        return array_merge([
            'client_revision_id' => (string) Str::uuid(),
            'driver_name' => $inspection->driver_name,
            'driver_employee_number' => $inspection->driver_employee_number,
            'odometer' => $inspection->odometer === null ? null : (string) $inspection->odometer,
            'odometer_exception' => $inspection->odometer_exception,
            'checks' => $checks,
            'keys_present' => $yesNo($inspection->keys_present),
            'acceptable_for_use' => $yesNo($inspection->acceptable_for_use),
            'comments' => $inspection->comments,
        ], $overrides);
    }
}
