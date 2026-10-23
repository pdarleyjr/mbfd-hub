<?php

declare(strict_types=1);

namespace App\Services\Captain5;

use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusInspection;
use App\Services\DailyCheckoutChecklistResolver;
use App\Services\InspectionPaperFields;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Which equipment issues belong to Captain 5 (docs/captain5-ems-operations-center.md section 4):
 * every Rescue issue, and only the medical portion of Engine/Ladder issues. Classification uses
 * the checklist the finding was recorded against (the inspected unit's template, which matters for
 * vehicle swaps) and stable item keys, never item wording.
 */
final class Captain5IssueScope
{
    public const KIND_RESCUE = 'rescue';

    public const KIND_MEDICAL = 'medical';

    public const RESCUE_TYPES = ['rescue'];

    /** Pseudo-compartment for scheduled duties (Fire Boat v2); never equipment. */
    private const NON_EQUIPMENT_COMPARTMENTS = ['scheduled_duties'];

    /** @var array<int, string> apparatus id => checklist type */
    private array $types = [];

    /** @var array<int, array<string, array<string, mixed>>> apparatus id => definitions */
    private array $definitions = [];

    /** @var array<int, int|null> inspection id => apparatus id */
    private array $inspectionApparatus = [];

    public function __construct(
        private readonly DailyCheckoutChecklistResolver $resolver,
        private readonly InspectionPaperFields $paperFields,
        private readonly MedicalScope $medical,
    ) {}

    public function classify(ApparatusDefect $defect): IssueScopeResult
    {
        if (in_array((string) $defect->compartment_key, self::NON_EQUIPMENT_COMPARTMENTS, true)) {
            return IssueScopeResult::outOfScope('non_equipment');
        }

        $apparatusId = $this->checklistApparatusId($defect);
        $type = $this->checklistType($apparatusId);

        if (in_array($type, self::RESCUE_TYPES, true)) {
            return new IssueScopeResult(true, self::KIND_RESCUE, $type, $defect->item_key, null);
        }
        if (! in_array($type, MedicalScope::MEDICAL_ONLY_TYPES, true)) {
            return IssueScopeResult::outOfScope('not_captain5_apparatus', $type);
        }

        $itemKey = $defect->item_key ?: $this->itemKeyByName($apparatusId, (string) $defect->compartment, (string) $defect->item);
        $classification = $this->medical->classify($type, $itemKey);

        return new IssueScopeResult(
            $classification === MedicalScope::MEDICAL,
            $classification === MedicalScope::MEDICAL ? self::KIND_MEDICAL : null,
            $type,
            $itemKey,
            $classification,
        );
    }

    public function inScope(ApparatusDefect $defect): bool
    {
        return $this->classify($defect)->inScope;
    }

    /**
     * Candidate issues on Captain 5 apparatus families, newest first. Callers still filter
     * with classify() (medical scope cannot be expressed in SQL).
     *
     * @return Builder<ApparatusDefect>
     */
    public function candidateQuery(): Builder
    {
        $ids = $this->candidateApparatusIds();
        $ids = $ids === [] ? [0] : $ids;

        // Either the physical truck or the unit whose checklist was used (vehicle swaps and reserves:
        // a Rescue crew's findings on a reserve truck are still Rescue findings).
        return ApparatusDefect::query()
            ->where(fn (Builder $query) => $query->whereIn('apparatus_id', $ids)
                ->orWhereIn('first_reported_inspection_id', ApparatusInspection::query()->select('id')->whereIn('apparatus_id', $ids))
                ->orWhereIn('apparatus_inspection_id', ApparatusInspection::query()->select('id')->whereIn('apparatus_id', $ids)))
            ->where(fn (Builder $query) => $query->whereNull('compartment_key')->orWhereNotIn('compartment_key', self::NON_EQUIPMENT_COMPARTMENTS));
    }

    /**
     * In-scope issues of a candidate query, scanned in id-descending chunks so a large number of
     * out-of-scope (Fleet) issues can never push in-scope ones out of a page. Each chunk resolves
     * its inspections in one query.
     *
     * @param  Builder<ApparatusDefect>  $query
     * @return array{0: Collection<int, ApparatusDefect>, 1: bool} [issues newest first, truncated]
     */
    public function collect(Builder $query, int $limit): array
    {
        $found = new Collection;
        $truncated = false;
        $query->orderByDesc('id')->chunk(200, function (Collection $chunk) use (&$found, &$truncated, $limit): bool {
            $this->primeInspections($chunk);
            foreach ($chunk as $defect) {
                if (! $this->inScope($defect)) {
                    continue;
                }
                if ($found->count() >= $limit) {
                    $truncated = true;

                    return false;
                }
                $found->push($defect);
            }

            return true;
        });

        return [$found, $truncated];
    }

    /**
     * @param  Collection<int, ApparatusDefect>  $defects
     * @return Collection<int, ApparatusDefect>
     */
    public function filter(Collection $defects): Collection
    {
        $this->primeInspections($defects);

        return $defects->filter(fn (ApparatusDefect $defect): bool => $this->inScope($defect))->values();
    }

