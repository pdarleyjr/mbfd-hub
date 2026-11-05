<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\ApparatusInventoryCorrection as Correction;
use App\Models\ApparatusInventoryCorrectionEvent as Event;
use App\Models\ApparatusInventoryCorrectionParticipant as Participant;
use App\Models\User;
use App\Support\Fleet\InventoryCorrectionAccess;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * One equipment change request per change: a member who reports a change another member already
 * reported is added to that request (a participant) instead of opening a second one, and open
 * duplicates filed before this existed are combined into the oldest. Every request is still
 * decided once, by Logistics, through InventoryCorrectionService.
 *
 * "The same change" means: the same apparatus, the same reason and the same checklist entry
 * (compartment and item), or, for an unlisted item, the same compartment and the same name
 * ignoring case, spacing and punctuation ("K. Tool" is "K Tool"). A member who proposes
 * something different (for example another destination) still joins; the difference is
 * recorded in the thread so Logistics sees it.
 */
final class InventoryCorrectionConsolidator
{
    /** Later reports of the same change by one member that each add a thread entry. */
    public const MAX_JOINS_PER_MEMBER = 10;

    public const JOINED_TEXT = 'Another member reported the same change.';

    // ------------------------------------------------------------------ matching

    /** The key two requests share when they ask for the same change; null when it cannot be told. */
    public static function groupKey(Correction $correction): ?string
    {
        return self::key(
            (int) $correction->apparatus_id,
            (string) $correction->reason,
            $correction->compartment_key,
            $correction->item_key,
            is_array($correction->proposal) ? (string) ($correction->proposal['name'] ?? '') : '',
        );
    }

    public static function key(int $apparatusId, string $reason, ?string $compartmentKey, ?string $itemKey, string $name): ?string
    {
        if ($compartmentKey === null || $compartmentKey === '') {
            return null;
        }
        if ($itemKey !== null && $itemKey !== '') {
            return "{$apparatusId}|{$reason}|{$compartmentKey}|item:{$itemKey}";
        }
        $normalized = EquipmentIdentity::normalizeReportedName($name);

        return $normalized === '' ? null : "{$apparatusId}|{$reason}|{$compartmentKey}|new:{$normalized}";
    }

    /**
     * The oldest open request by another member for the same change, or null. A member who already
     * filed their own open request for it keeps the existing per-reporter rules (an identical report
     * merges, a changed one is a new request), so this returns null for them.
     *
     * @param  array{compartment_id: string, item_id: string|null}  $target
     */
    public function sharedReport(User $member, int $apparatusId, string $reason, array $target, string $name): ?Correction
    {
        $key = self::key($apparatusId, $reason, $target['compartment_id'], $target['item_id'], $name);
        if ($key === null) {
            return null;
        }
        $matches = Correction::query()
            ->where('apparatus_id', $apparatusId)
            ->where('reason', $reason)
            ->where('compartment_key', $target['compartment_id'])
            ->when($target['item_id'] !== null, fn ($query) => $query->where('item_key', $target['item_id']), fn ($query) => $query->whereNull('item_key'))
            ->open()
            ->orderBy('id')
            ->get()
            ->filter(static fn (Correction $open): bool => self::groupKey($open) === $key)
            ->values();
        if ($matches->contains(static fn (Correction $open): bool => InventoryCorrectionAccess::reported($member, $open))) {
            return null;
        }

        return $matches->first();
    }

