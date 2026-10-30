<?php

declare(strict_types=1);

namespace Tests\Feature\Ems;

use App\Models\Ems\CatalogItem;
use App\Models\Ems\InventoryRequest;
use App\Models\Ems\SourceForm;
use App\Models\OperationalFormRecord;
use App\Support\Ems\EmsLinks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Ems\Concerns\BuildsEmsWorld;
use Tests\TestCase;

/**
 * The member side of the Medical Inventory Request inside the Employee Portal Forms app: what the page hands the
 * React app, the deep links notifications use, the Files record, and a full HTTP round trip with the exact payload
 * shapes the form builds (resources/js/operational-forms/ems/emsLogic.js buildPayload).
 */
final class MemberFormPageTest extends TestCase
{
    use BuildsEmsWorld;
    use RefreshDatabase;

    private const API = '/employee/forms/api/ems/requests';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->buildEmsWorld();
    }

    protected function tearDown(): void
    {
        $this->tearDownEmsWorld();
        parent::tearDown();
    }

    /** @return array<string, mixed> the JSON the page hands to the React app */
    private function bootstrap(string $query = ''): array
    {
        $html = $this->actingAs($this->emsMember())->get('/employee/forms'.$query)->assertOk()->getContent();
        self::assertSame(1, preg_match("/data-bootstrap='([^']*)'/", $html, $matches));

        return json_decode(html_entity_decode($matches[1], ENT_QUOTES), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_the_forms_page_hands_the_app_the_ems_endpoints_and_the_library_entry(): void
    {
        $bootstrap = $this->bootstrap();

        $ems = $bootstrap['endpoints']['ems'];
        $this->assertSame(route('employee.forms.api.ems.form'), $ems['form']);
        $this->assertSame(route('employee.forms.api.ems.requests.index'), $ems['requests']);
        $this->assertSame('__UUID__', $ems['placeholder']);
        $this->assertSame(url('/employee/forms/api/ems/requests/__UUID__'), $ems['request']);
        $this->assertSame(url('/employee/forms/api/ems/requests/__UUID__/messages'), $ems['messages']);
        $this->assertSame(url('/employee/forms/api/ems/requests/__UUID__/cancel'), $ems['cancel']);

        $links = collect($bootstrap['library_links'])->keyBy('key');
        $this->assertSame(['medical-inventory-request', 'take-home-vehicle-inspection', 'narcotics-check'], $links->keys()->all());
        $entry = $links['medical-inventory-request'];
        $this->assertSame('Medical Inventory Request', $entry['title']);
        $this->assertTrue($entry['in_app']);
        $this->assertSame(url('/employee/forms?form=medical-inventory-request'), $entry['href']);
        $this->assertSame(url('/employee/forms?form=medical-inventory-request&view=mine'), $entry['secondary']['href']);
        $this->assertArrayNotHasKey('in_app', $links['take-home-vehicle-inspection'], 'The take-home inspection still opens its own app.');

        // The in-app forms and their record endpoints are untouched.
        $this->assertSame(route('employee.forms.api.records.index'), $bootstrap['endpoints']['records']);
        $this->assertSame(['view' => null, 'request' => null], $bootstrap['deep_link']);
        $this->assertArrayNotHasKey('quantities', $bootstrap, 'The page never carries request data.');
    }

    /** @return iterable<string, array{string, array{view: string|null, request: string|null}}> */
    public static function deepLinks(): iterable
    {
        $uuid = '0f8fad5b-d9cb-469f-a165-70867728950e';

        yield 'new request' => ['?form=medical-inventory-request', ['view' => 'form', 'request' => null]];
        yield 'my requests' => ['?form=medical-inventory-request&view=mine', ['view' => 'mine', 'request' => null]];
        yield 'one request' => ['?ems_request='.$uuid, ['view' => 'detail', 'request' => $uuid]];
        yield 'upper case uuid' => ['?ems_request='.strtoupper($uuid), ['view' => 'detail', 'request' => $uuid]];
        yield 'request wins over form' => ['?form=medical-inventory-request&ems_request='.$uuid, ['view' => 'detail', 'request' => $uuid]];
        yield 'not a uuid' => ['?ems_request=not-a-uuid', ['view' => null, 'request' => null]];
        yield 'another form' => ['?form=something-else', ['view' => null, 'request' => null]];
        yield 'unknown view' => ['?form=medical-inventory-request&view=x', ['view' => 'form', 'request' => null]];
    }

    /**
     * @dataProvider deepLinks
     *
     * @param  array{view: string|null, request: string|null}  $expected
     */
    public function test_the_deep_link_parameters_are_accepted(string $query, array $expected): void
    {
        $this->assertSame($expected, $this->bootstrap($query)['deep_link']);
    }

    public function test_the_links_in_notifications_open_the_forms_app_on_the_right_view(): void
    {
        $uuid = (string) Str::uuid();

        $this->assertSame('/employee/forms?ems_request='.$uuid, EmsLinks::member($uuid));
        $this->assertSame('/employee/forms?form=medical-inventory-request', EmsLinks::memberForm());
        $this->assertSame('/employee/forms?form=medical-inventory-request&view=mine', EmsLinks::memberRequests());

        $this->assertSame(['view' => 'detail', 'request' => $uuid], $this->bootstrap(substr(EmsLinks::member($uuid), strlen('/employee/forms')))['deep_link']);
        $this->assertSame('form', $this->bootstrap(substr(EmsLinks::memberForm(), strlen('/employee/forms')))['deep_link']['view']);
        $this->assertSame('mine', $this->bootstrap(substr(EmsLinks::memberRequests(), strlen('/employee/forms')))['deep_link']['view']);
    }

    public function test_the_form_definition_carries_the_printed_note_and_search_aliases_without_changing_items(): void
    {
        SourceForm::query()->where('key', 'als-med-bag')->update(['notes' => ['source_notes' => [
            'Two-column layout. Left column: TRAUMA.', 'Totals can include amounts in Bobo Bag.', 'Printed banner: clipped.',
        ]]]);
        CatalogItem::query()->where('key', 'gauze-4x4')->update(['aliases' => ['gauze pad', 'Gauze 4x4']]);

        $this->actingAs($this->emsMember());
        $forms = collect($this->getJson('/employee/forms/api/ems/form')->assertOk()->json('data.forms'))->keyBy('key');

        $this->assertSame('Totals can include amounts in Bobo Bag.', $forms['als-med-bag']['printed_note']);
        $this->assertNull($forms['airway-bag']['printed_note'], 'Only notes printed on the paper form reach members.');
        $gauze = collect($forms['als-med-bag']['sections'])->flatMap(fn ($section) => $section['items'])->firstWhere('item_key', 'als.trauma.gauze');
        $this->assertContains('gauze pad', $gauze['aliases']);
        $this->assertNotContains($gauze['label'], $gauze['aliases'], 'The label itself is not repeated as an alias.');
        $this->assertSame('10 EACH', $gauze['min_text'], 'Source text is passed through exactly.');

        // Sections keep their parent so the app can nest "Rescue Only" under its category.
        $rescue = collect($forms['als-med-bag']['sections'])->firstWhere('key', 'als-rescue-only');
        $this->assertSame('als-trauma', $rescue['parent_key']);
    }

    public function test_a_sent_request_is_a_read_only_medical_inventory_request_record_in_the_members_forms_list(): void
    {
        $member = $this->emsMember('Paramedic', 'Pat Medic');
        $uuid = $this->submitRequest($member, [['form' => 'airway-bag', 'item_key' => 'airway.top.bvm', 'quantity' => 2]])['uuid'];
        $request = $this->requestModel($uuid);

        $this->actingAs($member);
        $list = $this->getJson('/employee/forms/api/records')->assertOk();
        $this->assertCount(1, $list->json('records'));
        $record = $list->json('records.0');
        $this->assertSame('medical_inventory_request', $record['form_type']);
        $this->assertSame('completed', $record['status']);
        $this->assertSame("Medical Inventory Request {$request->number} · Rescue 2", $record['title']);
        $this->assertCount(1, $record['documents']);
        $this->assertSame('application/pdf', $record['documents'][0]['mime_type']);
        $this->assertNotEmpty($record['documents'][0]['preview_url']);
        $this->assertNotEmpty($record['documents'][0]['download_url']);

        // Not editable, not regenerable, not deletable, and not creatable from the Forms library.
        $this->patchJson('/employee/forms/api/records/'.$record['id'], ['revision' => $record['revision'], 'data' => ['incident' => ['name' => 'x']]])->assertStatus(409)->assertJsonPath('code', 'read_only');
        $this->postJson('/employee/forms/api/records/'.$record['id'].'/generate')->assertStatus(409)->assertJsonPath('code', 'read_only');
        $this->deleteJson('/employee/forms/api/records/'.$record['id'])->assertStatus(409);
        $this->postJson('/employee/forms/api/records', ['form_type' => 'medical_inventory_request', 'title' => 'x'])->assertStatus(422);

        // The detail endpoint the member opens from the request view links to the same copy, and only the owner can open it.
        $copy = $this->getJson(self::API.'/'.$uuid)->assertOk()->json('data.files_copy');
        $this->assertSame('ready', $copy['status']);
        $this->assertSame($record['documents'][0]['download_url'], $copy['download_url']);
        $this->get($copy['download_url'])->assertOk();
        $this->actingAs($this->emsMember());
        $this->get($copy['download_url'])->assertNotFound();
        $this->assertSame([], $this->getJson('/employee/forms/api/records')->json('records'));
        $this->assertSame(1, OperationalFormRecord::query()->where('form_type', 'medical_inventory_request')->count());
    }

    public function test_a_partial_request_with_the_payload_the_form_builds_is_accepted_and_blank_stays_blank(): void
    {
        $member = $this->emsMember();
        $payload = [
            'client_submission_id' => (string) Str::uuid(),
            'target' => ['kind' => 'rescue_unit', 'apparatus_id' => $this->rescue2->id],
            // The form lists only touched items: no blanks, no zeros, no member_note key when the note is empty.
            'lines' => [
                ['form' => 'airway-bag', 'item_key' => 'airway.top.gloves', 'quantity' => 2],
                ['form' => 'als-med-bag', 'item_key' => 'als.rescue.narcs-box', 'flag' => true],
            ],
        ];

        $response = $this->emsPost($member, self::API, $payload)->assertCreated()->assertJsonPath('ok', true);
        $this->assertSame(['airway.top.gloves', 'als.rescue.narcs-box'], collect($response->json('data.lines'))->pluck('item_key')->all());
        $this->assertSame([2, null], collect($response->json('data.lines'))->pluck('requested_qty')->all());
        $this->assertNull($response->json('data.member_note'));
        $this->assertSame($member->name, $response->json('data.submitter_name'), 'Identity comes from the server.');
        $this->assertSame(1, InventoryRequest::query()->count());
    }

    public function test_detail_special_event_with_blank_optional_text_is_accepted_and_event_text_is_kept_when_typed(): void
    {
        $member = $this->emsMember();
        $line = [['form' => 'als-med-bag', 'item_key' => 'als.trauma.gauze', 'quantity' => 4]];

        $blank = $this->emsPost($member, self::API, [
            'client_submission_id' => (string) Str::uuid(), 'target' => ['kind' => 'detail_event'], 'lines' => $line,
        ])->assertCreated();
        $this->assertSame('detail_event', $blank->json('data.target_kind'));
        $this->assertSame('Detail / Special Event', $blank->json('data.target_label'));
        $this->assertNull($blank->json('data.event_assignment'));
        $this->assertNull($blank->json('data.apparatus_id'));

        $typed = $this->emsPost($member, self::API, [
            'client_submission_id' => (string) Str::uuid(), 'target' => ['kind' => 'detail_event', 'event_assignment' => 'Air show, medic 4'], 'lines' => $line,
        ])->assertCreated();
        $this->assertSame('Air show, medic 4', $typed->json('data.event_assignment'));
    }

    public function test_a_note_alone_and_a_no_answer_alone_are_valid_requests(): void
    {
        $member = $this->emsMember();

        $this->emsPost($member, self::API, [
            'client_submission_id' => (string) Str::uuid(), 'target' => ['kind' => 'other_unit', 'apparatus_id' => $this->engine1->id],
            'member_note' => 'Jump bag restock', 'lines' => [],
        ])->assertCreated()->assertJsonPath('data.lines', [])->assertJsonPath('data.target_label', 'Engine 1');

        $no = $this->emsPost($member, self::API, [
            'client_submission_id' => (string) Str::uuid(), 'target' => ['kind' => 'rescue_unit', 'apparatus_id' => $this->rescue2->id],
            'lines' => [['form' => 'als-med-bag', 'item_key' => 'als.rescue.narcs-box', 'flag' => false]],
        ])->assertCreated();
        $this->assertSame([false], collect($no->json('data.lines'))->pluck('requested_flag')->all());
        $this->assertSame(['not_issued'], collect($no->json('data.lines'))->pluck('line_status')->all(), 'A No answer never waits for supplies.');
    }

    public function test_replaying_the_same_client_submission_id_returns_the_same_request_and_never_a_second_one(): void
    {
        $member = $this->emsMember();
        $payload = [
            'client_submission_id' => (string) Str::uuid(),
            'target' => ['kind' => 'rescue_unit', 'apparatus_id' => $this->rescue2->id],
            'lines' => [['form' => 'airway-bag', 'item_key' => 'airway.top.stethoscope', 'quantity' => 1]],
        ];

        $first = $this->emsPost($member, self::API, $payload)->assertCreated()->assertJsonPath('meta.replayed', false);
        // The retry after a network failure, and a double tap, send the identical body again.
        $second = $this->emsPost($member, self::API, $payload)->assertOk()->assertJsonPath('meta.replayed', true);
        $third = $this->emsPost($member, self::API, $payload)->assertOk()->assertJsonPath('meta.replayed', true);

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame($first->json('data.number'), $third->json('data.number'));
        $this->assertSame(1, InventoryRequest::query()->count());
        $this->assertSame(1, OperationalFormRecord::query()->where('form_type', 'medical_inventory_request')->count());
    }

    public function test_the_refusals_the_form_shows_next_to_a_control_come_back_in_a_shape_it_can_place(): void
    {
        $member = $this->emsMember();
        $base = ['client_submission_id' => (string) Str::uuid(), 'lines' => [['form' => 'airway-bag', 'item_key' => 'airway.top.bvm', 'quantity' => 1]]];

        $noTarget = $this->emsPost($member, self::API, $base)->assertStatus(422)->assertJsonPath('ok', false);
        $this->assertArrayHasKey('target', (array) $noTarget->json('error.fields'));
        $this->assertNotSame('', $noTarget->json('error.message'));

        $badUnit = $this->emsPost($member, self::API, $base + ['target' => ['kind' => 'rescue_unit', 'apparatus_id' => 999999]])->assertStatus(422);
        $this->assertSame('invalid_unit', $badUnit->json('error.code'));
        $this->assertArrayHasKey('target.apparatus_id', (array) $badUnit->json('error.fields'));

        $empty = $this->emsPost($member, self::API, ['client_submission_id' => (string) Str::uuid(), 'target' => ['kind' => 'detail_event'], 'lines' => []])->assertStatus(422);
        $this->assertSame('empty_request', $empty->json('error.code'));
        $this->assertSame('Add at least one item to request, or write a note, before you send.', $empty->json('error.message'));
        $this->assertSame(0, InventoryRequest::query()->count());
    }

    public function test_a_signed_out_member_gets_a_401_the_app_can_turn_into_a_sign_in_message(): void
    {
        $this->postJson(self::API, ['client_submission_id' => (string) Str::uuid(), 'target' => ['kind' => 'detail_event'], 'lines' => []])->assertStatus(401);
        $this->getJson('/employee/forms/api/ems/form')->assertStatus(401);
        $this->assertSame(0, InventoryRequest::query()->count());
    }
}
