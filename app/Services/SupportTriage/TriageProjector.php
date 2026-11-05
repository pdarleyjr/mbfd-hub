<?php

declare(strict_types=1);

namespace App\Services\SupportTriage;

use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusInspection;
use App\Models\StationRequest;
use App\Models\StationSupplyRequest;
use App\Models\SupportTriage\SupportTriageLevel;
use App\Models\User;
use App\Services\DailyCheckoutChecklistResolver;
use App\Services\SupportTriage\Contracts\EquipmentClassifier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Staff-facing triage: one support_triage_levels row per open record holding the level the equipment catalog
 * produced, an optional staff override and the effective level every screen reads. It sends nothing to anyone
 * and runs whether the AI automation switch is ON or OFF.
 *
 * Effective level: a defect flagged significant or out of service is always HIGH; otherwise a staff override,
 * otherwise the catalog's answer. Member and staff flags on the record itself are never changed here.
 */
final class TriageProjector
{
    public const DEFECT = 'apparatus_defect';

    public const STATION_REQUEST = 'station_request';

    public const SUPPLY_REQUEST = 'station_supply_request';

    private const SUBJECTS = [self::DEFECT, self::STATION_REQUEST, self::SUPPLY_REQUEST];

    /** @var array<int, string|null> apparatus id => checklist type */
    private array $checklistTypes = [];

    /** @var array<int, int|null> inspection id => apparatus id */
    private array $inspectionApparatus = [];

    public function __construct(private readonly EquipmentClassifier $classifier) {}

    // ------------------------------------------------------------------ refresh

    public function refreshDefect(ApparatusDefect $defect): SupportTriageLevel
    {
        return $this->store(self::DEFECT, (int) $defect->getKey(), $this->computeDefect($defect));
    }

    public function refreshStationRequest(StationRequest $request): SupportTriageLevel
    {
        return $this->store(self::STATION_REQUEST, (int) $request->getKey(), $this->computeStationRequest($request));
    }

    public function refreshSupplyRequest(StationSupplyRequest $supply): SupportTriageLevel
    {
        return $this->store(self::SUPPLY_REQUEST, (int) $supply->getKey(), $this->computeSupplyRequest($supply));
    }

    /** The effective level for a record, or null when it has not been projected yet. */
    public function levelFor(string $subjectType, int $subjectId): ?string
    {
        $level = SupportTriageLevel::query()->where('subject_type', $subjectType)->where('subject_id', $subjectId)->value('level');

        return is_string($level) ? $level : null;
    }

    /**
     * Sets (or with null clears) a staff override. The caller has already checked the person may do this.
     * A defect flagged significant or out of service still reads HIGH.
     */
    public function setOverride(string $subjectType, int $subjectId, ?string $level, User $by): SupportTriageLevel
    {
        $this->assertSubject($subjectType);
        if ($level !== null && ! in_array($level, EquipmentClassification::LEVELS, true)) {
            throw ValidationException::withMessages(['level' => 'Choose Low, Medium or High.']);
        }

        return DB::transaction(function () use ($subjectType, $subjectId, $level, $by): SupportTriageLevel {
            $record = $this->loadRecord($subjectType, $subjectId);
            $row = SupportTriageLevel::query()->where('subject_type', $subjectType)->where('subject_id', $subjectId)->lockForUpdate()->first()
                ?? $this->project($subjectType, $record);
            $row->forceFill([
                'override_level' => $level,
                'override_by_user_id' => $level === null ? null : $by->getKey(),
                'override_at' => $level === null ? null : now(),
            ]);
            $row->level = $this->effective($row->auto_level, $level, $record instanceof ApparatusDefect && self::flagged($record));
            $row->save();

            return $row;
        });
    }

    // ------------------------------------------------------------------ reapply

    /**
     * What re-checking every open record under the current catalog would change. Reads only.
     *
     * @return array{by_level_before: array<string, int>, by_level_after: array<string, int>, changes: int, overridden_kept: int, open_records: int}
     */
    public function previewReapply(): array
    {
        $before = ['low' => 0, 'medium' => 0, 'high' => 0, 'none' => 0];
        $after = ['low' => 0, 'medium' => 0, 'high' => 0];
        $changes = $kept = $open = 0;

        foreach (self::SUBJECTS as $subject) {
            $this->eachOpen($subject, 200, function (Model $record, ?SupportTriageLevel $row) use ($subject, &$before, &$after, &$changes, &$kept, &$open): void {
                $open++;
                $computed = $this->compute($subject, $record);
                $override = $row instanceof SupportTriageLevel ? $row->override_level : null;
                $newLevel = $this->effective($computed['auto_level'], $override, $computed['flagged']);
                $before[$row instanceof SupportTriageLevel ? $row->level : 'none']++;
                $after[$newLevel]++;
                $kept += $override !== null ? 1 : 0;
                $changes += $row === null || $row->level !== $newLevel ? 1 : 0;
            });
        }

        return ['by_level_before' => $before, 'by_level_after' => $after, 'changes' => $changes, 'overridden_kept' => $kept, 'open_records' => $open];
    }

