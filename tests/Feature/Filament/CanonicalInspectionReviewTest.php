<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\ApparatusInspectionExceptionResource\Pages\ListInspectionExceptions;
use App\Filament\Resources\InspectionResource\Pages\ViewInspection;
use App\Models\Apparatus;
use App\Models\ApparatusInspection;
use App\Models\ApparatusInspectionException;
use App\Models\ApparatusInspectionReviewEvent;
use App\Models\ApparatusIssueMessage;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Feature\Defects\Concerns\BuildsContinuityFixtures;
use Tests\TestCase;

/** Approve, reject, clarify and the meter rollback decisions on the canonical inspection page. */
final class CanonicalInspectionReviewTest extends TestCase
{
    use BuildsContinuityFixtures;
    use RefreshDatabase;

    private Apparatus $rig;

    private User $member;

    private ApparatusInspection $held;

    private ApparatusInspectionException $rollback;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->rig = $this->rig();
        $this->member = $this->member('Riley Reporter');
        $this->held = $this->heldInspection();
        $this->rollback = $this->hold($this->held, ['field' => 'miles', 'reason' => 'rollback', 'submitted_value' => 900, 'baseline_value' => 950, 'authoritative_value' => 1000]);
        $this->actAs($this->admin());
    }

    // ------------------------------------------------------------- approve / reject

    public function test_approve_is_disabled_with_an_explanation_while_a_meter_hold_is_open(): void
    {
        $this->page()
            ->assertActionVisible('approveInspection')
            ->assertActionDisabled('approveInspection')
            ->assertSee('Decide the meter reading first');

        $this->assertSame('held_for_review', $this->held->fresh()->processing_status);
        $this->assertSame('open', $this->rollback->fresh()->status);
    }

    public function test_approve_clears_member_flag_and_damage_holds_and_records_the_note(): void
    {
        $flagged = $this->heldInspection('2026-10-20 09:00:00');
        $flag = $this->hold($flagged, ['field' => 'member_review_flag', 'reason' => 'member_flagged']);
        $damage = $this->hold($flagged, ['field' => 'significant_damage', 'reason' => 'new_significant_damage']);

        $this->pageFor($flagged)
            ->assertActionEnabled('approveInspection')
            ->callAction('approveInspection', ['note' => 'Reviewed the flag with the officer.'])
            ->assertHasNoActionErrors();

        $flagged->refresh();
        $this->assertSame('approved', $flagged->review_status);
        $this->assertSame('accepted', $flagged->processing_status);
        $this->assertSame(['resolved', 'resolved'], [$flag->fresh()->status, $damage->fresh()->status]);
        $this->assertDatabaseHas('apparatus_inspection_review_events', [
            'apparatus_inspection_id' => $flagged->id, 'status' => 'approved', 'internal_note' => 'Reviewed the flag with the officer.',
        ]);
    }

    public function test_out_of_service_happens_only_when_toggled_and_the_reviewer_may_update_the_unit(): void
    {
        // A reviewer without update rights is never offered the choice, and a forced value is ignored.
        $first = $this->heldInspection('2026-10-20 09:00:00');
        $this->hold($first, ['field' => 'member_review_flag', 'reason' => 'member_flagged']);
        $this->pageFor($first)->mountAction('approveInspection')->assertDontSee('Also mark unit Out of Service');
        $this->pageFor($first)->callAction('approveInspection', ['mark_out_of_service' => true]);
        $this->assertSame('approved', $first->fresh()->review_status);
        $this->assertSame('In Service', $this->rig->fresh()->status);

        // With update rights the toggle is offered, defaults off, and off means no change.
        $this->actAs($this->admin(true));
        $second = $this->heldInspection('2026-10-20 10:00:00');
        $this->hold($second, ['field' => 'member_review_flag', 'reason' => 'member_flagged']);
        $this->pageFor($second)->mountAction('approveInspection')
            ->assertSee('Also mark unit Out of Service')
            ->assertActionDataSet(['mark_out_of_service' => false]);
        $this->pageFor($second)->callAction('approveInspection', ['mark_out_of_service' => false]);
        $this->assertSame('approved', $second->fresh()->review_status);
        $this->assertSame('In Service', $this->rig->fresh()->status);

        $third = $this->heldInspection('2026-10-20 11:00:00');
        $this->hold($third, ['field' => 'member_review_flag', 'reason' => 'member_flagged']);
        $this->pageFor($third)->callAction('approveInspection', ['mark_out_of_service' => true]);
        $this->assertSame('Out of Service', $this->rig->fresh()->status);
    }

    public function test_reject_dismisses_every_hold_and_keeps_the_evidence(): void
    {
        $this->page()
            ->callAction('rejectInspection', ['reason' => 'Duplicate of the earlier checkout.'])
            ->assertHasNoActionErrors();

        $this->held->refresh();
        $this->assertSame('rejected', $this->held->review_status);
        $this->assertSame('rejected', $this->held->processing_status);
        $this->assertSame('dismissed', $this->rollback->fresh()->status);
        $this->assertSame(1000, (int) $this->rig->fresh()->current_miles, 'A rejected reading is never applied.');
    }

    public function test_request_clarification_keeps_the_hold_and_messages_the_member(): void
    {
        $clientId = (string) Str::uuid();

        $page = $this->page();
        foreach ([1, 2] as $attempt) {
            $page->callAction('requestClarification', ['client_message_id' => $clientId, 'message' => 'Was the odometer replaced?']);
        }

        $this->assertSame('held_for_review', $this->held->fresh()->processing_status);
        $this->assertSame(1, ApparatusInspectionReviewEvent::query()->where('status', 'clarification_requested')->count());
        $message = ApparatusIssueMessage::query()->sole();
        $this->assertSame('member', $message->visibility);
        $this->assertSame('Was the odometer replaced?', $message->body);
    }

    // --------------------------------------------------------- meter rollback hold

    public function test_the_hold_card_offers_the_four_decisions_and_shows_the_current_reading(): void
    {
        $html = $this->page()->assertSee('Meter reading hold: Odometer miles')->assertSee('1,000 mi')->html();

        preg_match_all('/data-hold-action="([A-Za-z]+)"/', $html, $matches);
        $this->assertSame(['acceptMeterReading', 'enterVerifiedReading', 'requestMeterClarification', 'keepCurrentReading'], $matches[1]);
    }

    public function test_accepting_the_lower_reading_needs_the_reset_confirmation_and_lowers_the_meter(): void
    {
        $this->page()
            ->mountAction('acceptMeterReading', ['exception' => $this->rollback->id])
            ->assertActionDataSet(['expected_current_value' => 1000.0]);

        $this->page()
            ->callAction('acceptMeterReading', ['expected_current_value' => 1000, 'reason' => 'Dash cluster replaced.', 'confirm_meter_reset' => false], ['exception' => $this->rollback->id])
            ->assertHasActionErrors(['confirm_meter_reset']);
        $this->assertSame(1000, (int) $this->rig->fresh()->current_miles);

        $this->page()
            ->callAction('acceptMeterReading', ['expected_current_value' => 1000, 'reason' => 'Dash cluster replaced.', 'confirm_meter_reset' => true], ['exception' => $this->rollback->id])
            ->assertHasNoActionErrors();

        $this->assertSame(900, (int) $this->rig->fresh()->current_miles);
        $this->assertSame('resolved', $this->rollback->fresh()->status);
        $this->assertSame('accepted', $this->held->fresh()->processing_status, 'Deciding the last hold releases the inspection.');
        $event = ApparatusInspectionReviewEvent::query()->where('metadata->exception_id', $this->rollback->id)->latest('id')->first();
        $this->assertTrue((bool) ($event->metadata['meter_reset'] ?? false));
    }

    public function test_a_verified_reading_that_is_higher_needs_no_confirmation_but_a_lower_one_does(): void
    {
        $this->page()
            ->callAction('enterVerifiedReading', ['expected_current_value' => 1000, 'value' => 950, 'reason' => 'Read the dash.'], ['exception' => $this->rollback->id]);
        $this->assertSame(1000, (int) $this->rig->fresh()->current_miles, 'A lower verified reading without the confirmation is refused.');
        $this->assertSame('open', $this->rollback->fresh()->status);

        $this->page()
            ->callAction('enterVerifiedReading', ['expected_current_value' => 1000, 'value' => 1010, 'reason' => 'Read the dash.'], ['exception' => $this->rollback->id])
            ->assertHasNoActionErrors();
        $this->assertSame(1010, (int) $this->rig->fresh()->current_miles);
        $this->assertSame('resolved', $this->rollback->fresh()->status);
    }

    public function test_asking_the_member_to_clarify_keeps_the_hold_open(): void
    {
        $this->page()
            ->callAction('requestMeterClarification', ['expected_current_value' => 1000, 'reason' => 'Please confirm what the odometer showed.'], ['exception' => $this->rollback->id])
            ->assertHasNoActionErrors();

        $this->assertSame('revision_requested', $this->rollback->fresh()->status);
        $this->assertSame('held_for_review', $this->held->fresh()->processing_status);
        $this->assertSame('Please confirm what the odometer showed.', ApparatusIssueMessage::query()->sole()->body);
    }

    public function test_keeping_the_current_reading_rejects_the_reported_value(): void
    {
        $this->page()
            ->callAction('keepCurrentReading', ['expected_current_value' => 1000, 'reason' => 'Reported value was a typo.'], ['exception' => $this->rollback->id])
            ->assertHasNoActionErrors();

        $this->assertSame('dismissed', $this->rollback->fresh()->status);
        $this->assertSame(1000, (int) $this->rig->fresh()->current_miles);
        $this->assertSame('accepted', $this->held->fresh()->processing_status);
    }

    public function test_a_stale_expected_reading_is_refused_with_the_services_message(): void
    {
        $this->rig->update(['current_miles' => 1200]);

        $this->page()
            ->callAction('keepCurrentReading', ['expected_current_value' => 1000, 'reason' => 'Decided on an old screen.'], ['exception' => $this->rollback->id])
            ->assertNotified('That decision was not saved');

        $this->assertSame('open', $this->rollback->fresh()->status);
        $this->assertSame('held_for_review', $this->held->fresh()->processing_status);
        $this->assertSame(1200, (int) $this->rig->fresh()->current_miles);
    }

    public function test_an_exception_id_from_another_inspection_is_rejected(): void
    {
        $other = $this->heldInspection('2026-10-19 08:00:00');
        $foreign = $this->hold($other, ['field' => 'miles', 'reason' => 'rollback', 'submitted_value' => 800, 'authoritative_value' => 1000]);

        try {
            $this->page()->callAction('keepCurrentReading', ['expected_current_value' => 1000, 'reason' => 'Forged.'], ['exception' => $foreign->id]);
            $this->fail('A forged exception id must not be accepted.');
        } catch (NotFoundHttpException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame('open', $foreign->fresh()->status);
    }

    public function test_a_viewer_who_cannot_review_sees_no_decisions_and_cannot_run_them(): void
    {
        $this->actAs($this->viewOnly());

        $page = $this->page();
        $this->assertStringNotContainsString('data-hold-action', $page->html());
        $page->assertActionHidden('approveInspection')->assertActionHidden('rejectInspection')->assertActionHidden('requestClarification')
            ->mountAction('keepCurrentReading', ['exception' => $this->rollback->id])
            ->assertActionNotMounted('keepCurrentReading');

        $this->assertSame('open', $this->rollback->fresh()->status);
    }

    public function test_the_exceptions_page_accepts_a_lower_reading_with_the_reset_confirmation(): void
    {
        Livewire::test(ListInspectionExceptions::class)->call('loadTable')
            ->callTableAction('reconcile', $this->rollback, data: [
                'action' => 'accept_submitted', 'reason' => 'Meter replaced.', 'confirm_meter_reset' => true, 'expected_current_value' => 1000,
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame(900, (int) $this->rig->fresh()->current_miles);
        $this->assertSame('resolved', $this->rollback->fresh()->status);
    }

    // ------------------------------------------------------------------ helpers

    private function heldInspection(string $at = '2026-10-21 09:00:00'): ApparatusInspection
    {
        $inspection = $this->inspectionBy($this->rig, $this->member, $at);
        $inspection->update(['processing_status' => 'held_for_review', 'review_status' => 'pending_review']);

        return $inspection->refresh();
    }

    /** @param array<string, mixed> $attributes */
    private function hold(ApparatusInspection $inspection, array $attributes): ApparatusInspectionException
    {
        return ApparatusInspectionException::query()->create([
            'apparatus_inspection_id' => $inspection->id,
            'apparatus_id' => $this->rig->id,
            'status' => 'open',
            'blocks_acceptance' => true,
            ...$attributes,
        ]);
    }

    private function page(): Testable
    {
        return $this->pageFor($this->held);
    }

    private function pageFor(ApparatusInspection $inspection): Testable
    {
        return Livewire::test(ViewInspection::class, ['record' => $inspection->getRouteKey()]);
    }

    private function admin(bool $mayUpdateApparatus = false): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $user = $this->member('Admin '.Str::random(4));
        $user->assignRole(Role::findOrCreate('logistics_admin', 'web'));
        $user->givePermissionTo(array_map(
            fn (string $name): Permission => Permission::findOrCreate($name, 'web'),
            ['view_any_apparatus', 'view_apparatus', 'view_any_inspection', 'view_inspection'],
        ));
        $update = Permission::findOrCreate('update_apparatus', 'web');
        if ($mayUpdateApparatus) {
            $user->givePermissionTo($update);
        }

        return $user->fresh();
    }

    private function viewOnly(): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $role = Role::findOrCreate('view_only_inspections', 'web');
        $role->givePermissionTo(array_map(
            fn (string $name): Permission => Permission::findOrCreate($name, 'web'),
            ['view_any_apparatus', 'view_apparatus', 'view_any_inspection', 'view_inspection'],
        ));
        $user = $this->member('View Only');
        $user->assignRole($role);

        return $user;
    }

    private function actAs(User $user): void
    {
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->withoutVite();
    }
}
