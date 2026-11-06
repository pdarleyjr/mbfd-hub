<?php

declare(strict_types=1);

namespace App\Services\Captain5;

use App\Models\Apparatus;
use App\Models\ApparatusInspection;
use App\Models\ApparatusInspectionReviewEvent;
use App\Models\User;
use App\Services\Security\Captain5AccessDecision;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Captain 5 "Clear" for a Rescue daily checkout he has handled: it leaves the Rescue daily checkouts list and shows
 * under Cleared, where Restore brings it back. Only the three captain5_clear columns change; the submitted
 * observations, payload hash, client_submission_id and review/processing state are never touched, so the Daily
 * Checkout offline queue and idempotency are unaffected. Each change is an append-only row in
 * apparatus_inspection_review_events (shown on the admin inspection timeline by its metadata title).
 *
 * Clearing what is already cleared, or restoring what is not, changes nothing and writes nothing: a retry is safe.
 */
final class Captain5InspectionClearing
{
    public const EVENT_CLEARED = 'captain5_cleared';

    public const EVENT_RESTORED = 'captain5_restored';

    public const NOTE_MAX = 500;

    /** The Cleared list looks back this far; the history window Captain 5 already uses for closed issues. */
    public const ARCHIVE_DAYS = 90;

    public const ARCHIVE_LIMIT = 200;

    public function __construct(
        private readonly Captain5IssueScope $scope,
        private readonly Captain5IssuePresenter $presenter,
    ) {}

    /** @throws AuthorizationException */
    public function clear(User $actor, Captain5AccessDecision $decision, int $inspectionId, ?string $note): ApparatusInspection
    {
        return $this->change($actor, $decision, $inspectionId, true, $note);
    }

    /** @throws AuthorizationException */
    public function restore(User $actor, Captain5AccessDecision $decision, int $inspectionId): ApparatusInspection
    {
        return $this->change($actor, $decision, $inspectionId, false, null);
    }

    /** @return Collection<int, ApparatusInspection> cleared Rescue checkouts, newest clear first */
    public function cleared(): Collection
    {
        $rescueIds = array_values(array_filter(
            $this->scope->candidateApparatusIds(),
            fn (int $id): bool => in_array($this->scope->checklistType($id), Captain5IssueScope::RESCUE_TYPES, true),
        ));
        if ($rescueIds === []) {
            return collect();
        }

        return ApparatusInspection::query()->operational()
            ->whereIn('apparatus_id', $rescueIds)
            ->whereNotNull('captain5_cleared_at')
            ->where('captain5_cleared_at', '>=', now()->subDays(self::ARCHIVE_DAYS))
            ->with('captain5ClearedBy:id,name,display_name')
            ->orderByDesc('captain5_cleared_at')->orderByDesc('id')
            ->limit(self::ARCHIVE_LIMIT)
            ->get(['id', 'apparatus_id', 'processed_apparatus_id', 'operator_name', 'rank', 'created_at', 'completed_at', 'captain5_cleared_at', 'captain5_cleared_by_user_id', 'captain5_clear_note']);
    }

    /**
     * @param  Collection<int, ApparatusInspection>  $inspections
     * @return list<array<string, mixed>>
     */
    public function presentCleared(Collection $inspections): array
    {
        $units = Apparatus::query()->whereIn('id', $inspections->pluck('apparatus_id')->unique()->all())->get()->keyBy('id');

        return $inspections->map(fn (ApparatusInspection $inspection): array => [
            'inspection_id' => (int) $inspection->getKey(),
            'apparatus_id' => (int) $inspection->apparatus_id,
            'unit' => $this->presenter->unit($units->get($inspection->apparatus_id)),
            'submitted_at' => $this->presenter->iso($inspection->completed_at ?? $inspection->created_at),
            'submitted_by' => trim($inspection->rank.' '.$inspection->operator_name) ?: null,
        ] + $this->clearFields($inspection))->values()->all();
    }

    /** @return array{cleared_at: string|null, cleared_by: string|null, clear_note: string|null} */
    public function clearFields(?ApparatusInspection $inspection): array
    {
        $by = $inspection?->captain5_cleared_by_user_id !== null ? $inspection->captain5ClearedBy : null;

        return [
            'cleared_at' => $this->presenter->iso($inspection?->captain5_cleared_at),
            'cleared_by' => $by instanceof User ? $this->presenter->name($by) : null,
            'clear_note' => $inspection?->captain5_clear_note,
        ];
    }

    private function change(User $actor, Captain5AccessDecision $decision, int $inspectionId, bool $clear, ?string $note): ApparatusInspection
    {
        if (! $decision->canAct) {
            throw new AuthorizationException('You can view this workspace but not clear checkouts.');
        }
        $note = $note === null ? null : trim($note);
        Validator::make(['note' => $note], ['note' => ['nullable', 'string', 'max:'.self::NOTE_MAX]], [
            'note.max' => 'Keep the note to '.self::NOTE_MAX.' characters or fewer.',
        ])->validate();
        $note = $note === '' ? null : $note;

        return DB::transaction(function () use ($actor, $inspectionId, $clear, $note): ApparatusInspection {
            $inspection = ApparatusInspection::query()->lockForUpdate()->find($inspectionId);
            // Same answer as GET /inspections/{id} for anything outside Captain 5's Rescue list: not available.
            if ($inspection === null || $inspection->test_excluded_at !== null || $this->scope->inspectionKind($inspection) !== Captain5IssueScope::KIND_RESCUE) {
                throw new AuthorizationException('Not available.');
            }
            if (($inspection->captain5_cleared_at !== null) === $clear) {
                return $inspection;
            }

            $previousNote = $inspection->captain5_clear_note;
            $inspection->forceFill([
                'captain5_cleared_at' => $clear ? now() : null,
                'captain5_cleared_by_user_id' => $clear ? $actor->getKey() : null,
                'captain5_clear_note' => $clear ? $note : null,
            ])->save();

            $name = $this->presenter->name($actor);
            ApparatusInspectionReviewEvent::query()->create([
                'apparatus_inspection_id' => $inspection->getKey(),
                'previous_status' => (string) $inspection->review_status,
                'status' => $clear ? self::EVENT_CLEARED : self::EVENT_RESTORED,
                // Kept out of internal_note on purpose: that column feeds the Daily Checkout notes feed.
                'internal_note' => null,
                'changed_by_user_id' => $actor->getKey(),
                'metadata' => array_filter([
                    'action' => $clear ? self::EVENT_CLEARED : self::EVENT_RESTORED,
                    'title' => $clear
                        ? 'Cleared by Captain 5'.($note !== null ? ': '.$note : '')
                        : 'Restored to the Captain 5 list',
                    'actor_name' => $name,
                    'note' => $clear ? $note : null,
                    'previous_note' => $clear ? null : $previousNote,
                ], static fn (mixed $value): bool => $value !== null),
            ]);

            return $inspection->refresh();
        }, 3);
    }
}
