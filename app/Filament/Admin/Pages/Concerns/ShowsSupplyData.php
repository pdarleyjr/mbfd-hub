<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Concerns;

use App\Filament\Resources\InventoryRequestResource;
use App\Filament\Support\EmsLabels;
use App\Filament\Support\SupplyDataLabels;
use App\Models\Ems\SupplyItem;
use App\Services\Ems\SupplyData\SupplyDataFilters;
use App\Services\Ems\SupplyData\SupplyDataReport;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The "Supply data" half of Admin -> EMS -> Medical Supply Data.
 *
 * Every figure comes from SupplyDataReport (the one calculation home, docs/ems/supply-data-contract.md).
 * This trait only turns the screen's filters into a SupplyDataFilters, asks the report, and hands the
 * answer to Blade. It never writes, never recomputes a total, and never decides what a number means.
 * Read access is the whole story here: nothing on this half changes a record.
 *
 * Uses: App\Filament\Concerns\RunsEmsActions (emsActor, emsCan).
 */
trait ShowsSupplyData
{
    public const SUPPLY_EXPORT_PATH = '/ems/api/supply-data/export.xlsx';

    /** The longest custom range the report accepts; the screen says so before asking. */
    public const MAX_CUSTOM_DAYS = 400;

    public const STOCK_PAGE_SIZE = 25;

    public const TABS = ['supply' => 'Supply data', 'requests' => 'Requests'];

    #[Url(as: 'tab', except: 'supply')]
    public string $tab = 'supply';

    public string $supplyPeriod = '30d';

    public string $supplyFrom = '';

    public string $supplyTo = '';

    public string $supplyUnit = '';

    public string $supplyCategory = '';

    public string $supplyItem = '';

    public bool $stockLowOnly = false;

    public int $stockShown = self::STOCK_PAGE_SIZE;

    /** @var array{filters: ?object, problems: list<string>}|null */
    private ?array $supplyFilterMemo = null;

    /** @var array<string, array<string, mixed>|null> */
    private array $supplyDrillMemo = [];

    // ----------------------------------------------------------------- state

    public function activeTab(): string
    {
        return array_key_exists($this->tab, self::TABS) ? $this->tab : 'supply';
    }

    public function showTab(string $tab): void
    {
        $this->emsActor('view');
        $this->tab = array_key_exists($tab, self::TABS) ? $tab : 'supply';
    }

    /** Choosing "Custom dates" starts from the last 30 days so the date boxes are never empty. */
    public function updatedSupplyPeriod(): void
    {
        if ($this->supplyPeriod === 'custom' && $this->supplyFrom === '' && $this->supplyTo === '') {
            $today = CarbonImmutable::now(EmsLabels::TIMEZONE)->startOfDay();
            $this->supplyTo = $today->format('Y-m-d');
            $this->supplyFrom = $today->subDays(29)->format('Y-m-d');
        }
    }

    /** Any new supply filter starts the stock list from its first page again. */
    public function updated(string $name): void
    {
        if (str_starts_with($name, 'supply')) {
            $this->stockShown = self::STOCK_PAGE_SIZE;
        }
    }

    /** A different category makes the chosen supply meaningless, so it is cleared. */
    public function updatedSupplyCategory(): void
    {
        $this->supplyItem = '';
    }

    public function resetSupplyFilters(): void
    {
        $this->emsActor('view');
        $this->reset(['supplyPeriod', 'supplyFrom', 'supplyTo', 'supplyUnit', 'supplyCategory', 'supplyItem', 'stockLowOnly', 'stockShown']);
    }

    public function showMoreStock(): void
    {
        $this->emsActor('view');
        $this->stockShown = min(1000, max(self::STOCK_PAGE_SIZE, $this->stockShown) + self::STOCK_PAGE_SIZE);
    }

    // --------------------------------------------------------------- filters

