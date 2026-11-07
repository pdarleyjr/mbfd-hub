<?php

declare(strict_types=1);

namespace App\Services\ChecklistTemplates;

use App\Enums\DailyCheckoutChecklistTemplate;
use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\DailyCheckoutChecklistTemplateEvent as Event;
use App\Models\DailyCheckoutChecklistTemplateVersion as Version;
use App\Models\User;
use App\Services\Captain5\MedicalScope;
use App\Services\DailyCheckoutChecklistResolver;
use App\Services\Inventory\ChecklistRevisionStore;
use App\Services\Inventory\EquipmentIdentity;
use App\Services\Inventory\InventoryChangePlanner;
use App\Services\Inventory\InventoryOverlay;
use App\Support\Fleet\ChecklistTemplateAccess;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use JsonException;

/**
 * The one write path for Daily Checkout checklist templates edited in the admin panel
 * (docs/checklist-template-editor.md).
 *
 * Logistics edits a draft copy of the live baseline (published version, else the repository file);
 * publishing makes it the baseline every apparatus on that checklist type resolves to. What this
 * class guarantees, so everything that already depends on a checklist keeps working:
 *
 * - Item and compartment ids never change and are never reused; positional ids are materialized at
 *   the position they already resolve to. New rows get fresh random / slug ids.
 * - Renames, moves and compartment renames leave aliases (legacyItemNames, legacyCompartmentNames)
 *   and pin the equipment identity, computed against the draft's base (what members had), so defect
 *   continuity, approved inventory changes and Captain 5 medical scope keep matching.
 * - Every key the form does not carry (inputType, snipeit_match, valueRequired, officer fields,
 *   schedules, Fire Boat 6 fields...) is preserved untouched.
 * - A publish is one transaction that snapshots every affected unit's current checklist as a
 *   recently retired revision first (in-flight checkouts stay valid), exactly like an applied
 *   inventory correction, and is refused if anything changed since the administrator's preview.
 */
final class ChecklistTemplateEditor
{
    public const MAX_IMPORT_BYTES = 2 * 1024 * 1024;

    private const ITEM_NAME_MAX = 160;

    private const COMPARTMENT_NAME_MAX = 120;

    /** @var array<string, int> optional item text field => max length */
    private const TEXT_LIMITS = ['manufacturer' => 120, 'model' => 120, 'description' => 500, 'note' => 500];

    /** @var array<string, string> item field => label shown in the diff */
    private const FIELD_LABELS = [
        'name' => 'Name',
        'expectedQuantity' => 'Quantity',
        'manufacturer' => 'Brand / manufacturer',
        'model' => 'Model',
        'description' => 'Description',
        'note' => 'Internal note',
        'compartment' => 'Location',
    ];

    /** @var array<string, string> overlay conflict reason => plain explanation */
    private const CONFLICT_REASONS = [
        'item_not_found' => 'its item is no longer on the checklist',
        'identity_mismatch' => 'its item id now names different equipment',
        'destination_missing' => 'the compartment it moves the item to is gone',
        'invalid_result' => 'it would leave two items with the same name in one compartment',
        'item_exists' => 'the item it adds already exists',
    ];

    public function __construct(
        private readonly DailyCheckoutChecklistResolver $resolver,
        private readonly InventoryChangePlanner $planner,
        private readonly ChecklistRevisionStore $revisions,
    ) {}

    // ------------------------------------------------------------------ reading

    /**
     * Checklist types that can be edited: every configured Daily Checkout template.
     *
     * @return list<string>
     */
    public function types(): array
    {
        $types = [];
        foreach (DailyCheckoutChecklistTemplate::cases() as $template) {
            if ($template->isConfigured()) {
                $types[] = $template->value;
            }
        }

        return $types;
    }

    public function label(string $type): string
    {
        return DailyCheckoutChecklistTemplate::options()[$type] ?? Str::headline($type);
    }

    /**
     * @return list<array{
     *     type: string,
     *     label: string,
     *     source: 'file'|'published',
     *     schema_version: int,
     *     published: array{id: int, version_number: int, published_at: CarbonInterface|null, published_by: string|null, change_summary: string|null}|null,
     *     draft: array{id: int, updated_at: CarbonInterface, updated_by: string|null, has_changes: bool}|null,
     *     compartment_count: int,
     *     item_count: int,
     *     units: list<array{id: int, label: string, daily_checkout: bool}>,
     *     file_drift: bool,
     *     file_error: string|null
     * }>
     */
    public function overview(): array
    {
        $usage = [];
        foreach ($this->planner->checklistUsage() as $entry) {
            $usage[$entry['type']] = $entry['units'];
        }

        $rows = [];
        foreach ($this->types() as $type) {
            $baseline = $this->repository()->baseline($type, $this->directory());
            $published = $this->repository()->published($type);
            $draft = $this->draft($type);
            $file = $this->repository()->fileTemplate($type, $this->directory());
            $fileHash = $this->fileHash($file);
            $checklist = is_array($baseline['checklist']) ? $baseline['checklist'] : [];

            $fileError = $file['error'];
            if ($fileError === null && is_array($file['checklist']) && ! $this->resolver->isSubmittable($file['checklist'])) {
                $fileError = 'invalid_schema';
            }

            $liveHash = null;
            if ($baseline['error'] === null && $checklist !== []) {
                $liveHash = $this->resolver->versionOf(self::materialize($checklist));
            }

            $rows[] = [
                'type' => $type,
                'label' => $this->label($type),
                'source' => $baseline['source'],
                'schema_version' => is_int($checklist['schema_version'] ?? null) ? $checklist['schema_version'] : 1,
                'published' => $published === null ? null : [
                    'id' => (int) $published->getKey(),
                    'version_number' => (int) $published->version_number,
                    'published_at' => $published->published_at,
                    'published_by' => $this->userName($published->publishedBy),
                    'change_summary' => $published->change_summary,
                ],
                'draft' => $draft === null ? null : [
                    'id' => (int) $draft->getKey(),
                    'updated_at' => $draft->updated_at ?? $draft->created_at ?? now(),
                    'updated_by' => $this->userName($draft->updatedBy),
                    'has_changes' => $liveHash === null || ! hash_equals($liveHash, $this->resolver->versionOf(self::checklistOf($draft))),
                ],
                'compartment_count' => is_array($checklist['compartments'] ?? null) ? count($checklist['compartments']) : 0,
                'item_count' => $this->itemCount($checklist),
                'units' => $usage[$type] ?? [],
                // The file changed since the version was drafted, unless it now equals the published
                // version itself (checklist-templates:export --write committed back).
                'file_drift' => $published !== null && ($fileHash === null
                    || (($published->source_file_hash === null || ! hash_equals($published->source_file_hash, $fileHash))
                        && ! hash_equals((string) $published->checklist_hash, $fileHash))),
                'file_error' => $fileError,
            ];
        }

        return $rows;
    }

    /**
     * The baseline every apparatus on the type resolves to right now (before its inventory overlay).
     *
     * @return array{checklist: array<string, mixed>, hash: string, source: 'file'|'published', version: Version|null}
     */
    public function live(string $type): array
    {
        $this->assertType($type);
        $baseline = $this->repository()->baseline($type, $this->directory());
        if ($baseline['error'] !== null || ! is_array($baseline['checklist'])) {
            throw new ChecklistTemplateException(sprintf(
                'The %s cannot be read (%s). Fix the repository file before editing it.',
                mb_strtolower($this->label($type)),
                str_replace('_', ' ', (string) $baseline['error']),
            ));
        }
        if (! $this->resolver->isSubmittable($baseline['checklist'])) {
            throw new ChecklistTemplateException(sprintf(
                'The live %s does not meet the Daily Checkout format. Fix it before editing.',
                mb_strtolower($this->label($type)),
            ));
        }

        return [
            'checklist' => $baseline['checklist'],
            'hash' => $this->resolver->versionOf($baseline['checklist']),
            'source' => $baseline['source'],
            // The very row baseline() read (a second read could see a different one inside a transaction).
            'version' => $baseline['source'] === 'published' && $baseline['version_id'] !== null ? Version::query()->find($baseline['version_id']) : null,
        ];
    }

