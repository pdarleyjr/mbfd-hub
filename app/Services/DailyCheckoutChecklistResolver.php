<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\DailyCheckoutChecklistTemplate;
use App\Models\Apparatus;
use JsonException;

/**
 * Resolves one tracked Daily Checkout checklist for an apparatus.
 *
 * A configured template is an explicit operational choice. Otherwise, only the
 * approved Engine, Rescue, and Ladder family rules (plus E2 and L3 overrides)
 * may select a checklist. Specialty, administrative, and ambiguous records stay
 * unusable until an authorized owner configures a tracked template.
 */
final class DailyCheckoutChecklistResolver
{
    /** @var array<string, string> */
    private const CHECKLISTS_BY_FAMILY = [
        'engine' => DailyCheckoutChecklistTemplate::Engine->value,
        'rescue' => DailyCheckoutChecklistTemplate::Rescue->value,
        'ladder' => DailyCheckoutChecklistTemplate::Ladder1->value,
    ];

    /** @var array<string, string> */
    private const CHECKLISTS_BY_IDENTITY_OVERRIDE = [
        'E2' => DailyCheckoutChecklistTemplate::Engine2->value,
        'L3' => DailyCheckoutChecklistTemplate::Ladder3->value,
    ];

    private readonly string $checklistDirectory;

    public function __construct(?string $checklistDirectory = null)
    {
        $this->checklistDirectory = $checklistDirectory ?? base_path('storage/checklists');
    }

    /**
     * @return array{
     *     checklist_type: string,
     *     configured_template: string,
     *     resolution_source: 'configured_template'|'family'|'family_override'|'pending',
     *     family: string|null,
     *     identity: string|null,
     *     ambiguity: string|null,
     *     path: string,
     *     fallback_used: false,
     *     item_count: int,
     *     checklist_version: string|null,
     *     checklist: array<string, mixed>|null,
     *     usable: bool,
     *     error: string|null
     * }
     */
    public function resolve(Apparatus $apparatus): array
    {
        $mapping = $this->mappingFor($apparatus);
        $checklistType = $mapping['checklist_type'];

        if ($mapping['error'] !== null) {
            return $this->unusable($mapping, '', $mapping['error']);
        }

        $path = "{$this->checklistDirectory}/{$checklistType}_checklist.json";

        if (! is_file($path) || ! is_readable($path)) {
            return $this->unusable($mapping, $path, is_file($path) ? 'unreadable' : 'missing');
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            return $this->unusable($mapping, $path, 'unreadable');
        }

        try {
            $checklist = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->unusable($mapping, $path, 'invalid_json');
        }

        if (! is_array($checklist)) {
            return $this->unusable($mapping, $path, 'invalid_json');
        }

        if (! $this->hasUnambiguousSubmissionSchema($checklist)) {
            return $this->unusable($mapping, $path, 'invalid_schema');
        }

        try {
            $checklistVersion = $this->checklistVersion($checklist);
        } catch (JsonException) {
            return $this->unusable($mapping, $path, 'invalid_json');
        }

        return [
            ...$mapping,
            'path' => $path,
            'fallback_used' => false,
            'item_count' => $this->itemCount($checklist),
            'checklist_version' => $checklistVersion,
            'checklist' => $checklist,
            'usable' => true,
            'error' => null,
        ];
    }

    public function checklistTypeFor(Apparatus $apparatus): string
    {
        return $this->mappingFor($apparatus)['checklist_type'];
    }

    /**
     * @return array{
     *     checklist_type: string,
     *     configured_template: string,
     *     resolution_source: 'configured_template'|'family'|'family_override'|'pending',
     *     family: string|null,
     *     identity: string|null,
     *     ambiguity: string|null,
     *     error: string|null
     * }
     */
    private function mappingFor(Apparatus $apparatus): array
    {
        $template = $this->configuredTemplateFor($apparatus);
        if ($template['error'] !== null) {
            return $this->unmapped(
                error: $template['error'],
                configuredTemplate: $template['value'],
                ambiguity: 'daily_checkout_template_invalid',
            );
        }

        if ($template['template'] !== null) {
            return [
                'checklist_type' => $template['template']->value,
                'configured_template' => $template['template']->value,
                'resolution_source' => 'configured_template',
                'family' => null,
                'identity' => null,
                'ambiguity' => null,
                'error' => null,
            ];
        }

        $specialty = $this->specialtyFor($apparatus);
        if ($specialty !== null) {
            return $this->unmapped(
                error: 'specialty_template_pending',
                configuredTemplate: $template['value'],
                ambiguity: "specialty:{$specialty}",
            );
        }

        $family = $this->familyFor($apparatus);
        if ($family['error'] !== null) {
            return $this->unmapped(
                error: $family['error'],
                configuredTemplate: $template['value'],
                ambiguity: $family['error'],
            );
        }

        $identity = $this->identityFor($apparatus);
        if ($identity['error'] !== null) {
            return $this->unmapped(
                error: $identity['error'],
                configuredTemplate: $template['value'],
                family: $family['family'],
                ambiguity: $identity['error'],
            );
        }

        $identityFamily = $identity['identity'] === null ? null : $this->familyForIdentity($identity['identity']);
        if ($family['family'] !== null && $identityFamily !== null && $family['family'] !== $identityFamily) {
            return $this->unmapped(
                error: 'ambiguous_apparatus_family',
                configuredTemplate: $template['value'],
                family: $family['family'],
                identity: $identity['identity'],
                ambiguity: 'identity_family_conflict',
            );
        }

        if ($identity['identity'] !== null && isset(self::CHECKLISTS_BY_IDENTITY_OVERRIDE[$identity['identity']])) {
            return [
                'checklist_type' => self::CHECKLISTS_BY_IDENTITY_OVERRIDE[$identity['identity']],
                'configured_template' => $template['value'],
                'resolution_source' => 'family_override',
                'family' => $identityFamily,
                'identity' => $identity['identity'],
                'ambiguity' => null,
                'error' => null,
            ];
        }

        if ($family['family'] === null) {
            return $this->unmapped(
                error: 'unclassified_family_template_pending',
                configuredTemplate: $template['value'],
                identity: $identity['identity'],
                ambiguity: 'family_unclassified',
            );
        }

        return [
            'checklist_type' => self::CHECKLISTS_BY_FAMILY[$family['family']],
            'configured_template' => $template['value'],
            'resolution_source' => 'family',
            'family' => $family['family'],
            'identity' => $identity['identity'],
            'ambiguity' => null,
            'error' => null,
        ];
    }

