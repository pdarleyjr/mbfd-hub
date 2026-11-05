<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Filament\Employee\Pages\MyInventoryCorrectionView;
use App\Filament\Resources\InventoryCorrectionResource;
use App\Models\ApparatusInventoryCorrection;
use App\Models\ApparatusInventoryCorrectionEvent as Event;
use App\Models\ApparatusInventoryCorrectionParticipant as Participant;
use App\Models\User;
use App\Notifications\Inspections\InspectionEventNotification;
use App\Notifications\Inspections\InspectionNotificationKind as Kind;
use App\Services\Inventory\EquipmentIdentity;
use App\Services\Inventory\InventoryCorrectionConflict;
use App\Services\Inventory\InventoryCorrectionConsolidator;
use App\Services\Inventory\InventoryCorrectionService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Inventory\Concerns\BuildsInventoryCorrectionFixtures;
use Tests\TestCase;

/**
 * The owner's rule: several members reporting the same equipment change make ONE request that
 * every reporting member is part of, decided once, never several of the same.
 */
final class InventoryCorrectionSharedRequestTest extends TestCase
{
    use BuildsInventoryCorrectionFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInventoryFleet();
    }

    /** @return array<string, mixed> */
    private function passportsMove(string $to): array
    {
        $passports = $this->item('E1', 'Passports');

        return ['reason' => 'moved', 'compartment_id' => $passports['compartment_id'], 'item_id' => $passports['item_id'], 'proposal' => ['to_compartment_id' => $to]];
    }

    /** @return array<string, mixed> */
    private function newItem(string $name): array
    {
        return ['reason' => 'new_item', 'compartment_id' => 'rear_cab', 'proposal' => ['name' => $name]];
    }

    private function otherMember(string $code = 'INV-002'): User
    {
        return $this->actingAsCanonicalFixture($code, 'Other Member '.$code, 'Firefighter');
    }

    public function test_a_second_member_joins_the_open_request_instead_of_filing_another(): void
    {
        $first = $this->report('E1', $this->passportsMove('rear_cab'))->assertCreated()->assertJsonPath('joined_existing', false);

        $other = $this->otherMember();
        $id = (string) Str::uuid();
        $payload = [...$this->passportsMove('rear_cab'), 'client_submission_id' => $id, 'note' => 'Saw it there too.'];
        $this->report('E1', $payload)
            ->assertOk()
            ->assertJsonPath('id', $first->json('id'))
            ->assertJsonPath('reference', $first->json('reference'))
            ->assertJsonPath('status', 'submitted')
            ->assertJsonPath('duplicate', false)
            ->assertJsonPath('joined_existing', true);

        $this->assertSame(1, ApparatusInventoryCorrection::query()->count(), 'one request, not two');
        $correction = ApparatusInventoryCorrection::query()->findOrFail($first->json('id'));
        $this->assertSame((int) $this->member->id, (int) $correction->reporter_user_id, 'the first reporter stays the reporter');
        $this->assertSame(
            [[(int) $this->member->id, Participant::SOURCE_REPORTER], [(int) $other->id, Participant::SOURCE_JOINED]],
            $correction->participants()->get()->map(fn (Participant $participant): array => [(int) $participant->user_id, $participant->source])->all(),
        );
        $joined = $correction->events()->where('event_type', Event::JOINED)->sole();
        $this->assertSame(Event::VISIBILITY_MEMBER, $joined->visibility);
        $this->assertSame('Another member reported the same change.', $joined->body);
        $this->assertSame('Saw it there too.', $joined->details['note']);
        $this->assertFalse($joined->details['proposal_differs']);

        // An offline replay of the same send is idempotent; the same id with other details is refused.
        $this->report('E1', $payload)->assertOk()->assertJsonPath('duplicate', true)->assertJsonPath('joined_existing', true)->assertJsonPath('id', $first->json('id'));
        $this->report('E1', [...$payload, 'note' => 'Changed.'])->assertStatus(409)->assertJsonPath('code', 'INVENTORY_CORRECTION_REPLAY_CONFLICT');
        // The same member saying the same thing again from another device adds nothing.
        $this->report('E1', [...$this->passportsMove('rear_cab'), 'note' => 'Saw it there too.'])->assertOk()->assertJsonPath('joined_existing', true);
        $this->assertSame(1, $correction->events()->where('event_type', Event::JOINED)->count());
        $this->assertSame(1, ApparatusInventoryCorrection::query()->count());
    }

    public function test_new_item_names_match_ignoring_case_spacing_and_punctuation(): void
    {
        $first = $this->report('E1', $this->newItem('K Tool'))->assertCreated();

        $second = $this->otherMember('INV-002');
        $this->report('E1', [...$this->newItem('  k. tool '), 'photo' => self::PNG])->assertOk()->assertJsonPath('joined_existing', true)->assertJsonPath('id', $first->json('id'));
        $third = $this->otherMember('INV-003');
        $this->report('E1', $this->newItem('K-TOOL'))->assertOk()->assertJsonPath('joined_existing', true)->assertJsonPath('id', $first->json('id'));
        $this->assertSame(1, ApparatusInventoryCorrection::query()->count());
        $this->assertSame(3, Participant::query()->where('apparatus_inventory_correction_id', $first->json('id'))->count());

        // A different item, or the same name in another compartment, is a different request.
        $this->report('E1', $this->newItem('Halligan'))->assertCreated();
        $this->report('E1', [...$this->newItem('K Tool'), 'compartment_id' => 'front_cab'])->assertCreated();
        $this->assertSame(3, ApparatusInventoryCorrection::query()->count());

        $this->assertFalse(Event::query()->where('event_type', Event::JOINED)->get()->contains(fn (Event $event): bool => $event->details['proposal_differs']), 'a spelling variant is the same proposal');

        // Only the member who took a photo and Logistics can open it; other members on the request cannot.
        $withPhoto = Event::query()->where('event_type', Event::JOINED)->get()->first(fn (Event $event): bool => filled($event->details['photo_path'] ?? null));
        $this->assertNotNull($withPhoto);
        $photo = route('inventory-corrections.photo', ['correction' => $first->json('id'), 'event' => $withPhoto->id]);
        $this->assertTrue(Participant::query()->where('apparatus_inventory_correction_id', $first->json('id'))->where('user_id', $third->id)->exists());
        $this->get($photo)->assertNotFound();
        $this->actingAsCanonicalUser($this->member);
        $this->get($photo)->assertNotFound();
        $this->actingAsCanonicalUser($second);
        $this->get($photo)->assertOk();
        $this->actingAs($this->logistics());
        $this->get($photo)->assertOk();
    }

    public function test_a_joined_member_never_sees_the_reporters_photo(): void
    {
        $passports = $this->item('E1', 'Passports');
        $replaced = ['reason' => 'replaced', 'compartment_id' => $passports['compartment_id'], 'item_id' => $passports['item_id'], 'proposal' => ['model' => 'X2']];
        $first = $this->report('E1', [...$replaced, 'photo' => self::PNG])->assertCreated();
        $this->get(route('inventory-corrections.photo', $first->json('id')))->assertOk();

        $this->otherMember();
        $this->report('E1', $replaced)->assertOk()->assertJsonPath('joined_existing', true);
        $this->get(route('inventory-corrections.photo', $first->json('id')))->assertNotFound();
    }

    public function test_reported_names_match_conservatively(): void
    {
        $this->assertSame('k tool', EquipmentIdentity::normalizeReportedName(' K. Tool '));
        $this->assertSame('k tool', EquipmentIdentity::normalizeReportedName('K-Tool'));
        $this->assertSame('k tool', EquipmentIdentity::normalizeReportedName('k_tool'));
        $this->assertNotSame(EquipmentIdentity::normalizeReportedName('Ladder 14'), EquipmentIdentity::normalizeReportedName("Ladder 14'"));
        $this->assertSame('name:passports', EquipmentIdentity::forItem(['name' => 'Passports']), 'stored equipment keys are unchanged');

        $first = $this->report('E1', $this->newItem('Ladder 14'))->assertCreated();
        $this->otherMember();
        $this->report('E1', $this->newItem("Ladder 14'"))->assertCreated()->assertJsonPath('joined_existing', false);
        $this->report('E1', $this->newItem('ladder  14'))->assertOk()->assertJsonPath('id', $first->json('id'));
    }

    public function test_a_different_proposal_still_joins_and_is_recorded_for_logistics(): void
    {
        $first = $this->report('E1', $this->passportsMove('rear_cab'))->assertCreated();

        $this->otherMember();
        $this->report('E1', $this->passportsMove('comp_a1'))->assertOk()->assertJsonPath('joined_existing', true)->assertJsonPath('id', $first->json('id'));

        $correction = ApparatusInventoryCorrection::query()->findOrFail($first->json('id'));
        $this->assertSame(1, ApparatusInventoryCorrection::query()->count(), 'no second request even though the destination differs');
        $this->assertSame(['to_compartment_id' => 'rear_cab'], $correction->proposal, 'the request itself is unchanged');
        $joined = $correction->events()->where('event_type', Event::JOINED)->sole();
        $this->assertTrue($joined->details['proposal_differs']);
        $this->assertSame(['to_compartment_id' => 'comp_a1'], $joined->details['proposal']);
        $this->assertStringStartsWith('Moved to: ', $joined->details['summary']);
        $this->assertSame(1, (int) $correction->lock_version, 'a join makes a page opened before it stale');

        $logistics = $this->logistics();
        $this->actingAs($logistics);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->get(InventoryCorrectionResource::getUrl('view', ['record' => $correction]))
            ->assertOk()
            ->assertSee('Another member reported the same change')
            ->assertSee('Proposed something different')
            ->assertSee('Also reported by')
            ->assertSee('Other Member INV-002');

        try {
            $this->service()->reject($correction->id, $logistics, null, null, 0);
            $this->fail('A decision from a page opened before the member joined must be refused.');
        } catch (InventoryCorrectionConflict) {
            $this->assertTrue($correction->fresh()->isOpen());
        }

        // The member who proposed something else is told plainly what was approved for the item.
        $this->approve($correction->fresh(), $logistics, ['op' => 'modify', 'fields' => ['compartment_id' => 'rear_cab']]);
        $other = User::query()->whereKey($joined->actor_user_id)->firstOrFail();
        Notification::assertSentTo($other, InspectionEventNotification::class, fn (InspectionEventNotification $notification): bool => $notification->kind === Kind::INVENTORY_CORRECTION_UPDATE
            && str_contains($notification->body, 'that you also reported')
            && str_contains($notification->body, 'What was approved: Compartment: Rear Cab'));
        Notification::assertSentTo($this->member, InspectionEventNotification::class, fn (InspectionEventNotification $notification): bool => $notification->kind === Kind::INVENTORY_CORRECTION_UPDATE
            && str_contains($notification->body, 'Logistics approved your correction'));
    }

    public function test_merged_requests_refuse_replies_and_reply_limits_are_per_member(): void
    {
        $first = $this->reported('E1', $this->passportsMove('rear_cab'));
        $other = $this->otherMember();
        $duplicate = $this->legacyDuplicate($first, $other, ['to_compartment_id' => 'rear_cab'], null);
        $this->assertTrue(app(InventoryCorrectionConsolidator::class)->merge($first->id, $duplicate->id, null));

        try {
            $this->service()->postMessage($duplicate->id, $other, 'Hello?', Event::VISIBILITY_MEMBER);
            $this->fail('A merged request must refuse replies.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString($first->reference(), $exception->getMessage());
        }

        for ($i = 0; $i < InventoryCorrectionService::MAX_MEMBER_REPLIES; $i++) {
            $this->service()->postMessage($first->id, $this->member, "Update {$i}", Event::VISIBILITY_MEMBER);
        }
        // The reporter used up their allowance; the other member on the request still has theirs.
        $this->service()->postMessage($first->id, $other, 'Still there on my shift.', Event::VISIBILITY_MEMBER);
        $this->expectException(ValidationException::class);
        $this->service()->postMessage($first->id, $this->member, 'One more', Event::VISIBILITY_MEMBER);
    }

    public function test_a_member_on_the_request_can_view_reply_and_is_told_the_decision(): void
    {
        $first = $this->report('E1', $this->passportsMove('rear_cab'))->assertCreated();
        $other = $this->otherMember();
        $this->report('E1', $this->passportsMove('rear_cab'))->assertOk()->assertJsonPath('joined_existing', true);
        $correction = ApparatusInventoryCorrection::query()->findOrFail($first->json('id'));

        // The participant sees it as theirs on the checklist and on their own page.
        $this->getJson("/api/public/apparatuses/{$this->units['E1']->id}/checklist")->assertOk()
            ->assertJsonPath('pending_corrections.0.id', $correction->id)
            ->assertJsonPath('pending_corrections.0.mine', true);
        $this->get(MyInventoryCorrectionView::getUrl(['correction' => $correction->id], panel: 'employee'))
            ->assertOk()
            ->assertSee('What was reported')
            ->assertSee('Reported by another member')
            ->assertSee('You reported the same change and were added to this request.')
            ->assertDontSee('Inventory Reporter');
        $this->service()->postMessage($correction->id, $other, 'It is on the top shelf.', Event::VISIBILITY_MEMBER);

        // A member who is not on the request still cannot see it.
        $this->otherMember('INV-009');
        $this->get(MyInventoryCorrectionView::getUrl(['correction' => $correction->id], panel: 'employee'))->assertNotFound();

        $logistics = $this->logistics();
        $this->service()->reject($correction->id, $logistics, 'It belongs in the front cab.');

        foreach ([$this->member, $other] as $recipient) {
            Notification::assertSentTo($recipient, InspectionEventNotification::class, fn (InspectionEventNotification $notification): bool => $notification->kind === Kind::INVENTORY_CORRECTION_UPDATE
                && str_contains($notification->url, '/my-inventory-corrections/'.$correction->id));
        }
        Notification::assertSentToTimes($other, InspectionEventNotification::class, 1);
    }

    public function test_per_reporter_rules_are_unchanged(): void
    {
        $first = $this->report('E1', $this->passportsMove('rear_cab'))->assertCreated();
        $this->otherMember();
        $this->report('E1', $this->passportsMove('rear_cab'))->assertOk()->assertJsonPath('joined_existing', true);

        // The original reporter's identical resend still merges into their own request.
        $this->actingAsCanonicalUser($this->member);
        $this->report('E1', $this->passportsMove('rear_cab'))->assertOk()->assertJsonPath('duplicate', true)->assertJsonPath('joined_existing', false)->assertJsonPath('id', $first->json('id'));
        // A replay of their original send is idempotent.
        $correction = ApparatusInventoryCorrection::query()->findOrFail($first->json('id'));
        $this->report('E1', [...$this->passportsMove('rear_cab'), 'client_submission_id' => $correction->client_request_id])
            ->assertOk()->assertJsonPath('duplicate', true)->assertJsonPath('id', $first->json('id'));
        // Changing their own mind is still a new request for Logistics.
        $this->report('E1', $this->passportsMove('comp_a1'))->assertCreated()->assertJsonPath('joined_existing', false);
        $this->assertSame(2, ApparatusInventoryCorrection::query()->open()->count());
    }

    public function test_the_consolidate_command_dry_runs_then_combines_open_duplicates_into_the_oldest(): void
    {
        $moved = $this->reported('E1', $this->passportsMove('rear_cab'));
        $kTool = $this->reported('E1', $this->newItem('K Tool'));
        // The reporter changing their own mind is left alone, as on submit.
        $changedMind = $this->reported('E1', $this->passportsMove('comp_a1'));
        $other = $this->otherMember();
        // Duplicates filed by another member before submit joined them.
        $movedDuplicate = $this->legacyDuplicate($moved, $other, ['to_compartment_id' => 'rear_cab'], 'Moved it myself.');
        $kToolDuplicate = $this->legacyDuplicate($kTool, $other, ['name' => 'K. Tool'], null);
        $movedDuplicate->forceFill(['photo_path' => 'inventory-corrections/private.png'])->save();
        Event::query()->create([
            'apparatus_inventory_correction_id' => $movedDuplicate->id, 'actor_user_id' => $other->id, 'event_type' => Event::MEMBER_REPLIED,
            'visibility' => Event::VISIBILITY_MEMBER, 'author_context' => 'member', 'body' => 'Any update?',
        ]);
        // A third member proposed a different destination: never combined.
        $third = $this->otherMember('INV-003');
        $differentDuplicate = $this->legacyDuplicate($moved, $third, ['to_compartment_id' => 'comp_a1'], null);
        $logistics = $this->logistics();
        $lockBefore = (int) $moved->fresh()->lock_version;

        $this->artisan('inventory-corrections:consolidate-duplicates')
            ->expectsOutputToContain('different proposal, not merged')
            ->expectsOutputToContain('Dry run: nothing was changed')
            ->assertSuccessful();
        $this->assertSame(6, ApparatusInventoryCorrection::query()->open()->count(), 'a dry run writes nothing');
        $this->assertSame(0, Event::query()->whereIn('event_type', [Event::MERGED_INTO, Event::MERGED_FROM])->count());

        $this->artisan('inventory-corrections:consolidate-duplicates', ['--apply' => true, '--actor' => (string) $logistics->id])
            ->expectsOutputToContain('Combined 2 duplicate request(s)')
            ->assertSuccessful();
        $this->assertTrue($differentDuplicate->fresh()->isOpen(), 'a different proposal is never merged');
        $this->assertFalse(app(InventoryCorrectionConsolidator::class)->merge($moved->id, $differentDuplicate->id, $logistics), 'the service refuses it too');
        $this->assertGreaterThan($lockBefore, (int) $moved->fresh()->lock_version, 'a merge makes an open review page stale');

        foreach ([[$movedDuplicate, $moved], [$kToolDuplicate, $kTool]] as [$duplicate, $survivor]) {
            $duplicate->refresh();
            $this->assertSame(ApparatusInventoryCorrection::STATUS_MERGED, $duplicate->status);
            $this->assertSame((int) $survivor->id, (int) $duplicate->duplicate_of_correction_id);
            $note = $duplicate->events()->where('event_type', Event::MERGED_INTO)->sole();
            $this->assertSame(Event::VISIBILITY_MEMBER, $note->visibility);
            $this->assertStringContainsString($survivor->reference(), (string) $note->body);
            $this->assertTrue($survivor->fresh()->isOpen());
            $this->assertTrue(Participant::query()->where('apparatus_inventory_correction_id', $survivor->id)->where('user_id', $other->id)->exists());
        }
        $this->assertTrue($changedMind->fresh()->isOpen(), 'the reporter\'s own changed request is not merged');
        // Only a proposal summary reaches the survivor: no note, reply or photo of the duplicate.
        $copied = $moved->events()->where('event_type', Event::JOINED)->sole();
        $this->assertSame((int) $other->id, (int) $copied->actor_user_id);
        $this->assertFalse($copied->details['proposal_differs']);
        $this->assertArrayNotHasKey('note', $copied->details);
        $this->assertArrayNotHasKey('photo_path', $copied->details);
        $this->assertSame(0, $moved->events()->where('event_type', Event::MEMBER_REPLIED)->count());
        $this->assertSame(0, $moved->events()->where('body', 'Moved it myself.')->count());

        // The reporter of the survivor never reads the other member's conversation.
        $this->actingAsCanonicalUser($this->member);
        $this->get(MyInventoryCorrectionView::getUrl(['correction' => $moved->id], panel: 'employee'))->assertOk()->assertDontSee('Any update?')->assertDontSee('Moved it myself.');
        // The other member follows the survivor and still reads their own conversation on the closed one.
        $this->actingAsCanonicalUser($other);
        $this->get(MyInventoryCorrectionView::getUrl(['correction' => $moved->id], panel: 'employee'))->assertOk()->assertSee('You reported the same change');
        $this->get(MyInventoryCorrectionView::getUrl(['correction' => $movedDuplicate->id], panel: 'employee'))->assertOk()->assertSee($moved->reference())->assertSee('Any update?');

        $this->artisan('inventory-corrections:consolidate-duplicates', ['--apply' => true])
            ->expectsOutputToContain('Combined 0 duplicate request(s)')
            ->assertSuccessful();
        $this->assertTrue($differentDuplicate->fresh()->isOpen());
    }

    public function test_the_participants_backfill_covers_every_reporter(): void
    {
        $correction = $this->reported('E1', $this->passportsMove('rear_cab'));
        $this->assertSame(1, $correction->participants()->where('source', Participant::SOURCE_REPORTER)->where('user_id', $this->member->id)->count());
    }

    /**
     * A second request for the same change by another member, as filed before submit joined them.
     *
     * @param  array<string, mixed>  $proposal
     */
    private function legacyDuplicate(ApparatusInventoryCorrection $original, User $reporter, array $proposal, ?string $note): ApparatusInventoryCorrection
    {
        $duplicate = $original->replicate(['lock_version']);
        $duplicate->forceFill([
            'client_request_id' => (string) Str::uuid(),
            'reporter_user_id' => $reporter->id,
            'reporter_employee_id' => $reporter->employee_profile_id,
            'proposal' => $proposal,
            'member_note' => $note,
            'inspection_draft_id' => null,
            'apparatus_inspection_id' => null,
        ])->save();
        Event::query()->create([
            'apparatus_inventory_correction_id' => $duplicate->id, 'actor_user_id' => $reporter->id, 'event_type' => Event::SUBMITTED,
            'visibility' => Event::VISIBILITY_MEMBER, 'author_context' => 'member', 'body' => $note,
        ]);

        return $duplicate;
    }
}
