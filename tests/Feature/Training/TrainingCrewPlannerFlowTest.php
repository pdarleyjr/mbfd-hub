<?php

declare(strict_types=1);

namespace Tests\Feature\Training;

use App\Filament\Training\Pages\TrainingCrewPlanner;
use App\Models\Employee;
use App\Models\Training\TrainingAttendance;
use App\Models\Training\TrainingCourse;
use App\Models\Training\TrainingModule;
use App\Models\Training\TrainingSession;
use App\Models\User;
use App\Services\Training\CrewPlanner\TrainingCrewPlannerDraftStore;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** The select-and-go planner flow. Every identity and PDF is synthetic. */
final class TrainingCrewPlannerFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private TrainingCourse $course;

    /** @var array<string, Employee> */
    private array $employees = [];

    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame('sqlite', DB::connection()->getDriverName());
        self::assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->withoutVite();
        $this->admin = User::factory()->create(['account_status' => 'active', 'must_change_password' => false]);
        $this->admin->assignRole(Role::findOrCreate('training_admin', 'web'));
        $this->actingAs($this->admin);
        $this->bindCanonicalSessionToLivewireTestRequests();
        Filament::setCurrentPanel(Filament::getPanel('training'));
        $this->course = TrainingCourse::query()->create(['name' => 'Synthetic flow course', 'classifications' => ['STC'],
            'track_attendance_matrix' => false, 'created_by' => $this->admin->id]);
        foreach (['90001' => 'Captain', '90002' => 'Firefighter', '90003' => 'Lieutenant'] as $number => $rank) {
            $this->employees[$number] = Employee::query()->create(['employee_id' => $number, 'name' => 'Synthetic '.$number,
                'rank' => $rank, 'roster_status' => 'active', 'password' => 'not-used']);
        }
    }

    public function test_choosing_a_course_applies_it_and_choosing_a_module_applies_it_without_a_button(): void
    {
        $module = TrainingModule::query()->create(['training_course_id' => $this->course->id, 'name' => 'Synthetic Module 1',
            'sort_order' => 0, 'is_required' => true, 'created_by' => $this->admin->id]);
        $component = Livewire::test(TrainingCrewPlanner::class);
        $id = $component->get('draftId');
        self::assertNull($this->draft($id)['course_id']);
        $component->set('courseId', $this->course->id)->assertHasNoErrors();
        self::assertSame($this->course->id, $this->draft($id)['course_id']);
        self::assertNull($this->draft($id)['module_id']);
        $component->assertSee('Synthetic Module 1')->assertSee('Entire course')->assertDontSee('Use this scope');
        $component->set('scope', (string) $module->id)->assertHasNoErrors();
        self::assertSame($module->id, $this->draft($id)['module_id']);
        $component->set('scope', 'course');
        self::assertNull($this->draft($id)['module_id']);
        $component->set('courseId', '')->assertHasNoErrors();
        self::assertSame($this->course->id, $this->draft($id)['course_id'], 'Clearing the choice applies nothing.');
        $component->set('courseId', $this->course->id);
        $component->set('scope', '999999')->assertStatus(422);
        self::assertNull($this->draft($id)['module_id'], 'A module from another course is never applied.');
    }

    public function test_session_rows_never_invent_default_times_and_save_themselves_once_valid(): void
    {
        $component = $this->readyComponent();
        $id = $component->get('draftId');
        $component->assertSet('slotEdits.0.start', '')->assertSet('slotEdits.0.end', '')->assertDontSee('Morning')->assertDontSee('Afternoon');
        self::assertSame([], $this->draft($id)['slots']);
        $component->set('slotEdits.0.start', '13:00');
        self::assertSame([], $this->draft($id)['slots'], 'A row with only a start time is not saved.');
        $component->set('slotEdits.0.end', '12:00')->assertHasErrors('slotEdits.0.end');
        self::assertSame([], $this->draft($id)['slots']);
        $component->set('slotEdits.0.end', '15:00')->assertHasNoErrors();
        $slots = $this->draft($id)['slots'];
        self::assertCount(1, $slots);
        self::assertSame('Session 1', $slots[0]['label']);
        self::assertSame('2026-10-21T13:00:00-04:00', $slots[0]['start_at']);
        self::assertSame('2026-10-21T15:00:00-04:00', $slots[0]['end_at']);
        $component->call('addSlot')->set('slotEdits.1.start', '16:00');
        self::assertCount(1, $this->draft($id)['slots'], 'An unfinished second row waits instead of dropping the first.');
        $component->set('slotEdits.1.end', '17:00');
        self::assertSame(['Session 1', 'Session 2'], array_column($this->draft($id)['slots'], 'label'));
        $component->call('removeSlot', 1);
        self::assertCount(1, $this->draft($id)['slots']);
    }

    public function test_existing_hub_sessions_prefill_only_the_start_time(): void
    {
        TrainingSession::query()->create(['training_course_id' => $this->course->id, 'public_id' => (string) Str::ulid(), 'course_name' => $this->course->name,
            'classifications' => ['STC'], 'held_on' => '2026-10-21', 'starts_at' => '2026-10-21T17:00:00Z', 'status' => 'draft',
            'attendance_source' => 'digital', 'revision' => 1, 'created_by' => $this->admin->id]);
        $component = $this->readyComponent();
        $component->assertSet('slotEdits.0.start', '13:00')->assertSet('slotEdits.0.end', '');
        self::assertSame([], $this->draft($component->get('draftId'))['slots']);
        $component->set('slotEdits.0.end', '15:00');
        self::assertCount(1, $this->draft($component->get('draftId'))['slots']);
    }

    public function test_selecting_and_unselecting_keeps_the_candidate_listed_and_never_trusts_the_browser(): void
    {
        $component = $this->readyComponent([['', '08:30', '11:30']]);
        $id = $component->get('draftId');
        $first = $this->employees['90001']->id;
        self::assertSame([], $this->draft($id)['selections'], 'Nobody is selected automatically.');
        $component->assertSeeHtml('aria-label="Select Synthetic 90001"')->assertSee('3 eligible · 0 selected');
        $component->call('toggleMember', $first)->assertHasNoErrors();
        self::assertArrayHasKey($first, $this->draft($id)['selections']);
        $component->assertSee('3 eligible · 1 selected')->assertSeeHtml('aria-label="Select Synthetic 90001"');
        $component->call('toggleMember', $first);
        self::assertSame([], $this->draft($id)['selections']);
        $component->assertSeeHtml('aria-label="Select Synthetic 90001"');
        $component->call('toggleMember', 987654)->assertHasErrors('selection');
        self::assertSame([], $this->draft($id)['selections']);
    }

    public function test_one_session_places_new_members_automatically_and_select_all_shown_respects_filters(): void
    {
        $component = $this->readyComponent([['', '08:30', '11:30']]);
        $id = $component->get('draftId');
        $slot = $this->draft($id)['slots'][0]['id'];
        $component->call('toggleMember', $this->employees['90002']->id);
        self::assertSame($slot, $this->draft($id)['selections'][$this->employees['90002']->id]['slot_id']);
        $component->set('filters.rank', 'Captain')->call('selectAllShown');
        self::assertCount(2, $this->draft($id)['selections']);
        $component->set('filters.rank', '')->call('selectAllShown');
        self::assertCount(3, $this->draft($id)['selections']);
        self::assertSame([$slot], array_values(array_unique(array_column($this->draft($id)['selections'], 'slot_id'))));
        $component->call('clearSelection');
        self::assertSame([], $this->draft($id)['selections']);
        $component->call('undoRemove');
        self::assertCount(3, $this->draft($id)['selections']);
    }

    public function test_several_sessions_need_a_valid_choice_and_never_accept_an_incompatible_one(): void
    {
        $component = $this->readyComponent([['', '08:30', '11:30'], ['', '13:00', '15:00']], ['90001' => ['08:00', '12:00']]);
        $id = $component->get('draftId');
        [$one, $two] = array_column($this->draft($id)['slots'], 'id');
        $partial = $this->employees['90001']->id;
        $full = $this->employees['90002']->id;
        $component->assertSee('Eligible for Session 1 only');
        $component->call('toggleMember', $partial)->call('toggleMember', $full);
        $selections = $this->draft($id)['selections'];
        self::assertSame($one, $selections[$partial]['slot_id'], 'A single possible session is chosen for the member.');
        self::assertNull($selections[$full]['slot_id'], 'Several possible sessions require a visible choice.');
        $component->call('setMemberSlot', $partial, $two)->assertHasErrors('selection');
        self::assertSame($one, $this->draft($id)['selections'][$partial]['slot_id']);
        $component->call('setMemberSlot', $full, $two)->assertHasNoErrors();
        self::assertSame($two, $this->draft($id)['selections'][$full]['slot_id']);
        $component->call('setMemberSlot', $full, '');
        self::assertNull($this->draft($id)['selections'][$full]['slot_id']);
    }

    public static function malformedSelectionRequests(): array
    {
        return [
            'zero member id' => ['toggleMember', [0]],
            'negative member id' => ['setMemberSlot', [-1, '']],
            'member who is not selected' => ['setMemberSlot', [424242, '']],
            'session that does not exist' => ['setMemberSlot', ['SELECTED', 'UNKNOWN_SESSION']],
        ];
    }

    #[DataProvider('malformedSelectionRequests')]
    public function test_malformed_selection_requests_are_rejected_without_changing_the_proposal(string $method, array $arguments): void
    {
        $component = $this->readyComponent([['', '08:30', '11:30'], ['', '13:00', '15:00']]);
        $id = $component->get('draftId');
        $component->call('toggleMember', $this->employees['90002']->id);
        $arguments = array_map(fn (mixed $argument): mixed => match ($argument) {
            'SELECTED' => $this->employees['90002']->id, 'UNKNOWN_SESSION' => (string) Str::uuid(), default => $argument,
        }, $arguments);
        $before = $this->draft($id);
        $component->call($method, ...$arguments)->assertStatus(422);
        self::assertSame($before, $this->draft($id));
    }

    /** One member signs in at 14:00 on $day to an earlier session of the course. */
    private function signInDuring(string $day): void
    {
        $earlier = TrainingSession::query()->create(['training_course_id' => $this->course->id, 'public_id' => (string) Str::ulid(), 'course_name' => $this->course->name,
            'classifications' => ['STC'], 'held_on' => CarbonImmutable::parse($day)->subDay()->toDateString(), 'status' => 'submitted',
            'attendance_source' => 'digital', 'revision' => 1, 'created_by' => $this->admin->id]);
        TrainingAttendance::query()->create(['training_session_id' => $earlier->id, 'employee_profile_id' => $this->employees['90001']->id,
            'snapshot' => ['rank' => 'Captain'], 'signature' => ['disk' => 'local', 'path' => 'private/source.png', 'mime' => 'image/png', 'sha256' => str_repeat('d', 64)],
            'signed_at' => CarbonImmutable::parse($day.' 14:00', 'America/New_York')->utc(), 'client_submission_id' => (string) Str::uuid(), 'request_hash' => str_repeat('e', 64)]);
    }

    public function test_attendance_is_judged_as_it_stood_when_todays_class_begins(): void
    {
        $today = CarbonImmutable::now('America/New_York')->toDateString();
        $page = $this->readyComponent([], [], $today)->instance();
        $cutoff = fn (array $draft): array => (fn (array $draft): array => $this->attendanceCutoff($draft))->call($page, $draft);
        $slot = fn (string $date, string $time): array => ['id' => (string) Str::uuid(), 'label' => 'Session 1',
            'start_at' => CarbonImmutable::parse($date.' '.$time, 'America/New_York')->toIso8601String(),
            'end_at' => CarbonImmutable::parse($date.' 23:59', 'America/New_York')->toIso8601String()];
        // Today's class has begun: judged at its start, so attendance recorded during it does not remove anyone.
        [$at, $classStart] = $cutoff(['slots' => [$slot($today, '00:00')], 'metadata' => ['schedule_date' => $today]]);
        self::assertTrue($classStart);
        self::assertSame(CarbonImmutable::parse($today.' 00:00', 'America/New_York')->getTimestamp(), $at->getTimestamp());
        // A class later today is judged now; the cutoff never moves into the future.
        [$at, $classStart] = $cutoff(['slots' => [$slot($today, '23:58')], 'metadata' => ['schedule_date' => $today]]);
        self::assertFalse($classStart);
        self::assertTrue($at->lessThanOrEqualTo(CarbonImmutable::now()));
        // An older roster is always judged now.
        $old = CarbonImmutable::now('America/New_York')->subDays(3)->toDateString();
        [, $classStart] = $cutoff(['slots' => [$slot($old, '13:00')], 'metadata' => ['schedule_date' => $old]]);
        self::assertFalse($classStart);
    }

    public function test_a_rejected_upload_message_survives_until_the_page_loads_and_shows_once(): void
    {
        // CI regression: a debounced search update consumed the flashed error before the redirect landed.
        session()->put(TrainingCrewPlanner::UPLOAD_ERROR_KEY, 'Synthetic upload problem. Your saved proposal is safe.');
        Livewire::test(TrainingCrewPlanner::class)->assertSee('Synthetic upload problem. Your saved proposal is safe.');
        self::assertFalse(session()->has(TrainingCrewPlanner::UPLOAD_ERROR_KEY));
        Livewire::test(TrainingCrewPlanner::class)->assertDontSee('Synthetic upload problem.');
    }

    public function test_an_older_roster_never_moves_the_attendance_cutoff_into_the_past(): void
    {
        // Security review: an old report must not hide attendance recorded since its date.
        $day = CarbonImmutable::now('America/New_York')->subDays(3)->toDateString();
        $component = $this->readyComponent([], [], $day);
        $this->signInDuring($day);
        $component->set('slotEdits.0.start', '13:00')->set('slotEdits.0.end', '15:00')->assertHasNoErrors();
        $component->assertDontSee('as it stood when class begins')->assertDontSeeHtml('aria-label="Select Synthetic 90001"');
        $component->call('toggleMember', $this->employees['90001']->id);
        self::assertArrayNotHasKey($this->employees['90001']->id, $this->draft($component->get('draftId'))['selections']);
    }

    public function test_export_revalidates_and_reports_members_who_stop_qualifying_in_a_needs_verification_group(): void
    {
        $component = $this->readyComponent([['', '08:30', '11:30']]);
        $id = $component->get('draftId');
        $component->call('selectAllShown')->assertDontSee('Battalion Chief');
        // Extending the class past the end of duty is picked up by the export itself.
        $component->set('slotEdits.0.end', '21:00')->assertHasNoErrors();
        $component->assertSee('Needs verification')->call('download', 'pdf')->assertHasErrors('confirmReview')->assertNoFileDownloaded();
        $component->set('confirmReview', true)->call('download', 'pdf')->assertFileDownloaded('MBFD-Training-Personnel-Request-2026-10-21.pdf');
        $component->call('download', 'xlsx')->assertFileDownloaded('MBFD-Training-Personnel-Request-2026-10-21.xlsx');
        self::assertCount(3, $this->draft($id)['selections']);
        $component->call('toggleMember', $this->employees['90001']->id);
        self::assertCount(2, $this->draft($id)['selections']);
        $component->call('download', 'bogus')->assertStatus(404);
    }

    public function test_every_mutation_is_denied_after_management_access_is_revoked(): void
    {
        $calls = [['toggleMember', [1]], ['selectAllShown', []], ['clearSelection', []], ['setMemberSlot', [1, '']], ['addSlot', []], ['saveSlots', []]];
        $components = array_map(fn (): mixed => Livewire::test(TrainingCrewPlanner::class), $calls);
        $this->admin->removeRole('training_admin');
        foreach ($calls as $index => [$method, $arguments]) {
            $components[$index]->call($method, ...$arguments)->assertForbidden();
        }
    }

    /**
     * A planner with a course applied, a roster uploaded for the date, and optional typed sessions.
     *
     * @param  list<array{string, string, string}>  $sessions
     * @param  array<string, array{string, string}>  $duty  employee id => [from, to] overriding the 08:00-20:00 default
     */
    private function readyComponent(array $sessions = [], array $duty = [], string $date = '2026-10-21'): mixed
    {
        $component = Livewire::test(TrainingCrewPlanner::class)->set('courseId', $this->course->id)->assertHasNoErrors();
        $id = $component->get('draftId');
        $this->post(route('training.crew-planner.upload'), ['draft_id' => $id, 'version' => $component->get('version'),
            'roster_pdf' => UploadedFile::fake()->createWithContent('synthetic-daily.pdf', $this->pdf($date, $duty))])->assertRedirect()->assertSessionHasNoErrors();
        $component->call('reloadProposal')->assertHasNoErrors();
        foreach ($sessions as $index => [$label, $start, $end]) {
            if ($index > 0) {
                $component->call('addSlot');
            }
            $component->set("slotEdits.$index.label", $label)->set("slotEdits.$index.start", $start)->set("slotEdits.$index.end", $end)->assertHasNoErrors();
        }

        return $component;
    }

    private function draft(string $id): array
    {
        return app(TrainingCrewPlannerDraftStore::class)->get($this->admin, $id);
    }

    /** Valid one-page template PDF with fabricated employees. */
    private function pdf(string $date, array $duty): string
    {
        $items = [[48, 700, CarbonImmutable::parse($date)->format('l, F j, Y')], [48, 680, 'B Shift'], [48, 660, 'Station 9'], [48, 640, 'Engine 9']];
        foreach (array_map('strval', array_keys($this->employees)) as $index => $number) {
            $y = 620 - 20 * $index;
            [$from, $to] = $duty[$number] ?? ['08:00', '20:00'];
            $hours = (string) ((int) substr($to, 0, 2) - (int) substr($from, 0, 2));
            array_push($items, [51, $y, 'Firefighter'], [173, $y, 'SYNTHETIC, '.$number], [311, $y, $number], [351, $y, 'SP'],
                [494.7, $y, $from], [515.9, $y, $to], [548, $y, $hours]);
        }
        $content = '';
        foreach ($items as [$x, $y, $text]) {
            $content .= $this->pdfText($x, $y, $text, 0.36040324);
        }
        $content .= $this->pdfText(0, 20, '1 of 1', 0.5).$this->pdfText(525, 20, '10/21/2026, 7:00 AM', 0.5);
        $objects = ['<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids [4 0 R] /Count 1 >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 3 0 R >> >> /Contents 5 0 R >>',
            '<< /Length '.strlen($content).">>\nstream\n".$content.'endstream'];
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n".$object."\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 6\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf."trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF";
    }

    private function pdfText(float $x, float $y, string $text, float $scale): string
    {
        $text = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);

        return sprintf("q %.8F 0 0 %.8F 0 0 cm BT /F1 18 Tf 1 0 0 1 %.8F %.8F Tm (%s) Tj ET Q\n", $scale, $scale, $x / $scale, $y / $scale, $text);
    }
}
