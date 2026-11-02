<?php

declare(strict_types=1);

namespace App\Services\Fleet;

use App\Models\Apparatus;
use App\Models\ApparatusEquipmentNumber;
use App\Models\ApparatusInspection;
use Illuminate\Database\Eloquent\Collection;

/**
 * The SCBA and radio numbers that carry from one Daily Checkout to the next on every apparatus, so a member only changes
 * what changed (docs/apparatus-issue-history.md section 7).
 *
 * Which entries count is read from the checklist itself: any write-in field or item named for an SCBA, airpack, SCUBA or
 * radio. Checklists are not edited for this, so their hashes and any open drafts are untouched. Numbers the checklist has
 * no entry for are "extras" a member added; they are sent beside the checklist and kept here.
 */
final class CrewEquipmentNumbers
{
    public const KIND_SCBA = 'scba';

    public const KIND_RADIO = 'radio';

    public const MAX_EXTRAS = 30;

    private const EXTRA_PREFIX = 'extra:';

    private const NUMBER_LENGTH = 255;

    /**
     * What the screen needs: each checklist entry with its current number, and the added ones.
     *
     * @param  array<string, mixed>  $checklist
     * @return array{slots: list<array<string, mixed>>, extras: list<array{client_id: string, kind: string, label: string|null, number: string}>}
     */
    public function block(Apparatus $apparatus, array $checklist): array
    {
        $current = ApparatusEquipmentNumber::query()->where('apparatus_id', $apparatus->id)->orderBy('id')->get();
        $numbers = $current->pluck('number', 'slot')->all();
        $slots = $this->slots($checklist);
        // Until a unit's first checkout under this feature, its numbers are whatever its latest inspection recorded.
        if ($numbers === []) {
            $numbers = $this->numbersFrom($this->latestInspection($apparatus), $slots);
        }

        return [
            'slots' => array_map(static fn (array $slot): array => [...$slot, 'number' => (string) ($numbers[$slot['slot']] ?? '')], $slots),
            'extras' => $current
                ->filter(static fn (ApparatusEquipmentNumber $row): bool => str_starts_with($row->slot, self::EXTRA_PREFIX))
                ->map(static fn (ApparatusEquipmentNumber $row): array => [
                    'client_id' => substr($row->slot, strlen(self::EXTRA_PREFIX)),
                    'kind' => $row->kind,
                    'label' => $row->label,
                    'number' => $row->number,
                ])->values()->all(),
        ];
    }

    /**
     * Update the current numbers from an accepted checkout, on the physical apparatus it settled on. The app reports the
     * numbers it showed the member, so only what they changed is applied: a draft or queued submission that sat for hours
     * cannot undo a change someone made in the meantime. A phone on an older app reports nothing, so its blank says nothing
     * and the number stays.
     *
     * @param  array<string, mixed>  $checklist
     * @param  array<string, mixed>  $evidence  the inspection's pending effects
     */
    public function record(ApparatusInspection $inspection, Apparatus $apparatus, array $checklist, array $evidence): void
    {
        $sawNumbers = array_key_exists('equipment_numbers_seen', $evidence);
        $seen = [];
        foreach ($evidence['equipment_numbers_seen'] ?? [] as $shown) {
            $seen[(string) $shown['slot']] = $this->text($shown['number'] ?? null) ?? '';
        }
        $slots = $this->slots($checklist);
        $existing = ApparatusEquipmentNumber::query()->where('apparatus_id', $apparatus->id)->get()->keyBy('slot');
        if ($existing->isEmpty()) {
            $existing = $this->startFromLatestInspection($inspection, $apparatus, $slots);
        }
        $values = $this->enteredValues($inspection);

        foreach ($slots as $slot) {
            $number = $values[$slot['slot']] ?? '';
            if ($sawNumbers && $number === ($seen[$slot['slot']] ?? '')) {
                continue;
            }
            if ($this->hasDigit($number)) {
                $this->put($inspection, $apparatus, $existing->get($slot['slot']), $slot['slot'], $slot['kind'], $slot['label'], $number);
            } elseif ($sawNumbers && $number === '') {
                $existing->get($slot['slot'])?->delete();
            }
        }

        $sent = [];
        foreach ($evidence['extra_equipment_numbers'] ?? [] as $extra) {
            $slot = self::EXTRA_PREFIX.strtolower((string) $extra['client_id']);
            $sent[] = $slot;
            $number = $this->text($extra['number'] ?? null) ?? '';
            if (($seen[$slot] ?? null) !== $number) {
                $this->put($inspection, $apparatus, $existing->get($slot), $slot, (string) $extra['kind'], $this->text($extra['label'] ?? null), $number);
            }
        }
        // Removed means shown and not sent back. One added since this member looked is not theirs to remove.
        foreach (array_keys($seen) as $slot) {
            if (str_starts_with($slot, self::EXTRA_PREFIX) && ! in_array($slot, $sent, true)) {
                $existing->get($slot)?->delete();
            }
        }
    }