    public function draft(string $type): ?Version
    {
        return Version::query()
            ->where('checklist_type', $type)
            ->where('status', Version::STATUS_DRAFT)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Published and retired versions, newest first.
     *
     * @return Collection<int, Version>
     */
    public function history(string $type): Collection
    {
        return Version::query()
            ->where('checklist_type', $type)
            ->whereIn('status', [Version::STATUS_PUBLISHED, Version::STATUS_RETIRED])
            ->with('publishedBy')
            ->orderByDesc('version_number')
            ->orderByDesc('id')
            ->get();
    }

    /** @return Collection<int, Event> */
    public function events(string $type, int $limit = 50): Collection
    {
        return Event::query()
            ->where('checklist_type', $type)
            ->with('user')
            ->orderByDesc('id')
            ->limit(max(1, $limit))
            ->get();
    }

    /**
     * The editable view of a checklist: compartments in order with their items. Positional ids are
     * shown materialized (the id they already resolve to).
     *
     * @param  array<string, mixed>  $checklist
     * @return array{compartments: list<array{id: string, name: string, items: list<array{id: string, name: string, expectedQuantity: int|null, manufacturer: string|null, model: string|null, description: string|null, note: string|null, compartment_id: string}>}>}
     */
    public function toForm(array $checklist): array
    {
        $compartments = [];
        foreach (self::compartmentsOf(self::materialize($checklist)) as $compartment) {
            $compartmentId = (string) ($compartment['id'] ?? '');
            $items = [];
            foreach (self::itemsOf($compartment) as $item) {
                $items[] = [
                    'id' => (string) ($item['id'] ?? ''),
                    'name' => (string) ($item['name'] ?? ''),
                    'expectedQuantity' => is_int($item['expectedQuantity'] ?? null) ? $item['expectedQuantity'] : null,
                    'manufacturer' => self::optionalString($item['manufacturer'] ?? null),
                    'model' => self::optionalString($item['model'] ?? null),
                    'description' => self::optionalString($item['description'] ?? null),
                    'note' => self::optionalString($item['note'] ?? null),
                    'compartment_id' => $compartmentId,
                ];
            }
            $compartments[] = ['id' => $compartmentId, 'name' => InventoryOverlay::compartmentName($compartment), 'items' => $items];
        }

        return ['compartments' => $compartments];
    }

    /**
     * Everything that keeps a checklist from being published, in plain English.
     *
     * @param  array<string, mixed>  $checklist
     * @return list<string>
     */
    public function validationErrors(array $checklist): array
    {
        $errors = $this->ruleViolations($checklist, allowEmptyCompartments: false);
        if (! $this->resolver->isSubmittable($checklist)) {
            $errors[] = 'The checklist does not meet the Daily Checkout format: every compartment needs an id, a unique name and at least one item, and item names must be unique within a compartment.';
        }

        return array_values(array_unique($errors));
    }

    /**
     * What changed between two checklists, by item and compartment id. Moving an item inside its
     * compartment is not an item change (reported as `items_reordered`).
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array{
     *     compartments_added: list<array{id: string, name: string}>,
     *     compartments_removed: list<array{id: string, name: string}>,
     *     compartments_renamed: list<array{id: string, from: string, to: string}>,
     *     compartments_reordered: bool,
     *     items_reordered: bool,
     *     items_added: list<array{id: string, name: string, compartment: string}>,
     *     items_removed: list<array{id: string, name: string, compartment: string}>,
     *     items_changed: list<array{id: string, name: string, compartment: string, changes: list<array{field: string, label: string, from: string|null, to: string|null}>}>,
     *     counts: array{added: int, removed: int, changed: int, compartments_changed: int},
     *     has_changes: bool
     * }
     */
    public function diff(array $before, array $after): array
    {
        $beforeCompartments = $this->compartmentIndex(self::materialize($before));
        $afterCompartments = $this->compartmentIndex(self::materialize($after));
        $beforeItems = $this->itemIndex(self::materialize($before));
        $afterItems = $this->itemIndex(self::materialize($after));

        $compartmentsAdded = $compartmentsRemoved = $compartmentsRenamed = [];
        foreach ($afterCompartments as $id => $compartment) {
            if (! isset($beforeCompartments[$id])) {
                $compartmentsAdded[] = ['id' => (string) $id, 'name' => $compartment['name']];
            } elseif ($beforeCompartments[$id]['name'] !== $compartment['name']) {
                $compartmentsRenamed[] = ['id' => (string) $id, 'from' => $beforeCompartments[$id]['name'], 'to' => $compartment['name']];
            }
        }
        foreach ($beforeCompartments as $id => $compartment) {
            if (! isset($afterCompartments[$id])) {
                $compartmentsRemoved[] = ['id' => (string) $id, 'name' => $compartment['name']];
            }
        }
        $commonBefore = array_values(array_filter(array_map('strval', array_keys($beforeCompartments)), static fn (string $id): bool => isset($afterCompartments[$id])));
        $commonAfter = array_values(array_filter(array_map('strval', array_keys($afterCompartments)), static fn (string $id): bool => isset($beforeCompartments[$id])));

        $itemsAdded = $itemsRemoved = $itemsChanged = [];
        $itemsReordered = false;
        foreach ($afterItems as $id => $entry) {
            if (! isset($beforeItems[$id])) {
                $itemsAdded[] = ['id' => (string) $id, 'name' => (string) ($entry['item']['name'] ?? ''), 'compartment' => $entry['compartment_name']];

                continue;
            }
            $old = $beforeItems[$id];
            $changes = [];
            foreach (['name', 'expectedQuantity', 'manufacturer', 'model', 'description', 'note'] as $field) {
                $from = self::displayValue($old['item'][$field] ?? null);
                $to = self::displayValue($entry['item'][$field] ?? null);
                if ($from !== $to) {
                    $changes[] = ['field' => $field, 'label' => self::FIELD_LABELS[$field], 'from' => $from, 'to' => $to];
                }
            }
            if ($old['compartment_id'] !== $entry['compartment_id']) {
                $changes[] = ['field' => 'compartment', 'label' => self::FIELD_LABELS['compartment'], 'from' => $old['compartment_name'], 'to' => $entry['compartment_name']];
            }
            if ($changes !== []) {
                $itemsChanged[] = ['id' => (string) $id, 'name' => (string) ($entry['item']['name'] ?? ''), 'compartment' => $entry['compartment_name'], 'changes' => $changes];
            }
        }
        foreach ($beforeItems as $id => $entry) {
            if (! isset($afterItems[$id])) {
                $itemsRemoved[] = ['id' => (string) $id, 'name' => (string) ($entry['item']['name'] ?? ''), 'compartment' => $entry['compartment_name']];
            }
        }
        foreach ($afterCompartments as $id => $compartment) {
            if (! isset($beforeCompartments[$id])) {
                continue;
            }
            $stayed = static fn (array $ids, array $other): array => array_values(array_filter($ids, static fn (string $itemId): bool => in_array($itemId, $other, true)));
            if ($stayed($beforeCompartments[$id]['item_ids'], $compartment['item_ids']) !== $stayed($compartment['item_ids'], $beforeCompartments[$id]['item_ids'])) {
                $itemsReordered = true;
            }
        }

        $compartmentsReordered = $commonBefore !== $commonAfter;
        $counts = [
            'added' => count($itemsAdded),
            'removed' => count($itemsRemoved),
            'changed' => count($itemsChanged),
            'compartments_changed' => count($compartmentsAdded) + count($compartmentsRemoved) + count($compartmentsRenamed),
        ];

        return [
            'compartments_added' => $compartmentsAdded,
            'compartments_removed' => $compartmentsRemoved,
            'compartments_renamed' => $compartmentsRenamed,
            'compartments_reordered' => $compartmentsReordered,
            'items_reordered' => $itemsReordered,
            'items_added' => $itemsAdded,
            'items_removed' => $itemsRemoved,
            'items_changed' => $itemsChanged,
            'counts' => $counts,
            'has_changes' => array_sum($counts) > 0 || $compartmentsReordered || $itemsReordered,
        ];
    }

    /**
     * What publishing the draft would do to every apparatus on its checklist type.
     *
     * @return array{
     *     diff: array<string, mixed>,
     *     units: list<array{id: int, label: string, daily_checkout: bool, version_before: string|null, version_after: string|null, conflicts: list<array{change_id: int, reason: string}>, open_issues_on_removed_items: int}>,
     *     errors: list<string>,
     *     warnings: list<string>,
     *     fingerprint: string,
     *     publishable: bool
     * }
     */
    public function preview(Version $draft): array
    {
        return $this->draftImpact($draft, $this->unitsFor((string) $draft->checklist_type, lock: false));
    }

    /**
     * What reverting the type to its repository file would do (same shape as preview()).
     *
     * @return array{
     *     diff: array<string, mixed>,
     *     units: list<array{id: int, label: string, daily_checkout: bool, version_before: string|null, version_after: string|null, conflicts: list<array{change_id: int, reason: string}>, open_issues_on_removed_items: int}>,
     *     errors: list<string>,
     *     warnings: list<string>,
     *     fingerprint: string,
     *     publishable: bool
     * }
     */
    public function previewRevertToFile(string $type): array
    {
        $this->assertType($type);

        return $this->revertImpact($type, $this->unitsFor($type, lock: false));
    }

    public function exportJson(array $checklist): string
    {
        return json_encode($checklist, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
    }

    // ------------------------------------------------------------------ drafts

    /**
     * The open draft of the type, started from the live baseline when there is none.
     */
    public function startDraft(string $type, User $user): Version
    {
        $this->authorize($user);
        $this->assertType($type);
        $existing = $this->draft($type);
        if ($existing !== null) {
            return $existing;
        }

        $live = $this->live($type);
        $checklist = self::materialize($live['checklist']);
        $fileHash = $this->fileHash($this->repository()->fileTemplate($type, $this->directory()));

        try {
            return DB::transaction(function () use ($type, $user, $live, $checklist, $fileHash): Version {
                $draft = Version::query()->create([
                    'checklist_type' => $type,
                    'status' => Version::STATUS_DRAFT,
                    'checklist' => $checklist,
                    'base_checklist' => $checklist,
                    'checklist_hash' => $this->resolver->versionOf($checklist),
                    'based_on_hash' => $live['hash'],
                    'based_on_version_id' => $live['version']?->getKey(),
                    'source_file_hash' => $fileHash,
                    'lock_version' => 0,
                    'created_by_user_id' => $user->getKey(),
                    'updated_by_user_id' => $user->getKey(),
                ]);
                $this->event($type, $draft, $user, Event::DRAFT_STARTED, [
                    'source' => $live['source'],
                    'based_on_hash' => $live['hash'],
                    'based_on_version_id' => $live['version']?->getKey(),
                ]);

                return $draft;
            });
        } catch (UniqueConstraintViolationException) {
            // Someone else started the draft a moment ago: everyone edits the one draft.
            return $this->draft($type) ?? throw new ChecklistTemplateException('The draft could not be started. Reload and try again.');
        }
    }

    /**
     * Saves the editor form onto the draft (see toForm() for the shape). Rows with an empty or unknown
     * id are new; every key the form does not carry is kept from the draft.
     *
     * @param  array<string, mixed>  $form
     *
     * @throws ValidationException with keys like `compartments.0.items.3.name`
     */
    public function saveDraft(Version $draft, array $form, User $user, int $expectedLockVersion): Version
    {
        $this->authorize($user);
        $this->assertType((string) $draft->checklist_type);

        return DB::transaction(function () use ($draft, $form, $user, $expectedLockVersion): Version {
            $locked = $this->lockDraft($draft, $expectedLockVersion);
            $previous = self::checklistOf($locked);
            $checklist = $this->checklistFromForm($locked, $form);
            $this->storeDraft($locked, $checklist, $user);
            $this->event((string) $locked->checklist_type, $locked, $user, Event::DRAFT_SAVED, [
                'counts' => $this->diff($previous, $checklist)['counts'],
                'lock_version' => $locked->lock_version,
            ]);

            return $locked->fresh() ?? $locked;
        });
    }

    public function discardDraft(Version $draft, User $user): void
    {
        $this->authorize($user);
        $this->assertType((string) $draft->checklist_type);

        DB::transaction(function () use ($draft, $user): void {
            $locked = Version::query()->whereKey($draft->getKey())->lockForUpdate()->first();
            if ($locked === null || $locked->status !== Version::STATUS_DRAFT) {
                throw new ChecklistTemplateException('This draft was already published or discarded. Reload the page.');
            }
            $locked->forceFill([
                'status' => Version::STATUS_DISCARDED,
                'discarded_at' => now(),
                'updated_by_user_id' => $user->getKey(),
            ])->save();
            $this->event((string) $locked->checklist_type, $locked, $user, Event::DRAFT_DISCARDED, [
                'counts' => $this->diff(is_array($locked->base_checklist) ? $locked->base_checklist : [], self::checklistOf($locked))['counts'],
            ]);
        });
    }

    /**
     * Replaces the draft's compartments and items with an uploaded JSON template. Officer fields,
     * schedules and every other top-level key must equal the live checklist: they are managed in the
     * repository, not here.
     *
     * @throws ValidationException with key `import`
     */
    public function importIntoDraft(string $type, string $json, User $user): Version
    {
        $this->authorize($user);
        $this->assertType($type);
        if (strlen($json) > self::MAX_IMPORT_BYTES) {
            throw ValidationException::withMessages(['import' => 'The file is larger than 2 MB.']);
        }
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw ValidationException::withMessages(['import' => 'The file is not valid JSON.']);
        }
        if (! is_array($decoded) || array_is_list($decoded) || ! is_array($decoded['compartments'] ?? null) || ! array_is_list($decoded['compartments'])) {
            throw ValidationException::withMessages(['import' => 'The file must be a checklist template: a JSON object with a "compartments" list.']);
        }

        $live = $this->live($type)['checklist'];
        foreach (array_unique([...array_keys($live), ...array_keys($decoded)]) as $key) {
            if ($key === 'compartments') {
                continue;
            }
            if (array_key_exists($key, $live) !== array_key_exists($key, $decoded)
                || $this->canonicalHash($live[$key] ?? null) !== $this->canonicalHash($decoded[$key] ?? null)) {
                throw ValidationException::withMessages(['import' => 'Import may only change compartments and items; officer fields and schedules are managed in the repository.']);
            }
        }

        $draft = $this->startDraft($type, $user);

        return DB::transaction(function () use ($draft, $decoded, $user): Version {
            $locked = $this->lockDraft($draft, null);
            $previous = self::checklistOf($locked);
            $checklist = $this->checklistFromImport($locked, $decoded['compartments']);
            $this->storeDraft($locked, $checklist, $user);
            $this->event((string) $locked->checklist_type, $locked, $user, Event::DRAFT_IMPORTED, [
                'counts' => $this->diff($previous, $checklist)['counts'],
                'lock_version' => $locked->lock_version,
            ]);

            return $locked->fresh() ?? $locked;
        });
    }

    /**
     * Copies a published or retired version's compartments and items into the draft (created when
     * there is none). The draft's base stays the live baseline: it is published only through the
     * normal review, and renames since that version get their aliases like any other edit.
     */
    public function restoreAsDraft(Version $version, User $user): Version
    {
        $this->authorize($user);
        $type = (string) $version->checklist_type;
        $this->assertType($type);
        if (! in_array($version->status, [Version::STATUS_PUBLISHED, Version::STATUS_RETIRED], true)) {
            throw new ChecklistTemplateException('Only a published version can be restored.');
        }

        $draft = $this->startDraft($type, $user);

        return DB::transaction(function () use ($draft, $version, $user): Version {
            $locked = $this->lockDraft($draft, null);
            $previous = self::checklistOf($locked);
            $restored = self::materialize(self::checklistOf($version));
            $checklist = self::checklistOf($locked);
            $checklist['compartments'] = $restored['compartments'] ?? [];
            $checklist = $this->withAliases($checklist, $this->baseOf($locked));
            $this->storeDraft($locked, $checklist, $user);
            $this->event((string) $locked->checklist_type, $locked, $user, Event::DRAFT_RESTORED, [
                'restored_version_id' => (int) $version->getKey(),
                'restored_version_number' => $version->version_number,
                'counts' => $this->diff($previous, $checklist)['counts'],
                'lock_version' => $locked->lock_version,
            ]);

            return $locked->fresh() ?? $locked;
        });
    }

    // ------------------------------------------------------------------ publishing

    /**
     * Publishes the draft (docs/checklist-template-editor.md section 5): one transaction that
     * re-checks everything the administrator previewed, snapshots each affected unit's current
     * checklist as recently retired, swaps the baseline and verifies every unit still resolves.
     */
    public function publish(Version $draft, User $user, string $summary, string $fingerprint, int $expectedLockVersion): Version
    {
        $this->authorize($user);
        $type = (string) $draft->checklist_type;
        $this->assertType($type);
        $summary = $this->summary($summary);

        try {
            return DB::transaction(function () use ($draft, $type, $user, $summary, $fingerprint, $expectedLockVersion): Version {
                $locked = $this->lockDraft($draft, $expectedLockVersion);
                $previous = Version::query()
                    ->where('checklist_type', $type)
                    ->where('status', Version::STATUS_PUBLISHED)
                    ->lockForUpdate()
                    ->first();
                $this->repository()->flush();

                $live = $this->live($type);
                if (! hash_equals((string) $locked->based_on_hash, $live['hash'])) {
                    throw new ChecklistTemplateException('The live checklist changed since this draft was started (someone else published, or the repository file changed). Discard this draft and start a new one.');
                }

                $units = $this->unitsFor($type, lock: true);
                $impact = $this->draftImpact($locked, $units);
                if (! hash_equals($impact['fingerprint'], $fingerprint)) {
                    throw new ChecklistTemplateException('The checklist or its vehicles changed since you previewed. Review the changes again.');
                }
                if (! $impact['publishable']) {
                    throw ValidationException::withMessages(['checklist' => $impact['errors'] !== [] ? $impact['errors'] : ['There is nothing to publish.']]);
                }

                $results = $this->swapBaseline($units, function () use ($locked, $previous, $user, $summary, $type): void {
                    if ($previous !== null) {
                        $previous->forceFill([
                            'status' => Version::STATUS_RETIRED,
                            'retired_at' => now(),
                            'retired_by_user_id' => $user->getKey(),
                        ])->save();
                    }
                    $number = (int) Version::query()->where('checklist_type', $type)->max('version_number') + 1;
                    $locked->forceFill([
                        'status' => Version::STATUS_PUBLISHED,
                        'version_number' => $number,
                        'checklist_hash' => $this->resolver->versionOf(self::checklistOf($locked)),
                        'change_summary' => $summary,
                        'published_by_user_id' => $user->getKey(),
                        'published_at' => now(),
                        'updated_by_user_id' => $user->getKey(),
                    ])->save();
                });

                $this->event($type, $locked, $user, Event::PUBLISHED, [
                    'version_number' => $locked->version_number,
                    'summary' => $summary,
                    'previous_version_id' => $previous?->getKey(),
                    'counts' => $impact['diff']['counts'],
                    'units' => $results,
                ]);

                return $locked->fresh() ?? $locked;
            });
        } finally {
            // Committed or rolled back, nobody may keep reading a memoized baseline from inside it.
            $this->repository()->flush();
        }
    }

    /**
     * Retires the published version so the type resolves to its repository file again (same
     * protocol as publish()).
     */
    public function revertToFile(string $type, User $user, string $summary, string $fingerprint): void
    {
        $this->authorize($user);
        $this->assertType($type);
        $summary = $this->summary($summary);

        try {
            DB::transaction(function () use ($type, $user, $summary, $fingerprint): void {
                $published = Version::query()
                    ->where('checklist_type', $type)
                    ->where('status', Version::STATUS_PUBLISHED)
                    ->lockForUpdate()
                    ->first();
                if ($published === null) {
                    throw new ChecklistTemplateException('This checklist already uses the repository file.');
                }
                $this->repository()->flush();

                $units = $this->unitsFor($type, lock: true);
                $impact = $this->revertImpact($type, $units);
                if (! hash_equals($impact['fingerprint'], $fingerprint)) {
                    throw new ChecklistTemplateException('The checklist or its vehicles changed since you previewed. Review the changes again.');
                }
                if (! $impact['publishable']) {
                    throw ValidationException::withMessages(['checklist' => $impact['errors'] !== [] ? $impact['errors'] : ['The repository file cannot be used.']]);
                }

                $results = $this->swapBaseline($units, static function () use ($published, $user): void {
                    $published->forceFill([
                        'status' => Version::STATUS_RETIRED,
                        'retired_at' => now(),
                        'retired_by_user_id' => $user->getKey(),
                    ])->save();
                });

                $this->event($type, $published, $user, Event::REVERTED_TO_FILE, [
                    'summary' => $summary,
                    'retired_version_number' => $published->version_number,
                    'counts' => $impact['diff']['counts'],
                    'units' => $results,
                ]);
            });
        } finally {
            $this->repository()->flush();
        }
    }

    // ------------------------------------------------------------------ impact

    /**
     * @param  Collection<int, Apparatus>  $units
     * @return array{diff: array<string, mixed>, units: list<array<string, mixed>>, errors: list<string>, warnings: list<string>, fingerprint: string, publishable: bool}
     */
    private function draftImpact(Version $draft, Collection $units): array
    {
        $type = (string) $draft->checklist_type;
        $checklist = self::checklistOf($draft);
        $errors = $warnings = [];
        $blocked = false;

        if ($draft->status !== Version::STATUS_DRAFT) {
            $errors[] = 'This draft was already published or discarded. Reload the page.';
        }

        $live = null;
        try {
            $live = $this->live($type);
        } catch (ChecklistTemplateException $exception) {
            $errors[] = $exception->getMessage();
        }
        $diff = $this->diff($live['checklist'] ?? ['compartments' => []], $checklist);

        if ($live !== null && ! hash_equals((string) $draft->based_on_hash, $live['hash'])) {
            $message = 'The live checklist changed since this draft was started (someone else published, or the repository file changed). Discard this draft and start a new one.';
            $warnings[] = $message;
            $errors[] = $message;
        }
        if ($live !== null && hash_equals($this->resolver->versionOf(self::materialize($live['checklist'])), $this->resolver->versionOf($checklist))) {
            $warnings[] = 'The draft is the same as the live checklist. There is nothing to publish.';
            $blocked = true;
        } elseif ($live !== null && ! $diff['has_changes']) {
            $warnings[] = 'Only hidden details changed (aliases or matching keys); members will see no difference.';
        }

        foreach ($this->validationErrors($checklist) as $error) {
            $errors[] = $error;
        }

        $impact = $this->unitImpact($units, $this->resolver->isSubmittable($checklist) ? $checklist : null, $diff['items_removed']);
        array_push($errors, ...$impact['errors']);
        array_push($warnings, ...$impact['warnings']);

        if (in_array($type, MedicalScope::MEDICAL_ONLY_TYPES, true) && $diff['items_added'] !== []) {
            $count = count($diff['items_added']);
            $warnings[] = sprintf(
                '%d new %s will appear in the Captain 5 medical scope review as unclassified until an administrator decides whether %s medical.',
                $count,
                $count === 1 ? 'item' : 'items',
                $count === 1 ? 'it is' : 'they are',
            );
        }

        $fingerprint = $this->fingerprint($this->resolver->versionOf($checklist), $impact['units']);
        $errors = array_values(array_unique($errors));

        return [
            'diff' => $diff,
            'units' => $impact['units'],
            'errors' => $errors,
            'warnings' => array_values(array_unique($warnings)),
            'fingerprint' => $fingerprint,
            'publishable' => $errors === [] && ! $blocked,
        ];
    }

    /**
     * @param  Collection<int, Apparatus>  $units
     * @return array{diff: array<string, mixed>, units: list<array<string, mixed>>, errors: list<string>, warnings: list<string>, fingerprint: string, publishable: bool}
     */
    private function revertImpact(string $type, Collection $units): array
    {
        $errors = $warnings = [];
        $published = $this->repository()->published($type);
        if ($published === null) {
            $errors[] = 'This checklist already uses the repository file.';
        }

        $file = $this->repository()->fileTemplate($type, $this->directory());
        $target = $file['checklist'];
        if ($file['error'] !== null || ! is_array($target)) {
            $errors[] = sprintf('The repository file cannot be used (%s).', str_replace('_', ' ', (string) ($file['error'] ?? 'invalid_json')));
            $target = null;
        } elseif (! $this->resolver->isSubmittable($target)) {
            $errors[] = 'The repository file does not meet the Daily Checkout format.';
            $target = null;
        }

        $live = null;
        try {
            $live = $this->live($type);
        } catch (ChecklistTemplateException $exception) {
            $errors[] = $exception->getMessage();
        }
        $diff = $this->diff($live['checklist'] ?? ['compartments' => []], $target ?? ['compartments' => []]);

        $impact = $this->unitImpact($units, $target, $diff['items_removed']);
        array_push($errors, ...$impact['errors']);
        array_push($warnings, ...$impact['warnings']);
        $errors = array_values(array_unique($errors));

        return [
            'diff' => $diff,
            'units' => $impact['units'],
            'errors' => $errors,
            'warnings' => array_values(array_unique($warnings)),
            'fingerprint' => $this->fingerprint($target === null ? '' : $this->resolver->versionOf($target), $impact['units']),
            'publishable' => $errors === [],
        ];
    }

    /**
     * Per apparatus: the checklist_version it has now and would have with `$target` as its baseline,
     * the approved inventory changes that would stop applying, and open issues on removed items.
     *
     * @param  Collection<int, Apparatus>  $units
     * @param  array<string, mixed>|null  $target  null when the target is not usable
     * @param  list<array{id: string, name: string, compartment: string}>  $removedItems
     * @return array{units: list<array{id: int, label: string, daily_checkout: bool, version_before: string|null, version_after: string|null, conflicts: list<array{change_id: int, reason: string}>, open_issues_on_removed_items: int}>, errors: list<string>, warnings: list<string>}
     */
    private function unitImpact(Collection $units, ?array $target, array $removedItems): array
    {
        $rows = $errors = $warnings = [];
        $openIssues = 0;
        foreach ($units as $apparatus) {
            $label = self::unitLabel($apparatus);
            $before = $this->resolver->resolve($apparatus);
            $after = $target === null ? null : $this->resolver->resolveWithTemplate($apparatus, $target);

            $known = [];
            foreach ($before['overlay_conflicts'] as $conflict) {
                $known[$conflict['change_id'].'|'.$conflict['reason']] = true;
            }
            $conflicts = [];
            foreach ($after['overlay_conflicts'] ?? [] as $conflict) {
                if (! isset($known[$conflict['change_id'].'|'.$conflict['reason']])) {
                    $conflicts[] = ['change_id' => (int) $conflict['change_id'], 'reason' => (string) $conflict['reason']];
                    $warnings[] = sprintf(
                        '%s: approved inventory change #%d would no longer apply (%s).',
                        $label,
                        $conflict['change_id'],
                        self::CONFLICT_REASONS[$conflict['reason']] ?? str_replace('_', ' ', (string) $conflict['reason']),
                    );
                }
            }
            if ($after !== null && $after['usable'] !== true) {
                $errors[] = sprintf('Publishing would leave %s without a usable checklist (%s).', $label, str_replace('_', ' ', (string) $after['error']));
            }

            $open = $this->openIssuesOnRemovedItems($apparatus, $removedItems);
            $openIssues += $open;
            $rows[] = [
                'id' => (int) $apparatus->getKey(),
                'label' => $label,
                'daily_checkout' => $apparatus->isDailyCheckoutRequired(),
                'version_before' => $before['checklist_version'],
                'version_after' => $after['checklist_version'] ?? null,
                'conflicts' => $conflicts,
                'open_issues_on_removed_items' => $open,
            ];
        }

        if ($openIssues > 0) {
            $warnings[] = sprintf(
                '%d open %s on items this removes. %s open and stay visible to Logistics.',
                $openIssues,
                $openIssues === 1 ? 'issue is' : 'issues are',
                $openIssues === 1 ? 'It stays' : 'They stay',
            );
        }
        $changed = array_filter($rows, static fn (array $row): bool => $row['version_before'] !== $row['version_after']);
        if ($changed !== [] && $target !== null) {
            $warnings[] = sprintf(
                'Checkouts started on the current checklist are still accepted for %d hours. Unsent checkouts saved on a device will need officer review.',
                max(0, (int) config('daily_checkout.inventory_revision_grace_hours', 168)),
            );
        }

        return ['units' => $rows, 'errors' => $errors, 'warnings' => $warnings];
    }

    /** @param list<array{id: string, name: string, compartment: string}> $removedItems */
    private function openIssuesOnRemovedItems(Apparatus $apparatus, array $removedItems): int
    {
        if ($removedItems === []) {
            return 0;
        }

        // Same matching defect continuity uses: the item id, else compartment and item names.
        return ApparatusDefect::query()
            ->where('apparatus_id', $apparatus->getKey())
            ->where('resolved', false)
            ->where(function ($query) use ($removedItems): void {
                foreach ($removedItems as $removed) {
                    $query->orWhere('item_key', $removed['id'])
                        ->orWhere(static fn ($names) => $names->where('compartment', $removed['compartment'])->where('item', $removed['name']));
                }
            })
            ->count();
    }

    /**
     * @param  Collection<int, Apparatus>  $units
     * @return list<array{id: int, label: string, version_before: string|null, version_after: string|null}>
     */
    private function swapBaseline(Collection $units, Closure $swap): array
    {
        $before = [];
        foreach ($units as $apparatus) {
            $resolution = $this->resolver->resolve($apparatus);
            $before[(int) $apparatus->getKey()] = $resolution;
            // In-flight checkouts on the current list stay valid for the grace period.
            $this->revisions->remember($apparatus, $resolution, null, retire: true);
        }

        $swap();
        $this->repository()->flush();

        $results = [];
        foreach ($units as $apparatus) {
            $after = $this->resolver->resolve($apparatus);
            if ($after['usable'] !== true) {
                throw new ChecklistTemplateException(sprintf('%s would be left without a usable checklist. Nothing was changed.', self::unitLabel($apparatus)));
            }
            $this->revisions->remember($apparatus, $after, null, retire: false);
            $results[] = [
                'id' => (int) $apparatus->getKey(),
                'label' => self::unitLabel($apparatus),
                'version_before' => $before[(int) $apparatus->getKey()]['checklist_version'],
                'version_after' => $after['checklist_version'],
            ];
        }

        return $results;
    }

    /**
     * Every apparatus currently resolving to the type, ascending id. Locked rows are taken in the
     * same order inspection submissions take them.
     *
     * @return Collection<int, Apparatus>
     */
    private function unitsFor(string $type, bool $lock): Collection
    {
        $units = Apparatus::query()->orderBy('id')->get()
            ->filter(fn (Apparatus $apparatus): bool => $this->resolver->checklistTypeFor($apparatus) === $type)
            ->values();
        if (! $lock) {
            return $units;
        }

        return Apparatus::query()->whereKey($units->modelKeys())->orderBy('id')->lockForUpdate()->get();
    }

    /** @param list<array<string, mixed>> $units */
    private function fingerprint(string $targetHash, array $units): string
    {
        $rows = array_map(static fn (array $unit): array => [
            $unit['id'], $unit['version_before'], $unit['version_after'], $unit['conflicts'],
        ], $units);

        return hash('sha256', json_encode([$targetHash, $rows], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    // ------------------------------------------------------------------ building drafts

    /**
     * @param  array<string, mixed>  $form
     * @return array<string, mixed>
     */
    private function checklistFromForm(Version $draft, array $form): array
    {
        $current = self::materialize(self::checklistOf($draft));
        $known = $this->knownIds((string) $draft->checklist_type, $current);
        $nameKey = $this->compartmentNameKey($current);

        $currentCompartments = [];
        $currentItems = [];
        foreach (self::compartmentsOf($current) as $compartment) {
            $currentCompartments[(string) $compartment['id']] = $compartment;
            foreach (self::itemsOf($compartment) as $item) {
                $currentItems[(string) $item['id']] = $item;
            }
        }

        $formCompartments = $form['compartments'] ?? null;
        if (! is_array($formCompartments) || ! array_is_list($formCompartments)) {
            throw ValidationException::withMessages(['checklist' => 'The checklist must list its compartments.']);
        }

        $errors = [];
        /** @var list<array{id: string, data: array<string, mixed>, items: list<array<string, mixed>>}> $built */
        $built = [];
        $indexById = [];
        // A new compartment may carry a temporary id from the form so items can be moved into it.
        $rawIds = [];
        foreach ($formCompartments as $ci => $formCompartment) {
            $formCompartment = is_array($formCompartment) ? $formCompartment : [];
            $name = self::text($formCompartment['name'] ?? null);
            if ($name === '') {
                $errors["compartments.{$ci}.name"] = 'Give the compartment a name.';
            } elseif (mb_strlen($name) > self::COMPARTMENT_NAME_MAX) {
                $errors["compartments.{$ci}.name"] = 'Compartment names may be at most '.self::COMPARTMENT_NAME_MAX.' characters.';
            }

            $id = self::text($formCompartment['id'] ?? null);
            $rawIds[$ci] = $id;
            if ($id !== '' && isset($indexById[$id])) {
                $errors["compartments.{$ci}.id"] = 'This compartment is listed twice.';
            }
            if ($id !== '' && isset($currentCompartments[$id]) && ! isset($indexById[$id])) {
                $data = $currentCompartments[$id];
                $data[self::nameKeyOf($data) ?? $nameKey] = $name;
            } else {
                if ($id !== '' && ! isset($indexById[$id])) {
                    $indexById[$id] = $ci;
                }
                $id = $this->newCompartmentId($name, $known);
                $data = ['id' => $id, $nameKey => $name, 'items' => []];
            }
            $indexById[$id] = $ci;
            $built[$ci] = ['id' => $id, 'data' => $data, 'items' => []];
        }

        $removedNames = [];
        foreach ($currentCompartments as $id => $compartment) {
            if (! isset($indexById[$id])) {
                $removedNames[$id] = InventoryOverlay::compartmentName($compartment);
            }
        }

        $formKeys = [];
        $seenItems = [];
        $moved = [];
        foreach ($formCompartments as $ci => $formCompartment) {
            $formItems = is_array($formCompartment) ? ($formCompartment['items'] ?? []) : [];
            if (! is_array($formItems) || ! array_is_list($formItems)) {
                $errors["compartments.{$ci}.items"] = 'The items of this compartment could not be read.';

                continue;
            }
            foreach ($formItems as $ii => $formItem) {
                $key = "compartments.{$ci}.items.{$ii}";
                if (! is_array($formItem)) {
                    $errors[$key] = 'This item could not be read.';

                    continue;
                }

                $target = $ci;
                $targetId = self::text($formItem['compartment_id'] ?? null);
                if ($targetId !== '' && $targetId !== $built[$ci]['id'] && $targetId !== $rawIds[$ci]) {
                    if (isset($removedNames[$targetId])) {
                        $errors["{$key}.compartment_id"] = "Move or remove the items in {$removedNames[$targetId]} before deleting it.";

                        continue;
                    }
                    if (! isset($indexById[$targetId])) {
                        $errors["{$key}.compartment_id"] = 'Choose a location that is on this checklist.';

                        continue;
                    }
                    $target = $indexById[$targetId];
                }

                $fields = $this->itemFields($formItem, $key, $errors);
                $id = self::text($formItem['id'] ?? null);
                if ($id !== '' && isset($currentItems[$id])) {
                    if (isset($seenItems[$id])) {
                        $errors["{$key}.id"] = 'This item is listed twice.';

                        continue;
                    }
                    $item = $currentItems[$id];
                } else {
                    $id = $this->newItemId($built[$target]['id'], $known);
                    $item = ['id' => $id, 'name' => '', 'inputType' => 'checkbox'];
                }
                $seenItems[$id] = true;
                $formKeys[$id] = $key;
                $item = self::withFields($item, $fields);

                if ($target === $ci) {
                    $built[$ci]['items'][] = $item;
                } else {
                    $moved[$target][] = $item;
                }
            }
        }
        foreach ($moved as $target => $items) {
            array_push($built[$target]['items'], ...$items);
        }

        // Names must stay unambiguous: defects and submissions are keyed by display names.
        $compartmentNames = [];
        foreach ($built as $ci => $compartment) {
            $name = InventoryOverlay::compartmentName($compartment['data']);
            $folded = mb_strtolower($name);
            if ($name !== '' && isset($compartmentNames[$folded])) {
                $errors["compartments.{$ci}.name"] ??= "Another compartment is already called \"{$name}\".";
            }
            $compartmentNames[$folded] = true;

            $itemNames = [];
            foreach ($compartment['items'] as $item) {
                $itemName = (string) ($item['name'] ?? '');
                $folded = mb_strtolower($itemName);
                if ($itemName !== '' && isset($itemNames[$folded])) {
                    $errors[($formKeys[(string) $item['id']] ?? 'checklist').'.name'] ??= "Another item in {$name} is already called \"{$itemName}\".";
                }
                $itemNames[$folded] = true;
            }
        }

        // The same ambiguity rules publishing enforces, reported on the row now rather than at publish.
        if ($errors === []) {
            foreach ($built as $compartment) {
                $label = InventoryOverlay::compartmentName($compartment['data']);
                $aliased = $this->withAliases(['compartments' => [[...$compartment['data'], 'items' => $compartment['items']]]], $this->baseOf($draft));
                foreach (self::ambiguities($aliased['compartments'][0]['items'], $label) as $problem) {
                    $item = $compartment['items'][$problem['item_index']] ?? [];
                    $errors[($formKeys[(string) ($item['id'] ?? '')] ?? 'checklist').'.name'] ??= $problem['message'];
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $compartments = [];
        foreach ($built as $compartment) {
            $data = $compartment['data'];
            $data['items'] = $compartment['items'];
            $compartments[] = $data;
        }
        $checklist = $current;
        $checklist['compartments'] = $compartments;

        return $this->withAliases($checklist, $this->baseOf($draft));
    }

    /**
     * @param  list<mixed>  $compartments  imported compartments
     * @return array<string, mixed>
     */
    private function checklistFromImport(Version $draft, array $compartments): array
    {
        $type = (string) $draft->checklist_type;
        $current = self::materialize(self::checklistOf($draft));
        $base = $this->baseOf($draft);
        $known = $this->knownIds($type, $current);
        $nameKey = $this->compartmentNameKey($current);

        // Ids that may be kept as is: those on the draft or its base. Any other id that was ever used
        // for this type belonged to removed equipment and must not come back meaning something else.
        $ownIds = [];
        $ownCompartmentIds = [];
        $baseNames = [];
        foreach ([$current, $base] as $checklist) {
            foreach (self::compartmentsOf($checklist) as $compartment) {
                $ownCompartmentIds[(string) ($compartment['id'] ?? '')] = true;
                foreach (self::itemsOf($compartment) as $item) {
                    $ownIds[(string) $item['id']] = true;
                }
            }
        }
        foreach ($this->itemIndex($base) as $id => $entry) {
            $baseNames[(string) $id] = (string) ($entry['item']['name'] ?? '');
        }

        $errors = [];
        $result = [];
        $usedItemIds = [];
        $usedCompartmentIds = [];
        foreach ($compartments as $ci => $compartment) {
            $position = $ci + 1;
            if (! is_array($compartment) || array_is_list($compartment)) {
                $errors[] = "Compartment {$position} is not an object.";

                continue;
            }
            $name = self::text($compartment['name'] ?? $compartment['title'] ?? null);
            $id = self::text($compartment['id'] ?? null);
            if ($id === '') {
                $id = $this->newCompartmentId($name, $known);
                unset($compartment['id']);
                $compartment = ['id' => $id, ...$compartment];
                if (self::nameKeyOf($compartment) === null) {
                    $compartment[$nameKey] = $name;
                }
            }
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_\-]{0,79}$/D', $id) !== 1) {
                $errors[] = "Compartment {$position} has an invalid id. Use letters, digits, '_' or '-', or remove the id to add it as a new compartment.";
            } elseif (! isset($ownCompartmentIds[$id]) && isset($known['compartments'][$id])) {
                // Diagrams and history are keyed by compartment id: a removed compartment's id must not
                // come back naming different storage.
                $errors[] = "Compartment id {$id} belonged to a compartment that was removed and cannot be reused. Remove the id to add it as a new compartment.";
            }
            if (isset($usedCompartmentIds[$id])) {
                $errors[] = "Compartment id {$id} is used twice.";
            }
            $usedCompartmentIds[$id] = true;

            $items = $compartment['items'] ?? [];
            if (! is_array($items) || ! array_is_list($items)) {
                $errors[] = "The items of compartment {$position} must be a list.";

                continue;
            }
            foreach ($items as $ii => $item) {
                if (! is_array($item) || array_is_list($item)) {
                    $errors[] = 'Item '.($ii + 1)." of compartment {$position} is not an object.";

                    continue;
                }
                $itemId = self::text($item['id'] ?? null);
                if ($itemId === '') {
                    // A file exported from the repository may carry positional ids implicitly: keep
                    // the id only when it still names the same item, else it is a new item.
                    $positional = $id.'-item-'.($ii + 1);
                    $itemId = isset($baseNames[$positional]) && $baseNames[$positional] === self::text($item['name'] ?? null)
                        ? $positional
                        : $this->newItemId($id, $known);
                    unset($item['id']);
                    $item = ['id' => $itemId, ...$item];
                    $item['inputType'] ??= 'checkbox';
                } elseif (! isset($ownIds[$itemId]) && isset($known['items'][$itemId])) {
                    $errors[] = "Item id {$itemId} belonged to an item that was removed and cannot be reused. Remove the id to add it as a new item.";
                }
                if (isset($usedItemIds[$itemId])) {
                    $errors[] = "Item id {$itemId} is used twice.";
                }
                $usedItemIds[$itemId] = true;
                $items[$ii] = $item;
            }
            $compartment['items'] = $items;
            $result[] = $compartment;
        }

        $checklist = $current;
        $checklist['compartments'] = $result;
        if ($errors === []) {
            $errors = $this->ruleViolations($checklist, allowEmptyCompartments: true);
        }
        if ($errors !== []) {
            throw ValidationException::withMessages(['import' => array_values(array_unique($errors))]);
        }

        return $this->withAliases($checklist, $base);
    }

    /**
     * Applies the alias and identity rules (docs/checklist-template-editor.md section 4) against the
     * draft's base, the checklist members had when the draft started. Recomputed on every save, so
     * renaming or moving back drops only what this draft added, never what the base already had.
     *
     * @param  array<string, mixed>  $checklist
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>
     */
    private function withAliases(array $checklist, array $base): array
    {
        $baseCompartments = [];
        foreach (self::compartmentsOf($base) as $compartment) {
            $baseCompartments[(string) $compartment['id']] = $compartment;
        }
        $baseItems = $this->itemIndex($base);

        foreach (self::compartmentsOf($checklist) as $ci => $compartment) {
            $compartmentId = (string) ($compartment['id'] ?? '');
            $baseCompartment = $baseCompartments[$compartmentId] ?? null;
            if ($baseCompartment !== null) {
                $compartment = self::withAlias(
                    $compartment,
                    'legacyCompartmentNames',
                    InventoryOverlay::compartmentName($baseCompartment),
                    InventoryOverlay::compartmentName($compartment) !== InventoryOverlay::compartmentName($baseCompartment),
                    $baseCompartment,
                );
            }

            foreach (self::itemsOf($compartment) as $ii => $item) {
                $baseEntry = $baseItems[(string) ($item['id'] ?? '')] ?? null;
                if ($baseEntry === null) {
                    continue; // new in this draft: nothing to stay compatible with
                }
                $baseItem = $baseEntry['item'];
                $renamed = (string) ($item['name'] ?? '') !== (string) ($baseItem['name'] ?? '');
                $item = self::withAlias($item, 'legacyItemNames', (string) ($baseItem['name'] ?? ''), $renamed, $baseItem);
                $item = self::withAlias($item, 'legacyCompartmentNames', $baseEntry['compartment_name'], $baseEntry['compartment_id'] !== $compartmentId, $baseItem);

                // The item keeps the equipment identity it had: approved inventory changes and the
                // "all apparatus carrying this item" scope match on it.
                $baseKey = is_string($baseItem['equipmentKey'] ?? null) ? trim($baseItem['equipmentKey']) : '';
                if ($baseKey !== '') {
                    $item['equipmentKey'] = $baseItem['equipmentKey'];
                } elseif ($renamed) {
                    $item['equipmentKey'] = EquipmentIdentity::forItem($baseItem);
                } else {
                    unset($item['equipmentKey']);
                }

                $compartment['items'][$ii] = $item;
            }
            $checklist['compartments'][$ci] = $compartment;
        }

        return $checklist;
    }

    /**
     * @param  array<string, mixed>  $row  item or compartment
     * @param  array<string, mixed>  $baseRow  the same row in the base
     * @return array<string, mixed>
     */
    private static function withAlias(array $row, string $key, string $baseValue, bool $changed, array $baseRow): array
    {
        $baseList = array_values(array_filter((array) ($baseRow[$key] ?? []), 'is_string'));
        $list = array_values(array_filter((array) ($row[$key] ?? []), 'is_string'));
        if ($changed && $baseValue !== '' && ! in_array($baseValue, $list, true)) {
            $list[] = $baseValue;
        } elseif (! $changed && ! in_array($baseValue, $baseList, true)) {
            $list = array_values(array_filter($list, static fn (string $value): bool => $value !== $baseValue));
        }

        if ($list === [] && ! array_key_exists($key, $baseRow)) {
            unset($row[$key]);
        } else {
            $row[$key] = $list;
        }

        return $row;
    }

    /**
     * Validates and normalizes one form row's editable fields. Absent keys mean "unchanged".
     *
     * @param  array<string, mixed>  $formItem
     * @param  array<string, string>  $errors
     * @return array<string, string|int|null>
     */
    private function itemFields(array $formItem, string $key, array &$errors): array
    {
        $fields = [];
        $name = self::text($formItem['name'] ?? null);
        if ($name === '') {
            $errors["{$key}.name"] = 'Give the item a name.';
        } elseif (mb_strlen($name) > self::ITEM_NAME_MAX) {
            $errors["{$key}.name"] = 'Item names may be at most '.self::ITEM_NAME_MAX.' characters.';
        }
        $fields['name'] = $name;

        if (array_key_exists('expectedQuantity', $formItem)) {
            $quantity = self::quantity($formItem['expectedQuantity']);
            if ($quantity === false) {
                $errors["{$key}.expectedQuantity"] = 'Quantity must be a whole number from 1 to 999, or blank.';
            } else {
                $fields['expectedQuantity'] = $quantity;
            }
        }

        foreach (self::TEXT_LIMITS as $field => $limit) {
            if (! array_key_exists($field, $formItem)) {
                continue;
            }
            $value = $formItem[$field];
            if ($value !== null && ! is_scalar($value)) {
                $errors["{$key}.{$field}"] = 'This value could not be read.';

                continue;
            }
            $text = self::text($value);
            if (mb_strlen($text) > $limit) {
                $errors["{$key}.{$field}"] = "At most {$limit} characters.";
            }
            $fields[$field] = $text === '' ? null : $text;
        }

        return $fields;
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, string|int|null>  $fields
     * @return array<string, mixed>
     */
    private static function withFields(array $item, array $fields): array
    {
        foreach ($fields as $field => $value) {
            if ($value === null) {
                unset($item[$field]); // an empty optional field is removed, not stored blank
            } else {
                $item[$field] = $value;
            }
        }

        return $item;
    }

    /**
     * The editor's field rules over a whole checklist, as plain sentences.
     *
     * @param  array<string, mixed>  $checklist
     * @return list<string>
     */
    private function ruleViolations(array $checklist, bool $allowEmptyCompartments): array
    {
        $compartments = $checklist['compartments'] ?? null;
        if (! is_array($compartments) || ! array_is_list($compartments) || $compartments === []) {
            return ['The checklist needs at least one compartment.'];
        }

        $errors = [];
        $compartmentNames = $compartmentIds = $itemIds = [];
        foreach ($compartments as $ci => $compartment) {
            if (! is_array($compartment)) {
                $errors[] = 'Compartment '.($ci + 1).' could not be read.';

                continue;
            }
            $name = self::text($compartment['name'] ?? $compartment['title'] ?? null);
            $label = $name !== '' ? $name : 'Compartment '.($ci + 1);
            $id = self::text($compartment['id'] ?? null);
            if ($id === '') {
                $errors[] = "{$label} has no id.";
            } elseif (isset($compartmentIds[$id])) {
                $errors[] = "Compartment id {$id} is used twice.";
            }
            $compartmentIds[$id] = true;

            if ($name === '') {
                $errors[] = "{$label} needs a name.";
            } elseif (mb_strlen($name) > self::COMPARTMENT_NAME_MAX) {
                $errors[] = "The name of {$label} is longer than ".self::COMPARTMENT_NAME_MAX.' characters.';
            } elseif (isset($compartmentNames[mb_strtolower($name)])) {
                $errors[] = "Two compartments are called \"{$name}\".";
            }
            $compartmentNames[mb_strtolower($name)] = true;

            $items = $compartment['items'] ?? null;
            if (! is_array($items) || ! array_is_list($items)) {
                $errors[] = "The items of {$label} could not be read.";

                continue;
            }
            if ($items === [] && ! $allowEmptyCompartments) {
                $errors[] = "Add at least one item to {$label} or delete it.";
            }

            $itemNames = [];
            foreach (self::ambiguities($items, $label) as $problem) {
                $errors[] = $problem['message'];
            }

            foreach ($items as $ii => $item) {
                if (! is_array($item)) {
                    $errors[] = 'Item '.($ii + 1)." in {$label} could not be read.";

                    continue;
                }
                $itemName = self::text($item['name'] ?? null);
                $itemLabel = $itemName !== '' ? "\"{$itemName}\" in {$label}" : 'Item '.($ii + 1)." in {$label}";
                if ($itemName === '') {
                    $errors[] = "{$itemLabel} needs a name.";
                } elseif (mb_strlen($itemName) > self::ITEM_NAME_MAX) {
                    $errors[] = "The name of {$itemLabel} is longer than ".self::ITEM_NAME_MAX.' characters.';
                } elseif (isset($itemNames[mb_strtolower($itemName)])) {
                    $errors[] = "{$label} lists \"{$itemName}\" twice.";
                }
                $itemNames[mb_strtolower($itemName)] = true;

                $itemId = self::text($item['id'] ?? null);
                if ($itemId !== '') {
                    if (isset($itemIds[$itemId])) {
                        $errors[] = "Item id {$itemId} is used twice.";
                    }
                    $itemIds[$itemId] = true;
                }

                if (array_key_exists('expectedQuantity', $item)
                    && (! is_int($item['expectedQuantity']) || $item['expectedQuantity'] < 1 || $item['expectedQuantity'] > 999)) {
                    $errors[] = "{$itemLabel}: quantity must be a whole number from 1 to 999.";
                }
                foreach (self::TEXT_LIMITS as $field => $limit) {
                    if (! array_key_exists($field, $item)) {
                        continue;
                    }
                    if (! is_string($item[$field])) {
                        $errors[] = "{$itemLabel}: ".mb_strtolower(self::FIELD_LABELS[$field]).' must be text.';
                    } elseif (mb_strlen($item[$field]) > $limit) {
                        $errors[] = "{$itemLabel}: ".mb_strtolower(self::FIELD_LABELS[$field])." is longer than {$limit} characters.";
                    }
                }
            }
        }

        return array_values(array_unique($errors));
    }

    /**
     * Equipment identity and former names must stay unambiguous inside a compartment: inventory changes
     * match items on the identity, and old issues are matched to items by compartment and name.
     *
     * @param  array<int, mixed>  $items  one compartment's items
     * @return list<array{item_index: int, message: string}> the index is the later item of each clash
     */
    private static function ambiguities(array $items, string $label): array
    {
        $problems = [];
        $identities = [];
        $formerNames = [];
        foreach ($items as $ii => $item) {
            if (! is_array($item)) {
                continue;
            }
            $name = self::text($item['name'] ?? null);
            $identities[EquipmentIdentity::forItem($item)][] = [$ii, $name];
            foreach ((array) ($item['legacyItemNames'] ?? []) as $former) {
                if (is_string($former) && trim($former) !== '') {
                    $formerNames[mb_strtolower(trim($former))][] = $name;
                }
            }
        }
        foreach ($identities as $members) {
            if (count($members) > 1) {
                $problems[] = [
                    'item_index' => (int) end($members)[0],
                    'message' => sprintf(
                        '%s: "%s" are the same equipment to the system (their names match once spacing and punctuation are ignored, or one was renamed from the other). Remove one of them or give one a clearly different name.',
                        $label,
                        implode('" and "', array_column($members, 1)),
                    ),
                ];
            }
        }
        foreach ($items as $ii => $item) {
            if (! is_array($item)) {
                continue;
            }
            $name = self::text($item['name'] ?? null);
            foreach ($formerNames[mb_strtolower($name)] ?? [] as $renamed) {
                if ($renamed !== $name) {
                    $problems[] = [
                        'item_index' => (int) $ii,
                        'message' => "{$label}: \"{$name}\" is the former name of \"{$renamed}\", so old issues could not tell them apart. Give the new item a distinguishing name (for example \"{$name} (new)\") or remove the old one.",
                    ];
                }
            }
        }

        return $problems;
    }

    // ------------------------------------------------------------------ ids

    /**
     * Every compartment and item id ever seen for the type: repository file, every version and
     * every draft base. New ids are unique against all of them, so an id never comes back meaning
     * different equipment (defects, inventory changes and Captain 5 decisions are keyed by id).
     *
     * @param  array<string, mixed>  $current
     * @return array{items: array<string, true>, compartments: array<string, true>}
     */
    private function knownIds(string $type, array $current): array
    {
        $checklists = [$current];
        $file = $this->repository()->fileTemplate($type, $this->directory());
        if (is_array($file['checklist'])) {
            $checklists[] = $file['checklist'];
        }
        // Discarded drafts were never issued: nothing (defects, inventory changes, decisions) is keyed to their ids.
        foreach (Version::query()->where('checklist_type', $type)->where('status', '!=', Version::STATUS_DISCARDED)->get(['checklist', 'base_checklist']) as $version) {
            $checklists[] = is_array($version->checklist) ? $version->checklist : [];
            $checklists[] = is_array($version->base_checklist) ? $version->base_checklist : [];
        }

        $known = ['items' => [], 'compartments' => []];
        foreach ($checklists as $checklist) {
            foreach (self::compartmentsOf(self::materialize($checklist)) as $compartment) {
                $known['compartments'][(string) $compartment['id']] = true;
                foreach (self::itemsOf($compartment) as $item) {
                    $known['items'][(string) $item['id']] = true;
                }
            }
        }

        return $known;
    }

    /** @param array{items: array<string, true>, compartments: array<string, true>} $known */
    private function newItemId(string $compartmentId, array &$known): string
    {
        $alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
        do {
            $suffix = '';
            for ($i = 0; $i < 8; $i++) {
                $suffix .= $alphabet[random_int(0, 35)];
            }
            $id = "{$compartmentId}-i{$suffix}";
        } while (isset($known['items'][$id]));
        $known['items'][$id] = true;

        return $id;
    }

    /** @param array{items: array<string, true>, compartments: array<string, true>} $known */
    private function newCompartmentId(string $name, array &$known): string
    {
        $slug = Str::limit(Str::slug(Str::ascii($name), '_'), 40, '');
        $stem = 'custom_'.($slug !== '' ? rtrim($slug, '_') : 'compartment');
        $id = $stem;
        for ($n = 2; isset($known['compartments'][$id]); $n++) {
            $id = "{$stem}_{$n}";
        }
        $known['compartments'][$id] = true;

        return $id;
    }

    // ------------------------------------------------------------------ helpers

    private function lockDraft(Version $draft, ?int $expectedLockVersion): Version
    {
        $locked = Version::query()->whereKey($draft->getKey())->lockForUpdate()->first();
        if ($locked === null
            || $locked->status !== Version::STATUS_DRAFT
            || ($expectedLockVersion !== null && (int) $locked->lock_version !== $expectedLockVersion)) {
            throw new ChecklistTemplateException('This draft was changed by someone else. Reload and try again.');
        }

        return $locked;
    }

    /** @param array<string, mixed> $checklist */
    private function storeDraft(Version $draft, array $checklist, User $user): void
    {
        $draft->forceFill([
            'checklist' => $checklist,
            'checklist_hash' => $this->resolver->versionOf($checklist),
            'lock_version' => (int) $draft->lock_version + 1,
            'updated_by_user_id' => $user->getKey(),
        ])->save();
    }

    /**
     * The live checklist the draft was started from (aliases are computed against it).
     *
     * @return array<string, mixed>
     */
    private function baseOf(Version $draft): array
    {
        if (is_array($draft->base_checklist)) {
            return self::materialize($draft->base_checklist);
        }
        // Defensive: re-derive from what the draft was based on.
        if ($draft->based_on_version_id !== null) {
            $version = Version::query()->find($draft->based_on_version_id);
            if ($version !== null && is_array($version->checklist)) {
                return self::materialize($version->checklist);
            }
        }
        $file = $this->repository()->fileTemplate((string) $draft->checklist_type, $this->directory());

        return is_array($file['checklist']) ? self::materialize($file['checklist']) : ['compartments' => []];
    }

    /** @param array<string, mixed> $details */
    private function event(string $type, ?Version $version, ?User $user, string $action, array $details): Event
    {
        return Event::query()->create([
            'checklist_type' => $type,
            'template_version_id' => $version?->getKey(),
            'action' => $action,
            'user_id' => $user?->getKey(),
            'details' => $details,
        ]);
    }

    private function summary(string $summary): string
    {
        $summary = trim($summary);
        $length = mb_strlen($summary);
        if ($length < 3 || $length > 500) {
            throw ValidationException::withMessages(['summary' => 'Describe the change in 3 to 500 characters.']);
        }

        return $summary;
    }

    private function authorize(User $user): void
    {
        if (! ChecklistTemplateAccess::canManage($user)) {
            throw new AuthorizationException('Only a Logistics administrator may change checklist templates.');
        }
    }

    private function assertType(string $type): void
    {
        if (! in_array($type, $this->types(), true)) {
            throw new ChecklistTemplateException('This checklist type cannot be edited.');
        }
    }

    private function repository(): ChecklistTemplateRepository
    {
        return app(ChecklistTemplateRepository::class);
    }

    private function directory(): string
    {
        return $this->resolver->templateDirectory();
    }

    /** @param array{checklist: array<string, mixed>|null} $file */
    private function fileHash(array $file): ?string
    {
        return is_array($file['checklist']) ? $this->resolver->versionOf($file['checklist']) : null;
    }

    private function canonicalHash(mixed $value): string
    {
        return $this->resolver->versionOf(['value' => $value]);
    }

    /** The key ('title' or 'name') new compartments use: whichever this template's compartments use. */
    private function compartmentNameKey(array $checklist): string
    {
        $counts = ['name' => 0, 'title' => 0];
        foreach (self::compartmentsOf($checklist) as $compartment) {
            $key = self::nameKeyOf($compartment);
            if ($key !== null) {
                $counts[$key]++;
            }
        }

        return $counts['title'] > $counts['name'] ? 'title' : 'name';
    }

    /** @param array<string, mixed> $compartment */
    private static function nameKeyOf(array $compartment): ?string
    {
        // Same precedence as the resolver and InspectionPaperFields: `name` wins over `title`.
        return match (true) {
            array_key_exists('name', $compartment) => 'name',
            array_key_exists('title', $compartment) => 'title',
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $checklist
     * @return array<string, array{name: string, item_ids: list<string>}>
     */
    private function compartmentIndex(array $checklist): array
    {
        $index = [];
        foreach (self::compartmentsOf($checklist) as $compartment) {
            $index[(string) ($compartment['id'] ?? '')] = [
                'name' => InventoryOverlay::compartmentName($compartment),
                'item_ids' => array_map(static fn (array $item): string => (string) ($item['id'] ?? ''), self::itemsOf($compartment)),
            ];
        }

        return $index;
    }

    /**
     * @param  array<string, mixed>  $checklist  materialized
     * @return array<string, array{item: array<string, mixed>, compartment_id: string, compartment_name: string}>
     */
    private function itemIndex(array $checklist): array
    {
        $index = [];
        foreach (self::compartmentsOf($checklist) as $compartment) {
            foreach (self::itemsOf($compartment) as $item) {
                $index[(string) ($item['id'] ?? '')] = [
                    'item' => $item,
                    'compartment_id' => (string) ($compartment['id'] ?? ''),
                    'compartment_name' => InventoryOverlay::compartmentName($compartment),
                ];
            }
        }

        return $index;
    }

    /** @param array<string, mixed> $checklist */
    private function itemCount(array $checklist): int
    {
        $count = 0;
        foreach (self::compartmentsOf($checklist) as $compartment) {
            $count += count(self::itemsOf($compartment));
        }

        return $count;
    }

    /**
     * Positional fallback ids made explicit, exactly as InventoryOverlay::withMaterializedItemIds
     * does: `{compartment id}-item-{position}`, the id the item already resolves to.
     *
     * @param  array<string, mixed>  $checklist
     * @return array<string, mixed>
     */
    private static function materialize(array $checklist): array
    {
        foreach ($checklist['compartments'] ?? [] as $compartmentIndex => $compartment) {
            if (! is_array($compartment)) {
                continue;
            }
            foreach ($compartment['items'] ?? [] as $itemIndex => $item) {
                if (is_array($item) && ! isset($item['id'])) {
                    $checklist['compartments'][$compartmentIndex]['items'][$itemIndex]['id'] = ($compartment['id'] ?? '').'-item-'.($itemIndex + 1);
                }
            }
        }

        return $checklist;
    }

    /**
     * @param  array<string, mixed>  $checklist
     * @return array<int, array<string, mixed>>
     */
    private static function compartmentsOf(array $checklist): array
    {
        $compartments = $checklist['compartments'] ?? [];

        return is_array($compartments) ? array_filter($compartments, 'is_array') : [];
    }

    /**
     * @param  array<string, mixed>  $compartment
     * @return array<int, array<string, mixed>>
     */
    private static function itemsOf(array $compartment): array
    {
        $items = $compartment['items'] ?? [];

        return is_array($items) ? array_filter($items, 'is_array') : [];
    }

    /**
     * A version's checklist; the column is never null, but a value that cannot be decoded must not
     * break the editor.
     *
     * @return array<string, mixed>
     */
    private static function checklistOf(Version $version): array
    {
        return is_array($version->checklist) ? $version->checklist : ['compartments' => []];
    }

    private static function unitLabel(Apparatus $apparatus): string
    {
        return trim((string) ($apparatus->designation ?: $apparatus->name ?: $apparatus->getAttribute('unit_id'))) ?: 'Apparatus #'.$apparatus->getKey();
    }

    private function userName(?User $user): ?string
    {
        if ($user === null) {
            return null;
        }
        $name = trim((string) ($user->display_name ?: $user->name));

        return $name !== '' ? $name : null;
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    private static function optionalString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    private static function displayValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return is_scalar($value) ? (string) $value : (json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: null);
    }

    /** @return int|null|false null = blank (remove), false = invalid */
    private static function quantity(mixed $value): int|null|false
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }
        if (is_string($value) && preg_match('/^\s*\d+\s*$/', $value) === 1) {
            $value = (int) trim($value);
        } elseif (is_float($value) && floor($value) === $value) {
            $value = (int) $value;
        }

        return is_int($value) && $value >= 1 && $value <= 999 ? $value : false;
    }
}
