<?php

declare(strict_types=1);

namespace App\Services\Personnel\Promotions;

use App\Models\PromotionalList;
use App\Models\PromotionalListEntry;
use App\Models\PromotionalListEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Every change to a promotional list goes through here.
 *
 * Current-rank rule: while a list is in effect (status "active" and not past its expiry date)
 * its eligible entries, ordered by original rank, hold current ranks 1..N with no gaps and no
 * duplicates. Entries that are not eligible, and every entry on a list that is not in effect,
 * have a null current rank. The ranks are recomputed in the same transaction as every change.
 *
 * A null actor means the console (import or the expiry scheduler). It is refused outside the
 * console so a web request can never skip authorization.
 */
final class PromotionalListService
{
    public function __construct(private readonly PromotionalListAccess $access) {}

    /** @param array<string, mixed> $attributes */
    public function createList(?User $actor, array $attributes): PromotionalList
    {
        $this->authorize($actor);
        $data = $this->validatedList($attributes);
        $this->assertListIsNew($data);

        return DB::transaction(function () use ($actor, $data): PromotionalList {
            $this->authorize($actor);
            $list = PromotionalList::query()->create([...$data, 'created_by' => $actor?->getKey()]);
            $this->event($actor, $list, 'created', metadata: ['list_type' => $data['list_type'], 'effective_date' => $data['effective_date'], 'status' => $data['status']]);

            return $list->fresh();
        }, 3);
    }

