<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\ApparatusResource\Pages\EditApparatus;
use App\Filament\Resources\ApparatusResource\RelationManagers\DefectsRelationManager;
use App\Filament\Resources\DefectResource\Pages\EditDefect;
use App\Filament\Resources\DefectResource\Pages\ListDefects;
use App\Filament\Resources\InspectionResource\Pages\ViewInspection;
use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusDefectEvent;
use App\Models\ApparatusInspection;
use App\Models\User;
use App\Notifications\Inspections\InspectionEventNotification;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Feature\Defects\Concerns\BuildsContinuityFixtures;
use Tests\TestCase;

/**
 * The report's identity (unit, compartment, item, issue type) is read-only after it is filed, the
 * Defects list filters cannot contradict each other, and Correct severity works on the inspection page.
 */
final class DefectIdentityAndFiltersTest extends TestCase
{
    use BuildsContinuityFixtures;
    use RefreshDatabase;

    private Apparatus $rig;

    private Apparatus $otherRig;

    private User $reporter;

    private User $admin;

    private ApparatusInspection $inspection;

    private ApparatusDefect $defect;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Gate::before(fn (User $user): ?bool => $user->hasRole('logistics_admin') ? true : null);
        $this->rig = $this->rig();
        $this->otherRig = $this->rig('Engine 9', 'E9');
        $this->reporter = $this->member('Riley Reporter');
        $this->admin = $this->reviewer();
        $this->inspection = $this->inspectionBy($this->rig, $this->reporter);
        $this->defect = $this->openDefect($this->rig, $this->inspection, $this->reporter);
        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->withoutVite();
    }

    private function assertIdentityUnchanged(): void
    {
        $fresh = $this->defect->fresh();
        $this->assertSame($this->rig->id, $fresh->apparatus_id);
        $this->assertSame('Cab', $fresh->compartment);
        $this->assertSame('Portable radio battery', $fresh->item);
        $this->assertSame('missing', $fresh->issue_type);
    }

    // ------------------------------------------------------------------ M5

    public function test_the_defect_edit_form_shows_the_identity_read_only(): void
    {
        $page = Livewire::test(EditDefect::class, ['record' => $this->defect->getRouteKey()])->assertSuccessful();

        foreach (['apparatus_id', 'compartment', 'item', 'issue_type'] as $field) {
            $page->assertFormFieldIsDisabled($field);
        }
        $page->assertFormFieldIsEnabled('notes');
    }

    public function test_a_forged_update_cannot_change_the_identity_but_notes_still_save(): void
    {
        Livewire::test(EditDefect::class, ['record' => $this->defect->getRouteKey()])
            ->set('data.apparatus_id', $this->otherRig->id)
            ->set('data.compartment', 'Rear')
            ->set('data.item', 'Hijacked item')
            ->set('data.issue_type', 'other')
            ->set('data.notes', 'Checked again at shift change.')
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertIdentityUnchanged();
        $this->assertSame('Checked again at shift change.', $this->defect->fresh()->notes);
        $this->assertSame(0, ApparatusDefectEvent::query()->count());
    }

    public function test_the_apparatus_defects_tab_edit_cannot_change_the_identity_either(): void
    {
        $manager = Livewire::test(DefectsRelationManager::class, [
            'ownerRecord' => $this->rig, 'pageClass' => EditApparatus::class, 'lazy' => false,
        ])->call('loadTable');

        $manager->callTableAction('edit', $this->defect, data: [
            'compartment' => 'Rear', 'item' => 'Hijacked item', 'issue_type' => 'other',
            'apparatus_id' => $this->otherRig->id, 'notes' => 'Edited notes.',
        ])->assertHasNoTableActionErrors();

        $this->assertIdentityUnchanged();
        $this->assertSame('Edited notes.', $this->defect->fresh()->notes);
    }

    public function test_the_identity_is_still_typed_when_a_defect_is_reported_by_hand(): void
    {
        $manager = Livewire::test(DefectsRelationManager::class, [
            'ownerRecord' => $this->rig, 'pageClass' => EditApparatus::class, 'lazy' => false,
        ])->call('loadTable');

        $manager->callTableAction('create', data: ['compartment' => 'Rear', 'item' => 'Scene light', 'issue_type' => 'damaged'])
            ->assertHasNoTableActionErrors();

        $made = ApparatusDefect::query()->where('item', 'Scene light')->sole();
        $this->assertSame('Rear', $made->compartment);
        $this->assertSame('damaged', $made->issue_type);
        $this->assertSame($this->rig->id, $made->apparatus_id);
    }

    // ------------------------------------------------------------------ filters

    /** @return array<string, ApparatusDefect> */
    private function oneOfEachStatus(): array
    {
        $byStatus = ['reported' => $this->defect];
        foreach (['acknowledged', 'resolved', 'dismissed'] as $status) {
            $byStatus[$status] = ApparatusDefect::query()->create([
                'apparatus_id' => $this->rig->id, 'compartment' => 'Cab', 'item' => 'Item '.$status,
                'status' => 'open', 'issue_type' => 'missing', 'reported_date' => now()->toDateString(), 'action_status' => $status,
            ]);
        }

        return $byStatus;
    }

    public function test_the_filters_have_distinct_plain_labels(): void
    {
        $labels = Livewire::test(ListDefects::class)->call('loadTable')->instance()->getTable()->getFilters();
        $names = collect($labels)->map(fn ($filter) => (string) $filter->getLabel())->values()->all();

        $this->assertSame(array_values(array_unique($names)), $names, 'no two filters share a label');
        $this->assertNotContains('Status', $names);
        $this->assertContains('Open or closed', $names);
        $this->assertContains('Exact status', $names);
    }

    public function test_the_default_view_is_open_issues_and_all_shows_everything(): void
    {
        $issues = $this->oneOfEachStatus();

        Livewire::test(ListDefects::class)->call('loadTable')
            ->assertCanSeeTableRecords([$issues['reported'], $issues['acknowledged']])
            ->assertCanNotSeeTableRecords([$issues['resolved'], $issues['dismissed']])
            ->removeTableFilter('unresolved')
            ->assertCanSeeTableRecords(array_values($issues));
    }

    public function test_picking_a_closed_exact_status_is_not_lost_to_the_open_default(): void
    {
        $issues = $this->oneOfEachStatus();

        Livewire::test(ListDefects::class)->call('loadTable')
            ->filterTable('action_status', 'dismissed')
            ->assertCanSeeTableRecords([$issues['dismissed']])
            ->assertCanNotSeeTableRecords([$issues['resolved'], $issues['reported']])
            ->filterTable('action_status', 'resolved')
            ->assertCanSeeTableRecords([$issues['resolved']])
            ->assertCanNotSeeTableRecords([$issues['dismissed'], $issues['acknowledged']])
            ->filterTable('action_status', 'acknowledged')
            ->assertCanSeeTableRecords([$issues['acknowledged']])
            ->assertCanNotSeeTableRecords([$issues['reported']]);
    }

    // ------------------------------------------------------------------ Correct severity (UI)

    private function page(): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::test(ViewInspection::class, ['record' => $this->inspection->getRouteKey()]);
    }

    public function test_correct_severity_runs_from_the_inspection_page_and_unlocks_the_close(): void
    {
        $this->defect->forceFill(['severity' => 'significant'])->save();
        $page = $this->page();
        $html = $page->html();

        $this->assertStringContainsString('data-issue-action="correctSeverity"', $html);
        $this->assertStringContainsString('Correct severity', $html);
        $this->assertStringNotContainsString('data-issue-action="dismissIssue"', $html, 'the close is hidden while the guard would refuse it');
        $this->assertStringContainsString('data-close-blocked', $html);
        $this->assertStringContainsString('is not available: This issue is marked significant damage', preg_replace('/\s+/', ' ', $html));

        $page->callAction('correctSeverity', [
            'idempotency_key' => 'sev-ui-1', 'severity' => 'routine', 'reason' => 'Entered as significant by mistake; only scuffed.',
        ], ['defect' => $this->defect->id])->assertHasNoActionErrors();

        $this->assertSame('routine', $this->defect->fresh()->severity);
        $this->assertSame(1, ApparatusDefectEvent::query()->where('event_type', 'severity_corrected')->count());
        Notification::assertSentTo($this->reporter, InspectionEventNotification::class);

        $after = $this->page()->html();
        $this->assertStringContainsString('data-issue-action="dismissIssue"', $after);
        $this->assertStringNotContainsString('data-close-blocked', $after);
        $this->assertStringContainsString('Severity corrected', preg_replace('/\s+/', ' ', strip_tags($after)));
    }

    public function test_the_severity_dialog_requires_a_real_reason(): void
    {
        $this->defect->forceFill(['severity' => 'significant'])->save();

        $this->page()
            ->mountAction('correctSeverity', ['defect' => $this->defect->id])
            ->assertSee('Why is the severity being changed?')
            ->assertSee('Save severity');
        $this->page()
            ->callAction('correctSeverity', ['idempotency_key' => 'sev-ui-2', 'severity' => 'routine', 'reason' => 'n/a'], ['defect' => $this->defect->id])
            ->assertHasActionErrors(['reason']);
        $this->page()
            ->callAction('correctSeverity', ['idempotency_key' => 'sev-ui-3', 'severity' => 'routine', 'reason' => 'Correct severity correct severity correct severity'], ['defect' => $this->defect->id])
            ->assertNotified('That could not be saved');

        $this->assertSame('significant', $this->defect->fresh()->severity);
        $this->assertSame(0, ApparatusDefectEvent::query()->count());
    }
}
