<?php

declare(strict_types=1);

namespace App\Services;

use JsonException;

/**
 * Read-only diagnostic companion for the checklist resolver.
 *
 * The runtime resolver intentionally exposes only a generic invalid-schema
 * result. A release gate needs the exact duplicate identifiers that made a
 * candidate unusable, without changing the runtime submission contract.
 */
final class DailyCheckoutChecklistEvidenceInspector
{
    /**
     * @return array{
     *     source_path: string|null,
     *     source_sha256: string|null,
     *     item_count: int|null,
     *     duplicate_identifiers: list<array{code: string, compartment_id: string|null, compartment_label: string|null, label: string, occurrences: list<int>}>
     * }
     */
    public function inspect(?string $path): array
    {
        if ($path === null || $path === '' || ! is_file($path) || ! is_readable($path)) {
            return [
                'source_path' => null,
                'source_sha256' => null,
                'item_count' => null,
                'duplicate_identifiers' => [],
            ];
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            return [
                'source_path' => $this->relativePath($path),
                'source_sha256' => null,
                'item_count' => null,
                'duplicate_identifiers' => [],
            ];
        }

        try {
            $checklist = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [
                'source_path' => $this->relativePath($path),
                'source_sha256' => hash('sha256', $contents),
                'item_count' => null,
                'duplicate_identifiers' => [],
            ];
        }

        if (! is_array($checklist) || ! is_array($checklist['compartments'] ?? null)) {
            return [
                'source_path' => $this->relativePath($path),
                'source_sha256' => hash('sha256', $contents),
                'item_count' => null,
                'duplicate_identifiers' => [],
            ];
        }

        $duplicates = [];
        $compartmentIds = [];
        $compartmentLabels = [];
        $itemCount = 0;

        foreach ($checklist['compartments'] as $compartmentIndex => $compartment) {
            if (! is_array($compartment)) {
                continue;
            }

            $compartmentId = $this->nonEmptyString($compartment['id'] ?? null);
            $compartmentLabel = $this->nonEmptyString($compartment['name'] ?? $compartment['title'] ?? null);
            if ($compartmentId !== null) {
                $compartmentIds[$compartmentId][] = $compartmentIndex;
            }
            if ($compartmentLabel !== null) {
                $compartmentLabels[$compartmentLabel][] = $compartmentIndex;
            }

            $itemNames = [];
            $items = $compartment['items'] ?? null;
            if (! is_array($items)) {
                continue;
            }
            $itemCount += count($items);

            foreach ($items as $itemIndex => $item) {
                if (! is_array($item)) {
                    continue;
                }

                $itemName = $this->nonEmptyString($item['name'] ?? null);
                if ($itemName !== null) {
                    $itemNames[$itemName][] = $itemIndex;
                }
            }

            foreach ($itemNames as $itemName => $occurrences) {
                if (count($occurrences) > 1) {
                    $duplicates[] = [
                        'code' => 'duplicate_item_label',
                        'compartment_id' => $compartmentId,
                        'compartment_label' => $compartmentLabel,
                        'label' => $itemName,
                        'occurrences' => array_values($occurrences),
                    ];
                }
            }
        }

        foreach ($compartmentIds as $identifier => $occurrences) {
            if (count($occurrences) > 1) {
                $duplicates[] = [
                    'code' => 'duplicate_compartment_id',
                    'compartment_id' => $identifier,
                    'compartment_label' => null,
                    'label' => $identifier,
                    'occurrences' => array_values($occurrences),
                ];
            }
        }
        foreach ($compartmentLabels as $label => $occurrences) {
            if (count($occurrences) > 1) {
                $duplicates[] = [
                    'code' => 'duplicate_compartment_label',
                    'compartment_id' => null,
                    'compartment_label' => $label,
                    'label' => $label,
                    'occurrences' => array_values($occurrences),
                ];
            }
        }

        return [
            'source_path' => $this->relativePath($path),
            'source_sha256' => hash('sha256', $contents),
            'item_count' => $itemCount,
            'duplicate_identifiers' => $duplicates,
        ];
    }

    private function relativePath(string $path): string
    {
        $resolvedPath = realpath($path);
        $basePath = realpath(base_path());
        if ($resolvedPath !== false && $basePath !== false && str_starts_with($resolvedPath, $basePath.DIRECTORY_SEPARATOR)) {
            return str_replace(DIRECTORY_SEPARATOR, '/', substr($resolvedPath, strlen($basePath) + 1));
        }

        return basename($path);
    }

    private function nonEmptyString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
