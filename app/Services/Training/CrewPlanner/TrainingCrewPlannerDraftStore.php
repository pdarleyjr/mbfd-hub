<?php

declare(strict_types=1);

namespace App\Services\Training\CrewPlanner;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Actor-owned proposal state only. No training or personnel model is ever saved. */
final class TrainingCrewPlannerDraftStore
{
    public const TTL_SECONDS = 14400;

    public function create(User $actor): array
    {
        $draft = [
            'id' => (string) Str::uuid(), 'version' => 1,
            'expires_at' => now()->addSeconds(self::TTL_SECONDS)->timestamp,
            'course_id' => null, 'module_id' => null, 'metadata' => [], 'staff' => [],
            'slots' => [], 'selections' => [], 'removed' => [],
        ];
        $this->put($actor, $draft);

        return $draft;
    }

    public function get(User $actor, string $id): array
    {
        abort_unless(Str::isUuid($id), 404);
        $encrypted = Cache::get($this->key($actor, $id));
        abort_unless(is_string($encrypted), 410, 'This proposal expired. Upload the daily report to start a new proposal.');
        $draft = json_decode(Crypt::decryptString($encrypted), true, flags: JSON_THROW_ON_ERROR);
        abort_unless(is_array($draft) && $draft['expires_at'] > now()->timestamp, 410);

        return $draft;
    }

    public function update(User $actor, string $id, int $expectedVersion, callable $change): array
    {
        return Cache::lock($this->key($actor, $id).':lock', 15)->block(3, function () use ($actor, $id, $expectedVersion, $change): array {
            $current = $this->get($actor, $id);
            $this->checkVersion($current, $expectedVersion);
            $next = $this->sanitize($change($current));
            $next['id'] = $current['id'];
            $next['expires_at'] = $current['expires_at'];
            $next['version'] = $current['version'] + 1;
            $this->put($actor, $next);

            return $next;
        });
    }

    public function discard(User $actor, string $id, int $expectedVersion): void
    {
        Cache::lock($this->key($actor, $id).':lock', 15)->block(3, function () use ($actor, $id, $expectedVersion): void {
            $this->checkVersion($this->get($actor, $id), $expectedVersion);
            Cache::forget($this->key($actor, $id));
        });
    }

    private function checkVersion(array $draft, int $expectedVersion): void
    {
        if ($draft['version'] !== $expectedVersion) {
            throw new HttpException(409, 'This proposal changed in another tab. Reload the proposal before editing; your saved list is safe.');
        }
    }

    private function put(User $actor, array $draft): void
    {
        Cache::put($this->key($actor, $draft['id']), Crypt::encryptString(json_encode($draft, JSON_THROW_ON_ERROR)), max(1, $draft['expires_at'] - now()->timestamp));
    }

    private function key(User $actor, string $id): string
    {
        abort_unless($actor->getKey() !== null, 403);

        return 'training-crew-planner:'.hash('sha256', $actor->getKey().'|'.$id);
    }

    private function sanitize(array $draft): array
    {
        $draft = array_intersect_key($draft, array_flip(['course_id', 'module_id', 'metadata', 'staff', 'slots', 'selections', 'removed']));
        $draft['metadata'] = array_intersect_key($draft['metadata'] ?? [], array_flip(['schedule_date', 'shift', 'printed_at', 'page_count', 'source_sha256', 'warnings']));
        $draft['staff'] = array_map(function (array $row): array {
            $row = array_intersect_key($row, array_flip(['raw_employee_id', 'raw_name', 'working_intervals', 'needs_review', 'review_intervals', 'global_review_reasons', 'observed_unit_context']));
            $row['working_intervals'] = array_map(fn (array $interval): array => array_intersect_key($interval, array_flip(['start_at', 'end_at'])), $row['working_intervals'] ?? []);
            $row['review_intervals'] = array_map(fn (array $interval): array => array_intersect_key($interval, array_flip(['start_at', 'end_at', 'reason'])), $row['review_intervals'] ?? []);
            $row['observed_unit_context'] = array_map(fn (array $context): array => array_intersect_key($context, array_flip(['station', 'apparatus', 'seat_rank', 'start_at', 'end_at', 'source_page'])), $row['observed_unit_context'] ?? []);

            return $row;
        }, $draft['staff'] ?? []);
        foreach (['selections', 'removed'] as $key) {
            $draft[$key] = array_map(fn (array $selection): array => array_intersect_key($selection, array_flip(['slot_id'])), $draft[$key] ?? []);
        }
        $draft['slots'] = array_map(fn (array $slot): array => array_intersect_key($slot, array_flip(['id', 'label', 'start_at', 'end_at'])), $draft['slots'] ?? []);
        abort_if(count($draft['staff']) > 2000 || count($draft['selections']) > 2000 || count($draft['slots']) > 12, 422);
        abort_if(strlen(json_encode($draft, JSON_THROW_ON_ERROR)) > 2000000, 422);

        return $draft;
    }
}
