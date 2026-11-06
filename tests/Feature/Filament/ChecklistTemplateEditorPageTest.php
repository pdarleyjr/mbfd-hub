<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\ChecklistTemplateResource;
use App\Filament\Resources\ChecklistTemplateResource\Pages\EditChecklistTemplate;
use App\Filament\Resources\ChecklistTemplateResource\Pages\ListChecklistTemplates;
use App\Models\DailyCheckoutChecklistTemplateVersion;
use App\Models\User;
use App\Services\ChecklistTemplates\ChecklistTemplateEditor;
use App\Support\HubTimezone;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Feature\Inventory\Concerns\BuildsInventoryCorrectionFixtures;
use Tests\TestCase;

/** Fleet > Checklist templates: who sees what, and the draft → preview → publish → discard flow (docs/checklist-template-editor.md section 7). */
final class ChecklistTemplateEditorPageTest extends TestCase
{
    use BuildsInventoryCorrectionFixtures;
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInventoryFleet();
        $this->manager = $this->logistics();
        $this->actingAs($this->manager);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_a_viewer_sees_the_index_and_a_read_only_editor(): void
    {
        $viewer = $this->fleetOnlyReviewer();
        $this->actingAs($viewer);

        $this->get(ChecklistTemplateResource::getUrl('index'))
            ->assertOk()
            ->assertSee($this->editor()->label('engine'))
            ->assertSee('Repository file')
            ->assertSee('View');
        $this->get(ChecklistTemplateResource::getUrl('editor', ['type' => 'engine']))
            ->assertOk()
            ->assertSee('How template changes work')
            ->assertSee('Front Cab')
            ->assertSee('Editing and publishing need a Logistics or administrator role');

        Livewire::test(EditChecklistTemplate::class, ['type' => 'engine'])
            ->assertOk()
            ->assertFormFieldIsDisabled('compartments')
            ->assertActionHidden('saveDraft')
            ->assertActionHidden('publish')
            ->assertActionHidden('importJson')
            ->assertActionHidden('discardDraft')
            ->assertActionVisible('exportJson');
    }

    public function test_members_who_cannot_review_are_refused(): void
    {
        $this->actingAs($this->member);

        $this->assertFalse(ChecklistTemplateResource::canViewAny());
        $this->get(ChecklistTemplateResource::getUrl('index', panel: 'admin'))->assertForbidden();
        $this->get(ChecklistTemplateResource::getUrl('editor', ['type' => 'engine'], panel: 'admin'))->assertForbidden();
    }

    public function test_unknown_checklist_types_are_not_found(): void
    {
        $this->get(ChecklistTemplateResource::getUrl('index').'/not-a-checklist')->assertNotFound();
        $this->get(ChecklistTemplateResource::getUrl('index').'/pending')->assertNotFound();
    }

    public function test_a_stale_tab_never_overwrites_a_draft_started_elsewhere_even_by_the_same_user(): void
    {
        // Tab 1 opens while there is no draft: it shows the live checklist.
        $page = $this->page();
        $itemPath = $this->itemPath($page, 'front_cab', 'Passports');

        // Tab 2 (same administrator) starts and saves a draft.
        $draft = $this->editor()->startDraft('engine', $this->manager);
        $form = $this->editor()->toForm($draft->checklist);
        $form['compartments'][0]['items'][0]['manufacturer'] = 'Saved in tab 2';
        $saved = $this->editor()->saveDraft($draft, $form, $this->manager, $draft->lock_version);

        $page->set($itemPath.'.name', 'Edited in tab 1')
            ->callAction('saveDraft')
            ->assertNotified('A draft of this checklist was started elsewhere');

        $after = $saved->fresh();
        $this->assertSame($saved->lock_version, $after->lock_version);
        $this->assertSame('Saved in tab 2', $after->checklist['compartments'][0]['items'][0]['manufacturer']);
    }