    /** @return array<string, string> the report's query parameters (contract section 3) */
    public function supplyInput(): array
    {
        $input = ['period' => $this->supplyPeriod];
        if ($this->supplyPeriod === 'custom') {
            $input['from'] = trim($this->supplyFrom);
            $input['to'] = trim($this->supplyTo);
        }
        foreach (['unit' => $this->supplyUnit, 'category' => $this->supplyCategory, 'item' => $this->supplyItem] as $key => $value) {
            if (trim($value) !== '') {
                $input[$key] = trim($value);
            }
        }

        return $input;
    }

    /** The export link: the same endpoint and the same filters the screen is showing. */
    public function supplyExportUrl(): string
    {
        return url(self::SUPPLY_EXPORT_PATH).'?'.http_build_query($this->supplyInput());
    }

    /** @return array{filters: ?object, problems: list<string>} */
    private function supplyFilterState(): array
    {
        if ($this->supplyFilterMemo !== null) {
            return $this->supplyFilterMemo;
        }
        $problems = $this->customRangeProblems();
        $filters = null;
        if ($problems === []) {
            try {
                $filters = SupplyDataFilters::fromArray($this->supplyInput() + ['limit' => SupplyDataFilters::MAX_LIMIT]);
            } catch (ValidationException $exception) {
                $problems = $this->friendlyProblems($exception);
            }
        }

        return $this->supplyFilterMemo = ['filters' => $filters, 'problems' => $problems];
    }

    /**
     * Checked before the report is asked, so a half-typed date gets a plain sentence instead of a rule name.
     * The report still validates everything again; these checks only choose the words.
     *
     * @return list<string>
     */
    private function customRangeProblems(): array
    {
        if (! array_key_exists($this->supplyPeriod, SupplyDataLabels::PERIODS)) {
            return ['That time period is not one of the choices. Pick Last 30 days, Last 90 days, Last 12 months or Custom dates.'];
        }
        if ($this->supplyPeriod !== 'custom') {
            return [];
        }
        $from = $this->supplyDate($this->supplyFrom);
        $to = $this->supplyDate($this->supplyTo);
        $problems = [];
        if (trim($this->supplyFrom) === '') {
            $problems[] = 'Choose a start date for the custom range.';
        } elseif ($from === null) {
            $problems[] = 'The start date was not understood. Pick it from the calendar.';
        }
        if (trim($this->supplyTo) === '') {
            $problems[] = 'Choose an end date for the custom range.';
        } elseif ($to === null) {
            $problems[] = 'The end date was not understood. Pick it from the calendar.';
        }
        if ($problems !== [] || $from === null || $to === null) {
            return $problems;
        }
        $today = CarbonImmutable::now(EmsLabels::TIMEZONE)->startOfDay();
        if ($from->greaterThan($to)) {
            return ['The start date is after the end date. Swap them or pick new dates.'];
        }
        if ($to->greaterThan($today)) {
            return ['The end date cannot be in the future. Today, in Eastern time, is '.$today->format('M j, Y').'.'];
        }
        if ($from->diffInDays($to) + 1 > self::MAX_CUSTOM_DAYS) {
            return ['A custom range can cover at most '.self::MAX_CUSTOM_DAYS.' days. Choose a shorter range, or use Last 12 months.'];
        }

        return [];
    }