    /**
     * The added numbers as permanent lines on the inspection's own record, beside the checklist's entries.
     *
     * @param  list<array<string, mixed>>  $extras
     * @return list<array{id: string, name: string, input_type: string, value: string}>
     */
    public function evidenceLines(array $extras): array
    {
        return array_map(static fn (array $extra): array => [
            'id' => 'extra-equipment-'.strtolower((string) $extra['client_id']),
            'name' => filled($extra['label'] ?? null) ? (string) $extra['label'] : ($extra['kind'] === self::KIND_RADIO ? 'Additional radio number' : 'Additional SCBA number'),
            'input_type' => 'text',
            'value' => (string) $extra['number'],
        ], $extras);
    }

    /**
     * @param  array<string, mixed>  $checklist
     * @return list<array{slot: string, kind: string, label: string, field_id: string|null, compartment_id: string|null, item_id: string|null, boxes: int}>
     */
    public function slots(array $checklist): array
    {
        $slots = [];
        foreach ($checklist['fields'] ?? $checklist['officerChecklist'] ?? [] as $field) {
            $kind = $this->kindOf($field);
            if ($kind !== null) {
                $slots[] = ['slot' => 'field:'.$field['id'], 'kind' => $kind, 'label' => (string) $field['name'], 'field_id' => (string) $field['id'], 'compartment_id' => null, 'item_id' => null, 'boxes' => 1];
            }
        }
        foreach ($checklist['compartments'] ?? [] as $compartment) {
            foreach ($compartment['items'] ?? [] as $index => $item) {
                $kind = $this->kindOf($item);
                if ($kind === null) {
                    continue;
                }
                $itemId = (string) ($item['id'] ?? $compartment['id'].'-item-'.($index + 1));
                // One box on the checklist holding several numbers, like a rescue's "SCBAs": one input for each.
                $quantity = $item['expectedQuantity'] ?? 1;
                $slots[] = ['slot' => 'item:'.$compartment['id'].'/'.$itemId, 'kind' => $kind, 'label' => (string) $item['name'], 'field_id' => null, 'compartment_id' => (string) $compartment['id'], 'item_id' => $itemId, 'boxes' => is_int($quantity) && $quantity > 1 ? $quantity : 1];
            }
        }
        usort($slots, static fn (array $a, array $b): int => ($a['kind'] === self::KIND_RADIO) <=> ($b['kind'] === self::KIND_RADIO));

        return $slots;
    }

    /** @param array<string, mixed> $entry a checklist field or item */
    private function kindOf(array $entry): ?string
    {
        if (! in_array($entry['inputType'] ?? 'checkbox', ['text', 'number'], true)) {
            return null;
        }
        $name = (string) ($entry['name'] ?? '');

        return match (true) {
            preg_match('/\bradio\b/i', $name) === 1 => self::KIND_RADIO,
            preg_match('/\b(scbas?|scuba|air ?packs?)\b/i', $name) === 1 => self::KIND_SCBA,
            default => null,
        };
    }

