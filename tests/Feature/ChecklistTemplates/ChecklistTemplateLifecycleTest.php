<?php

declare(strict_types=1);

namespace Tests\Feature\ChecklistTemplates;

use App\Models\ApparatusDefect;
use App\Models\ApparatusInspection;
use App\Services\ChecklistTemplates\ChecklistTemplateEditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Inventory\Concerns\BuildsInventoryCorrectionFixtures;
use Tests\TestCase;

/**
 * Start to finish through the member API (docs/checklist-template-editor.md): Logistics edits and publishes
 * the Engine template; members get the new checklist from the Daily Checkout API, submit against it, keep
 * their open issue on the renamed and moved item, and an inspection started before the publish is still
 * accepted. Other checklists are untouched, and reverting restores the original exactly.
 */
final class ChecklistTemplateLifecycleTest extends TestCase
{
    use BuildsInventoryCorrectionFixtures;
    use RefreshDatabase;

    public function test_a_published_template_reaches_members_and_keeps_history_and_in_flight_work(): void
    {
        $this->setUpInventoryFleet();
        $e1 = $this->units['E1']->id;
        $original = [
            'E1' => $this->resolved('E1')['checklist_version'],
            'E2' => $this->resolved('E2')['checklist_version'],
            'L1' => $this->resolved('L1')['checklist_version'],
        ];

        // A member reports the binoculars missing; an open issue exists before the template changes.
        $this->postJson("/api/public/apparatuses/{$e1}/inspections", $this->inspectionPayload('E1', 'Binoculars'))->assertCreated();
        $defect = ApparatusDefect::query()->where('apparatus_id', $e1)->where('item', 'Binoculars')->sole();
        $binoculars = $this->item('E1', 'Binoculars');

        // Another member opened the checkout before the publish and submits after it.
        $startedBefore = $this->inspectionPayload('E1');

        // Logistics edits the Engine template: rename + move + quantity + brand, and a new item.
        $manager = $this->logistics();
        $editor = app(ChecklistTemplateEditor::class);
        $draft = $editor->startDraft('engine', $manager);
        $form = $editor->toForm($draft->checklist);
        foreach ($form['compartments'] as $ci => $compartment) {
            foreach ($compartment['items'] as $ii => $item) {
                if ($item['name'] === 'Binoculars') {
                    $form['compartments'][$ci]['items'][$ii] = [...$item, 'name' => 'Binoculars 10x50', 'expectedQuantity' => 2,
                        'manufacturer' => 'Steiner', 'model' => 'Commander', 'compartment_id' => 'rear_cab'];
                }
            }
            if ($compartment['id'] === 'front_cab') {
                $form['compartments'][$ci]['items'][] = ['id' => '', 'name' => 'Thermal camera', 'expectedQuantity' => 1, 'manufacturer' => 'Bullard',
                    'model' => null, 'description' => 'Charged, in its case', 'note' => null, 'compartment_id' => 'front_cab'];
            }
        }
        $draft = $editor->saveDraft($draft, $form, $manager, $draft->lock_version);
        $preview = $editor->preview($draft);
        $this->assertTrue($preview['publishable'], implode(' ', $preview['errors']));
        $this->assertSame(1, $preview['diff']['counts']['added']);
        $this->assertSame(1, $preview['diff']['counts']['changed']);
        $editor->publish($draft, $manager, 'Binoculars moved to the rear cab; thermal camera added', $preview['fingerprint'], $draft->lock_version);

        // Members get the new checklist from the Daily Checkout API, with brand, model, quantity and location.
        $response = $this->actingAs($this->member)->getJson("/api/public/apparatuses/{$e1}/checklist")->assertOk();
        $checklist = $response->json('checklist');
        $byName = [];
        foreach ($checklist['compartments'] as $compartment) {
            foreach ($compartment['items'] as $item) {
                $byName[$item['name']] = [...$item, 'compartment_id' => $compartment['id']];
            }
        }
        $this->assertArrayNotHasKey('Binoculars', $byName);
        $this->assertSame('rear_cab', $byName['Binoculars 10x50']['compartment_id']);
        $this->assertSame($binoculars['item_id'], $byName['Binoculars 10x50']['id'], 'the item keeps its id');
        $this->assertSame(['Steiner', 'Commander', 2], [$byName['Binoculars 10x50']['manufacturer'], $byName['Binoculars 10x50']['model'], $byName['Binoculars 10x50']['expectedQuantity']]);
        $this->assertSame(['Bullard', 'Charged, in its case', 'front_cab'], [$byName['Thermal camera']['manufacturer'], $byName['Thermal camera']['description'], $byName['Thermal camera']['compartment_id']]);
        $this->assertNotSame($original['E1'], $response->json('checklist_version'));
        $this->assertSame($this->resolved('E1')['checklist_version'], $response->json('checklist_version'));
        // The open issue follows the item to its new place.
        $response->assertJsonPath('open_findings.0.compartment_id', 'rear_cab')
            ->assertJsonPath('open_findings.0.item_id', $binoculars['item_id']);

        // A checkout against the new checklist is accepted, and the missing binoculars are the same issue.
        $this->postJson("/api/public/apparatuses/{$e1}/inspections", $this->inspectionPayload('E1', 'Binoculars 10x50'))->assertCreated();
        $this->assertSame(1, ApparatusDefect::query()->where('apparatus_id', $e1)->where('resolved', false)->count());
        $this->assertSame(2, $defect->fresh()->observations()->count());

        // The checkout started before the publish is still accepted (grace revision) and records that version.
        $this->postJson("/api/public/apparatuses/{$e1}/inspections", $startedBefore)->assertCreated();
        $this->assertSame($original['E1'], ApparatusInspection::query()->latest('id')->firstOrFail()->checklist_version);

        // Other checklists never changed; the other Engine-template units did.
        $this->assertSame($original['E2'], $this->resolved('E2')['checklist_version']);
        $this->assertSame($original['L1'], $this->resolved('L1')['checklist_version']);
        $this->assertSame('Binoculars 10x50', $this->item('E3', 'Binoculars 10x50')['item']['name']);

        // Reverting restores the repository file exactly.
        $revert = $editor->previewRevertToFile('engine');
        $editor->revertToFile('engine', $manager, 'Back to the paper checklist', $revert['fingerprint']);
        $this->assertSame($original['E1'], $this->resolved('E1')['checklist_version']);
    }
}