    /**
     * Sets each issue's `apparatus` relation from one batched query (the shared model's relation is
     * untyped, so it is attached explicitly rather than eager-loaded by name).
     *
     * @param  Collection<int, ApparatusDefect>  $defects
     * @return Collection<int, ApparatusDefect>
     */
    public function attachApparatus(Collection $defects): Collection
    {
        $ids = $defects->pluck('apparatus_id')->filter()->map(fn ($id): int => (int) $id)->unique()->values()->all();
        $units = $ids === [] ? collect() : Apparatus::query()->whereIn('id', $ids)->get()->keyBy('id');
        foreach ($defects as $defect) {
            $defect->setRelation('apparatus', $units->get((int) $defect->apparatus_id));
        }

        return $defects;
    }

    /** @param Collection<int, ApparatusDefect> $defects */
    private function primeInspections(Collection $defects): void
    {
        $ids = $defects->map(fn (ApparatusDefect $d) => $d->first_reported_inspection_id ?? $d->apparatus_inspection_id)
            ->filter()->map(fn ($id): int => (int) $id)->unique()
            ->reject(fn (int $id): bool => array_key_exists($id, $this->inspectionApparatus))->values()->all();
        if ($ids === []) {
            return;
        }
        $found = ApparatusInspection::query()->whereIn('id', $ids)->pluck('apparatus_id', 'id');
        foreach ($ids as $id) {
            $this->inspectionApparatus[$id] = isset($found[$id]) ? (int) $found[$id] : null;
        }
    }

    /** Captain 5 sees the whole inspection of a Rescue, and only the medical portion of Engine/Ladder. */
    public function inspectionKind(ApparatusInspection $inspection): ?string
    {
        $type = $this->checklistType((int) $inspection->apparatus_id);

        return match (true) {
            in_array($type, self::RESCUE_TYPES, true) => self::KIND_RESCUE,
            in_array($type, MedicalScope::MEDICAL_ONLY_TYPES, true) => self::KIND_MEDICAL,
            default => null,
        };
    }

    public function itemIsMedical(ApparatusInspection $inspection, ?string $itemKey): bool
    {
        return $this->medical->classify($this->checklistType((int) $inspection->apparatus_id), $itemKey) === MedicalScope::MEDICAL;
    }

    public function checklistType(int $apparatusId): string
    {
        if (! array_key_exists($apparatusId, $this->types)) {
            $apparatus = Apparatus::query()->find($apparatusId);
            $this->types[$apparatusId] = $apparatus instanceof Apparatus ? $this->resolver->checklistTypeFor($apparatus) : 'unmapped';
        }

        return $this->types[$apparatusId];
    }

    /** @return list<int> apparatus ids whose template is Rescue or a medical-only template */
    public function candidateApparatusIds(): array
    {
        $ids = [];
        foreach (Apparatus::query()->get() as $apparatus) {
            $type = $this->types[(int) $apparatus->getKey()] ??= $this->resolver->checklistTypeFor($apparatus);
            if (in_array($type, self::RESCUE_TYPES, true) || in_array($type, MedicalScope::MEDICAL_ONLY_TYPES, true)) {
                $ids[] = (int) $apparatus->getKey();
            }
        }

        return $ids;
    }

    public function flush(): void
    {
        $this->types = $this->definitions = $this->inspectionApparatus = [];
        $this->medical->flush();
    }

    /** The unit whose checklist the finding was recorded against (the inspected unit). */
    private function checklistApparatusId(ApparatusDefect $defect): int
    {
        $inspectionId = $defect->first_reported_inspection_id ?? $defect->apparatus_inspection_id;
        if ($inspectionId !== null) {
            if (! array_key_exists((int) $inspectionId, $this->inspectionApparatus)) {
                $value = ApparatusInspection::query()->whereKey($inspectionId)->value('apparatus_id');
                $this->inspectionApparatus[(int) $inspectionId] = $value !== null ? (int) $value : null;
            }
            if ($this->inspectionApparatus[(int) $inspectionId] !== null) {
                return $this->inspectionApparatus[(int) $inspectionId];
            }
        }

        return (int) $defect->apparatus_id;
    }

    /** A legacy finding without item key: exactly one checklist item with these names, else null. */
    private function itemKeyByName(int $apparatusId, string $compartment, string $item): ?string
    {
        if (! array_key_exists($apparatusId, $this->definitions)) {
            $apparatus = Apparatus::query()->find($apparatusId);
            $resolution = $apparatus instanceof Apparatus ? $this->resolver->resolve($apparatus) : null;
            $this->definitions[$apparatusId] = is_array($resolution['checklist'] ?? null)
                ? $this->paperFields->equipmentDefinitions($resolution['checklist'])
                : [];
        }

        $matches = [];
        foreach ($this->definitions[$apparatusId] as $items) {
            foreach ($items as $id => $definition) {
                if (in_array($compartment, $definition['compartment_names'], true) && in_array($item, $definition['item_names'], true)) {
                    $matches[] = (string) $id;
                }
            }
        }

        return count($matches) === 1 ? $matches[0] : null;
    }
}
