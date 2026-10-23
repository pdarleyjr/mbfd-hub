<?php

declare(strict_types=1);

namespace Tests\Feature\Captain5\Admin;

use App\Filament\Resources\ApparatusResource\Pages\ViewApparatus;
use App\Filament\Resources\ApparatusResource\RelationManagers\DefectsRelationManager;
use App\Filament\Support\DefectHistory;
use App\Models\ApparatusDefect;
use App\Models\ApparatusDefectEvent;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\Feature\Captain5\Concerns\BuildsCaptain5World;
use Tests\TestCase;

/**
 * Captain 5 actions are recorded with the authority they were taken under, and every surface that
 * lists issue history shows that label: the canonical inspection page, the Apparatus defects
 * history and the member's own inspection view (which only ever names "Captain 5").
 */
final class Captain5AuditVisibilityTest extends TestCase
{
    use BuildsCaptain5World;
    use RefreshDatabase;

    private const RESCUE_ITEM = 'compartment_c-item-3'; // Bolt Cutters (Rescue: always in scope)

    private User $captain;

    private User $reporter;

    private ApparatusDefect $defect;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->buildCaptain5World('2026-10-20 10:00:00'); // A-shift duty day
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->captain = $this->officer('Captain', 'Captain Alpha');
        $this->seat($this->captain, 'Captain 5', 'A Shift');
        $this->reporter = $this->newMember('Firefighter Reporter');
        [$this->defect] = $this->reportMissing($this->rescue, $this->reporter, [self::RESCUE_ITEM]);

        $this->act('acknowledge')->assertOk();
        $this->act('ordered', [
            'vendor_reference' => 'PO-4471', 'member_message' => 'Bolt cutters ordered, due Friday.', 'internal_note' => 'Vendor confirmed price.',
        ])->assertOk();
    }

    public function test_the_canonical_inspection_page_shows_the_captain5_label_and_name(): void
    {
        $this->actAs($this->newReviewer('Logistics Reviewer'));

        $this->get('/admin/inspections/'.$this->defect->first_reported_inspection_id)->assertOk()
            ->assertSee('Acknowledged: Bolt Cutters')
            ->assertSee('Captain 5 (on duty) · Captain Alpha');
    }

    public function test_the_apparatus_issue_history_labels_captain5_actions_and_keeps_other_actors_plain(): void
    {
        $this->actAs($this->superAdmin());

        $manager = Livewire::test(DefectsRelationManager::class, [
            'ownerRecord' => $this->rescue, 'pageClass' => ViewApparatus::class, 'lazy' => false,
        ])->call('loadTable');

        $manager->assertTableActionVisible('issue_history', $this->defect)
            ->mountTableAction('issue_history', $this->defect)
            ->assertSee('Issue history')
            ->assertSee('Acknowledged')
            ->assertSee('Corrective action updated')
            ->assertSee('Captain 5 (on duty) · Captain Alpha')
            ->assertSee('Message to member: Bolt cutters ordered, due Friday.')
            ->assertSee('Internal note: Vendor confirmed price.')
            ->assertSee('Issue reported')
            ->assertSee('Firefighter Reporter')
            ->assertDontSee('Captain 5 (on duty) · Firefighter Reporter');

        // The history is a read of the append-only event log: every event, newest first, internal notes only on request.
        $eventCount = ApparatusDefectEvent::query()->where('apparatus_defect_id', $this->defect->id)->count();
        self::assertGreaterThanOrEqual(3, $eventCount);
        self::assertCount($eventCount, DefectHistory::rows($this->defect, true));
        self::assertSame('Corrective action updated', DefectHistory::rows($this->defect, false)[0]['title']);
        self::assertNull(DefectHistory::rows($this->defect, false)[0]['internal_note'], 'Viewers who cannot manage corrective actions never get internal notes');
    }

    public function test_the_member_sees_captain5_without_the_internal_note_or_the_captains_name(): void
    {
        $this->actAs($this->reporter);

        $this->get('/employee/my-vehicle-inspections/'.$this->defect->first_reported_inspection_id)->assertOk()
            ->assertSee('Captain 5')
            ->assertSee('Bolt cutters ordered, due Friday.')
            ->assertDontSee('Vendor confirmed price.')
            ->assertDontSee('Captain Alpha');
    }

    /** @param array<string, mixed> $data */
    private function act(string $action, array $data = []): TestResponse
    {
        $events = (int) ApparatusDefectEvent::query()->where('apparatus_defect_id', $this->defect->id)->max('id');
        $this->actAs($this->captain);
        $this->forgetCaptain5Memo();

        return $this->postJson('/api/captain5/issues/'.$this->defect->id.'/actions', [
            'action' => $action,
            'expected_state' => ($this->defect->fresh()->action_status ?: 'reported').':'.$events,
            'idempotency_key' => (string) Str::uuid(),
            ...$data,
        ]);
    }
}
