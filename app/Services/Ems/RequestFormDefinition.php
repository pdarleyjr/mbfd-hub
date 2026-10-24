<?php

declare(strict_types=1);

namespace App\Services\Ems;

use App\Models\Ems\SourceForm;
use App\Models\Ems\SourceFormItem;
use Illuminate\Support\Collection;

/** What the Medical Inventory Request form shows: the active airway and ALS bag forms plus the unit choices. */
final class RequestFormDefinition
{
    public const BAGS = [SourceForm::AIRWAY, SourceForm::ALS];

    public const MAX_QUANTITY = 999;

    public const NOTE_MAX = 2000;

    public const EVENT_MAX = 160;

    /** Source notes that are printed on the paper form; the others are extraction commentary and stay internal. */
    private const PRINTED_NOTE_PATTERN = '/^Totals can include\b/i';

    public function __construct(private readonly EmsUnitDirectory $units) {}

    /** @return array<string, SourceForm> bag key => active form (a missing bag is simply absent) */
    public function activeForms(): array
    {
        $forms = SourceForm::query()->whereIn('key', self::BAGS)->where('active', true)->get()->keyBy('key');
        $ordered = [];
        foreach (self::BAGS as $key) {
            if ($forms->has($key)) {
                $ordered[$key] = $forms->get($key);
            }
        }

        return $ordered;
    }

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $forms = [];
        foreach ($this->activeForms() as $form) {
            $forms[] = [
                'key' => $form->key,
                'title' => $form->title,
                'version_label' => $form->version_label,
                'printed_note' => $this->printedNote($form),
                'sections' => $this->sections($form->items()->with('catalogItem:id,name,aliases')->get()),
            ];
        }

        return [
            'forms' => $forms,
            'units' => $this->units->options(),
            'limits' => [
                'max_quantity' => self::MAX_QUANTITY,
                'note_max' => self::NOTE_MAX,
                'event_assignment_max' => self::EVENT_MAX,
            ],
            'rules' => [
                'blank_is_not_zero' => true,
                'needs_item_or_note' => true,
                'controlled_substance_note' => 'Narcotics are answered Yes or No only. They are restocked through the department controlled-substance procedure.',
            ],
        ];
    }

    /** The note printed on the paper form that members should see, for example "Totals can include amounts in Bobo Bag." */
    private function printedNote(SourceForm $form): ?string
    {
        $notes = (array) (((array) $form->notes)['source_notes'] ?? []);
        foreach ($notes as $note) {
            if (is_string($note) && preg_match(self::PRINTED_NOTE_PATTERN, $note) === 1) {
                return rtrim(trim($note), '.').'.';
            }
        }

        return null;
    }

    /**
     * Other names for the item, for search only (never matched at submission time).
     *
     * @return list<string>
     */
    private function aliases(SourceFormItem $item): array
    {
        $names = [...(array) ($item->catalogItem->aliases ?? []), $item->catalogItem?->name];

        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $name): string => is_string($name) ? trim($name) : '', $names),
            static fn (string $name): bool => $name !== '' && strcasecmp($name, (string) $item->label) !== 0,
        )));
    }

    /**
     * @param  Collection<int, SourceFormItem>  $items
     * @return list<array<string, mixed>>
     */
    private function sections(Collection $items): array
    {
        $sections = [];
        foreach ($items->groupBy('section_key') as $key => $group) {
            $first = $group->first();
            $sections[] = [
                'key' => (string) $key,
                'label' => $first->section_label,
                'parent_key' => $first->parent_section_key,
                'items' => $group->map(fn (SourceFormItem $item): array => [
                    'item_key' => $item->item_key,
                    'label' => $item->label,
                    'min_text' => $item->min_text,
                    'min_qty' => $item->min_qty,
                    'min_unit' => $item->min_unit,
                    'variant_group' => $item->variant_group,
                    'variant_label' => $item->variant_label,
                    'response_type' => $item->response_type,
                    'flags' => $item->flags ?? [],
                    'aliases' => $this->aliases($item),
                ])->values()->all(),
            ];
        }

        return $sections;
    }
}
