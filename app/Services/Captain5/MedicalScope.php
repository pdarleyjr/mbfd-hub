<?php

declare(strict_types=1);

namespace App\Services\Captain5;

use App\Models\Captain5\Captain5ItemClassification;
use App\Services\InspectionPaperFields;

/**
 * Classifies Engine/Ladder checklist items as Captain 5 medical scope from the reviewed
 * mapping in config/captain5_medical_scope.php and administrator decisions
 * (captain5_item_classifications), never from item wording.
 *
 * Result per item: `medical`, `not_medical` or `unclassified` (ambiguous or unknown; never
 * visible to Captain 5, listed for an administrator to decide).
 */
final class MedicalScope
{
    public const MEDICAL = 'medical';

    public const NOT_MEDICAL = 'not_medical';

    public const UNCLASSIFIED = 'unclassified';

    /** Templates whose medical portion Captain 5 oversees. */
    public const MEDICAL_ONLY_TYPES = ['engine', 'engine2', 'ladder1', 'ladder3'];

    /** @var array<string, array<string, string>>|null checklist_type => item_key => classification */
    private ?array $decisions = null;

    /** @var array<string, array<string, true>> */
    private array $baselineItems = [];

    public function __construct(private readonly InspectionPaperFields $paperFields) {}

    public function classify(string $checklistType, ?string $itemKey): string
    {
        if (! in_array($checklistType, self::MEDICAL_ONLY_TYPES, true) || $itemKey === null || $itemKey === '') {
            return self::UNCLASSIFIED;
        }

        $decision = $this->decisions()[$checklistType][$itemKey] ?? null;
        if ($decision !== null) {
            return $decision === Captain5ItemClassification::MEDICAL ? self::MEDICAL : self::NOT_MEDICAL;
        }

        // Item ids are positional in some templates: after an unreviewed template change the mapping
        // could point at different equipment, so nothing is classified until it is re-reviewed.
        $baseline = $this->baselineItems($checklistType);
        if ($baseline === []) {
            return self::UNCLASSIFIED;
        }
        $mapping = (array) config("captain5_medical_scope.{$checklistType}", []);
        if (in_array($itemKey, (array) ($mapping['medical'] ?? []), true)) {
            return self::MEDICAL;
        }
        if (in_array($itemKey, (array) ($mapping['ambiguous'] ?? []), true)) {
            return self::UNCLASSIFIED;
        }

        // Every other item of the reviewed template was reviewed and is not medical. Items that
        // did not exist at review time (inventory additions, template edits) wait for a decision.
        return isset($baseline[$itemKey]) ? self::NOT_MEDICAL : self::UNCLASSIFIED;
    }

    /**
     * Canonical template hash used by the mapping's `reviewed_version`.
     *
     * @param  array<string, mixed>  $checklist
     */
    public static function canonicalVersion(array $checklist): string
    {
        return hash('sha256', (string) json_encode($checklist, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** @return array<string, mixed>|null */
    public function baselineTemplate(string $checklistType): ?array
    {
        if (preg_match('/^[a-z0-9_]+$/D', $checklistType) !== 1) {
            return null;
        }
        $path = base_path("storage/checklists/{$checklistType}_checklist.json");
        if (! is_file($path)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    /** Forget cached decisions (after an administrator classifies an item). */
    public function flush(): void
    {
        $this->decisions = null;
    }

    /** @return array<string, true> */
    private function baselineItems(string $checklistType): array
    {
        if (isset($this->baselineItems[$checklistType])) {
            return $this->baselineItems[$checklistType];
        }
        $template = $this->baselineTemplate($checklistType);
        $reviewed = (string) config("captain5_medical_scope.{$checklistType}.reviewed_version", '');
        if ($template === null || $reviewed === '' || ! hash_equals($reviewed, self::canonicalVersion($template))) {
            // An unreviewed template change: nothing defaults to "not medical" until it is re-reviewed.
            return $this->baselineItems[$checklistType] = [];
        }

        $ids = [];
        foreach ($this->paperFields->equipmentDefinitions($template) as $items) {
            foreach (array_keys($items) as $id) {
                $ids[(string) $id] = true;
            }
        }

        return $this->baselineItems[$checklistType] = $ids;
    }

    /** @return array<string, array<string, string>> */
    private function decisions(): array
    {
        if ($this->decisions !== null) {
            return $this->decisions;
        }
        $decisions = [];
        foreach (Captain5ItemClassification::query()->current()->get(['checklist_type', 'item_key', 'classification']) as $row) {
            $decisions[(string) $row->checklist_type][(string) $row->item_key] = (string) $row->classification;
        }

        return $this->decisions = $decisions;
    }
}
