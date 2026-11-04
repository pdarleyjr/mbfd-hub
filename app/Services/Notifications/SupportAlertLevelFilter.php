<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\ApparatusDefect;
use App\Models\User;
use App\Services\SupportTriage\TriageProjector;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Drops an OPTIONAL staff triage alert for the people who turned that record's priority level off.
 *
 * The level always comes from the one triage projection (TriageProjector::levelFor); this class never ranks anything
 * itself. It runs when the alert is dispatched or fanned out, so the level is read at the moment the alert is decided.
 * It only removes recipients: it never adds one, never changes a channel and never sends anything.
 *
 * A record with no projection row yet is computed on demand where the projector can do it (defects), otherwise it
 * reads as MEDIUM, so a missing row can never make an alert vanish for everybody. Nothing here listens to projection
 * updates: re-triaging a record never notifies anyone.
 */
final class SupportAlertLevelFilter
{
    /** @var list<string> */
    public const SUBJECT_TYPES = ['apparatus_defect', 'station_request', 'station_supply_request'];

    public function __construct(private readonly SupportAlertLevelPreferences $preferences) {}

    /**
     * @param  Collection<int, User>  $recipients
     * @return Collection<int, User>
     */
    public function filter(Collection $recipients, string $subjectType, int $subjectId): Collection
    {
        if ($recipients->isEmpty()) {
            return $recipients;
        }

        return $this->preferences->whoWant($recipients, $this->levelFor($subjectType, $subjectId));
    }

    /** low, medium or high. Never null: an unknown level is medium. */
    public function levelFor(string $subjectType, int $subjectId): string
    {
        $projector = $this->projector();
        if ($projector === null || ! in_array($subjectType, self::SUBJECT_TYPES, true)) {
            return SupportAlertLevelPreferences::MEDIUM;
        }

        $level = $this->read($projector, $subjectType, $subjectId);
        if ($level === null) {
            $this->refresh($projector, $subjectType, $subjectId);
            $level = $this->read($projector, $subjectType, $subjectId);
        }

        return SupportAlertLevelPreferences::normalize($level);
    }

    private function projector(): ?object
    {
        if (! class_exists(TriageProjector::class) && ! app()->bound(TriageProjector::class)) {
            return null;
        }

        try {
            $projector = app(TriageProjector::class);
        } catch (Throwable $exception) {
            Log::warning('Triage projector unavailable; alert level read as medium', ['exception' => $exception->getMessage()]);

            return null;
        }

        return $projector;
    }

    private function read(object $projector, string $subjectType, int $subjectId): ?string
    {
        try {
            $level = $projector->levelFor($subjectType, $subjectId);
        } catch (Throwable $exception) {
            Log::warning('Triage level could not be read; alert level read as medium', [
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'exception' => $exception->getMessage(),
            ]);

            return null;
        }

        return is_string($level) && in_array(strtolower($level), SupportAlertLevelPreferences::LEVELS, true) ? strtolower($level) : null;
    }

    /**
     * The after-commit projection hook may not have run yet, so compute the row now. A savepoint keeps a failure
     * here from aborting the surrounding transaction.
     */
    private function refresh(object $projector, string $subjectType, int $subjectId): void
    {
        if ($subjectType !== 'apparatus_defect' || ! method_exists($projector, 'refreshDefect')) {
            return;
        }

        try {
            $defect = ApparatusDefect::query()->find($subjectId);
            if ($defect !== null) {
                DB::transaction(static fn () => $projector->refreshDefect($defect));
            }
        } catch (Throwable $exception) {
            Log::warning('Triage level could not be computed for an alert; read as medium', [
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'exception' => $exception->getMessage(),
            ]);
        }
    }
}
