<?php

declare(strict_types=1);

namespace App\Services\Ems\Import;

use App\Models\Ems\CatalogItem;
use App\Models\Ems\SourceForm;
use App\Models\Ems\SourceFormItem;
use App\Models\Ems\StorageLocation;
use App\Models\Ems\SupplyItem;
use App\Models\Ems\SupplyItemEvent;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Imports the tracked source extractions in database/data/ems (docs/ems/ems-inventory-contract.md
 * section 2). Idempotent and non-destructive:
 *  - the same source sha256 with the same extraction content is a no-op;
 *  - the same source sha256 with corrected extraction content upserts the reference rows by their stable keys;
 *  - a new source sha256 becomes a new form version: the old version is deactivated and kept;
 *  - request, count and movement history is never touched; fields an authorized person edited
 *    (recorded in ems_supply_item_events) are never overwritten by a re-import.
 */
final class SourceImporter
{
    public const SUPPLY_KEY = 'captain5-supply-room';

    public const FILES = [
        'airway_bag_inventory.json' => 'mbfd.ems.source_form.v1',
        'als_med_bag_inventory.json' => 'mbfd.ems.source_form.v1',
        'als_transport_vehicle_inventory.json' => 'mbfd.ems.source_form.v1',
        'captain5_supply_room_inventory.json' => 'mbfd.ems.supply_room_source.v1',
    ];

    private const FORM_KEYS = [SourceForm::AIRWAY, SourceForm::ALS, SourceForm::TRANSPORT];

    /** Supply fields an authorized person may edit; once edited, a re-import leaves them alone. */
    private const EDITABLE = [
        'threshold_qty_base', 'units_per_package', 'packages_per_case', 'package_unit',
        'order_qty_packages', 'location_id', 'needs_review', 'review_reasons',
    ];

    public const ABSENT_REASON = 'Not in the latest source document';

    private const ACTOR = 'Source import';

    /** Conversion and threshold fields: a change made by a re-import is written to the audit trail. */
    private const AUDITED = ['threshold_qty_base', 'units_per_package', 'packages_per_case', 'package_unit', 'order_qty_packages', 'location_id'];

    /** @var array<string, int> */
    private array $report = [];

    /** @var list<string> */
    private array $messages = [];

    /** @var array<string, CatalogItem> */
    private array $catalog = [];

    public function __construct(private readonly SupplyRowMapper $mapper = new SupplyRowMapper) {}

    /** @return array{counts: array<string, int>, messages: list<string>, dry_run: bool} */
    public function import(string $directory, bool $dryRun = false): array
    {
        $this->report = [];
        $this->messages = [];
        $this->catalog = [];

        $documents = [];
        foreach (self::FILES as $file => $schema) {
            $path = rtrim($directory, '/\\').DIRECTORY_SEPARATOR.$file;
            if (! is_file($path)) {
                $this->messages[] = "{$file} not found, skipped.";

                continue;
            }
            $raw = (string) file_get_contents($path);
            $document = json_decode($raw, true);
            if (! is_array($document) || ($document['schema'] ?? null) !== $schema) {
                throw new RuntimeException("{$file} is not a {$schema} document.");
            }
            $documents[$file] = [$document, hash('sha256', $raw)];
        }
        if ($documents === []) {
            throw new RuntimeException("No EMS source files were found in {$directory}.");
        }

        DB::beginTransaction();
        try {
            // Forms first so shared catalog identities are created in a stable order.
            foreach ($documents as $file => [$document, $extractionSha]) {
                if ($document['schema'] === 'mbfd.ems.source_form.v1') {
                    $this->importForm($document, $extractionSha);
                }
            }
            foreach ($documents as [$document, $extractionSha]) {
                if ($document['schema'] === 'mbfd.ems.supply_room_source.v1') {
                    $this->importSupply($document, $extractionSha);
                }
            }
            $dryRun ? DB::rollBack() : DB::commit();
        } catch (Throwable $exception) {
            DB::rollBack();
            throw $exception;
        }

        ksort($this->report);

        return ['counts' => $this->report, 'messages' => $this->messages, 'dry_run' => $dryRun];
    }

    // ------------------------------------------------------------------ forms

    /** @param array<string, mixed> $document */
    /**
     * An entry's identity reference. The tracked extractions say `item_ref` / `catalog_ref` (slug values
     * under a "*_key" name read as credentials to secret scanners); older documents and test fixtures say
     * `item_key` / `catalog_key`. Both are accepted, and the stored source row stays verbatim.
     *
     * @param  array<string, mixed>  $entry
     */
    private static function reference(array $entry, string $name): ?string
    {
        $value = $entry[$name.'_ref'] ?? $entry[$name.'_key'] ?? null;

        return $value === null ? null : (string) $value;
    }