    /**
     * Recomputes every open record under the current catalog. Staff overrides are never touched and nothing is
     * sent to anyone.
     *
     * @return int projections that changed (including new ones)
     */
    public function reapply(int $chunk = 200): int
    {
        $changed = 0;
        foreach (self::SUBJECTS as $subject) {
            $this->eachOpen($subject, max(1, $chunk), function (Model $record) use ($subject, &$changed): void {
                try {
                    $row = $this->store($subject, (int) $record->getKey(), $this->compute($subject, $record));
                    $changed += $row->wasRecentlyCreated || $row->wasChanged() ? 1 : 0;
                } catch (Throwable $exception) {
                    report($exception);
                }
            });
        }

        return $changed;
    }

    // ----------------------------------------------------------------- compute

    /**
     * @return array{auto_level: string, category: string, handler: string, rule_id: int|null, unclassified: bool, reasons: list<string>, flagged: bool}
     */
    private function compute(string $subject, Model $record): array
    {
        return match (true) {
            $record instanceof ApparatusDefect => $this->computeDefect($record),
            $record instanceof StationRequest => $this->computeStationRequest($record),
            $record instanceof StationSupplyRequest => $this->computeSupplyRequest($record),
            default => throw new \InvalidArgumentException('Unsupported triage subject '.$subject),
        };
    }

    /**
     * @return array{auto_level: string, category: string, handler: string, rule_id: int|null, unclassified: bool, reasons: list<string>, flagged: bool}
     */
    private function computeDefect(ApparatusDefect $defect): array
    {
        $significant = $defect->severity === 'significant';
        $classification = $this->classifier->classifyItem(
            (string) $defect->item,
            $defect->notes,
            $this->checklistType($defect),
            $significant,
            $defect->issue_type,
        );
        $outOfService = $defect->operational_impact === 'out_of_service';

        return $this->shape($classification, $classification->reasons, flagged: $significant || $outOfService, extra: $outOfService ? ['out_of_service'] : []);
    }

    /**
     * @return array{auto_level: string, category: string, handler: string, rule_id: int|null, unclassified: bool, reasons: list<string>, flagged: bool}
     */
    private function computeStationRequest(StationRequest $request): array
    {
        $names = $request->items->pluck('item_name')->filter()->implode(' ');
        $classification = $this->classifier->classifyText(trim($request->title.' '.$request->description.' '.$names), 'station');
        $level = $classification->level;
        $extra = [];
        // The member's own priority is kept and respected: they asked for it to be treated as urgent.
        if (in_array($request->priority, ['critical', 'high'], true)) {
            $level = EquipmentClassification::highest($level, EquipmentClassification::HIGH);
            $extra[] = 'member_priority';
        }

        return $this->shape($classification, $classification->reasons, flagged: false, extra: $extra, level: $level);
    }

    /**
     * @return array{auto_level: string, category: string, handler: string, rule_id: int|null, unclassified: bool, reasons: list<string>, flagged: bool}
     */
    private function computeSupplyRequest(StationSupplyRequest $supply): array
    {
        return $this->shape($this->classifier->classifyText((string) $supply->request_text, 'supply'), [], flagged: false);
    }

    /**
     * @param  list<string>  $reasons
     * @param  list<string>  $extra
     * @return array{auto_level: string, category: string, handler: string, rule_id: int|null, unclassified: bool, reasons: list<string>, flagged: bool}
     */
    private function shape(EquipmentClassification $classification, array $reasons, bool $flagged, array $extra = [], ?string $level = null): array
    {
        return [
            'auto_level' => $level ?? $classification->level,
            'category' => $classification->category,
            'handler' => $classification->handler,
            'rule_id' => $classification->ruleId,
            'unclassified' => $classification->unclassified,
            'reasons' => array_values(array_unique([...($reasons === [] ? $classification->reasons : $reasons), ...$extra])),
            'flagged' => $flagged,
        ];
    }

    private function effective(string $auto, ?string $override, bool $flagged): string
    {
        return $flagged ? EquipmentClassification::HIGH : ($override ?? $auto);
    }

    // ------------------------------------------------------------------ persist

    /**
     * @param  array{auto_level: string, category: string, handler: string, rule_id: int|null, unclassified: bool, reasons: list<string>, flagged: bool}  $computed
     */
    private function store(string $subject, int $id, array $computed): SupportTriageLevel
    {
        try {
            return $this->write($subject, $id, $computed);
        } catch (UniqueConstraintViolationException) {
            // A concurrent projection created the row first; update it.
            return $this->write($subject, $id, $computed);
        }
    }