    /** @param array<string, mixed> $attributes */
    public function updateList(User $actor, PromotionalList $list, array $attributes): PromotionalList
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($actor, $list, $attributes): PromotionalList {
            $this->authorize($actor);
            $current = $this->lockedList($list);
            $data = $this->validatedList([...$current->only(['list_type', 'type_label', 'title', 'source_reference', 'notes', 'status']),
                'effective_date' => $current->effective_date->toDateString(), 'expires_on' => $current->expires_on?->toDateString(),
                ...$attributes, 'status' => $current->status]);
            $this->assertListIsNew($data, $current);
            $changes = [];
            foreach (['list_type', 'type_label', 'title', 'effective_date', 'expires_on', 'source_reference', 'notes'] as $field) {
                $before = $current->getAttribute($field);
                $before = $before instanceof \DateTimeInterface ? $before->format('Y-m-d') : $before;
                if ($before !== $data[$field]) {
                    $changes[$field] = ['from' => $before, 'to' => $data[$field]];
                }
            }
            if ($changes === []) {
                return $current;
            }
            $current->fill($data)->save();
            $this->event($actor, $current, 'list_updated', metadata: ['changes' => $changes]);
            $this->recomputeRanks($current->fresh(), $actor, 'list_updated');

            return $current->fresh();
        }, 3);
    }

    public function changeListStatus(?User $actor, PromotionalList $list, string $status, string $reason): PromotionalList
    {
        $this->authorize($actor);
        Validator::make(['status' => $status, 'reason' => trim($reason)], [
            'status' => ['required', Rule::in(array_keys(PromotionalList::statuses()))],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ], ['reason.required' => 'Enter the reason for this change.', 'reason.min' => 'Enter the reason for this change.'])->validate();

        return DB::transaction(function () use ($actor, $list, $status, $reason): PromotionalList {
            $this->authorize($actor);
            $current = $this->lockedList($list);
            if ($current->status === $status) {
                throw ValidationException::withMessages(['status' => 'This list is already '.strtolower(PromotionalList::statuses()[$status]).'.']);
            }
            if ($status === PromotionalList::STATUS_ACTIVE && $current->isPastExpiry()) {
                throw ValidationException::withMessages(['status' => 'This list is past its expiry date. Change the expiry date before making it active.']);
            }
            $from = $current->status;
            $current->forceFill(['status' => $status])->save();
            $this->event($actor, $current, 'list_status_changed', reason: trim($reason), metadata: ['from' => $from, 'to' => $status]);
            $this->recomputeRanks($current->fresh(), $actor, 'list_status_changed');

            return $current->fresh();
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $context  extra metadata for the history entry (for example the import source)
     */
    public function addEntry(?User $actor, PromotionalList $list, array $attributes, bool $recompute = true, array $context = []): PromotionalListEntry
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($actor, $list, $attributes, $recompute, $context): PromotionalListEntry {
            $this->authorize($actor);
            $current = $this->lockedList($list);
            $data = $this->validatedEntry($attributes, $current, null);
            $entry = $current->entries()->create([...$data, 'status' => PromotionalListEntry::STATUS_ELIGIBLE, 'current_rank' => null]);
            $this->event($actor, $current, 'entry_added', $entry, metadata: [...$context, 'original_rank' => $entry->original_rank, 'soi_id' => $entry->soi_id, 'employee_id' => $entry->employee_id]);
            if ($recompute) {
                $this->recomputeRanks($current->fresh(), $actor, 'entry_added');
            }

            return $entry->fresh();
        }, 3);
    }

    /** @param array<string, mixed> $attributes */
    public function updateEntry(User $actor, PromotionalListEntry $entry, array $attributes): PromotionalListEntry
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($actor, $entry, $attributes): PromotionalListEntry {
            $this->authorize($actor);
            $current = PromotionalListEntry::query()->lockForUpdate()->findOrFail($entry->getKey());
            $list = $this->lockedList($current->list_id);
            $data = $this->validatedEntry([...$current->only(['soi_id', 'last_name', 'first_name', 'original_rank', 'scores']), ...$attributes,
                'employee_id' => $current->employee_id], $list, $current);
            unset($data['employee_id']);
            $changes = [];
            foreach ($data as $field => $value) {
                if (! $this->sameValue($current->getAttribute($field), $value)) {
                    $changes[$field] = ['from' => $current->getAttribute($field), 'to' => $value];
                }
            }
            if ($changes === []) {
                return $current;
            }
            $current->fill($data)->save();
            $this->event($actor, $list, 'entry_updated', $current, metadata: ['changes' => $changes]);
            if (array_key_exists('original_rank', $changes)) {
                $this->recomputeRanks($list->fresh(), $actor, 'entry_updated');
            }

            return $current->fresh();
        }, 3);
    }

    /** Link (or, with null, unlink) an entry to a personnel record. The caller always picks the employee explicitly. */
    public function linkEmployee(?User $actor, PromotionalListEntry $entry, ?int $employeeId, array $context = []): PromotionalListEntry
    {
        $this->authorize($actor);
        Validator::make(['employee_id' => $employeeId], ['employee_id' => ['nullable', 'integer', Rule::exists('employees', 'id')]],
            ['employee_id.exists' => 'Choose a person from the personnel roster.'])->validate();

        return DB::transaction(function () use ($actor, $entry, $employeeId, $context): PromotionalListEntry {
            $this->authorize($actor);
            $current = PromotionalListEntry::query()->lockForUpdate()->findOrFail($entry->getKey());
            $list = $this->lockedList($current->list_id);
            if ($employeeId !== null && PromotionalListEntry::query()->where('list_id', $current->list_id)
                ->where('employee_id', $employeeId)->whereKeyNot($current->getKey())->exists()) {
                throw ValidationException::withMessages(['employee_id' => 'That person is already linked to another entry on this list.']);
            }
            if ($current->employee_id === $employeeId) {
                return $current;
            }
            $from = $current->employee_id;
            $current->forceFill(['employee_id' => $employeeId])->save();
            $this->event($actor, $list, $employeeId === null ? 'entry_unlinked' : 'entry_linked', $current,
                metadata: [...$context, 'from_employee_id' => $from, 'to_employee_id' => $employeeId]);

            return $current->fresh();
        }, 3);
    }

    public function changeEntryStatus(?User $actor, PromotionalListEntry $entry, string $status, string $reason): PromotionalListEntry
    {
        $this->authorize($actor);
        Validator::make(['status' => $status, 'reason' => trim($reason)], [
            'status' => ['required', Rule::in(array_keys(PromotionalListEntry::statuses()))],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ], ['reason.required' => 'Enter the reason for this change, for example "Promoted 2026-11-01".', 'reason.min' => 'Enter the reason for this change.'])->validate();

        return DB::transaction(function () use ($actor, $entry, $status, $reason): PromotionalListEntry {
            $this->authorize($actor);
            $current = PromotionalListEntry::query()->lockForUpdate()->findOrFail($entry->getKey());
            $list = $this->lockedList($current->list_id);
            if ($current->status === $status) {
                throw ValidationException::withMessages(['status' => 'This entry is already '.strtolower(PromotionalListEntry::statuses()[$status]).'.']);
            }
            $from = $current->status;
            $current->forceFill(['status' => $status, 'status_reason' => trim($reason), 'status_changed_by' => $actor?->getKey(), 'status_changed_at' => now()])->save();
            $this->event($actor, $list, 'entry_status_changed', $current, trim($reason), ['from' => $from, 'to' => $status]);
            $this->recomputeRanks($list->fresh(), $actor, 'entry_status_changed');

            return $current->fresh();
        }, 3);
    }

    /**
     * Recompute current ranks for one list and record the before and after of every change.
     * Safe to run at any time; it changes nothing when the ranks are already correct.
     *
     * @return int number of entries whose current rank changed
     */
    public function recomputeRanks(PromotionalList $list, ?User $actor, string $trigger): int
    {
        return DB::transaction(function () use ($list, $actor, $trigger): int {
            $current = PromotionalList::query()->findOrFail($list->getKey());
            $inEffect = $current->isInEffect();
            $next = 1;
            $changes = [];
            foreach ($current->entries()->orderBy('original_rank')->lockForUpdate()->get() as $entry) {
                $new = $inEffect && $entry->status === PromotionalListEntry::STATUS_ELIGIBLE ? $next++ : null;
                if ($entry->current_rank !== $new) {
                    PromotionalListEntry::query()->whereKey($entry->getKey())->update(['current_rank' => $new, 'updated_at' => now()]);
                    $changes[] = ['entry_id' => $entry->id, 'original_rank' => $entry->original_rank, 'from' => $entry->current_rank, 'to' => $new];
                }
            }
            if ($changes !== []) {
                $this->event($actor, $current, 'ranks_recomputed', metadata: ['trigger' => $trigger, 'in_effect' => $inEffect, 'changes' => $changes]);
            }

            return count($changes);
        }, 3);
    }

    /**
     * Mark active lists past their expiry date as expired and clear their current ranks.
     *
     * @return int number of lists expired
     */
    public function expireDue(): int
    {
        $expired = 0;
        $due = PromotionalList::query()->where('status', PromotionalList::STATUS_ACTIVE)
            ->whereNotNull('expires_on')->where('expires_on', '<', PromotionalList::today())->get();
        foreach ($due as $list) {
            DB::transaction(function () use ($list, &$expired): void {
                $current = $this->lockedList($list);
                if ($current->status !== PromotionalList::STATUS_ACTIVE || ! $current->isPastExpiry()) {
                    return;
                }
                $current->forceFill(['status' => PromotionalList::STATUS_EXPIRED])->save();
                $this->event(null, $current, 'list_status_changed', reason: 'Expiry date reached', metadata: ['from' => PromotionalList::STATUS_ACTIVE, 'to' => PromotionalList::STATUS_EXPIRED]);
                $this->recomputeRanks($current->fresh(), null, 'list_expired');
                $expired++;
            }, 3);
        }

        return $expired;
    }

    /** @param array<string, mixed> $metadata */
    public function recordEvent(?User $actor, PromotionalList $list, string $action, ?string $reason = null, array $metadata = []): void
    {
        $this->event($actor, $list, $action, null, $reason, $metadata);
    }

    /** @param array<string, mixed> $metadata */
    private function event(?User $actor, PromotionalList $list, string $action, ?PromotionalListEntry $entry = null, ?string $reason = null, array $metadata = []): void
    {
        PromotionalListEvent::query()->create(['list_id' => $list->getKey(), 'entry_id' => $entry?->getKey(), 'actor_user_id' => $actor?->getKey(),
            'action' => $action, 'reason' => $reason, 'metadata' => $metadata, 'created_at' => now()]);
    }

    /** Strict comparison that ignores array key order (jsonb does not keep it). */
    private function sameValue(mixed $before, mixed $after): bool
    {
        if (is_array($before) && is_array($after)) {
            ksort($before);
            ksort($after);
        }

        return $before === $after;
    }

    private function authorize(?User $actor): void
    {
        if ($actor === null) {
            abort_unless(app()->runningInConsole(), 403);

            return;
        }
        $this->access->authorizeManage($actor);
    }

    private function lockedList(PromotionalList|string $list): PromotionalList
    {
        return PromotionalList::query()->lockForUpdate()->findOrFail($list instanceof PromotionalList ? $list->getKey() : $list);
    }

    /**
     * Normalize and validate list attributes; used by every writer and by the import dry run.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function validatedList(array $attributes): array
    {
        $attributes = array_map(fn ($value) => is_string($value) ? trim($value) : $value, $attributes);
        $attributes += ['status' => PromotionalList::STATUS_DRAFT, 'type_label' => null, 'expires_on' => null, 'notes' => null];
        foreach (['type_label', 'expires_on', 'notes'] as $optional) {
            $attributes[$optional] = ($attributes[$optional] ?? '') === '' ? null : $attributes[$optional];
        }
        $data = Validator::make($attributes, [
            'list_type' => ['required', Rule::in(array_keys(PromotionalList::types()))],
            'type_label' => ['nullable', 'string', 'max:80', Rule::requiredIf(($attributes['list_type'] ?? null) === PromotionalList::TYPE_OTHER)],
            'title' => ['required', 'string', 'max:200'],
            'effective_date' => ['required', 'date_format:Y-m-d'],
            'expires_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_date'],
            'status' => ['required', Rule::in(array_keys(PromotionalList::statuses()))],
            'source_reference' => ['required', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ], [
            'type_label.required' => 'Name the kind of list when the type is Other.',
            'expires_on.after_or_equal' => 'The expiry date cannot be before the effective date.',
            'source_reference.required' => 'Describe the source document for this list.',
        ])->validate();
        if ($data['list_type'] !== PromotionalList::TYPE_OTHER) {
            $data['type_label'] = null;
        }

        return $data;
    }

    /** @param array<string, mixed> $data */
    private function assertListIsNew(array $data, ?PromotionalList $ignore = null): void
    {
        $duplicate = PromotionalList::query()->where('list_type', $data['list_type'])->whereDate('effective_date', $data['effective_date'])
            ->when($data['list_type'] === PromotionalList::TYPE_OTHER, fn ($query) => $query->where('type_label', $data['type_label']))
            ->when($ignore !== null, fn ($query) => $query->whereKeyNot($ignore->getKey()))->exists();
        if ($duplicate) {
            throw ValidationException::withMessages(['effective_date' => 'A list of this type with this effective date already exists.']);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function validatedEntry(array $attributes, PromotionalList $list, ?PromotionalListEntry $existing): array
    {
        $attributes = array_map(fn ($value) => is_string($value) ? trim($value) : $value, array_diff_key($attributes, ['scores' => 1]))
            + ['scores' => $attributes['scores'] ?? []];
        $attributes['soi_id'] = ($attributes['soi_id'] ?? '') === '' ? null : $attributes['soi_id'];
        $attributes['employee_id'] = ($attributes['employee_id'] ?? '') === '' ? null : $attributes['employee_id'];
        $scoreKeys = array_keys((array) config('promotional_lists.score_columns'));
        $unique = fn (string $column) => Rule::unique('promotional_list_entries', $column)->where('list_id', $list->getKey())->ignore($existing?->getKey());
        $validated = Validator::make($attributes, [
            'original_rank' => ['required', 'integer', 'min:1', 'max:9999', $unique('original_rank')],
            'soi_id' => ['nullable', 'string', 'max:40', $unique('soi_id')],
            'last_name' => ['required', 'string', 'max:120'],
            'first_name' => ['required', 'string', 'max:120'],
            'employee_id' => ['nullable', 'integer', Rule::exists('employees', 'id'), $unique('employee_id')],
            'scores' => ['array:'.implode(',', $scoreKeys)],
            'scores.*' => ['nullable', 'string', 'max:20'],
        ], [
            'original_rank.unique' => 'Another entry on this list already has that original rank.',
            'soi_id.unique' => 'Another entry on this list already has that SOI ID.',
            'employee_id.unique' => 'That person is already linked to another entry on this list.',
            'scores.array' => 'Use only the score columns printed on the list.',
        ])->validate();
        $scores = [];
        foreach ($scoreKeys as $key) {
            $scores[$key] = trim((string) ($validated['scores'][$key] ?? ''));
        }
        $validated['scores'] = $scores;

        return $validated;
    }
}
