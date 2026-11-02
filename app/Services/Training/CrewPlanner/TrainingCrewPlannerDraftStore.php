<?php

declare(strict_types=1);

namespace App\Services\Training\CrewPlanner;

use App\Models\Training\TrainingCrewPlanDraft;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use JsonException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Actor-owned proposal state only. No training or personnel model is ever saved. A proposal lives in the
 * database, encrypted, for a week after its last edit, so a refresh, an app update or a cache restart
 * cannot lose a day's planning. Roster data is never copied in: it is read fresh for each check.
 */
final class TrainingCrewPlannerDraftStore
{
    public const TTL_SECONDS = 604800;

    public function create(User $actor, ?string $scheduleDate = null): array
    {
        abort_unless($actor->getKey() !== null, 403);
        $draft = [
            'id' => (string) Str::uuid(), 'version' => 1,
            'expires_at' => now()->addSeconds(self::TTL_SECONDS)->timestamp, 'saved_at' => now()->timestamp,
            'course_id' => null, 'module_id' => null, 'class_title' => '',
            'metadata' => ['schedule_date' => $scheduleDate, 'acknowledged_snapshot_id' => null],
            'slots' => [], 'selections' => [], 'removed' => [],
        ];
        TrainingCrewPlanDraft::query()->create([
            'id' => $draft['id'], 'user_id' => $actor->getKey(), 'version' => 1,
            'payload' => $this->seal($draft), 'expires_at' => now()->addSeconds(self::TTL_SECONDS),
        ]);

        return $draft;
    }

    /** The member's most recent unexpired proposal, so coming back to the page picks up where they left off. */
    public function latest(User $actor): ?array
    {
        $row = TrainingCrewPlanDraft::query()->where('user_id', $actor->getKey())->where('expires_at', '>', now())
            ->orderByDesc('updated_at')->first();
        if ($row === null) {
            return null;
        }
        try {
            return $this->open($row);
        } catch (HttpException) {
            return null; // Unreadable, and now removed: the member simply starts a new plan.
        }
    }

    public function get(User $actor, string $id): array
    {
        return $this->open($this->row($actor, $id));
    }

    public function update(User $actor, string $id, int $expectedVersion, callable $change): array
    {
        return DB::transaction(function () use ($actor, $id, $expectedVersion, $change): array {
            $row = $this->row($actor, $id, true);
            $current = $this->open($row);
            $this->checkVersion($current, $expectedVersion);
            $next = $this->sanitize($change($current));
            $next['id'] = $current['id'];
            $next['expires_at'] = now()->addSeconds(self::TTL_SECONDS)->timestamp;
            $next['saved_at'] = now()->timestamp;
            $next['version'] = $current['version'] + 1;
            $row->forceFill(['version' => $next['version'], 'payload' => $this->seal($next), 'expires_at' => now()->addSeconds(self::TTL_SECONDS)])->save();

            return $next;
        });
    }

    public function discard(User $actor, string $id, int $expectedVersion): void
    {
        DB::transaction(function () use ($actor, $id, $expectedVersion): void {
            $row = $this->row($actor, $id, true);
            $this->checkVersion($this->open($row), $expectedVersion);
            $row->delete();
        });
    }

    /** Another member's id behaves exactly like one that never existed. */
    private function row(User $actor, string $id, bool $lock = false): TrainingCrewPlanDraft
    {
        abort_unless($actor->getKey() !== null, 403);
        abort_unless(Str::isUuid($id), 404);
        $query = TrainingCrewPlanDraft::query()->where('id', $id)->where('user_id', $actor->getKey());
        $row = ($lock ? $query->lockForUpdate() : $query)->first();
        abort_unless($row instanceof TrainingCrewPlanDraft && $row->expires_at->isFuture(), 410, 'This proposal expired. Start a new one; nothing was sent or recorded.');

        return $row;
    }

    /** A proposal that can no longer be read (a rotated app key, a damaged row) must not lock its owner out of the page. */
    private function open(TrainingCrewPlanDraft $row): array
    {
        try {
            return json_decode(Crypt::decryptString((string) $row->payload), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException $failure) {
            Log::warning('training_crew_plan.draft_unreadable', ['draft_id' => $row->getKey(), 'exception' => $failure::class]);
            $row->delete();
            abort(410, 'This proposal could not be read. Start a new one; nothing was sent or recorded.');
        }
    }

    private function seal(array $draft): string
    {
        return Crypt::encryptString(json_encode($draft, JSON_THROW_ON_ERROR));
    }

    private function checkVersion(array $draft, int $expectedVersion): void
    {
        if ($draft['version'] !== $expectedVersion) {
            throw new HttpException(409, 'This proposal changed in another tab. Reload the proposal before editing; your saved list is safe.');
        }
    }

    private function sanitize(array $draft): array
    {
        $draft = array_intersect_key($draft, array_flip(['course_id', 'module_id', 'class_title', 'metadata', 'slots', 'selections', 'removed']));
        $draft['class_title'] = mb_substr(trim((string) ($draft['class_title'] ?? '')), 0, 160);
        $draft['metadata'] = array_intersect_key($draft['metadata'] ?? [], array_flip(['schedule_date', 'acknowledged_snapshot_id']));
        foreach (['selections', 'removed'] as $key) {
            $draft[$key] = array_map(fn (array $selection): array => array_intersect_key($selection, array_flip(['slot_id', 'exception'])), $draft[$key] ?? []);
        }
        $draft['slots'] = array_map(fn (array $slot): array => array_intersect_key($slot, array_flip(['id', 'label', 'start_at', 'end_at', 'station_id', 'venue', 'location'])), $draft['slots'] ?? []);
        abort_if(count($draft['selections']) > 2000 || count($draft['slots']) > 12, 422);
        abort_if(strlen(json_encode($draft, JSON_THROW_ON_ERROR)) > 2000000, 422);

        return $draft;
    }
}