    /**
     * @param  array{auto_level: string, category: string, handler: string, rule_id: int|null, unclassified: bool, reasons: list<string>, flagged: bool}  $computed
     */
    private function write(string $subject, int $id, array $computed): SupportTriageLevel
    {
        // Row-locked so a concurrent staff override can never be overwritten by a stale effective level.
        return DB::transaction(function () use ($subject, $id, $computed): SupportTriageLevel {
            $row = SupportTriageLevel::query()->where('subject_type', $subject)->where('subject_id', $id)->lockForUpdate()->first()
                ?? new SupportTriageLevel(['subject_type' => $subject, 'subject_id' => $id]);
            $row->fill([
                'auto_level' => $computed['auto_level'],
                'category' => $computed['category'],
                'handler' => $computed['handler'],
                'rule_id' => $computed['rule_id'],
                'unclassified' => $computed['unclassified'],
                'reasons' => $computed['reasons'],
                'policy_version' => $this->classifier->policyVersion(),
            ]);
            // The override is only read here, never written.
            $row->level = $this->effective($computed['auto_level'], $row->override_level, $computed['flagged']);
            if ($row->isDirty()) {
                $row->computed_at = now();
                $row->save();
            }

            return $row;
        });
    }

    private function project(string $subject, Model $record): SupportTriageLevel
    {
        return $this->store($subject, (int) $record->getKey(), $this->compute($subject, $record));
    }

    // ----------------------------------------------------------------- records

    private function assertSubject(string $subject): void
    {
        if (! in_array($subject, self::SUBJECTS, true)) {
            throw ValidationException::withMessages(['subject' => 'That kind of record has no priority.']);
        }
    }

    private function loadRecord(string $subject, int $id): Model
    {
        return match ($subject) {
            self::DEFECT => ApparatusDefect::query()->findOrFail($id),
            self::STATION_REQUEST => StationRequest::query()->with('items:id,station_request_id,item_name')->findOrFail($id),
            default => StationSupplyRequest::query()->findOrFail($id),
        };
    }

    /**
     * Every open record of a kind, in id order, with its current projection row.
     *
     * @param  callable(Model, ?SupportTriageLevel): void  $each
     */
    private function eachOpen(string $subject, int $chunk, callable $each): void
    {
        /** @var Builder<Model> $query */
        $query = match ($subject) {
            self::DEFECT => ApparatusDefect::query()->where('resolved', false),
            self::STATION_REQUEST => StationRequest::query()->open()->with('items:id,station_request_id,item_name'),
            default => StationSupplyRequest::query()->open(),
        };
        $query->chunkById($chunk, function ($records) use ($subject, $each): void {
            $rows = SupportTriageLevel::query()->where('subject_type', $subject)->whereIn('subject_id', $records->modelKeys())->get()->keyBy('subject_id');
            foreach ($records as $record) {
                $each($record, $rows->get((int) $record->getKey()));
            }
        });
    }

    private static function flagged(ApparatusDefect $defect): bool
    {
        return $defect->severity === 'significant' || $defect->operational_impact === 'out_of_service';
    }

    // -------------------------------------------------------- checklist type

    private function checklistType(ApparatusDefect $defect): ?string
    {
        try {
            $inspectionId = $defect->first_reported_inspection_id ?? $defect->apparatus_inspection_id;
            $apparatusId = $inspectionId !== null ? $this->inspectionApparatusId((int) $inspectionId) : null;
            $apparatusId ??= (int) $defect->apparatus_id;
            if ($apparatusId <= 0) {
                return null;
            }
            if (! array_key_exists($apparatusId, $this->checklistTypes)) {
                // Same lookup the vehicle adapter uses; nothing here reads Captain 5 data.
                $apparatus = Apparatus::query()->find($apparatusId);
                $type = $apparatus instanceof Apparatus ? app(DailyCheckoutChecklistResolver::class)->checklistTypeFor($apparatus) : null;
                $type = $type === '' ? null : $type;
                $this->checklistTypes[$apparatusId] = $type === 'unmapped' ? null : $type;
            }

            return $this->checklistTypes[$apparatusId];
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    private function inspectionApparatusId(int $inspectionId): ?int
    {
        if (! array_key_exists($inspectionId, $this->inspectionApparatus)) {
            $value = ApparatusInspection::query()->whereKey($inspectionId)->value('apparatus_id');
            $this->inspectionApparatus[$inspectionId] = $value !== null ? (int) $value : null;
        }

        return $this->inspectionApparatus[$inspectionId];
    }
}
