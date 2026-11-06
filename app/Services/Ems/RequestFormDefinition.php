<?php

declare(strict_types=1);

namespace App\Services\Ems;

use App\Enums\DailyCheckoutChecklistTemplate;
use App\Models\Apparatus;
use App\Models\Ems\SourceForm;
use App\Models\Ems\SourceFormItem;
use App\Services\DailyCheckoutChecklistResolver;
use Illuminate\Support\Collection;

/**
 * What a medical inventory form shows: the active lists of one family (docs/ems/four-inventory-forms.md) plus the unit
 * choices. With no family it answers exactly as the single Medical Inventory Request did (ALS Transport), so a page loaded
 * before the four forms keeps working.
 */
final class RequestFormDefinition
{
    /** The three lists the single Medical Inventory Request had. Kept for callers that mean the original form. */
    public const BAGS = [SourceForm::AIRWAY, SourceForm::ALS, SourceForm::TRANSPORT];

    public const MAX_QUANTITY = 999;

    /** ALS Transport lists about 500 rows; a request never needs more lines than a family has. */
    public const MAX_LINES = 900;

    public const NOTE_MAX = 2000;

    public const EVENT_MAX = 160;

    /** Source notes that are printed on the paper form; the others are extraction commentary and stay internal. */
    private const PRINTED_NOTE_PATTERN = '/^Totals can include\b/i';

    public function __construct(
        private readonly EmsUnitDirectory $units,
        private readonly DailyCheckoutChecklistResolver $checklists,
    ) {}

    /**
     * The family a request or query names. Nothing named is ALS Transport; a name this release does not know is refused.
     *
     * @return array{key: string, title: string, short_title: string, form_key: string, description: string, bag_keys: list<string>}
     */
    public function family(?string $key): array
    {
        if ($key === null || trim($key) === '') {
            return FormFamilies::resolve(null);
        }

        return FormFamilies::find(trim($key)) ?? throw new EmsException(
            'That form is not available. Reload the page and try again.',
            'unknown_family',
            422,
            ['family' => ['That form is not available. Reload the page and try again.']],
        );
    }

    /** @return array<string, SourceForm> list key => active form of the family, in tab order (a list that is not imported is simply absent) */
    public function activeForms(?string $family = null): array
    {
        $bags = $this->family($family)['bag_keys'];
        $forms = SourceForm::query()->whereIn('key', $bags)->where('active', true)->get()->keyBy('key');
        $ordered = [];
        foreach ($bags as $key) {
            if ($forms->has($key)) {
                $ordered[$key] = $forms->get($key);
            }
        }

        return $ordered;
    }

    /** @return array<string, mixed> */
    public function definition(?string $family = null): array
    {
        $chosen = $this->family($family);
        $forms = [];
        foreach ($this->activeForms($chosen['key']) as $form) {
            $forms[] = [
                'key' => $form->key,
                'title' => $form->title,
                'version_label' => $form->version_label,
                'printed_note' => $this->printedNote($form),
                'sections' => $this->sections($form, $form->items()->with('catalogItem:id,name,aliases')->get()),
            ];
        }

        return [
            'family' => FormFamilies::summary($chosen) + ['bag_keys' => $chosen['bag_keys'], 'default_target' => $this->defaultTarget($chosen['key'])],
            'families' => array_map(FormFamilies::summary(...), FormFamilies::all()),
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

    /**
     * Where the form preselects its unit: Detail / Special Event for the BLS Detail form, and the one Fire Boat 6 for its
     * form (the apparatus whose Daily Checkout checklist is the Fire Boat 6 template; nothing when there are none or several,
     * or the boat is not in the unit list). Only a preselection; the member still picks.
     */
    private function defaultTarget(string $familyKey): ?string
    {
        if ($familyKey === FormFamilies::BLS_DETAIL) {
            return 'detail';
        }
        if ($familyKey !== FormFamilies::FIRE_BOAT_6) {
            return null;
        }

        $boats = Apparatus::query()->get()
            ->filter(fn (Apparatus $apparatus): bool => $this->checklists->checklistTypeFor($apparatus) === DailyCheckoutChecklistTemplate::FireBoat6->value)
            ->values();
        if ($boats->count() !== 1) {
            return null;
        }
        $id = (int) $boats->first()->getKey();

        return $this->units->find($id) !== null ? 'unit:'.$id : null;
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
     * Other names for the item, for search only (never matched at submission time): the catalog's names and the list's own
     * search terms.
     *
     * @return list<string>
     */
    private function aliases(SourceFormItem $item): array
    {
        $terms = (array) (((array) $item->flags)['search_terms'] ?? []);
        $names = [...(array) ($item->catalogItem->aliases ?? []), $item->catalogItem?->name, ...$terms];

        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $name): string => is_string($name) ? trim($name) : '', $names),
            static fn (string $name): bool => $name !== '' && strcasecmp($name, (string) $item->label) !== 0,
        )));
    }

    /**
     * @param  Collection<int, SourceFormItem>  $items
     * @return list<array<string, mixed>>
     */
    private function sections(SourceForm $form, Collection $items): array
    {
        $sectionNotes = (array) (((array) $form->notes)['section_notes'] ?? []);
        $sections = [];
        foreach ($items->groupBy('section_key') as $key => $group) {
            $first = $group->first();
            $printed = (array) ($sectionNotes[(string) $key] ?? []);
            $sections[] = [
                'key' => (string) $key,
                'label' => $first->section_label,
                'parent_key' => $first->parent_section_key,
                'note' => $printed['note'] ?? null,
                'cadence' => $printed['cadence'] ?? null,
                'items' => $group->map(fn (SourceFormItem $item): array => [
                    'item_key' => $item->item_key,
                    'label' => $item->label,
                    'source_label' => $item->source_label,
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