    /**
     * @return array{
     *     template: DailyCheckoutChecklistTemplate|null,
     *     value: string,
     *     error: 'invalid_checklist_template'|null
     * }
     */
    private function configuredTemplateFor(Apparatus $apparatus): array
    {
        $attributes = $apparatus->getAttributes();
        $raw = $attributes['daily_checkout_template'] ?? null;
        $value = $raw instanceof DailyCheckoutChecklistTemplate
            ? $raw->value
            : $this->nonEmptyString($raw);

        if ($value === null) {
            return [
                'template' => null,
                'value' => DailyCheckoutChecklistTemplate::Pending->value,
                'error' => null,
            ];
        }

        $value = strtolower($value);
        $template = DailyCheckoutChecklistTemplate::tryFrom($value);
        if ($template === null) {
            return [
                'template' => null,
                'value' => $value,
                'error' => 'invalid_checklist_template',
            ];
        }

        return [
            'template' => $template->isConfigured() ? $template : null,
            'value' => $template->value,
            'error' => null,
        ];
    }

    /** @return array{family: 'engine'|'rescue'|'ladder'|null, error: 'ambiguous_apparatus_family'|null} */
    private function familyFor(Apparatus $apparatus): array
    {
        $families = [];
        foreach ([$apparatus->type, $apparatus->class_description] as $value) {
            $family = $this->familyFromValue($value);
            if ($family !== null) {
                $families[$family] = true;
            }
        }

        if (count($families) > 1) {
            return ['family' => null, 'error' => 'ambiguous_apparatus_family'];
        }

        /** @var 'engine'|'rescue'|'ladder'|null $family */
        $family = array_key_first($families);

        return ['family' => $family, 'error' => null];
    }

    /** @return 'engine'|'rescue'|'ladder'|null */
    private function familyFromValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $normalized = strtolower(preg_replace('/\s+/', ' ', $value) ?? '');

