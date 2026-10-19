<?php

declare(strict_types=1);

namespace Tests\Feature\Employee;

use App\Enums\AccountStatus;
use App\Filament\Employee\Pages\MyVehicleInspections;
use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusInspection;
use App\Models\ApparatusServiceTicket;
use App\Models\Employee;
use App\Models\Station;
use App\Models\User;
use App\Services\Inspections\EmployeeInspectionHistoryService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class MyVehicleInspectionsTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Storage::fake('public');
    }

    public function test_list_shows_inspections_owned_by_user_id_or_employee_id_and_hides_everyone_elses(): void
    {
        [$me, $myEmployee] = $this->member('M-100', 'Alex Member');
        [$other, $otherEmployee] = $this->member('M-200', 'Casey Other');
        $apparatus = $this->apparatus();

        $byActor = $this->inspection($apparatus, ['actor_user_id' => $me->id, 'employee_id' => null]);
        $byEmployee = $this->inspection($apparatus, ['actor_user_id' => null, 'employee_id' => $myEmployee->id]);
        $others = $this->inspection($apparatus, ['actor_user_id' => $other->id, 'employee_id' => $otherEmployee->id]);
        // Same display name as me, but no foreign key to me: never attributed by text.
        $sameNameOnly = $this->inspection($apparatus, ['actor_user_id' => $other->id, 'employee_id' => $otherEmployee->id, 'operator_name' => $myEmployee->name]);
        $unattributed = $this->inspection($apparatus, ['actor_user_id' => null, 'employee_id' => null, 'operator_name' => $myEmployee->name]);

        $this->livewireAs($me)
            ->assertCanSeeTableRecords([$byActor, $byEmployee])
            ->assertCanNotSeeTableRecords([$others, $sameNameOnly, $unattributed]);
    }

    public function test_search_and_filters_can_only_narrow_never_widen_the_owned_set(): void
    {
        [$me] = $this->member('M-101', 'Alex Member');
        [$other, $otherEmployee] = $this->member('M-201', 'Casey Other');
        $apparatus = $this->apparatus();

        $mine = $this->inspection($apparatus, ['actor_user_id' => $me->id, 'inspection_reference' => 'INS-E91-MINE-000001', 'review_status' => 'approved']);
        $mineWithFinding = $this->inspection($apparatus, [
            'actor_user_id' => $me->id,
            'inspection_reference' => 'INS-E91-MINE-000002',
            'review_status' => 'pending_review',
            'results' => $this->results('Missing'),
        ]);
        $theirs = $this->inspection($apparatus, ['actor_user_id' => $other->id, 'employee_id' => $otherEmployee->id, 'inspection_reference' => 'INS-E91-THEIRS-000003', 'results' => $this->results('Damaged')]);

        $page = $this->livewireAs($me);

        $page->searchTable('THEIRS')->assertCanNotSeeTableRecords([$mine, $mineWithFinding, $theirs]);
        $page->searchTable('MINE')->assertCanSeeTableRecords([$mine, $mineWithFinding])->assertCanNotSeeTableRecords([$theirs]);
        $page->searchTable('')
            ->filterTable('has_findings', true)->assertCanSeeTableRecords([$mineWithFinding])->assertCanNotSeeTableRecords([$mine, $theirs])
            ->filterTable('has_findings', false)->assertCanSeeTableRecords([$mine])->assertCanNotSeeTableRecords([$mineWithFinding, $theirs])
            ->set('tableFilters.has_findings.value', null)
            ->filterTable('review_status', 'pending_review')->assertCanSeeTableRecords([$mineWithFinding])->assertCanNotSeeTableRecords([$mine, $theirs])
            ->set('tableFilters.review_status.value', null)
            // A forged apparatus filter value still only narrows the owned rows.
            ->filterTable('apparatus_id', $apparatus->id)->assertCanSeeTableRecords([$mine, $mineWithFinding])->assertCanNotSeeTableRecords([$theirs]);
    }

    public function test_list_is_newest_first_and_paginates_on_the_server(): void
    {
        [$me] = $this->member('M-102', 'Alex Member');
        $apparatus = $this->apparatus();
        $rows = [];
        foreach (range(1, 12) as $day) {
            $rows[] = $this->inspection($apparatus, ['actor_user_id' => $me->id, 'completed_at' => now()->subDays(20 - $day)]);
        }

        $this->livewireAs($me)
            ->set('tableRecordsPerPage', 10)
            ->assertCanSeeTableRecords(array_slice(array_reverse($rows), 0, 10), inOrder: true)
            ->assertCanNotSeeTableRecords(array_slice(array_reverse($rows), 10))
            ->call('setPage', 2)
            ->assertCanSeeTableRecords(array_slice(array_reverse($rows), 10), inOrder: true)
            ->assertCanNotSeeTableRecords(array_slice(array_reverse($rows), 0, 10));
    }

    public function test_list_query_count_does_not_grow_with_the_number_of_rows(): void
    {
        [$me] = $this->member('M-103', 'Alex Member');
        $apparatus = $this->apparatus();
        $this->inspection($apparatus, ['actor_user_id' => $me->id, 'results' => $this->results('Missing')]);
        $few = $this->countQueries(fn () => $this->livewireAs($me));

        foreach (range(1, 12) as $ignored) {
            $this->inspection($this->apparatus('E9'.(++$this->sequence)), ['actor_user_id' => $me->id, 'results' => $this->results('Damaged')]);
        }
        $many = $this->countQueries(fn () => $this->livewireAs($me));

        $this->assertSame($few, $many, 'List rendering must not issue per-row queries.');
    }

    public function test_detail_page_is_visible_to_the_owner_only(): void
    {
        [$me] = $this->member('M-104', 'Alex Member');
        [$other] = $this->member('M-204', 'Casey Other');
        $inspection = $this->inspection($this->apparatus(), ['actor_user_id' => $me->id, 'inspection_reference' => 'INS-E91-DETAIL-000001', 'results' => $this->results('Missing', 'Hose bundle is short one length')]);

        $this->actingAs($me)->get("/employee/my-vehicle-inspections/{$inspection->id}")
            ->assertOk()
            ->assertSee('INS-E91-DETAIL-000001')
            ->assertSee('Hose bundle is short one length');

        $this->actingAs($other)->get("/employee/my-vehicle-inspections/{$inspection->id}")->assertForbidden();
    }

    public function test_detail_follow_up_maps_real_defect_and_ticket_state_without_fabricating(): void
    {
        [$me] = $this->member('M-105', 'Alex Member');
        $apparatus = $this->apparatus();
        $inspection = $this->inspection($apparatus, [
            'actor_user_id' => $me->id,
            'results' => [[
                'id' => 'c1', 'name' => 'Driver Side',
                'items' => [
                    ['id' => 'i1', 'name' => 'Nozzle', 'status' => 'Missing', 'notes' => null],
                    ['id' => 'i2', 'name' => 'Light', 'status' => 'Damaged', 'notes' => 'Cracked lens'],
                    ['id' => 'i3', 'name' => 'Axe', 'status' => 'Damaged', 'notes' => null],
                    ['id' => 'i4', 'name' => 'Ladder', 'status' => 'Missing', 'notes' => null],
                    ['id' => 'i5', 'name' => 'Radio', 'status' => 'Present', 'notes' => null],
                ],
            ]],
        ]);
        $ticket = ApparatusServiceTicket::query()->create([
            'apparatus_id' => $apparatus->id, 'unit_designation_snapshot' => 'E91', 'origin' => 'hub', 'category' => 'repair',
            'title' => 'Fix light', 'description' => 'Light lens', 'status' => 'completed', 'completed_at' => now(),
        ]);
        $this->defect($apparatus, $inspection, 'Nozzle', 'open');
        $this->defect($apparatus, $inspection, 'Light', 'in_progress');
        $this->defect($apparatus, $inspection, 'Axe', 'in_progress', $ticket->id);
        // "Ladder" has no defect row at all.

        $detail = app(EmployeeInspectionHistoryService::class)->detail($inspection->fresh());
        $stage = collect($detail['findings'])->mapWithKeys(fn (array $f): array => [$f['name'] => $f['follow_up']]);

        $this->assertSame('Reported', $stage['Nozzle']['stage']);
        $this->assertNull($stage['Nozzle']['ticket_number']);
        $this->assertSame('Under review', $stage['Light']['stage']);
        $this->assertSame('Service ticket opened', $stage['Axe']['stage']);
        $this->assertSame($ticket->fresh()->ticket_number, $stage['Axe']['ticket_number']);
        $this->assertSame('Completed', $stage['Axe']['ticket_status']);
        $this->assertTrue($stage['Axe']['repair_completed']);
        $this->assertFalse($stage['Light']['repair_completed']);
        $this->assertFalse($stage['Ladder']['linked']);
        $this->assertSame('No defect record linked', $stage['Ladder']['stage']);

        $this->defect($apparatus, $inspection, 'Radio', 'resolved');
        $this->assertSame('Resolved', app(EmployeeInspectionHistoryService::class)->defectStageLabel(ApparatusDefect::query()->where('item', 'Radio')->firstOrFail()));
    }

    public function test_pdf_is_available_to_the_owner_and_to_administrators_but_not_to_other_members_or_guests(): void
    {
        [$me] = $this->member('M-106', 'Alex Member');
        [$other] = $this->member('M-206', 'Casey Other');
        $admin = User::factory()->create(['account_status' => AccountStatus::Active, 'employee_profile_id' => null]);
        $admin->givePermissionTo(Permission::findOrCreate('view_inspection', 'web'));
        $inspection = $this->inspection($this->apparatus(), ['actor_user_id' => $me->id, 'inspection_reference' => 'INS-E91-PDF-000001']);
        $url = route('employee.vehicle-inspections.pdf', $inspection);

        $this->get($url)->assertRedirect('/login');

        $owner = $this->actingAs($me)->get($url)->assertOk();
        $this->assertStringStartsWith('application/pdf', (string) $owner->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', (string) $owner->headers->get('Content-Disposition'));
        $this->assertStringContainsString('INS-E91-PDF-000001.pdf', (string) $owner->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', $owner->getContent());
        $inline = $this->actingAs($me)->get($url.'?inline=1')->assertOk();
        $this->assertStringContainsString('inline', (string) $inline->headers->get('Content-Disposition'));

        $this->actingAs($other)->get($url)->assertForbidden();

        $viaAdmin = $this->actingAs($admin)->get($url)->assertOk();
        $this->assertStringStartsWith('application/pdf', (string) $viaAdmin->headers->get('Content-Type'));
    }

    public function test_pdf_view_renders_every_compartment_note_and_embedded_images_from_stored_results(): void
    {
        [$me] = $this->member('M-107', 'Alex Member');
        Storage::disk('public')->put('defects/finding.png', base64_decode(self::PNG));
        Storage::disk('public')->put('signatures/sig.png', base64_decode(self::PNG));
        $inspection = $this->inspection($this->apparatus(), [
            'actor_user_id' => $me->id,
            'officer_signature' => 'signatures/sig.png',
            'results' => [
                ['id' => 'c1', 'name' => 'Cab', 'items' => [['id' => 'a', 'name' => 'SCBA', 'status' => 'Present', 'notes' => null]]],
                ['id' => 'c2', 'name' => 'Pump Panel', 'items' => [['id' => 'b', 'name' => 'Gauge', 'status' => 'Damaged', 'notes' => 'Glass cracked', 'photo_path' => 'defects/finding.png']]],
            ],
        ]);
        $history = app(EmployeeInspectionHistoryService::class);
        $detail = $history->detail($inspection->fresh());

        $html = view('pdf.apparatus-inspection', [
            'detail' => $detail,
            'images' => [
                'signature' => (string) $history->embeddableImage('signatures/sig.png', 1_000_000),
                $detail['compartments'][1]['items'][0]['photo_key'] => (string) $history->embeddableImage('defects/finding.png', 1_000_000),
            ],
            'omittedImages' => 0,
            'logo' => null,
            'generatedAt' => now(),
        ])->render();

        foreach (['Cab', 'Pump Panel', 'SCBA', 'Gauge', 'Glass cracked', 'Inspector signature', 'data:image/png;base64,'] as $expected) {
            $this->assertStringContainsString($expected, $html);
        }
    }

    public function test_images_are_served_only_through_the_authorized_route(): void
    {
        [$me] = $this->member('M-108', 'Alex Member');
        [$other] = $this->member('M-208', 'Casey Other');
        Storage::disk('public')->put('defects/finding.png', base64_decode(self::PNG));
        Storage::disk('public')->put('signatures/sig.png', base64_decode(self::PNG));
        Storage::disk('public')->put('defects/unrelated.png', base64_decode(self::PNG));
        $inspection = $this->inspection($this->apparatus(), [
            'actor_user_id' => $me->id,
            'officer_signature' => 'signatures/sig.png',
            'results' => [['id' => 'c', 'name' => 'Cab', 'items' => [['id' => 'a', 'name' => 'Gauge', 'status' => 'Damaged', 'notes' => null, 'photo_path' => 'defects/finding.png']]]],
        ]);
        $history = app(EmployeeInspectionHistoryService::class);
        $photoKey = $history->imageKey('defects/finding.png');

        $this->actingAs($me)->get(route('employee.vehicle-inspections.image', [$inspection, 'signature']))
            ->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->actingAs($me)->get(route('employee.vehicle-inspections.image', [$inspection, $photoKey]))->assertOk();
        // A key for a file that is not part of this inspection's evidence.
        $this->actingAs($me)->get(route('employee.vehicle-inspections.image', [$inspection, $history->imageKey('defects/unrelated.png')]))->assertNotFound();
        $this->actingAs($me)->get("/employee/vehicle-inspections/{$inspection->id}/images/..%2F..%2F.env")->assertNotFound();
        $this->actingAs($other)->get(route('employee.vehicle-inspections.image', [$inspection, 'signature']))->assertForbidden();
    }

    public function test_service_never_exposes_reviewer_notes_and_owner_helper_ignores_name_text(): void
    {
        [$me, $employee] = $this->member('M-109', 'Alex Member');
        $inspection = $this->inspection($this->apparatus(), ['actor_user_id' => null, 'employee_id' => null, 'operator_name' => $employee->name]);
        $history = app(EmployeeInspectionHistoryService::class);

        $this->assertFalse($history->owns($me, $inspection));
        $this->assertFalse($me->can('viewOwn', $inspection));
        $detail = $history->detail($inspection->fresh());
        $this->assertArrayNotHasKey('internal_note', $detail);
        $this->assertArrayNotHasKey('review_events', $detail);
    }

    public function test_date_range_filter_uses_eastern_calendar_days(): void
    {
        [$me] = $this->member('M-110', 'Alex Member');
        $apparatus = $this->apparatus();
        // 03:30 UTC on Mar 10 is 11:30 PM on Mar 9 in Miami (EDT).
        $lateEvening = $this->inspection($apparatus, ['actor_user_id' => $me->id, 'completed_at' => '2026-03-10 03:30:00']);
        $nextDay = $this->inspection($apparatus, ['actor_user_id' => $me->id, 'completed_at' => '2026-03-10 15:00:00']);

        $this->livewireAs($me)
            ->filterTable('submitted_between', ['from' => '2026-03-10', 'until' => null])
            ->assertCanSeeTableRecords([$nextDay])->assertCanNotSeeTableRecords([$lateEvening])
            ->filterTable('submitted_between', ['from' => null, 'until' => '2026-03-09'])
            ->assertCanSeeTableRecords([$lateEvening])->assertCanNotSeeTableRecords([$nextDay]);
    }

    public function test_guests_are_sent_to_login_for_the_detail_page_and_image_route(): void
    {
        [$me] = $this->member('M-111', 'Alex Member');
        $inspection = $this->inspection($this->apparatus(), ['actor_user_id' => $me->id]);

        $this->get("/employee/my-vehicle-inspections/{$inspection->id}")->assertRedirect();
        $this->get(route('employee.vehicle-inspections.image', [$inspection, 'signature']))->assertRedirect('/login');
    }

    /** @return array{0: User, 1: Employee} */
    private function member(string $number, string $name): array
    {
        $employee = Employee::query()->create([
            'employee_id' => $number,
            'name' => $name,
            'rank' => 'Firefighter',
            'password' => 'not-used-by-tests',
            'must_change_password' => false,
        ]);
        $user = User::factory()->create([
            'account_status' => AccountStatus::Active,
            'employee_id' => $employee->employee_id,
            'employee_profile_id' => $employee->id,
        ]);

        return [$user, $employee];
    }

    private function apparatus(string $designation = 'E91'): Apparatus
    {
        $station = Station::query()->firstOrCreate(
            ['station_number' => 91],
            ['name' => 'Station 91', 'address' => '91 Test Street', 'is_active' => true],
        );

        return Apparatus::query()->create([
            'station_id' => $station->id,
            'unit_id' => $designation,
            'name' => "Engine {$designation}",
            'type' => 'Engine',
            'vehicle_number' => $designation,
            'designation' => $designation,
            'slug' => strtolower("engine-{$designation}"),
            'make' => 'Fixture',
            'model' => 'Fixture',
            'year' => 2026,
            'status' => 'In Service',
            'daily_checkout_requirement' => 'required',
        ]);
    }

    /** @param  array<string, mixed>  $overrides */
    private function inspection(Apparatus $apparatus, array $overrides = []): ApparatusInspection
    {
        $number = ++$this->sequence;

        return ApparatusInspection::query()->create(array_merge([
            'apparatus_id' => $apparatus->id,
            'operator_name' => 'Fixture Operator',
            'rank' => 'Firefighter',
            'shift' => 'A',
            'vehicle_number' => $apparatus->vehicle_number,
            'designation_at_time' => $apparatus->designation,
            'inspection_reference' => sprintf('INS-%s-%06d', $apparatus->designation, $number),
            'results' => $this->results('Present'),
            'review_status' => 'approved',
            'processing_status' => 'accepted',
            'checklist_version' => str_repeat('a', 64),
            'completed_at' => now()->subMinutes($number),
        ], $overrides));
    }

    /** @return list<array<string, mixed>> */
    private function results(string $status, ?string $notes = null): array
    {
        return [[
            'id' => 'cab', 'name' => 'Cab',
            'items' => [['id' => 'item-1', 'name' => 'Item One', 'status' => $status, 'notes' => $notes]],
        ]];
    }

    private function defect(Apparatus $apparatus, ApparatusInspection $inspection, string $item, string $status, ?int $ticketId = null): ApparatusDefect
    {
        $defect = ApparatusDefect::query()->create([
            'apparatus_id' => $apparatus->id,
            'apparatus_inspection_id' => $inspection->id,
            'compartment' => 'Driver Side',
            'item' => $item,
            'status' => $status,
            'issue_type' => 'missing',
            'service_ticket_id' => $ticketId,
            'reported_date' => now()->toDateString(),
        ]);
        DB::table('apparatus_defect_observations')->insert([
            'apparatus_defect_id' => $defect->id,
            'apparatus_inspection_id' => $inspection->id,
            'observation' => 'first_reported',
            'reported_status' => 'Missing',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $defect;
    }

    private function livewireAs(User $user): \Livewire\Features\SupportTesting\Testable
    {
        $this->actingAs($user);
        $this->bindCanonicalSessionToLivewireTestRequests();
        Filament::setCurrentPanel(Filament::getPanel('employee'));

        return Livewire::test(MyVehicleInspections::class);
    }

    private function countQueries(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        // Only the inspection list's own reads: session/permission bootstrap
        // queries legitimately differ between the first and later renders.
        $count = count(array_filter(
            array_column(DB::getQueryLog(), 'query'),
            static fn (string $sql): bool => (bool) preg_match('/"(apparatus_inspections|apparatuses|stations|apparatus_defects)"/', $sql),
        ));
        DB::disableQueryLog();

        return $count;
    }
}