    /** @param array<string, mixed> $a @param array<string, mixed> $b */
    public static function sameProposal(array $a, array $b): bool
    {
        // Typed text compares like reported names ("K. Tool" proposes the same as "K Tool");
        // compartment ids and numbers compare exactly.
        $canonical = static function (array $proposal): string {
            foreach ($proposal as $key => $value) {
                if (is_string($value) && $key !== 'to_compartment_id') {
                    $proposal[$key] = EquipmentIdentity::normalizeReportedName($value);
                }
            }
            ksort($proposal, SORT_STRING);

            return (string) json_encode($proposal, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        };

        return $canonical($a) === $canonical($b);
    }

    /**
     * Plain-language proposal, for the thread: "Moved to: Rear Cab · Quantity: 2".
     *
     * @param  array<string, mixed>  $proposal
     * @param  array<string, mixed>  $snapshot
     */
    public static function describe(array $proposal, array $snapshot): string
    {
        $labels = ['to_location_text' => 'Where it is now', 'name' => 'Name', 'manufacturer' => 'Manufacturer', 'model' => 'Model', 'description' => 'Description', 'quantity' => 'Quantity'];
        $parts = [];
        if (isset($proposal['to_compartment_id'])) {
            $parts[] = 'Moved to: '.($snapshot['to_compartment'] ?? $proposal['to_compartment_id']);
        }
        foreach ($labels as $key => $label) {
            if (isset($proposal[$key]) && $proposal[$key] !== '') {
                $parts[] = "{$label}: {$proposal[$key]}";
            }
        }

        return $parts === [] ? 'No details proposed' : implode(' · ', $parts);
    }

    // ------------------------------------------------------------------ joining

    /**
     * The request an earlier send of this same join went to (offline replay), or null.
     * Another member's id or a different payload under the same id is refused.
     */
    public function replayedJoin(User $member, string $clientSubmissionId, string $hash): ?Correction
    {
        $event = Event::query()->where('idempotency_key', strtolower($clientSubmissionId))->first();
        if ($event === null) {
            return null;
        }
        $details = is_array($event->details) ? $event->details : [];
        if ($event->event_type !== Event::JOINED
            || (int) $event->actor_user_id !== (int) $member->getKey()
            || ! hash_equals((string) ($details['payload_hash'] ?? ''), $hash)) {
            throw new InventoryCorrectionConflict('replay_conflict', 'This correction was already sent with different details. Start a new report.');
        }

        return Correction::query()->findOrFail($event->apparatus_inventory_correction_id);
    }

    /**
     * Adds the member to the open request and records what they reported. Returns null when the
     * request was decided in the meantime, so the caller files a new one instead; `recorded` is false
     * when nothing new was added to the thread (a repeat), so the caller can discard an unused photo.
     *
     * @param  array<string, mixed>  $proposal  cleaned proposal
     * @param  array<string, mixed>  $snapshot  what the member pointed at (names the destination)
     * @return array{correction: Correction, recorded: bool}|null
     */
    public function join(Correction $shared, User $member, ?int $employeeId, array $proposal, array $snapshot, ?string $note, ?string $photoPath, string $clientSubmissionId, string $hash): ?array
    {
        return DB::transaction(function () use ($shared, $member, $employeeId, $proposal, $snapshot, $note, $photoPath, $clientSubmissionId, $hash): ?array {
            $correction = Correction::query()->whereKey($shared->getKey())->lockForUpdate()->first();
            if ($correction === null || ! $correction->isOpen()) {
                return null;
            }
            $added = $this->addParticipant($correction, (int) $member->getKey(), $employeeId, Participant::SOURCE_JOINED, now());

            $differs = ! self::sameProposal($proposal, is_array($correction->proposal) ? $correction->proposal : []);
            $content = hash('sha256', json_encode([$proposal, $note], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $earlier = Event::query()
                ->where('apparatus_inventory_correction_id', $correction->getKey())
                ->where('event_type', Event::JOINED)
                ->where('actor_user_id', $member->getKey())
                ->get(['id', 'details']);
            // The same member saying the same thing again (another device, a new tap) adds nothing.
            $repeat = $photoPath === null && $earlier->contains(static fn (Event $event): bool => ($event->details['content_hash'] ?? null) === $content);
            if ($repeat || $earlier->count() >= self::MAX_JOINS_PER_MEMBER) {
                if ($added) {
                    $this->bumpLock($correction);
                }

                return ['correction' => $correction, 'recorded' => false];
            }

            Event::query()->create([
                'apparatus_inventory_correction_id' => $correction->getKey(),
                'actor_user_id' => $member->getKey(),
                'event_type' => Event::JOINED,
                'visibility' => Event::VISIBILITY_MEMBER,
                'author_context' => 'member',
                'body' => self::JOINED_TEXT,
                'details' => [
                    'note' => $note,
                    'proposal' => $proposal,
                    'proposal_differs' => $differs,
                    'summary' => self::describe($proposal, $snapshot),
                    'photo_path' => $photoPath,
                    'payload_hash' => $hash,
                    'content_hash' => $content,
                ],
                'idempotency_key' => strtolower($clientSubmissionId),
            ]);
            // A reviewer holding the page from before this member joined must reload before deciding.
            $this->bumpLock($correction);

            return ['correction' => $correction, 'recorded' => true];
        }, 3);
    }

    /** Idempotent: a member already on the request keeps their first row (a reporter stays the reporter). True when added. */
    public function addParticipant(Correction $correction, ?int $userId, ?int $employeeId, string $source, \DateTimeInterface $joinedAt): bool
    {
        if ($userId === null && $employeeId === null) {
            return false;
        }
        $exists = Participant::query()
            ->where('apparatus_inventory_correction_id', $correction->getKey())
            ->where(function ($query) use ($userId, $employeeId): void {
                if ($userId !== null) {
                    $query->orWhere('user_id', $userId);
                }
                if ($employeeId !== null) {
                    $query->orWhere('employee_id', $employeeId);
                }
            })
            ->exists();
        if ($exists) {
            return false;
        }
        Participant::query()->create([
            'apparatus_inventory_correction_id' => $correction->getKey(),
            'user_id' => $userId,
            'employee_id' => $employeeId,
            'source' => $source,
            'joined_at' => $joinedAt,
        ]);

        return true;
    }

    // ----------------------------------------------------------------- merging

    /**
     * Open duplicates by different members, grouped under the oldest request for the same change.
     * Only requests that propose exactly the same thing are combined (`duplicates`); one that proposes
     * something different (another destination, quantity or name) is listed under `different` and left
     * open, so no member is told a change they did not ask for was decided on their request. A member's
     * own second request about the same item is left alone, as on submit.
     *
     * @return list<array{survivor: Correction, duplicates: list<Correction>, different: list<Correction>}>
     */
    public function duplicateGroups(): array
    {
        return Correction::query()
            ->open()
            ->with(['apparatus:id,designation,name,unit_id'])
            ->orderBy('id')
            ->get()
            ->groupBy(static fn (Correction $correction): string => self::groupKey($correction) ?? 'single:'.$correction->getKey())
            ->map(static function (Collection $group): ?array {
                $survivor = $group->first();
                $others = $group->slice(1)->reject(static fn (Correction $other): bool => self::sameReporter($survivor, $other));
                [$same, $different] = $others->partition(static fn (Correction $other): bool => self::sameProposal((array) $other->proposal, (array) $survivor->proposal));

                return $others->isEmpty() ? null : ['survivor' => $survivor, 'duplicates' => $same->values()->all(), 'different' => $different->values()->all()];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Combines one open duplicate into the surviving request: its members join the survivor, one
     * "same change" entry (proposal only) is added to the survivor's thread, and it closes as merged
     * with a member-visible note naming the survivor. Its own conversation, note and photo stay on
     * the duplicate, where its members still read them. False, writing nothing, when either was
     * decided meanwhile or they no longer propose exactly the same change.
     */
    public function merge(int $survivorId, int $duplicateId, ?User $actor): bool
    {
        return DB::transaction(function () use ($survivorId, $duplicateId, $actor): bool {
            // Lock order: correction rows ascending id.
            $rows = Correction::query()->whereIn('id', [$survivorId, $duplicateId])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $survivor = $rows[$survivorId] ?? null;
            $duplicate = $rows[$duplicateId] ?? null;
            if ($survivorId === $duplicateId || $survivor === null || $duplicate === null || ! $survivor->isOpen() || ! $duplicate->isOpen()
                || self::groupKey($survivor) === null || self::groupKey($survivor) !== self::groupKey($duplicate)
                || ! self::sameProposal((array) $duplicate->proposal, (array) $survivor->proposal)) {
                return false;
            }

            $this->moveParticipants($duplicate, $survivor);
            $this->summarizeInto($duplicate, $survivor);
            $context = $actor === null || InventoryCorrectionAccess::canReview($actor) ? 'logistics' : 'member';
            $this->write($survivor, $actor, Event::MERGED_FROM, Event::VISIBILITY_INTERNAL, $context, "Duplicate request {$duplicate->reference()} was combined into this one. Its conversation stays on {$duplicate->reference()}.", [
                'merged_from_correction_id' => (int) $duplicate->getKey(),
            ]);
            $this->bumpLock($survivor);

            $message = "Another member already reported this change. This request was combined with {$survivor->reference()}, which will be decided once for everyone who reported it.";
            $duplicate->forceFill([
                'status' => Correction::STATUS_MERGED,
                'duplicate_of_correction_id' => $survivor->getKey(),
                'decision_reason' => $message,
                'decided_by_user_id' => $actor?->getKey(),
                'decided_at' => now(),
                'lock_version' => (int) $duplicate->lock_version + 1,
            ])->save();
            $this->write($duplicate, $actor, Event::MERGED_INTO, Event::VISIBILITY_MEMBER, $context, $message, ['merged_into_correction_id' => (int) $survivor->getKey()]);

            return true;
        }, 3);
    }

    private function moveParticipants(Correction $from, Correction $to): void
    {
        $participants = Participant::query()->where('apparatus_inventory_correction_id', $from->getKey())->orderBy('id')->get();
        // Requests filed before participants existed still carry their reporter on the row.
        $this->addParticipant($to, $from->reporter_user_id !== null ? (int) $from->reporter_user_id : null, $from->reporter_employee_id !== null ? (int) $from->reporter_employee_id : null, Participant::SOURCE_JOINED, $from->created_at ?? now());
        foreach ($participants as $participant) {
            $this->addParticipant($to, $participant->user_id !== null ? (int) $participant->user_id : null, $participant->employee_id !== null ? (int) $participant->employee_id : null, Participant::SOURCE_JOINED, $participant->joined_at ?? now());
        }
    }

    /**
     * One "same change" entry on the survivor for the duplicate's reporter: what they proposed, never
     * their note, replies, Logistics' questions or photo, which stay private to the duplicate.
     */
    private function summarizeInto(Correction $from, Correction $to): void
    {
        $submitted = Event::query()->where('apparatus_inventory_correction_id', $from->getKey())->where('event_type', Event::SUBMITTED)->orderBy('id')->first();
        $proposal = is_array($from->proposal) ? $from->proposal : [];
        $this->write($to, null, Event::JOINED, Event::VISIBILITY_MEMBER, 'member', self::JOINED_TEXT, [
            'merged_from_correction_id' => (int) $from->getKey(),
            'proposal' => $proposal,
            'proposal_differs' => false,
            'summary' => self::describe($proposal, (array) $from->target_snapshot),
        ], $submitted === null ? $from->reporter_user_id : ($submitted->actor_user_id ?? $from->reporter_user_id),
            $submitted === null ? $from->created_at : ($submitted->created_at ?? $from->created_at));
    }

    private function bumpLock(Correction $correction): void
    {
        $correction->forceFill(['lock_version' => (int) $correction->lock_version + 1])->save();
    }

    /** @param array<string, mixed>|null $details */
    private function write(Correction $correction, ?User $actor, string $type, string $visibility, string $context, ?string $body, ?array $details, mixed $actorId = null, mixed $at = null): Event
    {
        return Event::query()->create([
            'apparatus_inventory_correction_id' => $correction->getKey(),
            'actor_user_id' => $actor?->getKey() ?? $actorId,
            'event_type' => $type,
            'visibility' => $visibility,
            'author_context' => $context,
            'body' => $body,
            'details' => $details,
            ...($at !== null ? ['created_at' => $at] : []),
        ]);
    }

    private static function sameReporter(Correction $a, Correction $b): bool
    {
        return ($a->reporter_user_id !== null && (int) $a->reporter_user_id === (int) $b->reporter_user_id)
            || ($a->reporter_employee_id !== null && (int) $a->reporter_employee_id === (int) $b->reporter_employee_id);
    }
}
