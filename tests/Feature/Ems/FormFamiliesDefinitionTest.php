<?php

declare(strict_types=1);

namespace Tests\Feature\Ems;

use App\Models\Apparatus;
use App\Models\Ems\SourceForm;
use App\Models\Ems\SourceFormItem;
use App\Services\Ems\FormFamilies;
use App\Services\Ems\Import\SourceImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Ems\Concerns\BuildsEmsWorld;
use Tests\TestCase;

/** The four form families (docs/ems/four-inventory-forms.md): the table, the member form definition and the importer's additive fields. */
final class FormFamiliesDefinitionTest extends TestCase
{
    use BuildsEmsWorld;
    use RefreshDatabase;

    private const FORM = '/employee/forms/api/ems/form';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildEmsWorld();
    }

    protected function tearDown(): void
    {
        $this->tearDownEmsWorld();
        parent::tearDown();
    }

    // ------------------------------------------------------------------ the table

    public function test_the_four_families_are_the_contract_table_in_order(): void
    {
        $this->assertSame(
            [
                ['als-transport', 'ALS Transport Inventory Form', 'ALS Transport', 'als-transport-inventory', ['airway-bag', 'als-med-bag', 'als-transport-vehicle', 'handtevy']],
                ['als-non-transport', 'ALS Non Transport Inventory Form', 'ALS Non Transport', 'als-non-transport-inventory', ['als-non-transport', 'handtevy']],
                ['fire-boat-6', 'Fire Boat 6 Medical Inventory Form', 'Fire Boat 6', 'fire-boat-6-medical-inventory', ['fire-boat-6-als']],
                ['bls-detail', 'BLS Detail Medical Inventory Form', 'BLS Detail', 'bls-detail-medical-inventory', ['bls-detail-bag']],
            ],
            array_map(fn (array $f): array => [$f['key'], $f['title'], $f['short_title'], $f['form_key'], $f['bag_keys']], FormFamilies::all()),
        );
        foreach (FormFamilies::all() as $family) {
            $this->assertNotSame('', $family['description']);
        }
        $this->assertSame(
            ['airway-bag', 'als-med-bag', 'als-transport-vehicle', 'handtevy', 'als-non-transport', 'fire-boat-6-als', 'bls-detail-bag'],
            FormFamilies::allBagKeys(),
            'Handtevy is one list used by two forms.',
        );
    }

    public function test_lookups_treat_nothing_and_the_legacy_key_as_als_transport(): void
    {
        $this->assertSame('als-transport', FormFamilies::resolve(null)['key']);
        $this->assertSame('als-transport', FormFamilies::resolve('')['key']);
        $this->assertSame('als-transport', FormFamilies::resolve('a-future-family')['key'], 'A value this release does not know never breaks a reader.');
        $this->assertSame('bls-detail', FormFamilies::resolve('bls-detail')['key']);

        $this->assertSame('als-transport', FormFamilies::fromFormKey('medical-inventory-request')['key']);
        $this->assertSame('fire-boat-6', FormFamilies::fromFormKey('fire-boat-6-medical-inventory')['key']);
        $this->assertNull(FormFamilies::fromFormKey('fire-boat-6'), 'A family key is not a deep-link key.');
        $this->assertNull(FormFamilies::fromFormKey(null));

        $this->assertNull(FormFamilies::find('nope'));
        $this->assertSame(['handtevy'], array_slice(FormFamilies::bagsFor('als-non-transport'), 1));
        $this->assertSame([], FormFamilies::bagsFor('nope'));
    }

    // ---------------------------------------------------------- GET /form (member)

    public function test_without_a_family_the_form_answers_as_before_for_als_transport_plus_the_new_fields(): void
    {
        $this->actingAs($this->emsMember());
        $data = $this->getJson(self::FORM)->assertOk()->assertJsonPath('ok', true)->json('data');

        // The previous response shape is intact.
        $this->assertSame(['airway-bag', 'als-med-bag'], array_column($data['forms'], 'key'), 'Only the lists that are imported appear.');
        $this->assertSame(['max_quantity', 'note_max', 'event_assignment_max'], array_keys($data['limits']));
        $this->assertTrue($data['rules']['blank_is_not_zero']);
        $this->assertArrayHasKey('units', $data);
        $this->assertSame(['key', 'title', 'version_label', 'printed_note', 'sections'], array_keys($data['forms'][0]));

        // The additive fields.
        $this->assertSame('als-transport', $data['family']['key']);
        $this->assertSame('ALS Transport Inventory Form', $data['family']['title']);
        $this->assertSame('ALS Transport', $data['family']['short_title']);
        $this->assertSame('als-transport-inventory', $data['family']['form_key']);
        $this->assertSame(['airway-bag', 'als-med-bag', 'als-transport-vehicle', 'handtevy'], $data['family']['bag_keys']);
        $this->assertNull($data['family']['default_target']);
        $this->assertSame(['als-transport', 'als-non-transport', 'fire-boat-6', 'bls-detail'], array_column($data['families'], 'key'));
        $this->assertSame(['key', 'title', 'short_title', 'form_key', 'description'], array_keys($data['families'][0]));

        $section = $data['forms'][0]['sections'][0];
        $this->assertArrayHasKey('note', $section);
        $this->assertNull($section['note']);
        $this->assertNull($section['cadence']);
        $item = $section['items'][0];
        $this->assertSame('STETHOSCOPE', $item['source_label']);
    }

    public function test_each_family_answers_with_its_own_lists_in_tab_order(): void
    {
        $this->importFamilyLists();
        $this->actingAs($this->emsMember());

        $keys = fn (string $family): array => array_column($this->getJson(self::FORM.'?family='.$family)->assertOk()->json('data.forms'), 'key');

        $this->assertSame(['airway-bag', 'als-med-bag', 'handtevy'], $keys('als-transport'), 'Handtevy closes the ALS Transport form.');
        $this->assertSame(['als-non-transport', 'handtevy'], $keys('als-non-transport'));
        $this->assertSame(['fire-boat-6-als'], $keys('fire-boat-6'));
        $this->assertSame(['bls-detail-bag'], $keys('bls-detail'));
        $this->assertSame($keys('als-transport'), array_column($this->getJson(self::FORM)->json('data.forms'), 'key'), 'No parameter is ALS Transport.');

        $this->getJson(self::FORM.'?family=bls-detail')->assertJsonPath('data.family.title', 'BLS Detail Medical Inventory Form')
            ->assertJsonPath('data.family.bag_keys', ['bls-detail-bag'])
            ->assertJsonCount(4, 'data.families');
    }

    public function test_a_family_whose_lists_are_not_imported_yet_answers_with_no_forms_not_an_error(): void
    {
        $this->actingAs($this->emsMember());

        $this->getJson(self::FORM.'?family=fire-boat-6')->assertOk()->assertJsonPath('data.forms', [])->assertJsonPath('data.family.key', 'fire-boat-6');
    }

    public function test_an_unknown_family_is_refused_in_the_standard_envelope(): void
    {
        $this->actingAs($this->emsMember());

        $this->getJson(self::FORM.'?family=nope')->assertStatus(422)
            ->assertJsonPath('ok', false)->assertJsonPath('error.code', 'unknown_family')
            ->assertJsonStructure(['error' => ['code', 'message', 'fields']]);
        $this->getJson(self::FORM.'?family='.str_repeat('x', 80))->assertStatus(422);
        // An empty value is the same as none.
        $this->getJson(self::FORM.'?family=')->assertOk()->assertJsonPath('data.family.key', 'als-transport');
    }

    public function test_the_bls_detail_form_preselects_detail_special_event(): void
    {
        $this->importFamilyLists();
        $this->actingAs($this->emsMember());

        $this->getJson(self::FORM.'?family=bls-detail')->assertOk()->assertJsonPath('data.family.default_target', 'detail');
        $this->getJson(self::FORM.'?family=als-non-transport')->assertOk()->assertJsonPath('data.family.default_target', null);
    }

    /** One request per test: the route keeps its controller (and its unit list) for the life of the app. */
    private function boatTarget(): ?string
    {
        $this->importFamilyLists();
        $this->actingAs($this->emsMember());

        return $this->getJson(self::FORM.'?family=fire-boat-6')->assertOk()->json('data.family.default_target');
    }

    public function test_the_fire_boat_6_form_preselects_nothing_when_there_is_no_boat(): void
    {
        $this->assertNull($this->boatTarget(), 'Rescue 2 and Engine 1 never qualify.');
    }

    public function test_the_fire_boat_6_form_preselects_the_one_boat_the_checklist_resolver_names(): void
    {
        $boat = $this->fireBoat6();

        $this->assertSame('unit:'.$boat->id, $this->boatTarget());
        $this->assertContains($boat->id, array_column($this->getJson(self::FORM.'?family=fire-boat-6')->json('data.units'), 'id'), 'The preselected unit is one the form can submit for.');
    }

    public function test_two_fire_boats_leave_the_choice_to_the_member(): void
    {
        $this->fireBoat6();
        // The unit identity guard now keeps FB6 on one truck; a second FB6 can only be a record saved before the guard.
        Apparatus::withoutEvents(fn () => $this->fireBoat6('In Service', 'FB-6'));

        $this->assertNull($this->boatTarget());
    }

    public function test_a_boat_that_is_not_in_the_unit_list_is_not_preselected(): void
    {
        $this->importFamilyLists();
        $this->fireBoat6('Out of Service'); // out-of-service non-rescue units are not offered as a unit choice
        $this->actingAs($this->emsMember());

        $this->assertNull($this->getJson(self::FORM.'?family=fire-boat-6')->assertOk()->json('data.family.default_target'));
        $this->assertSame(1, Apparatus::query()->where('unit_id', 'FB6')->count());
    }

    public function test_printed_details_from_the_lists_reach_the_form_without_changing_identity(): void
    {
        $this->importFamilyLists();
        $this->actingAs($this->emsMember());
        $form = collect($this->getJson(self::FORM.'?family=als-non-transport')->assertOk()->json('data.forms'))->firstWhere('key', 'handtevy');

        $sections = collect($form['sections'])->keyBy('key');
        $this->assertSame('Check each size against the Handtevy tape.', $sections['hv-bvm']['note']);
        $this->assertNull($sections['hv-bvm']['cadence']);
        $this->assertNull($sections['hv-monthly']['note']);
        $this->assertSame('monthly_first_tuesday', $sections['hv-monthly']['cadence']);

        $items = collect($sections['hv-bvm']['items'])->keyBy('item_key');
        $bvm = $items['hv.grey.bvm'];
        $this->assertSame('BVM GREY', $bvm['source_label']);
        $this->assertSame('In Handtevy', $bvm['flags']['location_text']);
        $this->assertSame('Grey', $bvm['flags']['color_band']);
        $this->assertContains('pedi bag valve', $bvm['aliases'], 'Search terms join the aliases.');
        $this->assertContains('infant bvm', $bvm['aliases']);
        $this->assertNotContains('BVM Grey', $bvm['aliases']);
        $this->assertSame('Detail Airway Bags Only', $items['hv.tape']['flags']['qualifier_text']);
        $monthly = collect($sections['hv-monthly']['items'])->firstWhere('item_key', 'hv.expiry');
        $this->assertSame('monthly_first_tuesday', $monthly['flags']['cadence']);
        $this->assertSame('yes_no', $monthly['response_type']);
    }

    // ----------------------------------------------------------------- the importer

    public function test_the_importer_keeps_only_the_allowed_flags_and_stores_the_printed_notes(): void
    {
        $counts = $this->importFamilyLists();
        foreach (['handtevy', 'als-non-transport', 'fire-boat-6-als', 'bls-detail-bag'] as $key) {
            $this->assertSame(1, $counts["forms.{$key}.new_version"], $key);
        }

        $handtevy = SourceForm::query()->where('key', SourceForm::HANDTEVY)->firstOrFail();
        $this->assertSame('HANDTEVY INVENTORY', $handtevy->notes['printed_header']);
        $this->assertSame(['Fixture provenance note.'], $handtevy->notes['review_notes']);
        $this->assertSame(['hv-bvm', 'hv-monthly'], array_keys($handtevy->notes['section_notes']));
        $this->assertSame(['note' => 'Check each size against the Handtevy tape.', 'cadence' => null], $handtevy->notes['section_notes']['hv-bvm']);
        $this->assertSame(['note' => null, 'cadence' => 'monthly_first_tuesday'], $handtevy->notes['section_notes']['hv-monthly']);

        $flags = SourceFormItem::query()->where('source_form_id', $handtevy->id)->where('item_key', 'hv.expiry')->firstOrFail()->flags;
        $this->assertArrayNotHasKey('surprise', $flags, 'Anything outside the allowlist is dropped.');
        $this->assertSame('monthly_first_tuesday', $flags['cadence']);

        // A list that prints none of this keeps exactly the notes it always had.
        $airway = SourceForm::query()->where('key', SourceForm::AIRWAY)->firstOrFail();
        $this->assertSame(['source_notes', 'pages', 'extraction_sha256'], array_keys($airway->notes));
        $plain = SourceFormItem::query()->where('source_form_id', $airway->id)->firstOrFail()->flags;
        $this->assertSame(['detail_airway_bag_only', 'rescue_only', 'refrigerated', 'controlled_substance'], array_keys($plain));
    }

    public function test_cadence_other_than_the_known_one_and_extra_search_terms_are_cleaned(): void
    {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ems-clean-'.bin2hex(random_bytes(4));
        mkdir($dir);
        $this->emsTempDirs[] = $dir;
        $document = json_decode((string) file_get_contents($this->writeHandtevy($dir)), true);
        $terms = array_map(fn (int $n): string => 'term '.$n, range(1, 14));
        $document['sections'][0]['cadence'] = 'weekly';
        $document['sections'][0]['items'][0]['flags'] += ['cadence' => 'daily', 'search_terms' => [...$terms, ' term 1 ', '', 42, str_repeat('long ', 40)], 'location_text' => '  In Handtevy  '];
        $document['source']['sha256'] = str_repeat('9', 64);
        file_put_contents($dir.'/handtevy_inventory.json', json_encode($document));

        app(SourceImporter::class)->import($dir);

        $form = SourceForm::query()->where('key', SourceForm::HANDTEVY)->firstOrFail();
        $this->assertNull($form->notes['section_notes']['hv-bvm']['cadence'] ?? null);
        $flags = SourceFormItem::query()->where('source_form_id', $form->id)->where('item_key', 'hv.grey.bvm')->firstOrFail()->flags;
        $this->assertArrayNotHasKey('cadence', $flags);
        $this->assertSame('In Handtevy', $flags['location_text']);
        $this->assertCount(10, $flags['search_terms']);
        $this->assertSame(array_slice($terms, 0, 10), $flags['search_terms']);
    }

    public function test_reimporting_the_new_lists_and_the_old_ones_changes_nothing(): void
    {
        $this->importFamilyLists();
        $before = SourceFormItem::query()->count();

        // The same files again: every list is unchanged and no row moves.
        $dir = $this->emsTempDirs[array_key_last($this->emsTempDirs)];
        $counts = app(SourceImporter::class)->import($dir)['counts'];
        foreach (['handtevy', 'als-non-transport', 'fire-boat-6-als', 'bls-detail-bag'] as $key) {
            $this->assertSame(1, $counts["forms.{$key}.unchanged"], $key);
            $this->assertArrayNotHasKey("forms.{$key}.new_version", $counts);
        }
        $this->assertSame($before, SourceFormItem::query()->count());

        // The first fixture directory holds the older lists; they stay unchanged too.
        $counts = app(SourceImporter::class)->import($this->emsTempDirs[0])['counts'];
        $this->assertSame(1, $counts['forms.airway-bag.unchanged']);
        $this->assertSame(1, $counts['forms.als-med-bag.unchanged']);
    }

    /** @return string the path written */
    private function writeHandtevy(string $dir): string
    {
        $path = $dir.'/handtevy_inventory.json';
        file_put_contents($path, json_encode([
            'schema' => 'mbfd.ems.source_form.v1',
            'source' => ['key' => 'handtevy', 'title' => 'HANDTEVY', 'version_label' => '1/2026', 'file_name' => 'h.pdf', 'sha256' => str_repeat('d', 64), 'pages' => 1, 'notes' => []],
            'sections' => [['key' => 'hv-bvm', 'label' => 'BVMs', 'source_label' => 'BVMS', 'kind' => 'category', 'parent_key' => null, 'sort' => 10, 'items' => [
                $this->listItem('hv.grey.bvm', 'bvm-pediatric', 'BVM Grey', '1', 1),
            ]]],
        ]));

        return $path;
    }
}