    public function test_a_manager_saves_a_draft_previews_it_and_publishes_it(): void
    {
        $page = $this->page();
        $page->assertActionVisible('saveDraft')->assertActionHidden('discardDraft')->assertActionHidden('revertToFile');
        $itemPath = $this->itemPath($page, 'front_cab', 'Passports');

        $page->set($itemPath.'.name', 'Passport binders')
            ->set($itemPath.'.expectedQuantity', '3')
            ->set($itemPath.'.manufacturer', 'Pelican')
            ->callAction('saveDraft')
            ->assertHasNoErrors()
            ->assertNotified('Draft saved')
            ->assertActionVisible('discardDraft');

        $draft = $this->editor()->draft('engine');
        $this->assertNotNull($draft);
        $saved = collect($draft->checklist['compartments'])->firstWhere('id', 'front_cab');
        $item = collect($saved['items'])->firstWhere('name', 'Passport binders');
        $this->assertNotNull($item, 'the rename is in the draft');
        $this->assertSame(3, $item['expectedQuantity']);
        $this->assertSame('Pelican', $item['manufacturer']);
        $this->assertSame('front_cab-item-3', $item['id'], 'the item keeps its id');
        $this->assertNull($this->editor()->live('engine')['version'], 'members still get the repository file');

        $page->mountAction('publish')
            ->assertSee('Passport binders')
            ->assertSee('Pelican')
            ->assertSee('E1')
            ->assertSee('Publishing changes the checklist for')
            ->setActionData(['summary' => 'Passports are kept in binders now.', 'confirm' => true])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('Published v1');

        $live = $this->editor()->live('engine');
        $this->assertSame('published', $live['source']);
        $this->assertSame(1, $live['version']?->version_number);
        $this->assertNull($this->editor()->draft('engine'));
        $this->assertSame('Passport binders', $this->item('E1', 'Passport binders')['item']['name']);

        $this->get(ChecklistTemplateResource::getUrl('index'))->assertOk()->assertSee('Published v1')->assertSee('Passports are kept in binders now.');
        Livewire::test(ListChecklistTemplates::class)->loadTable()->assertOk()->assertSee('Published v1')
            ->assertCanSeeTableRecords(DailyCheckoutChecklistTemplateVersion::query()->where('status', 'published')->get());
        $this->get(ChecklistTemplateResource::getUrl('editor', ['type' => 'engine']))->assertOk()
            ->assertSee('Version history')->assertSee('v1')->assertSee('Passports are kept in binders now.');
    }

    public function test_publishing_requires_a_summary_and_the_confirmation(): void
    {
        $page = $this->page();
        $itemPath = $this->itemPath($page, 'front_cab', 'Passports');
        $page->set($itemPath.'.expectedQuantity', '4')
            ->mountAction('publish')
            ->setActionData(['summary' => '', 'confirm' => false])
            ->callMountedAction()
            ->assertHasActionErrors(['summary' => 'required', 'confirm' => 'accepted']);

        $this->assertNotNull($this->editor()->draft('engine'), 'opening the preview saved the edit as a draft');
        $this->assertSame('file', $this->editor()->live('engine')['source']);
    }

    public function test_a_manager_discards_the_draft(): void
    {
        $page = $this->page();
        $itemPath = $this->itemPath($page, 'front_cab', 'Passports');
        $page->set($itemPath.'.name', 'Passport wallet')->callAction('saveDraft')->assertHasNoErrors();
        $this->assertNotNull($this->editor()->draft('engine'));

        $page->callAction('discardDraft')->assertNotified('Draft discarded')->assertActionHidden('discardDraft');

        $this->assertNull($this->editor()->draft('engine'));
        $this->assertSame('Passports', $this->item('E1', 'Passports')['item']['name']);
        $this->itemPath($page, 'front_cab', 'Passports'); // the form shows the live checklist again
    }

    public function test_invalid_rows_are_refused_before_anything_is_saved(): void
    {
        $page = $this->page();
        $itemPath = $this->itemPath($page, 'front_cab', 'Passports');
        $page->set($itemPath.'.name', '')
            ->set($itemPath.'.expectedQuantity', '1000')
            ->callAction('saveDraft')
            ->assertHasErrors([$itemPath.'.name', $itemPath.'.expectedQuantity']);

        $this->assertNull($this->editor()->draft('engine'));
        // The refused row's compartment is opened so its errors are visible.
        $this->assertSame(explode('.', $itemPath)[2], $page->get('openCompartment'));

        // Correcting the row and saving again works: the refused first save left no draft behind.
        $page->set($itemPath.'.name', 'Passports')
            ->set($itemPath.'.expectedQuantity', '2')
            ->callAction('saveDraft')
            ->assertHasNoErrors()
            ->assertNotified('Draft saved');
        $this->assertNotNull($this->editor()->draft('engine'));
    }

    public function test_items_are_edited_one_compartment_at_a_time_and_closed_edits_are_kept(): void
    {
        $page = $this->page();
        $keys = array_keys((array) $page->get('data.compartments'));
        $this->assertNull($page->get('openCompartment'));

        $page->callFormComponentAction('compartments', 'toggleItems', arguments: ['item' => $keys[0]]);
        $this->assertSame($keys[0], $page->get('openCompartment'));
        $page->callFormComponentAction('compartments', 'toggleItems', arguments: ['item' => $keys[1]]);
        $this->assertSame($keys[1], $page->get('openCompartment'));

        // An edit in a compartment that is not open is still saved.
        $itemPath = $this->itemPath($page, 'front_cab', 'Passports');
        $page->set($itemPath.'.manufacturer', 'Saved while closed')->callAction('saveDraft')->assertNotified('Draft saved');
        $this->assertContains('Saved while closed', array_column(array_merge(...array_column($this->editor()->draft('engine')->checklist['compartments'], 'items')), 'manufacturer'));

        // The open compartment stays open across the save.
        $this->assertSame(array_keys((array) $page->get('data.compartments'))[1], $page->get('openCompartment'));
    }

