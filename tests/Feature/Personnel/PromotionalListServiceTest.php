<?php

declare(strict_types=1);

namespace Tests\Feature\Personnel;

use App\Models\Employee;
use App\Models\PromotionalList;
use App\Models\PromotionalListEntry;
use App\Models\PromotionalListEvent;
use App\Models\User;
use App\Services\Personnel\Promotions\PromotionalListService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Filament\StationAdminFixtures;
use Tests\TestCase;

/** Current-rank rule, status changes, expiry, linking and history. Fake names only. */
final class PromotionalListServiceTest extends TestCase
{
    use RefreshDatabase;
    use StationAdminFixtures;

    private PromotionalListService $service;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpStationAdmin();
        $this->service = app(PromotionalListService::class);
        $this->actor = $this->actingAsAdminWith(['personnel.directory.view', 'personnel.promotions.manage']);
    }

    public function test_the_first_printed_rank_may_be_missing_and_current_ranks_still_start_at_one(): void
    {
        $list = $this->activeList();
        $this->entries($list, [2, 3, 4, 5, 6]);

        self::assertSame([2 => 1, 3 => 2, 4 => 3, 5 => 4, 6 => 5], $this->currentRanks($list));
    }

    public function test_changing_an_entry_status_shifts_everyone_below_without_gaps_duplicates_or_zeros(): void
    {
        $list = $this->activeList();
        $entries = $this->entries($list, [2, 3, 4, 5, 6]);

        $this->service->changeEntryStatus($this->actor, $entries[3], 'promoted', 'Promoted 2026-11-01');

        self::assertSame([2 => 1, 3 => null, 4 => 2, 5 => 3, 6 => 4], $this->currentRanks($list));
        $ranks = array_filter($this->currentRanks($list), fn ($rank): bool => $rank !== null);
        self::assertSame(array_values(array_unique($ranks)), array_values($ranks), 'No duplicate current ranks.');
        self::assertSame([], array_filter($ranks, fn (int $rank): bool => $rank < 1), 'No zero or negative ranks.');
        $entry = $entries[3]->fresh();
        self::assertSame('Promoted 2026-11-01', $entry->status_reason);
        self::assertSame($this->actor->id, $entry->status_changed_by);
        self::assertNotNull($entry->status_changed_at);
    }

    public function test_returning_an_entry_to_eligible_restores_its_place_by_original_rank(): void
    {
        $list = $this->activeList();
        $entries = $this->entries($list, [2, 3, 4]);
        $this->service->changeEntryStatus($this->actor, $entries[2], 'declined', 'Declined in writing');
        self::assertSame([2 => null, 3 => 1, 4 => 2], $this->currentRanks($list));

        $this->service->changeEntryStatus($this->actor, $entries[2], 'eligible', 'Decline withdrawn');

        self::assertSame([2 => 1, 3 => 2, 4 => 3], $this->currentRanks($list));
    }

    public function test_status_changes_need_a_reason_and_a_real_change(): void
    {
        $entries = $this->entries($this->activeList(), [2]);

        try {
            $this->service->changeEntryStatus($this->actor, $entries[2], 'promoted', '  ');
            self::fail('A blank reason must be refused.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('reason', $exception->errors());
        }
        $this->expectException(ValidationException::class);
        $this->service->changeEntryStatus($this->actor, $entries[2], 'eligible', 'No change');
    }

    public function test_an_expired_list_holds_no_current_ranks_and_reactivating_restores_them(): void
    {
        $list = $this->activeList();
        $this->entries($list, [2, 3]);

        $this->service->changeListStatus($this->actor, $list, 'expired', 'Reached its expiry date');
        self::assertSame([2 => null, 3 => null], $this->currentRanks($list));
        self::assertSame('Expired', $list->fresh()->statusLabel());

        $this->service->changeListStatus($this->actor, $list, 'active', 'Expiry extended by the chief');
        self::assertSame([2 => 1, 3 => 2], $this->currentRanks($list));
    }

    public function test_draft_and_superseded_lists_do_not_rank_anyone(): void
    {
        $list = $this->service->createList($this->actor, $this->listAttributes(['status' => 'draft']));
        $this->entries($list, [2, 3]);
        self::assertSame([2 => null, 3 => null], $this->currentRanks($list));

        $this->service->changeListStatus($this->actor, $list, 'active', 'Effective today');
        self::assertSame([2 => 1, 3 => 2], $this->currentRanks($list));

        $this->service->changeListStatus($this->actor, $list, 'superseded', 'Replaced by the 2027 list');
        self::assertSame([2 => null, 3 => null], $this->currentRanks($list));
    }

    public function test_a_list_past_its_expiry_date_never_ranks_and_the_scheduler_marks_it_expired(): void
    {
        $this->travelTo(Carbon::create(2027, 3, 30, 12, 0, 0, 'America/New_York'));
        $list = $this->service->createList($this->actor, $this->listAttributes(['status' => 'active', 'expires_on' => '2027-03-31']));
        $this->entries($list, [2, 3]);
        self::assertSame([2 => 1, 3 => 2], $this->currentRanks($list));
        self::assertTrue($list->fresh()->isInEffect());

        $this->travelTo(Carbon::create(2027, 4, 1, 0, 30, 0, 'America/New_York'));
        self::assertFalse($list->fresh()->isInEffect(), 'The expiry date has passed even though the status is still active.');
        self::assertSame('Expired', $list->fresh()->statusLabel());

        self::assertSame(1, $this->service->expireDue());
        self::assertSame('expired', $list->fresh()->status);
        self::assertSame([2 => null, 3 => null], $this->currentRanks($list));
        self::assertSame(0, $this->service->expireDue(), 'Running the scheduler again changes nothing.');
        $event = PromotionalListEvent::query()->where('action', 'list_status_changed')->latest('id')->firstOrFail();
        self::assertNull($event->actor_user_id);
        self::assertSame('Expiry date reached', $event->reason);
    }

    public function test_an_expired_list_cannot_be_made_active_until_its_expiry_date_changes(): void
    {
        $this->travelTo(Carbon::create(2027, 4, 5, 12, 0, 0, 'America/New_York'));
        $list = $this->service->createList($this->actor, $this->listAttributes(['status' => 'expired', 'expires_on' => '2027-03-31']));

        $this->expectException(ValidationException::class);
        $this->service->changeListStatus($this->actor, $list, 'active', 'Mistake');
    }

    public function test_every_change_is_written_to_an_append_only_history_with_before_and_after_ranks(): void
    {
        $list = $this->activeList();
        $entries = $this->entries($list, [2, 3, 4]);
        $this->service->changeEntryStatus($this->actor, $entries[2], 'removed', 'Resigned');
        $this->service->linkEmployee($this->actor, $entries[3], $this->employee('Jordan Brennan')->id);

        $actions = PromotionalListEvent::query()->where('list_id', $list->id)->orderBy('id')->pluck('action')->all();
        foreach (['created', 'entry_added', 'ranks_recomputed', 'entry_status_changed', 'entry_linked'] as $expected) {
            self::assertContains($expected, $actions);
        }
        $status = PromotionalListEvent::query()->where('action', 'entry_status_changed')->firstOrFail();
        self::assertSame($entries[2]->id, $status->entry_id);
        self::assertSame('Resigned', $status->reason);
        self::assertSame(['from' => 'eligible', 'to' => 'removed'], $status->metadata);
        $recompute = PromotionalListEvent::query()->where('action', 'ranks_recomputed')->latest('id')->firstOrFail();
        self::assertSame('entry_status_changed', $recompute->metadata['trigger']);
        self::assertEqualsCanonicalizing([[2, 1, null], [3, 2, 1], [4, 3, 2]],
            array_map(fn (array $change): array => [$change['original_rank'], $change['from'], $change['to']], $recompute->metadata['changes']));

        $this->expectException(\LogicException::class);
        $status->update(['reason' => 'Edited later']);
    }

    public function test_history_rows_cannot_be_deleted(): void
    {
        $list = $this->activeList();
        $event = PromotionalListEvent::query()->where('list_id', $list->id)->firstOrFail();

        $this->expectException(\LogicException::class);
        $event->delete();
    }

    public function test_original_rank_and_soi_id_are_unique_per_list_and_scores_are_kept_as_printed(): void
    {
        $list = $this->activeList();
        $this->service->addEntry($this->actor, $list, $this->entryAttributes(2, ['soi_id' => 'L31', 'scores' => ['oral' => 'p', 'education' => '-', 'veteran' => '', 'total' => '108.00']]));

        $entry = PromotionalListEntry::query()->firstOrFail();
        self::assertSame('p', $entry->scores['oral']);
        self::assertSame('-', $entry->scores['education']);
        self::assertSame('', $entry->scores['veteran']);
        self::assertSame('108.00', $entry->scores['total']);

        foreach ([$this->entryAttributes(2), $this->entryAttributes(3, ['soi_id' => 'L31'])] as $duplicate) {
            try {
                $this->service->addEntry($this->actor, $list, $duplicate);
                self::fail('Duplicates must be refused.');
            } catch (ValidationException) {
                self::assertSame(1, $list->entries()->count());
            }
        }
        // The same numbers are fine on another list.
        $other = $this->service->createList($this->actor, $this->listAttributes(['list_type' => 'captain', 'title' => 'Captain Results 2026']));
        $this->service->addEntry($this->actor, $other, $this->entryAttributes(2, ['soi_id' => 'L31']));
        self::assertSame(1, $other->entries()->count());
    }

    public function test_correcting_an_original_rank_recomputes_current_ranks(): void
    {
        $list = $this->activeList();
        $entries = $this->entries($list, [2, 3, 4]);

        $this->service->updateEntry($this->actor, $entries[4], ['original_rank' => 1]);

        self::assertSame([1 => 1, 2 => 2, 3 => 3], $this->currentRanks($list));
    }

    public function test_linking_an_employee_is_explicit_unique_per_list_and_reversible(): void
    {
        $list = $this->activeList();
        $entries = $this->entries($list, [2, 3]);
        $employee = $this->employee('Casey Fontaine');

        $this->service->linkEmployee($this->actor, $entries[2], $employee->id);
        self::assertSame($employee->id, $entries[2]->fresh()->employee_id);

        try {
            $this->service->linkEmployee($this->actor, $entries[3], $employee->id);
            self::fail('One person cannot hold two entries on the same list.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('employee_id', $exception->errors());
        }
        $this->service->linkEmployee($this->actor, $entries[2], null);
        self::assertNull($entries[2]->fresh()->employee_id);
        self::assertContains('entry_unlinked', PromotionalListEvent::query()->pluck('action')->all());
    }

    public function test_list_type_other_needs_a_label_and_a_list_is_unique_by_type_and_effective_date(): void
    {
        try {
            $this->service->createList($this->actor, $this->listAttributes(['list_type' => 'other', 'title' => 'Special list']));
            self::fail('Other needs a label.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('type_label', $exception->errors());
        }
        $other = $this->service->createList($this->actor, $this->listAttributes(['list_type' => 'other', 'type_label' => 'Training Officer', 'title' => 'Training Officer 2026']));
        self::assertSame('Training Officer', $other->typeName());

        $this->activeList();
        $this->expectException(ValidationException::class);
        $this->service->createList($this->actor, $this->listAttributes());
    }

    /** @return array<string, mixed> */
    private function listAttributes(array $overrides = []): array
    {
        return [...[
            'list_type' => 'lieutenant', 'title' => 'Fixture Lieutenant Results 2026', 'effective_date' => '2026-04-01',
            'expires_on' => null, 'status' => 'active', 'source_reference' => 'Fixture results document', 'notes' => null,
        ], ...$overrides];
    }

    /** @return array<string, mixed> */
    private function entryAttributes(int $rank, array $overrides = []): array
    {
        return [...['original_rank' => $rank, 'soi_id' => 'L'.$rank, 'last_name' => 'Fixture'.$rank, 'first_name' => 'Pat', 'scores' => ['total' => '90.00']], ...$overrides];
    }

    private function activeList(): PromotionalList
    {
        return $this->service->createList($this->actor, $this->listAttributes());
    }

    /**
     * @param  list<int>  $ranks
     * @return array<int, PromotionalListEntry> keyed by original rank
     */
    private function entries(PromotionalList $list, array $ranks): array
    {
        $entries = [];
        foreach ($ranks as $rank) {
            $entries[$rank] = $this->service->addEntry($this->actor, $list, $this->entryAttributes($rank));
        }

        return $entries;
    }

    /** @return array<int, int|null> original rank => current rank */
    private function currentRanks(PromotionalList $list): array
    {
        return $list->entries()->orderBy('original_rank')->pluck('current_rank', 'original_rank')->map(fn ($rank): ?int => $rank === null ? null : (int) $rank)->all();
    }

    private function employee(string $name): Employee
    {
        static $sequence = 0;
        $sequence++;

        return Employee::query()->create(['employee_id' => sprintf('P%04d', $sequence), 'name' => $name, 'rank' => 'Firefighter', 'roster_status' => 'active']);
    }
}