    private function supplyDate(string $value): ?CarbonImmutable
    {
        $parsed = preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value)) === 1
            ? CarbonImmutable::createFromFormat('!Y-m-d', trim($value), EmsLabels::TIMEZONE)
            : false;

        return $parsed !== false && $parsed->format('Y-m-d') === trim($value) ? $parsed : null;
    }

    /**
     * The report's own sentence when it wrote one for people; Laravel's stock "The x field ..." wording is replaced.
     *
     * @return list<string>
     */
    private function friendlyProblems(ValidationException $exception): array
    {
        $words = ['period' => 'time period', 'from' => 'start date', 'to' => 'end date', 'unit' => 'unit', 'item' => 'supply', 'category' => 'category'];
        $problems = [];
        foreach ($exception->errors() as $field => $messages) {
            $word = $words[$field] ?? 'filter';
            foreach ($messages as $message) {
                $problems[] = preg_match('/^The (selected )?[\w .\-]+ (field|is|must|has)\b/i', $message) === 1
                    ? "The {$word} was not accepted. Reset the filters or choose a different {$word}."
                    : $message;
            }
        }

        return array_values(array_unique($problems !== [] ? $problems : ['Those filters were not accepted. Reset the filters and try again.']));
    }

    /**
     * The page's filters for one supply or unit. As on the API, the supply or unit itself comes from the click, so the
     * same key in the filters is left out (a supply page has no use for a category filter either).
     *
     * @param  list<string>  $drop
     */
    private function drillFilters(array $drop): ?object
    {
        if ($this->supplyFilterState()['filters'] === null) {
            return null;
        }
        try {
            return SupplyDataFilters::fromArray(array_diff_key($this->supplyInput(), array_flip($drop)) + ['limit' => SupplyDataFilters::MAX_LIMIT]);
        } catch (ValidationException) {
            return null;
        }
    }

    // --------------------------------------------------------------- options

    /** @return array<string, string> unit key => label, as the report names units */
    private function supplyUnitOptions(): array
    {
        $options = [];
        foreach (InventoryRequestResource::unitOptions() as $id => $label) {
            $options['unit:'.$id] = (string) $label;
        }
        $options['detail'] = 'Detail / Special Event';
        $options['unassigned'] = 'Unit no longer on file';

        return $options;
    }

    /**
     * The categories the report offers (it already excludes controlled and inactive supplies); the one in use is always listed.
     *
     * @param  array<string, mixed>|null  $overview
     * @return list<string>
     */
    private function supplyCategoryOptions(?array $overview): array
    {
        $options = array_map('strval', array_values((array) ($overview['filter_options']['categories'] ?? [])));
        if ($this->supplyCategory !== '' && ! in_array($this->supplyCategory, $options, true)) {
            $options[] = $this->supplyCategory;
        }

        return $options;
    }

    /** @return array<int, string> supply id => name with its count unit; controlled substances never appear */
    private function supplyItemOptions(): array
    {
        return SupplyItem::query()->where('active', true)->where('controlled_substance', false)
            ->when($this->supplyCategory !== '', fn ($query) => $query->whereHas('catalogItem', fn ($catalog) => $catalog->where('category', $this->supplyCategory)))
            ->orderBy('name')->limit(1000)->get(['id', 'name', 'count_unit'])
            ->mapWithKeys(fn (SupplyItem $item): array => [(int) $item->id => $item->name.' ('.SupplyDataLabels::unitWord($item->count_unit).')'])->all();
    }

    // ------------------------------------------------------------- view data

    /** @return SupplyDataReport the single calculation home (a test may bind a stand-in with the same shapes) */
    private function supplyReport(): object
    {
        return app(SupplyDataReport::class);
    }

    /** @return array<string, mixed> */
    protected function supplyViewData(): array
    {
        $state = $this->supplyFilterState();
        /** @var array<string, mixed>|null $overview */
        $overview = $state['filters'] === null ? null : $this->supplyReport()->overview($state['filters']);
        $stock = is_array($overview) ? array_values($overview['stock'] ?? []) : [];
        if ($this->stockLowOnly) {
            $stock = array_values(array_filter($stock, static fn (array $row): bool => in_array($row['status'] ?? null, ['zero', 'below_minimum'], true) || ($row['needs_reorder'] ?? false) === true));
        }
        $shown = max(self::STOCK_PAGE_SIZE, min(1000, $this->stockShown));

        return [
            'overview' => $overview,
            'supplyProblems' => $state['problems'],
            'periods' => SupplyDataLabels::PERIODS,
            'supplyUnits' => $this->supplyUnitOptions(),
            'supplyCategories' => $this->supplyCategoryOptions($overview),
            'supplyItems' => $this->supplyItemOptions(),
            'stockRows' => array_slice($stock, 0, $shown),
            'stockTotal' => count($stock),
            'distributionNote' => SupplyDataLabels::DISTRIBUTION_NOTE,
            'maxCustomDays' => self::MAX_CUSTOM_DAYS,
            'todayEastern' => CarbonImmutable::now(EmsLabels::TIMEZONE)->format('Y-m-d'),
            'earliestCustomFrom' => CarbonImmutable::now(EmsLabels::TIMEZONE)->subDays(self::MAX_CUSTOM_DAYS - 1)->format('Y-m-d'),
        ];
    }

    // ------------------------------------------------------------ drill-downs

    public function itemDetailAction(): Action
    {
        return Action::make('itemDetail')
            ->slideOver()->modalWidth('3xl')
            ->visible(fn (): bool => $this->emsCan('view'))
            ->modalHeading(fn (array $arguments): string => (string) ($this->itemDrill($arguments)['item']['name'] ?? 'Supply details'))
            ->modalDescription(SupplyDataLabels::DISTRIBUTION_NOTE)
            ->modalContent(fn (array $arguments): View => view('filament.admin.pages.medical-supply-data.item-detail', [
                'detail' => $this->itemDrill($arguments),
                'problems' => $this->supplyFilterState()['problems'],
                'distributionNote' => SupplyDataLabels::DISTRIBUTION_NOTE,
            ]))
            ->modalSubmitAction(false)->modalCancelActionLabel('Close');
    }

    public function unitDetailAction(): Action
    {
        return Action::make('unitDetail')
            ->slideOver()->modalWidth('3xl')
            ->visible(fn (): bool => $this->emsCan('view'))
            ->modalHeading(fn (array $arguments): string => (string) ($this->unitDrill($arguments)['unit']['label'] ?? 'Unit details'))
            ->modalDescription(SupplyDataLabels::DISTRIBUTION_NOTE)
            ->modalContent(fn (array $arguments): View => view('filament.admin.pages.medical-supply-data.unit-detail', [
                'detail' => $this->unitDrill($arguments),
                'problems' => $this->supplyFilterState()['problems'],
                'distributionNote' => SupplyDataLabels::DISTRIBUTION_NOTE,
            ]))
            ->modalSubmitAction(false)->modalCancelActionLabel('Close');
    }

    /**
     * One supply, from the report; null when the supply is not available or the filters are not valid.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>|null
     */
    private function itemDrill(array $arguments): ?array
    {
        $id = (string) ($arguments['item'] ?? '');
        $key = 'item:'.$id;
        if (array_key_exists($key, $this->supplyDrillMemo)) {
            return $this->supplyDrillMemo[$key];
        }
        $filters = $this->drillFilters(['item', 'category']);
        $item = ctype_digit($id) ? SupplyItem::query()->whereKey((int) $id)->where('controlled_substance', false)->first() : null;
        if ($filters === null || $item === null) {
            return $this->supplyDrillMemo[$key] = null;
        }
        try {
            $detail = $this->supplyReport()->item($item, $filters);
        } catch (ModelNotFoundException|NotFoundHttpException) {
            $detail = null;
        }

        return $this->supplyDrillMemo[$key] = $detail;
    }

    /**
     * One unit, from the report; null when the unit is not known or the filters are not valid.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>|null
     */
    private function unitDrill(array $arguments): ?array
    {
        $unitKey = (string) ($arguments['unit'] ?? '');
        $key = 'unit:'.$unitKey;
        if (array_key_exists($key, $this->supplyDrillMemo)) {
            return $this->supplyDrillMemo[$key];
        }
        $filters = $this->drillFilters(['unit']);
        if ($filters === null || preg_match('/^(unit:\d+|detail)$/', $unitKey) !== 1) {
            return $this->supplyDrillMemo[$key] = null;
        }
        try {
            $detail = $this->supplyReport()->unit($unitKey, $filters);
        } catch (ModelNotFoundException|NotFoundHttpException) {
            $detail = null;
        }

        return $this->supplyDrillMemo[$key] = $detail;
    }
}
