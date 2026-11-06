<?php

declare(strict_types=1);

namespace Tests\Feature\ChecklistTemplates;

use App\Enums\DailyCheckoutChecklistTemplate;
use App\Models\Apparatus;
use App\Models\ApparatusInventoryChange;
use App\Models\DailyCheckoutChecklistTemplateEvent;
use App\Models\DailyCheckoutChecklistTemplateVersion as Version;
use App\Models\User;
use App\Services\Captain5\MedicalScope;
use App\Services\ChecklistTemplates\ChecklistTemplateEditor;
use App\Services\ChecklistTemplates\ChecklistTemplateException;
use App\Services\ChecklistTemplates\ChecklistTemplateRepository;
use App\Services\DailyCheckoutChecklistResolver;
use App\Services\Inventory\ChecklistRevisionStore;
use App\Services\Inventory\EquipmentIdentity;
use App\Services\SupportTriage\EquipmentRuleService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Inventory\Concerns\BuildsInventoryCorrectionFixtures;
use Tests\TestCase;

/**
 * The Daily Checkout checklist template editor (docs/checklist-template-editor.md): nothing changes
 * until someone publishes; a publish reaches exactly the units on that checklist, keeps in-flight
 * work valid, and keeps ids, aliases and equipment identity so everything keyed by them still matches.
 */