        return match ($normalized) {
            'engine', 'engines', 'engine company' => 'engine',
            'rescue', 'rescues', 'rescue company' => 'rescue',
            'ladder', 'ladders', 'ladder company' => 'ladder',
            default => null,
        };
    }

    /** @return array{identity: string|null, error: 'ambiguous_apparatus_identity'|null} */
    private function identityFor(Apparatus $apparatus): array
    {
        $identities = [];
        foreach ([$apparatus->getAttribute('unit_id'), $apparatus->designation, $apparatus->name] as $value) {
            $identity = $this->identityFromValue($value);
            if ($identity !== null) {
                $identities[$identity] = true;
            }
        }

        if (count($identities) > 1) {
            return ['identity' => null, 'error' => 'ambiguous_apparatus_identity'];
        }

        return ['identity' => array_key_first($identities), 'error' => null];
    }

    private function identityFromValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = preg_replace('/[\s-]+/', '', strtoupper(trim($value)));
        if ($normalized === null || $normalized === '') {
            return null;
        }

        if (preg_match('/^(?:E|ENGINE)(\d+)$/', $normalized, $matches) === 1) {
            return "E{$matches[1]}";
        }

        if (preg_match('/^(?:L|LADDER)(\d+)$/', $normalized, $matches) === 1) {
            return "L{$matches[1]}";
        }

        if (preg_match('/^(?:R|RESCUE)(\d+)$/', $normalized, $matches) === 1) {
            return "R{$matches[1]}";
        }

        return null;
    }

    /** @return 'engine'|'rescue'|'ladder' */
    private function familyForIdentity(string $identity): string
    {
        return match ($identity[0]) {
            'E' => 'engine',
            'R' => 'rescue',
            'L' => 'ladder',
            default => throw new \LogicException("Unsupported apparatus identity family: {$identity}"),
        };
    }

    private function specialtyFor(Apparatus $apparatus): ?string
    {
        foreach ([$apparatus->getAttribute('unit_id'), $apparatus->designation, $apparatus->name, $apparatus->type, $apparatus->class_description] as $value) {
            if (! is_string($value)) {
                continue;
            }

            $normalized = preg_replace('/[^a-z0-9]+/', '', strtolower($value)) ?? '';
            if (str_contains($normalized, 'airtruck')) {
                return 'air_truck';
            }

            if (str_contains($normalized, 'fireboat') || $normalized === 'fb6') {
                return 'fire_boat';
            }

            if (preg_match('/\b(?:command|administrative|administration|admin)\b/i', $value) === 1) {
                return 'administrative';
            }
        }

        return null;
    }

    /**
     * @return array{
     *     checklist_type: 'unmapped',
     *     configured_template: string,
     *     resolution_source: 'pending',
     *     family: string|null,
     *     identity: string|null,
     *     ambiguity: string|null,
     *     error: string
     * }
     */
    private function unmapped(
        string $error,
        string $configuredTemplate,
        ?string $family = null,
        ?string $identity = null,
        ?string $ambiguity = null,
    ): array {
        return [
            'checklist_type' => 'unmapped',
            'configured_template' => $configuredTemplate,
            'resolution_source' => 'pending',
            'family' => $family,
            'identity' => $identity,
            'ambiguity' => $ambiguity,
            'error' => $error,
        ];
    }

    /** @param array<string, mixed> $checklist */
    private function itemCount(array $checklist): int
    {
        if (! is_array($checklist['compartments'] ?? null)) {
            return 0;
        }

        $count = 0;
        foreach ($checklist['compartments'] as $compartment) {
            if (is_array($compartment) && is_array($compartment['items'] ?? null)) {
                $count += count($compartment['items']);
            }
        }

        return $count;
    }

    /** @param array<string, mixed> $checklist */
    private function checklistVersion(array $checklist): string
    {
        $canonical = json_encode(
            $this->canonicalize($checklist),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );

        return hash('sha256', $canonical);
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }

    /** @param array<string, mixed> $checklist */
    private function hasUnambiguousSubmissionSchema(array $checklist): bool
    {
        $compartments = $checklist['compartments'] ?? null;
        if (! is_array($compartments) || ! array_is_list($compartments) || $compartments === []) {
            return false;
        }

        $compartmentIds = [];
        $compartmentNames = [];
        foreach ($compartments as $compartment) {
            if (! is_array($compartment)) {
                return false;
            }

            $compartmentId = $this->nonEmptyString($compartment['id'] ?? null);
            $compartmentName = $this->nonEmptyString($compartment['name'] ?? $compartment['title'] ?? null);
            $items = $compartment['items'] ?? null;
            if ($compartmentId === null || $compartmentName === null || ! is_array($items) || ! array_is_list($items) || $items === []) {
                return false;
            }

            // Defects are keyed by the canonical compartment display name and
            // item name, so duplicate display names would make them ambiguous
            // even if the stable compartment IDs differ.
            if (isset($compartmentIds[$compartmentId]) || isset($compartmentNames[$compartmentName])) {
                return false;
            }
            $compartmentIds[$compartmentId] = true;
            $compartmentNames[$compartmentName] = true;

            $itemNames = [];
            foreach ($items as $item) {
                if (! is_array($item)) {
                    return false;
                }

                $itemName = $this->nonEmptyString($item['name'] ?? null);
                // The public submission contract identifies an item by its
                // compartment and display name. Duplicate names therefore make a
                // result unverifiable and must fail closed.
                if ($itemName === null || isset($itemNames[$itemName])) {
                    return false;
                }

                $itemNames[$itemName] = true;
            }
        }

        return true;
    }

    private function nonEmptyString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @param array{
     *     checklist_type: string,
     *     configured_template: string,
     *     resolution_source: 'configured_template'|'family'|'family_override'|'pending',
     *     family: string|null,
     *     identity: string|null,
     *     ambiguity: string|null,
     *     error: string|null
     * } $mapping
     * @return array{
     *     checklist_type: string,
     *     configured_template: string,
     *     resolution_source: 'configured_template'|'family'|'family_override'|'pending',
     *     family: string|null,
     *     identity: string|null,
     *     ambiguity: string|null,
     *     path: string,
     *     fallback_used: false,
     *     item_count: 0,
     *     checklist_version: null,
     *     checklist: null,
     *     usable: false,
     *     error: string
     * }
     */
    private function unusable(array $mapping, string $path, string $error): array
    {
        return [
            ...$mapping,
            'path' => $path,
            'fallback_used' => false,
            'item_count' => 0,
            'checklist_version' => null,
            'checklist' => null,
            'usable' => false,
            'error' => $error,
        ];
    }
}