    private function importForm(array $document, string $extractionSha): void
    {
        $source = (array) ($document['source'] ?? []);
        $key = (string) ($source['key'] ?? '');
        $sha = (string) ($source['sha256'] ?? '');
        if (! in_array($key, self::FORM_KEYS, true) || preg_match('/^[0-9a-f]{64}$/', $sha) !== 1) {
            throw new RuntimeException("Source form \"{$key}\" has an unknown key or a missing sha256.");
        }

        $form = SourceForm::query()->where('key', $key)->where('sha256', $sha)->first();
        if ($form !== null && ($form->notes['extraction_sha256'] ?? null) === $extractionSha) {
            $this->bump("forms.{$key}.unchanged");

            return;
        }

        $notes = ['source_notes' => array_values((array) ($source['notes'] ?? [])), 'pages' => $source['pages'] ?? null, 'extraction_sha256' => $extractionSha];
        if ($form === null) {
            SourceForm::query()->where('key', $key)->where('active', true)->update(['active' => false]);
            $form = SourceForm::query()->create([
                'key' => $key, 'version_label' => $source['version_label'] ?? null, 'title' => (string) ($source['title'] ?? $key),
                'file_name' => $source['file_name'] ?? null, 'sha256' => $sha, 'notes' => $notes, 'imported_at' => now(), 'active' => true,
            ]);
            $this->bump("forms.{$key}.new_version");
        } else {
            $form->update(['notes' => $notes, 'title' => (string) ($source['title'] ?? $form->title), 'version_label' => $source['version_label'] ?? $form->version_label]);
            $this->bump("forms.{$key}.corrected");
        }

        $seen = [];
        foreach ((array) ($document['sections'] ?? []) as $section) {
            foreach ((array) ($section['items'] ?? []) as $item) {
                $itemKey = self::reference((array) $item, 'item') ?? '';
                if ($itemKey === '' || isset($seen[$itemKey])) {
                    throw new RuntimeException("The {$key} source has ".($itemKey === '' ? 'an item without an item reference' : "the item \"{$itemKey}\" more than once").'. Fix the extraction and import again; nothing was changed.');
                }
                $seen[$itemKey] = true;
            }
        }
        foreach ((array) ($document['sections'] ?? []) as $section) {
            $sectionSort = (int) ($section['sort'] ?? 0);
            foreach (array_values((array) ($section['items'] ?? [])) as $index => $item) {
                $this->upsertFormItem($form, (array) $section, (array) $item, $sectionSort * 1000 + $index);
            }
        }
        // Items an older extraction had but this one lacks are flagged, never deleted (requests may reference them).
        foreach (SourceFormItem::query()->where('source_form_id', $form->getKey())->whereNotIn('item_key', array_keys($seen))->get() as $absent) {
            if ($absent->review_reason !== self::ABSENT_REASON) {
                $absent->update(['needs_review' => true, 'review_reason' => self::ABSENT_REASON]);
                $this->bump('form_items.flagged_absent');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $section
     * @param  array<string, mixed>  $item
     */
    private function upsertFormItem(SourceForm $form, array $section, array $item, int $sort): void
    {
        $flags = (array) ($item['flags'] ?? []);
        $normalizedFlags = [
            'detail_airway_bag_only' => (bool) ($flags['detail_airway_bag_only'] ?? false),
            'rescue_only' => (bool) ($flags['rescue_only'] ?? false),
            'refrigerated' => (bool) ($flags['refrigerated'] ?? false),
            'controlled_substance' => (bool) ($flags['controlled_substance'] ?? false),
        ];
        // The transport vehicle sheet also prints a "Fill if <" level next to the AMOUNT; the bag forms do not.
        if (isset($flags['fill_if_text']) && trim((string) $flags['fill_if_text']) !== '') {
            $normalizedFlags['fill_if_text'] = trim((string) $flags['fill_if_text']);
            $normalizedFlags['fill_if_qty'] = isset($flags['fill_if_qty']) ? (int) $flags['fill_if_qty'] : null;
        }
        $catalogRef = self::reference($item, 'catalog');
        $catalog = $catalogRef !== null
            ? $this->catalogItem($catalogRef, (string) $item['label'], (string) ($section['label'] ?? ''), $normalizedFlags, null)
            : null;

        $model = SourceFormItem::query()->firstOrNew(['source_form_id' => $form->getKey(), 'item_key' => (string) self::reference($item, 'item')]);
        $model->fill([
            'catalog_item_id' => $catalog?->getKey(),
            'section_key' => (string) $section['key'],
            'section_label' => (string) $section['label'],
            'parent_section_key' => $section['parent_key'] ?? null,
            'label' => (string) $item['label'],
            'source_label' => (string) ($item['source_label'] ?? $item['label']),
            'min_text' => $item['min_text'] ?? null,
            'min_qty' => isset($item['min_qty']) ? (int) $item['min_qty'] : null,
            'min_unit' => $item['min_unit'] ?? null,
            'min_applies_to' => $item['min_applies_to'] ?? null,
            'variant_group' => $item['variant_group'] ?? null,
            'variant_label' => $item['variant_label'] ?? null,
            'response_type' => (string) ($item['response_type'] ?? 'quantity'),
            'flags' => $normalizedFlags,
            'notes' => $item['notes'] ?? null,
            'needs_review' => (bool) ($item['needs_review'] ?? false),
            'review_reason' => $item['review_reason'] ?? null,
            'source_ref' => $item['source_ref'] ?? null,
            'sort' => $sort,
        ]);
        $created = ! $model->exists;
        if ($created || $model->isDirty()) {
            $model->save();
            $this->bump($created ? 'form_items.created' : 'form_items.updated');
        }
    }

    // ----------------------------------------------------------------- supply

    /** @param array<string, mixed> $document */
    private function importSupply(array $document, string $extractionSha): void
    {
        $source = (array) ($document['source'] ?? []);
        $sha = (string) ($source['sha256'] ?? '');
        if (preg_match('/^[0-9a-f]{64}$/', $sha) !== 1) {
            throw new RuntimeException('The supply room source has no sha256.');
        }

        $form = SourceForm::query()->where('key', self::SUPPLY_KEY)->where('sha256', $sha)->first();
        if ($form !== null && ($form->notes['extraction_sha256'] ?? null) === $extractionSha) {
            $this->bump('supply_source.unchanged');

            return;
        }
        $notes = [
            'source_notes' => array_values((array) ($source['notes'] ?? [])),
            'supplier_legend' => array_values((array) ($source['supplier_legend'] ?? [])),
            'revised_label' => $source['revised_label'] ?? null,
            'printed_label' => $source['printed_label'] ?? null,
            'pages' => $source['pages'] ?? null,
            'extraction_sha256' => $extractionSha,
            'disclaimer' => 'Supplier names, prices and price dates are historical and unverified.',
        ];
        if ($form === null) {
            SourceForm::query()->where('key', self::SUPPLY_KEY)->where('active', true)->update(['active' => false]);
            SourceForm::query()->create([
                'key' => self::SUPPLY_KEY, 'version_label' => $source['revised_label'] ?? null, 'title' => (string) ($source['title'] ?? 'EMS Inventory'),
                'file_name' => $source['file_name'] ?? null, 'sha256' => $sha, 'notes' => $notes, 'imported_at' => now(), 'active' => true,
            ]);
            $this->bump('supply_source.new_version');
        } else {
            $form->update(['notes' => $notes]);
            $this->bump('supply_source.corrected');
        }

        $seen = [];
        foreach ((array) ($document['rows'] ?? []) as $row) {
            $rowKey = (string) ($row['row_key'] ?? '');
            if ($rowKey === '' || isset($seen[$rowKey])) {
                throw new RuntimeException('The supply room source has '.($rowKey === '' ? 'a row without a row_key' : "the row_key \"{$rowKey}\" more than once").'. Fix the extraction and import again; nothing was changed.');
            }
            $seen[$rowKey] = true;
        }
        $locations = $this->locations((array) ($document['rows'] ?? []));
        foreach (array_values((array) ($document['rows'] ?? [])) as $index => $row) {
            $this->upsertSupplyRow((array) $row, $locations, (string) $sha);
        }
        // Rows an older extraction had but this one lacks are flagged for review, never deleted.
        foreach (SupplyItem::query()->whereNotNull('source_row_key')->whereNotIn('source_row_key', array_keys($seen))->get() as $absent) {
            $reasons = array_values(array_unique([...(array) ($absent->review_reasons ?? []), self::ABSENT_REASON]));
            if (! $absent->needs_review || $reasons !== (array) $absent->review_reasons) {
                $absent->update(['needs_review' => true, 'review_reasons' => $reasons]);
                $this->bump('supply_rows.flagged_absent');
            }
        }
        $this->report['supply_rows.needs_review'] = SupplyItem::query()->where('needs_review', true)->count();
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, StorageLocation>
     */
    private function locations(array $rows): array
    {
        $map = [];
        foreach ($rows as $row) {
            $code = trim((string) ($row['location_code'] ?? ''));
            if ($code === '' || isset($map[$code])) {
                continue;
            }
            $location = StorageLocation::query()->firstOrCreate(['code' => $code], ['label' => $code, 'sort' => (count($map) + 1) * 10, 'active' => true]);
            if ($location->wasRecentlyCreated) {
                $this->bump('locations.created');
            }
            $map[$code] = $location;
        }

        return $map;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, StorageLocation>  $locations
     */
    private function upsertSupplyRow(array $row, array $locations, string $sourceSha): void
    {
        $rowKey = (string) ($row['row_key'] ?? '');
        if ($rowKey === '') {
            throw new RuntimeException('A supply room row has no row_key.');
        }
        $mapped = $this->mapper->map($row)['attributes'];
        $flags = [
            'refrigerated' => $mapped['refrigerated'], 'controlled_substance' => $mapped['controlled_substance'],
        ];
        $catalogRef = self::reference($row, 'catalog');
        $catalog = $catalogRef !== null
            ? $this->catalogItem($catalogRef, (string) $row['item_text'], null, $flags, $mapped['has_expiration'], $mapped['bag_alias_text'])
            : null;
        $code = trim((string) ($row['location_code'] ?? ''));
        $mapped['catalog_item_id'] = $catalog?->getKey();
        $mapped['location_id'] = $code !== '' ? $locations[$code]->getKey() : null;

        $item = SupplyItem::query()->firstOrNew(['source_row_key' => $rowKey]);
        if ($item->exists) {
            // Fields a PERSON edited stay as they are (audit rows written by this importer do not count).
            $edited = SupplyItemEvent::query()->where('supply_item_id', $item->getKey())
                ->where(fn ($q) => $q->whereNull('actor_name')->orWhere('actor_name', '!=', self::ACTOR))
                ->pluck('field')->unique()->all();
            $mapped = array_diff_key($mapped, array_flip(array_intersect(self::EDITABLE, $edited)));
        } else {
            $mapped['active'] = true;
        }
        $item->fill($mapped);
        $created = ! $item->exists;
        $audited = [];
        if (! $created) {
            foreach (self::AUDITED as $field) {
                if ($item->isDirty($field)) {
                    $audited[$field] = [$item->getOriginal($field), $item->getAttribute($field)];
                }
            }
        }
        if ($created || $item->isDirty()) {
            $item->save();
            $this->bump($created ? 'supply_rows.created' : 'supply_rows.updated');
            // A corrected extraction that moves a conversion or threshold leaves an audit row.
            foreach ($audited as $field => [$before, $after]) {
                SupplyItemEvent::query()->create([
                    'supply_item_id' => $item->getKey(), 'user_id' => null, 'actor_name' => self::ACTOR, 'field' => $field,
                    'before' => ['value' => $before], 'after' => ['value' => $after],
                    'reason' => 'Corrected source extraction (source sha256 '.substr($sourceSha, 0, 12).')', 'created_at' => now(),
                ]);
            }
        }
    }

    // ---------------------------------------------------------------- catalog

    /**
     * Catalog identities are created from the first source that names them and are shared only
     * when the extraction asserted the same catalog_key. Later sources add search aliases and
     * widen the safety flags (refrigerated / controlled); they never rename the item.
     *
     * @param  array<string, bool>  $flags
     */
    private function catalogItem(string $key, string $name, ?string $category, array $flags, ?bool $hasExpiration, ?string $alias = null): CatalogItem
    {
        $item = $this->catalog[$key] ??= CatalogItem::query()->firstOrNew(['key' => $key]);
        $created = ! $item->exists;
        $aliases = array_values(array_unique(array_filter([...(array) ($item->aliases ?? []), $name !== $item->name ? $name : null, $alias])));
        $item->fill([
            'name' => $item->name ?: $name,
            'category' => $item->category ?: ($category !== null && $category !== '' ? $category : null),
            'aliases' => $aliases === [] ? null : $aliases,
            'refrigerated' => (bool) $item->refrigerated || ($flags['refrigerated'] ?? false),
            'controlled_substance' => (bool) $item->controlled_substance || ($flags['controlled_substance'] ?? false),
            'has_expiration' => $item->has_expiration ?? $hasExpiration,
            'active' => $item->active ?? true,
        ]);
        if ($created || $item->isDirty()) {
            $item->save();
            if ($created) {
                $this->bump('catalog.created');
            }
        }

        return $item;
    }

    private function bump(string $counter): void
    {
        $this->report[$counter] = ($this->report[$counter] ?? 0) + 1;
    }
}
