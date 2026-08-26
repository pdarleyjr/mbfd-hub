<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Apparatus;
use JsonException;

/**
 * Resolves the one tracked checklist that applies to an apparatus.
 *
 * Specialized apparatus never silently fall back to the generic checklist:
 * a missing, unreadable, invalid, or empty source is an unusable resolution.
 */
final class DailyCheckoutChecklistResolver
{
    /** @var array<string, array{checklist_type: string, category: string}> */
    private const CHECKLISTS_BY_APPARATUS_IDENTITY = [
        'E1' => ['checklist_type' => 'engine', 'category' => 'engine'],
        'E2' => ['checklist_type' => 'engine2', 'category' => 'engine'],
        'L1' => ['checklist_type' => 'ladder1', 'category' => 'ladder'],
        'L3' => ['checklist_type' => 'ladder3', 'category' => 'ladder'],
        'R1' => ['checklist_type' => 'rescue', 'category' => 'rescue'],
    ];

    private readonly string $checklistDirectory;

    public function __construct(?string $checklistDirectory = null)
    {
        $this->checklistDirectory = $checklistDirectory ?? base_path('storage/checklists');
    }

    /**
     * @return array{
     *     checklist_type: string,
     *     path: string,
     *     fallback_used: bool,
     *     item_count: int,
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
            return $this->unusable($checklistType, '', $mapping['error']);
        }

        $path = "{$this->checklistDirectory}/{$checklistType}_checklist.json";

        if (! is_file($path) || ! is_readable($path)) {
            return $this->unusable($checklistType, $path, is_file($path) ? 'unreadable' : 'missing');
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            return $this->unusable($checklistType, $path, 'unreadable');
        }

        try {
            $checklist = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->unusable($checklistType, $path, 'invalid_json');
        }

        if (! is_array($checklist)) {
            return $this->unusable($checklistType, $path, 'invalid_json');
        }

        if (! $this->hasUnambiguousSubmissionSchema($checklist)) {
            return $this->unusable($checklistType, $path, 'invalid_schema');
        }

        $itemCount = $this->itemCount($checklist);

        return [
            'checklist_type' => $checklistType,
            'path' => $path,
            'fallback_used' => false,
            'item_count' => $itemCount,
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
     * Maps only the explicitly supported units that have checked-in checklist assets.
     * An engine, ladder, or rescue record with an unsupported or contradictory identity
     * must be corrected by an authorized owner; it must not inherit another unit's list.
     *
     * @return array{checklist_type: string, error: string|null}
     */
    private function mappingFor(Apparatus $apparatus): array
    {
        $identities = [];
        foreach ([$apparatus->unit_id, $apparatus->designation, $apparatus->name] as $value) {
            $identity = $this->specializedIdentity($value);
            if ($identity !== null) {
                $identities[$identity] = true;
            }
        }

        $categories = [];
        foreach ([$apparatus->type, $apparatus->class_description] as $value) {
            $category = $this->specializedCategory($value);
            if ($category !== null) {
                $categories[$category] = true;
            }
        }

        if (count($identities) > 1 || count($categories) > 1) {
            return $this->unmapped('ambiguous_specialized_apparatus');
        }

        $identity = array_key_first($identities);
        $category = array_key_first($categories);

        if ($identity !== null) {
            $mapping = self::CHECKLISTS_BY_APPARATUS_IDENTITY[$identity] ?? null;
            if ($mapping === null) {
                return $this->unmapped('unmapped_specialized_apparatus');
            }

            if ($category !== null && $mapping['category'] !== $category) {
                return $this->unmapped('ambiguous_specialized_apparatus');
            }

            return [
                'checklist_type' => $mapping['checklist_type'],
                'error' => null,
            ];
        }

        if ($category !== null) {
            return $this->unmapped('unmapped_specialized_apparatus');
        }

        return [
            'checklist_type' => 'default',
            'error' => null,
        ];
    }

    private function specializedIdentity(mixed $value): ?string
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

    private function specializedCategory(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = strtolower(trim($value));

        if (preg_match('/\bengines?\b/', $value) === 1) {
            return 'engine';
        }

        if (preg_match('/\bladders?\b/', $value) === 1) {
            return 'ladder';
        }

        if (preg_match('/\brescues?\b/', $value) === 1) {
            return 'rescue';
        }

        return null;
    }

    /** @return array{checklist_type: 'unmapped', error: string} */
    private function unmapped(string $error): array
    {
        return [
            'checklist_type' => 'unmapped',
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
    private function hasUnambiguousSubmissionSchema(array $checklist): bool
    {
        $compartments = $checklist['compartments'] ?? null;
        if (! is_array($compartments) || ! array_is_list($compartments) || $compartments === []) {
            return false;
        }

        $compartmentIds = [];
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

            if (isset($compartmentIds[$compartmentId])) {
                return false;
            }
            $compartmentIds[$compartmentId] = true;

            $itemNames = [];
            foreach ($items as $item) {
                if (! is_array($item)) {
                    return false;
                }

                $itemName = $this->nonEmptyString($item['name'] ?? null);
                // The public submission contract currently identifies an item by its
                // compartment and display name. A duplicate name in one compartment
                // would make a defect/result unverifiable, so never publish it as a
                // valid Daily Checkout checklist.
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
     * @return array{
     *     checklist_type: string,
     *     path: string,
     *     fallback_used: bool,
     *     item_count: int,
     *     checklist: null,
     *     usable: false,
     *     error: string
     * }
     */
    private function unusable(string $checklistType, string $path, string $error): array
    {
        return [
            'checklist_type' => $checklistType,
            'path' => $path,
            'fallback_used' => false,
            'item_count' => 0,
            'checklist' => null,
            'usable' => false,
            'error' => $error,
        ];
    }
}