final class ChecklistTemplateEditorTest extends TestCase
{
    use BuildsInventoryCorrectionFixtures;
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInventoryFleet();
        $this->manager = $this->logistics();
    }

    public function test_without_a_published_version_every_template_resolves_exactly_as_the_file(): void
    {
        $resolver = app(DailyCheckoutChecklistResolver::class);
        // An open draft must not change anything either.
        $this->editor()->startDraft('engine', $this->manager);

        foreach (DailyCheckoutChecklistTemplate::cases() as $template) {
            if (! $template->isConfigured()) {
                continue;
            }
            $designation = $template === DailyCheckoutChecklistTemplate::FireBoat6 ? 'FB6' : 'X'.$template->value;
            $apparatus = new Apparatus([
                'unit_id' => $designation, 'designation' => $designation, 'type' => 'Special',
                'daily_checkout_template' => $template->value,
            ]);
            $path = base_path("storage/checklists/{$template->value}_checklist.json");
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

            $resolution = $resolver->resolve($apparatus);

            $this->assertTrue($resolution['usable'], $template->value);
            $this->assertSame($path, $resolution['path'], $template->value);
            $this->assertSame($decoded, $resolution['checklist'], $template->value);
            $this->assertSame($resolver->versionOf($decoded), $resolution['checklist_version'], $template->value);
            $this->assertSame($resolver->versionOf($decoded), $resolution['checklist_base_version'], $template->value);
            $this->assertSame(
                ['checklist_type', 'configured_template', 'resolution_source', 'family', 'identity', 'ambiguity', 'error', 'path', 'fallback_used', 'item_count', 'checklist_version', 'checklist_base_version', 'inventory_change_ids', 'overlay_conflicts', 'checklist', 'usable'],
                array_keys($resolution),
                $template->value,
            );
        }

        foreach (['E1', 'E2', 'L1', 'L3', 'R1'] as $designation) {
            $resolution = $this->resolved($designation);
            $decoded = json_decode((string) file_get_contents($resolution['path']), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame($decoded, $resolution['checklist'], $designation);
        }
    }

    public function test_every_shipped_template_survives_an_unchanged_save_exactly_and_is_valid(): void
    {
        $resolver = app(DailyCheckoutChecklistResolver::class);
        foreach ($this->editor()->types() as $type) {
            $file = json_decode((string) file_get_contents(base_path("storage/checklists/{$type}_checklist.json")), true, 512, JSON_THROW_ON_ERROR);

            // Opening the editor and pressing Save without touching anything must not alter a single
            // key, value or alias: otherwise a no-op publish would change what members are issued.
            $draft = $this->editor()->startDraft($type, $this->manager);
            $saved = $this->editor()->saveDraft($draft, $this->editor()->toForm($draft->checklist), $this->manager, $draft->lock_version);

            $materialized = $file;
            foreach ($materialized['compartments'] as $ci => $compartment) {
                foreach ($compartment['items'] as $ii => $item) {
                    $materialized['compartments'][$ci]['items'][$ii]['id'] ??= $compartment['id'].'-item-'.($ii + 1);
                }
            }
            $this->assertSame($materialized, $saved->checklist, $type);
            $this->assertSame($resolver->versionOf($materialized), $saved->checklist_hash, $type);
            $this->assertSame([], $this->editor()->validationErrors($saved->checklist), $type);
            $this->assertFalse($this->editor()->diff($file, $saved->checklist)['has_changes'], $type);

            $this->editor()->discardDraft($saved, $this->manager);
        }
    }

    public function test_resolve_with_template_runs_the_same_steps_with_a_supplied_baseline(): void
    {
        $resolver = app(DailyCheckoutChecklistResolver::class);
        $current = $resolver->resolve($this->unit('E1'));

        $same = $resolver->resolveWithTemplate($this->unit('E1'), $current['checklist']);
        $this->assertSame($current, $same);

        $broken = $resolver->resolveWithTemplate($this->unit('E1'), ['compartments' => []]);
        $this->assertFalse($broken['usable']);
        $this->assertSame('invalid_schema', $broken['error']);
        $this->assertSame($current['path'], $broken['path']);
    }

    public function test_publishing_changes_exactly_the_units_on_that_checklist_and_keeps_in_flight_work_valid(): void
    {
        $before = [];
        foreach (array_keys($this->units) as $designation) {
            $before[$designation] = $this->resolved($designation)['checklist_version'];
        }

        $draft = $this->editor()->startDraft('engine', $this->manager);
        $form = $this->editor()->toForm($draft->checklist);
        [$ci, $ii] = $this->position($form, 'Passports');
        $form['compartments'][$ci]['items'][$ii]['expectedQuantity'] = 5;
        $form['compartments'][$ci]['items'][$ii]['manufacturer'] = 'US State Dept';
        $draft = $this->editor()->saveDraft($draft, $form, $this->manager, $draft->lock_version);

        $published = $this->publish($draft, 'Passports: five per engine');

        $this->assertSame(Version::STATUS_PUBLISHED, $published->status);
        $this->assertSame(1, $published->version_number);
        foreach (['E1', 'E3', 'E21'] as $designation) {
            $resolution = $this->resolved($designation);
            $this->assertNotSame($before[$designation], $resolution['checklist_version'], $designation);
            $passports = $this->item($designation, 'Passports')['item'];
            $this->assertSame(5, $passports['expectedQuantity']);
            $this->assertSame('US State Dept', $passports['manufacturer']);
            $this->assertNotNull(
                app(ChecklistRevisionStore::class)->recentlyRetired($this->units[$designation]->id, $before[$designation]),
                "{$designation}: a checkout started on the previous checklist is still accepted",
            );
            $this->assertSame(base_path('storage/checklists/engine_checklist.json'), $resolution['path']);
        }
        foreach (['E2', 'L1', 'L3', 'R1'] as $designation) {
            $this->assertSame($before[$designation], $this->resolved($designation)['checklist_version'], $designation);
        }

        $event = DailyCheckoutChecklistTemplateEvent::query()->where('action', DailyCheckoutChecklistTemplateEvent::PUBLISHED)->sole();
        $this->assertSame(1, $event->details['counts']['changed']);
        $this->assertCount(3, $event->details['units']);
        $this->assertSame('published', $this->editor()->live('engine')['source']);
        $this->assertCount(1, $this->editor()->history('engine'));
    }

    public function test_a_rename_leaves_aliases_and_keeps_approved_inventory_changes_applying(): void
    {
        // An approved unit change on E1's Passports (quantity 4).
        $passports = $this->item('E1', 'Passports');
        $correction = $this->reported('E1', ['reason' => 'wrong_quantity', 'compartment_id' => $passports['compartment_id'], 'item_id' => $passports['item_id'], 'proposal' => ['quantity' => 4]]);
        $this->approve($correction, $this->manager, ['op' => ApparatusInventoryChange::OP_MODIFY, 'fields' => ['expectedQuantity' => 4]]);
        $change = ApparatusInventoryChange::query()->where('apparatus_id', $this->units['E1']->id)->sole();
        $this->assertSame(4, $this->item('E1', 'Passports')['item']['expectedQuantity']);
        $medicalBefore = app(MedicalScope::class)->classify('engine', $passports['item_id']);

        $draft = $this->editor()->startDraft('engine', $this->manager);
        $form = $this->editor()->toForm($draft->checklist);
        [$ci, $ii] = $this->position($form, 'Passports');
        $form['compartments'][$ci]['items'][$ii]['name'] = 'Passport cards';
        $draft = $this->editor()->saveDraft($draft, $form, $this->manager, $draft->lock_version);

        $item = $this->draftItem($draft, $passports['item_id']);
        $this->assertSame('Passport cards', $item['name']);
        $this->assertContains('Passports', $item['legacyItemNames']);
        $this->assertSame(EquipmentIdentity::forItem(['name' => 'Passports']), $item['equipmentKey']);

        $preview = $this->editor()->preview($draft);
        $this->assertTrue($preview['publishable'], implode(' ', $preview['errors']));
        $e1 = collect($preview['units'])->firstWhere('id', $this->units['E1']->id);
        $this->assertSame([], $e1['conflicts']);

        $this->publish($draft, 'Rename passports');

        $resolution = $this->resolved('E1');
        $this->assertSame([], $resolution['overlay_conflicts'], 'no identity_mismatch');
        $this->assertContains($change->id, $resolution['inventory_change_ids']);
        $renamed = $this->item('E1', 'Passport cards')['item'];
        $this->assertSame(4, $renamed['expectedQuantity'], 'the approved quantity still applies');
        $this->assertSame($passports['item_id'], $renamed['id']);

        // Captain 5 medical scope keeps reading the reviewed repository file: same id, same answer.
        app()->forgetScopedInstances();
        $this->assertSame($medicalBefore, app(MedicalScope::class)->classify('engine', $passports['item_id']));
    }

    public function test_renaming_back_drops_only_what_the_draft_added(): void
    {
        $draft = $this->editor()->startDraft('engine', $this->manager);
        $form = $this->editor()->toForm($draft->checklist);
        [$ci, $ii] = $this->position($form, 'Passports');
        $id = $form['compartments'][$ci]['items'][$ii]['id'];
        $form['compartments'][$ci]['items'][$ii]['name'] = 'Passport cards';
        $draft = $this->editor()->saveDraft($draft, $form, $this->manager, $draft->lock_version);
        $form['compartments'][$ci]['items'][$ii]['name'] = 'Passports';
        $draft = $this->editor()->saveDraft($draft, $form, $this->manager, $draft->lock_version);

        $this->assertSame($this->baseItem($draft, $id), $this->draftItem($draft, $id));
        $this->assertFalse($this->editor()->preview($draft)['publishable'], 'identical to live');
    }

    public function test_a_move_adds_the_old_compartment_as_an_alias(): void
    {
        $draft = $this->editor()->startDraft('engine', $this->manager);
        $form = $this->editor()->toForm($draft->checklist);
        [$ci, $ii] = $this->position($form, 'Passports');
        $from = $form['compartments'][$ci];
        $to = $form['compartments'][$ci + 1];
        $id = $from['items'][$ii]['id'];
        $form['compartments'][$ci]['items'][$ii]['compartment_id'] = $to['id'];
        $draft = $this->editor()->saveDraft($draft, $form, $this->manager, $draft->lock_version);

        $compartment = collect($draft->checklist['compartments'])->firstWhere('id', $to['id']);
        $moved = end($compartment['items']);
        $this->assertSame($id, $moved['id'], 'appended to the destination');
        $this->assertContains($from['name'], $moved['legacyCompartmentNames']);
        $this->assertSame($id, collect($this->editor()->diff($this->editor()->live('engine')['checklist'], $draft->checklist)['items_changed'])->sole()['id']);
    }

    public function test_new_rows_get_fresh_ids_and_hidden_keys_survive_a_save(): void
    {
        $draft = $this->editor()->startDraft('ladder3', $this->manager);
        $hidden = $this->itemWithKey($draft->checklist, 'snipeit_match');
        $valueRequired = $this->itemWithKey($draft->checklist, 'valueRequired');

        $form = $this->editor()->toForm($draft->checklist);
        $firstId = $form['compartments'][0]['id'];
        $form['compartments'][0]['items'][] = ['id' => '', 'name' => 'Thermal imager battery', 'expectedQuantity' => '2', 'manufacturer' => 'Bullard', 'model' => '', 'description' => null, 'note' => '', 'compartment_id' => $firstId];
        $form['compartments'][] = ['id' => '', 'name' => 'Roof box', 'items' => [
            ['id' => '', 'name' => 'Salvage cover', 'expectedQuantity' => null, 'compartment_id' => ''],
        ]];
        $draft = $this->editor()->saveDraft($draft, $form, $this->manager, $draft->lock_version);

        $added = collect($this->editor()->diff($this->editor()->live('ladder3')['checklist'], $draft->checklist)['items_added'])->keyBy('name');
        $this->assertCount(2, $added);
        $battery = $this->draftItem($draft, $added['Thermal imager battery']['id']);
        $this->assertMatchesRegularExpression('/^'.preg_quote($firstId, '/').'-i[a-z0-9]{8}$/', $battery['id']);
        $this->assertSame(['id' => $battery['id'], 'name' => 'Thermal imager battery', 'inputType' => 'checkbox', 'expectedQuantity' => 2, 'manufacturer' => 'Bullard'], $battery);
        $roof = collect($draft->checklist['compartments'])->last();
        $this->assertSame('custom_roof_box', $roof['id']);
        $this->assertSame('Roof box', $roof['title'], 'new compartments use the key this template uses');
        $this->assertMatchesRegularExpression('/^custom_roof_box-i[a-z0-9]{8}$/', $roof['items'][0]['id']);
        $this->assertDoesNotMatchRegularExpression('/-item-\d+$/', $roof['items'][0]['id'], 'not positional');

        $this->assertSame($hidden, $this->draftItem($draft, $hidden['id']));
        $this->assertSame($valueRequired, $this->draftItem($draft, $valueRequired['id']));
        $this->assertTrue($this->editor()->preview($draft)['publishable']);

        // A second compartment whose name slugs the same never takes an id already in use.
        $form = $this->editor()->toForm($draft->checklist);
        $form['compartments'][] = ['id' => '', 'name' => 'Roof box!', 'items' => [['id' => '', 'name' => 'Salvage cover']]];
        $draft = $this->editor()->saveDraft($draft, $form, $this->manager, $draft->lock_version);
        $second = collect($draft->checklist['compartments'])->last();
        $this->assertSame('custom_roof_box_2', $second['id']);
        $this->assertNotSame($roof['items'][0]['id'], $second['items'][0]['id']);
    }

    public function test_positional_ids_are_materialized_where_they_already_resolve(): void
    {
        $file = json_decode((string) file_get_contents(base_path('storage/checklists/rescue_checklist.json')), true);
        $draft = $this->editor()->startDraft('rescue', $this->manager);

        foreach ($file['compartments'] as $c => $compartment) {
            foreach ($compartment['items'] as $i => $item) {
                $expected = $item['id'] ?? $compartment['id'].'-item-'.($i + 1);
                $this->assertSame($expected, $draft->checklist['compartments'][$c]['items'][$i]['id']);
            }
        }
        $this->assertFalse($this->editor()->diff($file, $draft->checklist)['has_changes']);
    }

    public function test_field_rules_are_reported_by_form_position(): void
    {
        $draft = $this->editor()->startDraft('engine', $this->manager);
        $form = $this->editor()->toForm($draft->checklist);
        $form['compartments'][0]['items'][1]['name'] = '';
        $form['compartments'][0]['items'][2]['expectedQuantity'] = 1000;
        $form['compartments'][1]['items'][0]['name'] = $form['compartments'][1]['items'][1]['name'];

        try {
            $this->editor()->saveDraft($draft, $form, $this->manager, $draft->lock_version);
            $this->fail('invalid form saved');
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            $this->assertArrayHasKey('compartments.0.items.1.name', $errors);
            $this->assertArrayHasKey('compartments.0.items.2.expectedQuantity', $errors);
            $this->assertArrayHasKey('compartments.1.items.1.name', $errors);
        }
        $this->assertSame(0, $draft->fresh()->lock_version, 'nothing saved');
    }

    public function test_a_stale_lock_version_and_a_stale_fingerprint_are_refused(): void
    {
        $draft = $this->editor()->startDraft('engine', $this->manager);
        $form = $this->editor()->toForm($draft->checklist);
        [$ci, $ii] = $this->position($form, 'Passports');
        $form['compartments'][$ci]['items'][$ii]['expectedQuantity'] = 3;
        $saved = $this->editor()->saveDraft($draft, $form, $this->manager, 0);

        $this->assertThrows(
            fn () => $this->editor()->saveDraft($draft, $form, $this->manager, 0),
            ChecklistTemplateException::class,
            'This draft was changed by someone else. Reload and try again.',
        );

        $preview = $this->editor()->preview($saved);
        $this->assertThrows(
            fn () => $this->editor()->publish($saved, $this->manager, 'Quantity', $preview['fingerprint'], 0),
            ChecklistTemplateException::class,
            'This draft was changed by someone else. Reload and try again.',
        );

        // The vehicles changed after the preview: E3 now uses another checklist.
        $this->units['E3']->forceFill(['daily_checkout_template' => 'engine2'])->save();
        $this->assertThrows(
            fn () => $this->editor()->publish($saved, $this->manager, 'Quantity', $preview['fingerprint'], $saved->lock_version),
            ChecklistTemplateException::class,
            'The checklist or its vehicles changed since you previewed. Review the changes again.',
        );
        $this->assertSame(Version::STATUS_DRAFT, $saved->fresh()->status);
        $this->assertSame('file', $this->editor()->live('engine')['source']);
    }

    public function test_a_draft_whose_base_was_published_by_someone_else_cannot_be_published(): void
    {
        $draft = $this->editor()->startDraft('engine', $this->manager);
        $form = $this->editor()->toForm($draft->checklist);
        [$ci, $ii] = $this->position($form, 'Passports');
        $form['compartments'][$ci]['items'][$ii]['expectedQuantity'] = 3;
        $draft = $this->editor()->saveDraft($draft, $form, $this->manager, $draft->lock_version);
        $preview = $this->editor()->preview($draft);

        // Someone publishes a different version of the same checklist in the meantime.
        Version::query()->create([
            'checklist_type' => 'engine', 'status' => Version::STATUS_PUBLISHED, 'version_number' => 1,
            'checklist' => $form = ['compartments' => [['id' => 'x', 'title' => 'X', 'items' => [['id' => 'x-1', 'name' => 'Y']]]]],
            'checklist_hash' => str_repeat('a', 64), 'based_on_hash' => str_repeat('b', 64), 'published_at' => now(),
        ]);
        app(ChecklistTemplateRepository::class)->flush();

        $this->assertThrows(
            fn () => $this->editor()->publish($draft, $this->manager, 'Quantity', $preview['fingerprint'], $draft->lock_version),
            ChecklistTemplateException::class,
        );
        $after = $this->editor()->preview($draft);
        $this->assertFalse($after['publishable']);
        $this->assertNotSame([], $after['errors']);
    }

    public function test_only_a_logistics_administrator_may_change_templates(): void
    {
        $reviewer = $this->fleetOnlyReviewer();

        $this->assertThrows(fn () => $this->editor()->startDraft('engine', $reviewer), AuthorizationException::class);
        $this->assertThrows(fn () => $this->editor()->startDraft('engine', $this->member), AuthorizationException::class);
        $draft = $this->editor()->startDraft('engine', $this->manager);
        $this->assertThrows(fn () => $this->editor()->saveDraft($draft, $this->editor()->toForm($draft->checklist), $reviewer, 0), AuthorizationException::class);
        $this->assertThrows(fn () => $this->editor()->discardDraft($draft, $reviewer), AuthorizationException::class);
        $this->assertThrows(fn () => $this->editor()->publish($draft, $reviewer, 'Nope', 'x', 0), AuthorizationException::class);
        $this->assertThrows(fn () => $this->editor()->startDraft('pending', $this->manager), ChecklistTemplateException::class);
    }

    public function test_reverting_to_the_file_restores_the_file_baseline_and_hash(): void
    {
        $original = $this->resolved('L1')['checklist_version'];
        $draft = $this->editor()->startDraft('ladder1', $this->manager);
        $form = $this->editor()->toForm($draft->checklist);
        $form['compartments'][0]['name'] .= ' (front)';
        $draft = $this->editor()->saveDraft($draft, $form, $this->manager, $draft->lock_version);
        $renamed = collect($draft->checklist['compartments'])->first();
        $this->assertNotSame([], $renamed['legacyCompartmentNames']);
        $this->publish($draft, 'Rename the first compartment');
        $published = $this->resolved('L1')['checklist_version'];
        $this->assertNotSame($original, $published);

        $preview = $this->editor()->previewRevertToFile('ladder1');
        $this->assertTrue($preview['publishable'], implode(' ', $preview['errors']));
        $this->editor()->revertToFile('ladder1', $this->manager, 'Back to the repository file', $preview['fingerprint']);

        $this->assertSame($original, $this->resolved('L1')['checklist_version']);
        $this->assertSame('file', $this->editor()->live('ladder1')['source']);
        $this->assertSame(Version::STATUS_RETIRED, Version::query()->where('checklist_type', 'ladder1')->sole()->status);
        $this->assertNotNull(app(ChecklistRevisionStore::class)->recentlyRetired($this->units['L1']->id, $published));
        $this->assertFalse($this->editor()->previewRevertToFile('ladder1')['publishable'], 'already on the file');
        $this->assertSame(1, DailyCheckoutChecklistTemplateEvent::query()->where('action', DailyCheckoutChecklistTemplateEvent::REVERTED_TO_FILE)->count());
    }

    public function test_import_replaces_items_but_may_not_change_officer_fields(): void
    {
        $live = $this->editor()->live('engine')['checklist'];

        $changedOfficer = $live;
        $changedOfficer['officerChecklist'][0]['name'] = 'Something else';
        $this->assertThrows(
            fn () => $this->editor()->importIntoDraft('engine', json_encode($changedOfficer), $this->manager),
            ValidationException::class,
            'Import may only change compartments and items; officer fields and schedules are managed in the repository.',
        );
        $this->assertThrows(fn () => $this->editor()->importIntoDraft('engine', '{not json', $this->manager), ValidationException::class);

        $import = $live;
        $import['compartments'][0]['items'][0]['name'] = 'Imported name';
        $import['compartments'][0]['items'][] = ['name' => 'Imported new item', 'inputType' => 'checkbox'];
        $draft = $this->editor()->importIntoDraft('engine', json_encode($import), $this->manager);

        $first = $draft->checklist['compartments'][0]['items'][0];
        $this->assertSame('Imported name', $first['name']);
        $this->assertContains($live['compartments'][0]['items'][0]['name'], $first['legacyItemNames']);
        $items = $draft->checklist['compartments'][0]['items'];
        $new = end($items);
        $this->assertMatchesRegularExpression('/-i[a-z0-9]{8}$/', $new['id']);
        $this->assertSame(1, DailyCheckoutChecklistTemplateEvent::query()->where('action', DailyCheckoutChecklistTemplateEvent::DRAFT_IMPORTED)->count());
        $this->assertTrue($this->editor()->preview($draft)['publishable']);
    }

    public function test_restore_discard_history_and_events(): void
    {
        $draft = $this->editor()->startDraft('rescue', $this->manager);
        $form = $this->editor()->toForm($draft->checklist);
        $form['compartments'][0]['items'][0]['note'] = 'Check the seal';
        $draft = $this->editor()->saveDraft($draft, $form, $this->manager, $draft->lock_version);
        $v1 = $this->publish($draft, 'Seal note');

        $draft = $this->editor()->startDraft('rescue', $this->manager);
        $form = $this->editor()->toForm($draft->checklist);
        $form['compartments'][0]['items'][0]['note'] = '';
        $draft = $this->editor()->saveDraft($draft, $form, $this->manager, $draft->lock_version);
        $this->publish($draft, 'Drop the seal note');

        $restored = $this->editor()->restoreAsDraft($v1, $this->manager);
        $this->assertSame('Check the seal', $restored->checklist['compartments'][0]['items'][0]['note']);
        $this->assertSame([2, 1], $this->editor()->history('rescue')->pluck('version_number')->all());

        $this->editor()->discardDraft($restored, $this->manager);
        $this->assertNull($this->editor()->draft('rescue'));
        $this->assertSame(
            ['draft_discarded', 'draft_restored', 'draft_started', 'published', 'draft_saved', 'draft_started', 'published', 'draft_saved', 'draft_started'],
            $this->editor()->events('rescue')->pluck('action')->all(),
        );

        $overview = collect($this->editor()->overview())->keyBy('type');
        $this->assertSame('published', $overview['rescue']['source']);
        $this->assertSame(2, $overview['rescue']['published']['version_number']);
        $this->assertFalse($overview['rescue']['file_drift']);
        $this->assertSame('file', $overview['engine']['source']);
        $this->assertSame(['E1', 'E3', 'E21'], array_column($overview['engine']['units'], 'label'));
    }

    public function test_preview_reports_open_issues_and_medical_scope_for_new_items(): void
    {
        $passports = $this->item('E1', 'Passports');
        \App\Models\ApparatusDefect::query()->create([
            'apparatus_id' => $this->units['E1']->id, 'compartment' => $passports['compartment'], 'item' => 'Passports',
            'status' => 'open', 'issue_type' => 'missing', 'reported_date' => now()->toDateString(), 'resolved' => false,
        ]);

        $draft = $this->editor()->startDraft('engine', $this->manager);
        $form = $this->editor()->toForm($draft->checklist);
        [$ci, $ii] = $this->position($form, 'Passports');
        array_splice($form['compartments'][$ci]['items'], $ii, 1);
        $form['compartments'][$ci]['items'][] = ['id' => '', 'name' => 'Trauma shears', 'compartment_id' => $form['compartments'][$ci]['id']];
        $draft = $this->editor()->saveDraft($draft, $form, $this->manager, $draft->lock_version);

        $preview = $this->editor()->preview($draft);
        $e1 = collect($preview['units'])->firstWhere('id', $this->units['E1']->id);
        $this->assertSame(1, $e1['open_issues_on_removed_items']);
        $this->assertSame(1, $preview['diff']['counts']['removed']);
        $this->assertSame(1, $preview['diff']['counts']['added']);
        $this->assertNotEmpty(array_filter($preview['warnings'], static fn (string $warning): bool => str_contains($warning, 'Captain 5')));

        $this->publish($draft, 'Swap passports for shears');
        $shears = $this->item('E1', 'Trauma shears')['item_id'];
        $this->assertSame(MedicalScope::UNCLASSIFIED, app(MedicalScope::class)->classify('engine', $shears));
    }

    public function test_equipment_rules_read_the_published_baseline(): void
    {
        $draft = $this->editor()->startDraft('engine', $this->manager);
        $form = $this->editor()->toForm($draft->checklist);
        $form['compartments'][0]['items'][] = ['id' => '', 'name' => 'Zzyzx unique test tool'];
        $draft = $this->editor()->saveDraft($draft, $form, $this->manager, $draft->lock_version);
        $this->publish($draft, 'Add test tool');

        $method = new \ReflectionMethod(EquipmentRuleService::class, 'checklistItemNames');
        $names = $method->invoke(app(EquipmentRuleService::class));
        $this->assertArrayHasKey('Zzyzx unique test tool', $names['engine']);
        $this->assertArrayHasKey('Passports', $names['engine2']);
    }

    public function test_status_and_export_commands_run(): void
    {
        $this->artisan('checklist-templates:status')->assertSuccessful();
        $this->artisan('checklist-templates:export', ['type' => 'nope'])->assertFailed();

        $file = (string) file_get_contents(base_path('storage/checklists/default_checklist.json'));
        $expected = $this->editor()->exportJson(json_decode($file, true));
        $this->artisan('checklist-templates:export', ['type' => 'default'])
            ->expectsOutputToContain('"compartments"')
            ->assertSuccessful();

        $directory = sys_get_temp_dir().'/mbfd-template-export-'.uniqid('', true);
        mkdir($directory);
        copy(base_path('storage/checklists/default_checklist.json'), "{$directory}/default_checklist.json");
        $this->app->instance(DailyCheckoutChecklistResolver::class, new DailyCheckoutChecklistResolver($directory));
        try {
            $this->artisan('checklist-templates:export', ['type' => 'default', '--write' => true])
                ->expectsOutputToContain('Wrote')
                ->assertSuccessful();
            $this->assertSame($expected, file_get_contents("{$directory}/default_checklist.json"));
        } finally {
            @unlink("{$directory}/default_checklist.json");
            @rmdir($directory);
        }
    }

    // ------------------------------------------------------------------ helpers

    private function editor(): ChecklistTemplateEditor
    {
        return app(ChecklistTemplateEditor::class);
    }

    private function publish(Version $draft, string $summary): Version
    {
        $draft = $draft->fresh();
        $preview = $this->editor()->preview($draft);
        $this->assertTrue($preview['publishable'], implode(' ', [...$preview['errors'], ...$preview['warnings']]));

        return $this->editor()->publish($draft, $this->manager, $summary, $preview['fingerprint'], $draft->lock_version);
    }

    /**
     * @param  array<string, mixed>  $form
     * @return array{0: int, 1: int}
     */
    private function position(array $form, string $name): array
    {
        foreach ($form['compartments'] as $ci => $compartment) {
            foreach ($compartment['items'] as $ii => $item) {
                if ($item['name'] === $name) {
                    return [$ci, $ii];
                }
            }
        }
        $this->fail("{$name} not on the form");
    }

    /** @return array<string, mixed> */
    private function draftItem(Version $draft, string $id): array
    {
        return $this->findItem($draft->checklist, $id);
    }

    /** @return array<string, mixed> */
    private function baseItem(Version $draft, string $id): array
    {
        return $this->findItem($draft->base_checklist, $id);
    }

    /**
     * @param  array<string, mixed>  $checklist
     * @return array<string, mixed>
     */
    private function findItem(array $checklist, string $id): array
    {
        foreach ($checklist['compartments'] as $compartment) {
            foreach ($compartment['items'] as $item) {
                if (($item['id'] ?? null) === $id) {
                    return $item;
                }
            }
        }
        $this->fail("{$id} not found");
    }

    /**
     * @param  array<string, mixed>  $checklist
     * @return array<string, mixed>
     */
    private function itemWithKey(array $checklist, string $key): array
    {
        foreach ($checklist['compartments'] as $compartment) {
            foreach ($compartment['items'] as $item) {
                if (array_key_exists($key, $item)) {
                    return $item;
                }
            }
        }
        $this->fail("no item with {$key}");
    }
}
