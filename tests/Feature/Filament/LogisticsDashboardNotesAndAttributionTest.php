<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Enums\AccountStatus;
use App\Filament\Widgets\StationOperationsHubWidget;
use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusDefectEvent;
use App\Models\ApparatusInspection;
use App\Models\ApparatusInspectionReviewEvent;
use App\Models\ApparatusIssueMessage;
use App\Models\Station;
use App\Models\User;
use App\Services\Defects\DefectCorrectiveActionService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Logistics Dashboard: status lines name the person who set them, and one card lists every
 * internal note and member message on Daily Checkouts, linking to the inspection's admin view.
 */
final class LogisticsDashboardNotesAndAttributionTest extends TestCase
{
    use RefreshDatabase;

    private User $reviewer;

    private Apparatus $unit;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-26 09:00:00', 'America/New_York'));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->reviewer = User::factory()->create(['account_status' => AccountStatus::Active, 'name' => 'Jordan Rivera']);
        $this->reviewer->assignRole(Role::findOrCreate('super_admin', 'web'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $station = Station::query()->create(['station_number' => 2, 'name' => 'Station 2', 'address' => '2 Test Street', 'is_active' => true]);
        $this->unit = Apparatus::query()->create([
            'station_id' => $station->id, 'unit_id' => 'R2', 'designation' => 'R 2', 'vehicle_number' => 'R2',
            'make' => 'Fixture', 'model' => 'Rescue', 'status' => 'In Service',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_an_acknowledged_issue_names_who_acknowledged_it_and_when(): void
    {
        $defect = $this->issue('Kitty Litter');
        Carbon::setTestNow(Carbon::parse('2026-10-21 08:38:00', 'America/New_York'));
        $this->actingAs($this->reviewer);
        app(DefectCorrectiveActionService::class)->acknowledge($defect->id, $this->reviewer, ['internal_note' => 'Ordering from supply.']);

        // A later note on the same status must not re-attribute it to the note's author.
        Carbon::setTestNow(Carbon::parse('2026-10-22 10:00:00', 'America/New_York'));
        $other = User::factory()->create(['account_status' => AccountStatus::Active, 'name' => 'Casey Other']);
        $other->assignRole(Role::findOrCreate('super_admin', 'web'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        app(DefectCorrectiveActionService::class)->acknowledge($defect->id, $other, ['internal_note' => 'Still on order.']);

        Carbon::setTestNow(Carbon::parse('2026-10-26 09:00:00', 'America/New_York'));
        $actions = app(StationOperationsHubWidget::class)->getViewData()['department']['inspectionQueues']['actions'];

        $this->assertSame(1, $actions['count']);
        $this->assertSame('Acknowledged by Jordan Rivera', $actions['rows'][0]['status']);
        $this->assertSame('Oct 21, 8:38 AM', $actions['rows'][0]['timeLabel']);
        $this->assertSame('Acknowledged by Jordan Rivera', $defect->fresh()->attributedActionLabel());
        // Members still read the department, never a staff member's name.
        $this->assertSame('Acknowledged by Logistics', $defect->fresh()->actionLabel());
    }

    public function test_a_new_issue_names_the_member_who_reported_it(): void
    {
        $defect = $this->issue('Helmet Shields');
        $member = User::factory()->create(['account_status' => AccountStatus::Active, 'name' => 'Sam Crew', 'display_name' => 'FF Sam Crew']);
        ApparatusDefectEvent::query()->create([
            'apparatus_defect_id' => $defect->id, 'actor_user_id' => $member->id, 'event_type' => 'reported', 'to_status' => 'reported',
        ]);
        $this->actingAs($this->reviewer);

        $issues = app(StationOperationsHubWidget::class)->getViewData()['department']['inspectionQueues']['issues'];

        $this->assertSame([$defect->id], array_column($issues['rows'], 'id'));
        $this->assertSame('Awaiting verification · reported by FF Sam Crew', $issues['rows'][0]['status']);
    }

    public function test_the_notes_card_lists_internal_notes_and_member_messages_with_links_to_the_daily(): void
    {
        $member = User::factory()->create(['account_status' => AccountStatus::Active, 'name' => 'Sam Crew']);
        $held = $this->inspection('held_for_review');
        $defect = $this->issue('LP CO Monitor', $held);
        $this->actingAs($this->reviewer);
        app(DefectCorrectiveActionService::class)->acknowledge($defect->id, $this->reviewer, ['internal_note' => 'Serial number does not match.']);

        ApparatusIssueMessage::query()->create([
            'apparatus_inspection_id' => $held->id, 'author_user_id' => $this->reviewer->id, 'visibility' => ApparatusIssueMessage::VISIBILITY_INTERNAL,
            'author_context' => ApparatusIssueMessage::CONTEXT_LOGISTICS, 'body' => 'Check the mileage photo before approving.',
        ]);
        ApparatusIssueMessage::query()->create([
            'apparatus_inspection_id' => $held->id, 'author_user_id' => $member->id, 'visibility' => ApparatusIssueMessage::VISIBILITY_MEMBER,
            'author_context' => ApparatusIssueMessage::CONTEXT_MEMBER, 'body' => 'Odometer photo re-uploaded.',
        ]);
        $closed = $this->inspection('accepted');
        ApparatusInspectionReviewEvent::query()->create([
            'apparatus_inspection_id' => $closed->id, 'previous_status' => 'held_for_review', 'status' => 'accepted',
            'internal_note' => 'Approved after phone call.', 'changed_by_user_id' => $this->reviewer->id,
        ]);

        $notes = app(StationOperationsHubWidget::class)->getViewData()['department']['dailyNotes'];
        $rows = collect($notes['rows']);

        // Weeks later, notes on still-open work remain (any age); the closed decision's note has aged out.
        Carbon::setTestNow(Carbon::parse('2026-11-20 09:00:00', 'America/New_York'));
        $later = collect(app(StationOperationsHubWidget::class)->getViewData()['department']['dailyNotes']['rows']);
        $this->assertCount(3, $later);
        $this->assertTrue($later->every(static fn (array $row): bool => $row['open']));
        $this->assertContains('Serial number does not match.', $later->pluck('excerpt')->all());
        Carbon::setTestNow(Carbon::parse('2026-10-26 09:00:00', 'America/New_York'));

        $this->assertSame(3, $notes['count'], 'Notes on open work are counted; the accepted inspection is not.');
        $this->assertCount(4, $rows);
        $this->assertFalse($rows->last()['open'], 'Notes on closed work follow the open ones.');

        $issueNote = $rows->firstWhere('excerpt', 'Serial number does not match.');
        $this->assertSame('internal', $issueNote['kind']);
        $this->assertSame('R 2 · LP CO Monitor', $issueNote['subject']);
        $this->assertSame('Jordan Rivera', $issueNote['author']);
        $this->assertStringEndsWith('/admin/inspections/'.$held->id.'#issue-'.$defect->id, $issueNote['url']);

        $reply = $rows->firstWhere('excerpt', 'Odometer photo re-uploaded.');
        $this->assertSame('member_reply', $reply['kind']);
        $this->assertSame('Sam Crew', $reply['author']);
        $this->assertStringEndsWith('/admin/inspections/'.$held->id.'#messages', $reply['url']);

        $review = $rows->firstWhere('excerpt', 'Approved after phone call.');
        $this->assertSame('Internal note', $review['kindLabel']);
        $this->assertStringEndsWith('/admin/inspections/'.$closed->id.'#approval', $review['url']);
    }

    public function test_the_notes_card_is_staff_only_and_renders_without_overlapping_rows(): void
    {
        $held = $this->inspection('held_for_review');
        ApparatusIssueMessage::query()->create([
            'apparatus_inspection_id' => $held->id, 'author_user_id' => $this->reviewer->id, 'visibility' => ApparatusIssueMessage::VISIBILITY_INTERNAL,
            'author_context' => ApparatusIssueMessage::CONTEXT_LOGISTICS, 'body' => 'Internal only.',
        ]);

        $this->actingAs(User::factory()->create(['account_status' => AccountStatus::Active]));
        $this->assertNull(app(StationOperationsHubWidget::class)->getViewData()['department']['dailyNotes']);

        $this->actingAs($this->reviewer);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(StationOperationsHubWidget::class)
            ->assertSee('Daily Checkout notes &amp; messages', false)
            ->assertSee('Internal only.');

        $css = (string) file_get_contents(resource_path('css/filament/hub-inspection-views.css'));
        $this->assertMatchesRegularExpression('/\.hub-insp-queue__list \{[^}]*grid-auto-rows: max-content/', $css, 'Overflowing queue rows keep their content height.');
    }

    private function inspection(string $processing): ApparatusInspection
    {
        return ApparatusInspection::query()->create([
            'apparatus_id' => $this->unit->id, 'client_submission_id' => (string) Str::uuid(), 'operator_name' => 'Fixture Operator',
            'rank' => 'Firefighter', 'shift' => 'A-Day', 'unit_number' => 'R2', 'designation_at_time' => 'R 2',
            'processing_status' => $processing, 'review_status' => $processing === 'held_for_review' ? 'pending_review' : 'approved',
            'completed_at' => now()->subHour(),
        ]);
    }

    private function issue(string $item, ?ApparatusInspection $inspection = null): ApparatusDefect
    {
        $defect = ApparatusDefect::recordDefect($this->unit->id, 'Cab', $item, 'Missing', 'Fixture finding.');
        if ($inspection !== null) {
            $defect->forceFill(['first_reported_inspection_id' => $inspection->id, 'apparatus_inspection_id' => $inspection->id])->save();
        }

        return $defect->fresh();
    }
}