    /**
     * What this inspection recorded for each slot.
     *
     * @return array<string, string>
     */
    private function enteredValues(ApparatusInspection $inspection): array
    {
        $values = [];
        foreach (is_array($inspection->checklist_evidence) ? $inspection->checklist_evidence : [] as $field) {
            if (isset($field['id'])) {
                $values['field:'.$field['id']] = $this->text($field['value'] ?? null) ?? '';
            }
        }
        foreach (is_array($inspection->results) ? $inspection->results : [] as $compartment) {
            foreach ($compartment['items'] ?? [] as $item) {
                if (isset($compartment['id'], $item['id'])) {
                    $values['item:'.$compartment['id'].'/'.$item['id']] = $this->text($item['value'] ?? null) ?? '';
                }
            }
        }

        return $values;
    }

    /**
     * The numbers a unit had before its first checkout under this feature become rows first, so one phone filling a single
     * box cannot lose the rest.
     *
     * @param  list<array<string, mixed>>  $slots
     * @return Collection<string, ApparatusEquipmentNumber>
     */
    private function startFromLatestInspection(ApparatusInspection $inspection, Apparatus $apparatus, array $slots): Collection
    {
        $latest = $this->latestInspection($apparatus, (int) $inspection->id);
        $numbers = $this->numbersFrom($latest, $slots);
        if ($latest === null || $numbers === []) {
            return new Collection;
        }
        foreach ($slots as $slot) {
            if (isset($numbers[$slot['slot']])) {
                $this->put($latest, $apparatus, null, $slot['slot'], $slot['kind'], $slot['label'], $numbers[$slot['slot']]);
            }
        }

        return ApparatusEquipmentNumber::query()->where('apparatus_id', $apparatus->id)->get()->keyBy('slot');
    }

    private function latestInspection(Apparatus $apparatus, ?int $except = null): ?ApparatusInspection
    {
        return ApparatusInspection::query()->operational()
            ->where(static fn ($query) => $query->where('processed_apparatus_id', $apparatus->id)
                ->orWhere(static fn ($legacy) => $legacy->whereNull('processed_apparatus_id')->where('apparatus_id', $apparatus->id)))
            ->whereNotNull('completed_at')
            ->when($except !== null, static fn ($query) => $query->whereKeyNot($except))
            ->orderByDesc('completed_at')->orderByDesc('id')
            ->first(['id', 'results', 'checklist_evidence', 'actor_user_id']);
    }

    /**
     * Older inspections hold words like "OK" or "Inspected" in these boxes, so only an entry with a digit carries forward.
     *
     * @param  list<array<string, mixed>>  $slots
     * @return array<string, string>
     */
    private function numbersFrom(?ApparatusInspection $inspection, array $slots): array
    {
        if ($inspection === null) {
            return [];
        }
        $entered = $this->enteredValues($inspection);
        $numbers = [];
        foreach ($slots as $slot) {
            $value = $entered[$slot['slot']] ?? '';
            if ($this->hasDigit($value)) {
                $numbers[$slot['slot']] = $value;
            }
        }

        return $numbers;
    }

    private function hasDigit(string $value): bool
    {
        return preg_match('/\d/', $value) === 1;
    }

    private function put(ApparatusInspection $inspection, Apparatus $apparatus, ?ApparatusEquipmentNumber $row, string $slot, string $kind, ?string $label, string $number): void
    {
        $number = mb_substr($number, 0, self::NUMBER_LENGTH);
        if ($number === '') {
            return;
        }
        // An unchanged number keeps who last changed it and when.
        if ($row instanceof ApparatusEquipmentNumber && $row->number === $number && $row->label === $label) {
            return;
        }
        ApparatusEquipmentNumber::query()->updateOrCreate(
            ['apparatus_id' => $apparatus->id, 'slot' => $slot],
            ['kind' => $kind, 'label' => $label, 'number' => $number, 'source_inspection_id' => $inspection->id, 'updated_by_user_id' => $inspection->actor_user_id],
        );
    }

    private function text(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return null;
        }
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
