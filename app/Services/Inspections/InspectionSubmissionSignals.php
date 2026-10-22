<?php

declare(strict_types=1);

namespace App\Services\Inspections;

use App\Services\InspectionPaperFields;
use Illuminate\Validation\ValidationException;

/**
 * The reviewer-facing signals a member can attach to a Daily Checkout
 * submission: the "flag for further review" request and the per-item
 * "significant damage" marker (docs/daily-inspection-continuity.md sections 5.3 and 6).
 */
final class InspectionSubmissionSignals
{
    /**
     * Canonicalize before the replay hash is computed so equivalent payloads
     * ("1" and true, padded explanations) hash and replay identically.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function normalize(array $payload): array
    {
        if (array_key_exists('review_flag', $payload)) {
            $flag = $payload['review_flag'];
            if (! is_array($flag) || ! is_string($flag['explanation'] ?? null)) {
                unset($payload['review_flag']);
            } else {
                $payload['review_flag'] = [
                    'explanation' => trim($flag['explanation']),
                    'items' => $this->uniqueItems($flag['items'] ?? []),
                ];
            }
        }
        foreach ($payload['compartments'] ?? [] as $compartmentIndex => $compartment) {
            foreach ($compartment['items'] ?? [] as $itemIndex => $item) {
                if (array_key_exists('significant', $item)) {
                    $payload['compartments'][$compartmentIndex]['items'][$itemIndex]['significant'] = filter_var($item['significant'], FILTER_VALIDATE_BOOLEAN);
                }
            }
        }
        foreach ($payload['defects'] ?? [] as $index => $defect) {
            if (array_key_exists('significant', $defect)) {
                $payload['defects'][$index]['significant'] = filter_var($defect['significant'], FILTER_VALIDATE_BOOLEAN);
            }
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $submission  a normalized submission
     * @param  array<string, mixed>|null  $checklist  the resolved checklist
     *
     * @throws ValidationException
     */
    public function validate(array $submission, ?array $checklist): void
    {
        if ($checklist === null) {
            return;
        }
        $this->validateFlaggedItems($submission, app(InspectionPaperFields::class)->equipmentDefinitions($checklist));
        $this->validateSignificanceMatchesItems($submission);
    }

    /**
     * Columns to persist on the inspection, with names snapshotted from the
     * checklist so history survives later template changes.
     *
     * @param  array<string, mixed>  $submission
     * @param  array<string, mixed>  $checklist
     * @return array{review_flag_explanation: string|null, review_flag_items: list<array<string, string>>|null}
     */
    public function persistedFlag(array $submission, array $checklist): array
    {
        $flag = $submission['review_flag'] ?? null;
        if (! is_array($flag) || ! filled($flag['explanation'] ?? null)) {
            return ['review_flag_explanation' => null, 'review_flag_items' => null];
        }
        $definitions = app(InspectionPaperFields::class)->equipmentDefinitions($checklist);
        $items = [];
        foreach ($flag['items'] ?? [] as $reference) {
            $definition = $definitions[$reference['compartment_id']][$reference['item_id']] ?? null;
            $items[] = [
                'compartment_id' => $reference['compartment_id'],
                'item_id' => $reference['item_id'],
                'compartment' => $definition['compartment'] ?? $reference['compartment_id'],
                'item' => $definition['item'] ?? $reference['item_id'],
            ];
        }

        return ['review_flag_explanation' => $flag['explanation'], 'review_flag_items' => $items];
    }

    /** @param array<string, array<string, array<string, mixed>>> $definitions */
    private function validateFlaggedItems(array $submission, array $definitions): void
    {
        foreach ($submission['review_flag']['items'] ?? [] as $index => $reference) {
            if (! isset($definitions[$reference['compartment_id']][$reference['item_id']])) {
                throw ValidationException::withMessages([
                    "review_flag.items.{$index}" => 'Choose items from this inspection checklist.',
                ]);
            }
        }
    }

    /**
     * A defect record must carry the same significance as the checklist item it reports.
     *
     * @param  array<string, mixed>  $submission
     */
    private function validateSignificanceMatchesItems(array $submission): void
    {
        $byIds = [];
        $byNames = [];
        foreach ($submission['compartments'] ?? [] as $compartment) {
            foreach ($compartment['items'] ?? [] as $item) {
                $significant = ($item['significant'] ?? false) === true;
                $byIds[($compartment['id'] ?? '')."\0".($item['id'] ?? '')] = $significant;
                $byNames[($compartment['name'] ?? '')."\0".($item['name'] ?? '')] = $significant;
            }
        }
        foreach ($submission['defects'] ?? [] as $index => $defect) {
            $key = isset($defect['compartment_id'], $defect['item_id'])
                ? $defect['compartment_id']."\0".$defect['item_id']
                : null;
            $itemSignificant = $key !== null && array_key_exists($key, $byIds)
                ? $byIds[$key]
                : ($byNames[($defect['compartment'] ?? '')."\0".($defect['item'] ?? '')] ?? null);
            if ($itemSignificant === null) {
                continue;
            }
            if ((($defect['significant'] ?? false) === true) !== $itemSignificant) {
                throw ValidationException::withMessages([
                    "defects.{$index}.significant" => 'The defect significance must match its checklist item.',
                ]);
            }
        }
    }

    /** @return list<array{compartment_id: string, item_id: string}> */
    private function uniqueItems(mixed $items): array
    {
        $unique = [];
        foreach (is_array($items) ? $items : [] as $item) {
            $unique[$item['compartment_id']."\0".$item['item_id']] = [
                'compartment_id' => (string) $item['compartment_id'],
                'item_id' => (string) $item['item_id'],
            ];
        }

        return array_values($unique);
    }
}