    public function test_export_downloads_the_checklist_shown_as_json(): void
    {
        $this->actingAs($this->fleetOnlyReviewer());
        $name = 'engine-checklist-'.now(HubTimezone::NAME)->format('Y-m-d').'.json';

        $page = Livewire::test(EditChecklistTemplate::class, ['type' => 'engine'])
            ->callAction('exportJson')
            ->assertFileDownloaded($name);

        $download = $page->effects['download'] ?? [];
        $json = json_decode((string) base64_decode((string) ($download['content'] ?? ''), true), true);
        $this->assertIsArray($json);
        $this->assertSame('front_cab', $json['compartments'][0]['id'] ?? null);
    }

    public function test_import_history_restore_and_revert_from_the_page(): void
    {
        $exported = $this->editor()->exportJson($this->editor()->live('engine')['checklist']);
        $renamed = str_replace('"Passports"', '"Passport case"', $exported);
        $this->assertNotSame($exported, $renamed);

        $this->page()
            ->callAction('importJson', data: ['json' => '{"not": "a checklist"}'])
            ->assertNotified('Nothing was changed');
        $this->page()
            ->callAction('importJson', data: ['json' => $renamed])
            ->assertHasNoActionErrors()
            ->assertNotified('Imported into the draft');
        $this->assertNotNull($this->editor()->draft('engine'));

        $this->publishDraft('Passport case.');
        $draft = $this->editor()->startDraft('engine', $this->manager);
        $form = $this->editor()->toForm($draft->checklist);
        $form['compartments'][0]['items'][0]['name'] = 'Rugged laptop';
        $this->editor()->saveDraft($draft, $form, $this->manager, (int) $draft->lock_version);
        $this->publishDraft('Toughbook is now a rugged laptop.');
        [$v2, $v1] = $this->editor()->history('engine')->all();

        $page = $this->page()->assertSee('Toughbook is now a rugged laptop.')->assertActionVisible('revertToFile');
        $page->mountAction('compareVersion', ['version' => $v2->id])->assertSee('Rugged laptop')->assertSee('Compared with v1.');
        $this->page()->mountAction('viewJson', ['version' => $v1->id])->assertSee('Passport case');
        $this->page()->callAction('restoreVersion', arguments: ['version' => $v1->id])
            ->assertNotified('v1 restored as the draft');
        $this->assertSame('Toughbook', $this->editor()->draft('engine')->checklist['compartments'][0]['items'][0]['name']);

        $this->page()->callAction('discardDraft');
        $this->page()->mountAction('revertToFile')
            ->assertSee('Toughbook')
            ->setActionData(['summary' => 'Back to the repository list.', 'confirm' => true])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('Reverted to the repository file');
        $this->assertSame('file', $this->editor()->live('engine')['source']);
    }

    private function publishDraft(string $summary): void
    {
        $draft = $this->editor()->draft('engine');
        $preview = $this->editor()->preview($draft);
        $this->assertTrue($preview['publishable'], implode(' ', $preview['errors']));
        $this->editor()->publish($draft, $this->manager, $summary, $preview['fingerprint'], (int) $draft->lock_version);
    }

    private function page(): Testable
    {
        return Livewire::test(EditChecklistTemplate::class, ['type' => 'engine'])->assertOk();
    }

    /** "data.compartments.{key}.items.{key}" for the named item in a compartment, from the form's repeater keys. */
    private function itemPath(Testable $page, string $compartmentId, string $itemName): string
    {
        foreach ((array) $page->get('data.compartments') as $compartmentKey => $compartment) {
            if (($compartment['id'] ?? null) !== $compartmentId) {
                continue;
            }
            foreach ((array) ($compartment['items'] ?? []) as $itemKey => $item) {
                if (($item['name'] ?? null) === $itemName) {
                    return "data.compartments.{$compartmentKey}.items.{$itemKey}";
                }
            }
        }
        $this->fail("{$itemName} is not in {$compartmentId} on the form");
    }

    private function editor(): ChecklistTemplateEditor
    {
        return app(ChecklistTemplateEditor::class);
    }
}
