<?php

declare(strict_types=1);

namespace Tests\Feature\Ems;

use App\Models\Ems\InventoryRequest;
use App\Models\Ems\InventoryRequestEvent;
use App\Models\Ems\InventoryRequestLine;
use App\Models\Ems\SourceFormItem;
use App\Models\OperationalFormDocument;
use App\Models\OperationalFormRecord;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use Tests\Feature\Ems\Concerns\BuildsEmsWorld;
use Tests\TestCase;

final class RequestSubmissionTest extends TestCase
{
    use BuildsEmsWorld;
    use RefreshDatabase;

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

    private const URI = '/employee/forms/api/ems/requests';

    private function payload(array $lines, array $extra = []): array
    {
        return $extra + [
            'client_submission_id' => (string) Str::uuid(),
            'target' => ['kind' => 'rescue_unit', 'apparatus_id' => $this->rescue2->id],
            'lines' => $lines,
        ];
    }

    public function test_form_definition_lists_the_active_forms_flags_and_unit_choices(): void
    {
        $member = $this->emsMember();
        $this->actingAs($member);

        $response = $this->getJson('/employee/forms/api/ems/form')->assertOk()->assertJsonPath('ok', true);

        $forms = collect($response->json('data.forms'));
        $this->assertSame(['airway-bag', 'als-med-bag'], $forms->pluck('key')->all());
        $als = $forms->firstWhere('key', 'als-med-bag');
        $items = collect($als['sections'])->flatMap(fn ($s) => $s['items'])->keyBy('item_key');
        $this->assertSame('yes_no', $items['als.rescue.narcs-box']['response_type']);
        $this->assertTrue($items['als.rescue.narcs-box']['flags']['controlled_substance']);
        $this->assertTrue($items['als.rescue.cardizem']['flags']['refrigerated']);
        $this->assertSame('3 SETS', collect($forms->firstWhere('key', 'airway-bag')['sections'][0]['items'])->firstWhere('item_key', 'airway.top.gloves')['min_text']);

        $units = collect($response->json('data.units'));
        $this->assertSame('Rescue 2', $units->first()['label'], 'Rescue units come first.');
        $this->assertSame('rescue_unit', $units->first()['kind']);
        $this->assertSame('Detail / Special Event', $units->last()['label']);
        $this->assertSame(['Rescue 2', 'Engine 1', 'Detail / Special Event'], $units->pluck('label')->all());
    }

    public function test_a_partial_request_is_valid_and_blank_stays_blank(): void
    {
        $member = $this->emsMember('Paramedic', 'Pat Medic');
        $this->travelTo(CarbonImmutable::parse('2026-10-20 14:05:00', 'America/New_York'));

        $response = $this->emsPost($member, self::URI, $this->payload([
            ['form' => 'airway-bag', 'item_key' => 'airway.top.stethoscope', 'quantity' => 2],
            ['form' => 'airway-bag', 'item_key' => 'airway.top.gloves', 'quantity' => ''],
            ['form' => 'airway-bag', 'item_key' => 'airway.top.bvm', 'quantity' => null],
            ['form' => 'als-med-bag', 'item_key' => 'als.trauma.gauze', 'quantity' => '0'],
            ['form' => 'als-med-bag', 'item_key' => 'als.trauma.tape', 'quantity' => '5'],
        ]))->assertCreated()->assertJsonPath('ok', true)->assertJsonPath('data.status', 'submitted');

        $request = $this->requestModel($response->json('data.id'));
        $this->assertMatchesRegularExpression('/^MIR-\d{6}$/', $request->number);
        $lines = $request->lines;
        $this->assertSame(['airway.top.stethoscope', 'als.trauma.tape'], $lines->pluck('item_key')->all(), 'Only items with a quantity are stored.');
        $this->assertSame([2, 5], $lines->pluck('requested_qty')->all());
        $this->assertNull(InventoryRequestLine::query()->where('item_key', 'airway.top.gloves')->first(), 'Blank is never stored as 0.');
        $this->assertNull(InventoryRequestLine::query()->where('item_key', 'als.trauma.gauze')->first());
        $this->assertSame('Pat Medic', $request->submitter_name);
        $this->assertSame('Paramedic', $request->submitter_rank);
        $this->assertSame('Rescue 2', $request->target_label);
        $this->assertSame('rescue_unit', $request->target_kind);
        $this->assertSame(1, $request->events()->where('action', 'submitted')->count());
    }

    public function test_an_empty_submission_is_refused_with_a_friendly_message_but_a_note_alone_is_enough(): void
    {
        $member = $this->emsMember();

        $this->emsPost($member, self::URI, $this->payload([
            ['form' => 'airway-bag', 'item_key' => 'airway.top.gloves', 'quantity' => ''],
        ]))->assertStatus(422)->assertJsonPath('ok', false)->assertJsonPath('error.code', 'empty_request')
            ->assertJsonPath('error.message', 'Add at least one item to request, or write a note, before you send.');
        $this->assertSame(0, InventoryRequest::query()->count());

        $this->emsPost($member, self::URI, $this->payload([], ['member_note' => 'Need to talk about the jump bag restock']))
            ->assertCreated()->assertJsonPath('data.member_note', 'Need to talk about the jump bag restock')->assertJsonPath('data.lines', []);
    }

    public function test_quantities_are_whole_numbers_one_to_999(): void
    {
        $member = $this->emsMember();
        foreach (['1000', '-2', '2.5', 'abc'] as $bad) {
            $this->emsPost($member, self::URI, $this->payload([['form' => 'airway-bag', 'item_key' => 'airway.top.stethoscope', 'quantity' => $bad]]))
                ->assertStatus(422)->assertJsonPath('error.code', 'invalid_quantity');
        }
        $this->emsPost($member, self::URI, $this->payload([['form' => 'airway-bag', 'item_key' => 'airway.top.stethoscope', 'quantity' => 999]]))->assertCreated();
        $this->assertSame(1, InventoryRequest::query()->count());
    }

    public function test_unknown_duplicate_and_mismatched_items_are_refused(): void
    {
        $member = $this->emsMember();
        $this->emsPost($member, self::URI, $this->payload([['form' => 'airway-bag', 'item_key' => 'airway.nope', 'quantity' => 1]]))
            ->assertStatus(422)->assertJsonPath('error.code', 'unknown_item');
        $this->emsPost($member, self::URI, $this->payload([
            ['form' => 'airway-bag', 'item_key' => 'airway.top.bvm', 'quantity' => 1],
            ['form' => 'airway-bag', 'item_key' => 'airway.top.bvm', 'quantity' => 2],
        ]))->assertStatus(422)->assertJsonPath('error.code', 'duplicate_item');
        $this->emsPost($member, self::URI, $this->payload([['form' => 'als-med-bag', 'item_key' => 'als.rescue.narcs-box', 'quantity' => 1]]))
            ->assertStatus(422)->assertJsonPath('error.code', 'invalid_answer');
        $this->emsPost($member, self::URI, $this->payload([['form' => 'airway-bag', 'item_key' => 'airway.top.bvm', 'flag' => true]]))
            ->assertStatus(422)->assertJsonPath('error.code', 'invalid_answer');
    }

    public function test_yes_no_answers_are_stored_and_a_blank_answer_is_not(): void
    {
        $member = $this->emsMember();

        $response = $this->emsPost($member, self::URI, $this->payload([
            ['form' => 'als-med-bag', 'item_key' => 'als.rescue.narcs-box', 'flag' => true],
            ['form' => 'als-med-bag', 'item_key' => 'als.rescue.cardizem', 'quantity' => 1],
        ]))->assertCreated();

        $lines = $this->requestModel($response->json('data.id'))->lines->keyBy('item_key');
        $this->assertTrue($lines['als.rescue.narcs-box']->requested_flag);
        $this->assertNull($lines['als.rescue.narcs-box']->requested_qty);
        $this->assertSame(1, $lines['als.rescue.cardizem']->requested_qty);
        $this->assertTrue($lines['als.rescue.cardizem']->flag('refrigerated'));

        $blank = $this->emsPost($member, self::URI, $this->payload([
            ['form' => 'als-med-bag', 'item_key' => 'als.rescue.narcs-box', 'flag' => null],
        ], ['member_note' => 'just a note']))->assertCreated();
        $this->assertSame([], $blank->json('data.lines'));
    }

    public function test_the_unit_is_validated_against_the_directory_and_detail_text_is_optional(): void
    {
        $member = $this->emsMember();
        $line = [['form' => 'airway-bag', 'item_key' => 'airway.top.bvm', 'quantity' => 1]];

        $this->emsPost($member, self::URI, $this->payload($line, ['target' => ['kind' => 'rescue_unit', 'apparatus_id' => 999999]]))
            ->assertStatus(422)->assertJsonPath('error.code', 'invalid_unit');
        $this->emsPost($member, self::URI, $this->payload($line, ['target' => ['kind' => 'other_unit']]))
            ->assertStatus(422)->assertJsonPath('error.code', 'invalid_unit');
        $this->emsPost($member, self::URI, $this->payload($line, ['target' => []]))->assertStatus(422);

        // The server decides the kind from the unit directory, not from what the client claims.
        $engine = $this->emsPost($member, self::URI, $this->payload($line, ['target' => ['kind' => 'rescue_unit', 'apparatus_id' => $this->engine1->id]]))->assertCreated();
        $this->assertSame('other_unit', $engine->json('data.target_kind'));

        $detail = $this->emsPost($member, self::URI, $this->payload($line, ['target' => ['kind' => 'detail_event', 'apparatus_id' => $this->engine1->id]]))->assertCreated();
        $this->assertSame('detail_event', $detail->json('data.target_kind'));
        $this->assertNull($detail->json('data.apparatus_id'));
        $this->assertNull($detail->json('data.event_assignment'));
        $this->assertSame('Detail / Special Event', $detail->json('data.target_label'));

        $named = $this->emsPost($member, self::URI, $this->payload($line, ['target' => ['kind' => 'detail_event', 'event_assignment' => 'Ultra, Bayside']]))->assertCreated();
        $this->assertSame('Ultra, Bayside', $named->json('data.event_assignment'));
        $this->emsPost($member, self::URI, $this->payload($line, ['target' => ['kind' => 'detail_event', 'event_assignment' => str_repeat('x', 161)]]))->assertStatus(422);
    }

    public function test_a_retry_with_the_same_submission_id_returns_the_same_request(): void
    {
        $member = $this->emsMember();
        $payload = $this->payload([['form' => 'airway-bag', 'item_key' => 'airway.top.bvm', 'quantity' => 1]]);

        $first = $this->emsPost($member, self::URI, $payload)->assertCreated();
        $second = $this->emsPost($member, self::URI, $payload)->assertOk()->assertJsonPath('meta.replayed', true);

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, InventoryRequest::query()->count());
        $this->assertSame(1, InventoryRequestEvent::query()->where('action', 'submitted')->count());

        $other = $this->emsMember();
        $this->emsPost($other, self::URI, $payload)->assertStatus(409)->assertJsonPath('error.code', 'duplicate_submission');
    }

    public function test_identity_and_time_come_from_the_server_not_the_form(): void
    {
        $member = $this->emsMember('Firefighter', 'Real Name');
        $this->travelTo(CarbonImmutable::parse('2026-10-21 09:30:00', 'America/New_York'));

        $response = $this->emsPost($member, self::URI, $this->payload(
            [['form' => 'airway-bag', 'item_key' => 'airway.top.bvm', 'quantity' => 1]],
            ['submitter_name' => 'Somebody Else', 'submitted_at' => '2020-01-01T00:00:00Z', 'submitted_by_user_id' => 1, 'number' => 'MIR-000001'],
        ))->assertCreated();

        $request = $this->requestModel($response->json('data.id'));
        $this->assertSame('Real Name', $request->submitter_name);
        $this->assertSame($member->id, $request->submitted_by_user_id);
        $this->assertSame($member->employee_profile_id, $request->submitted_by_employee_id);
        $this->assertTrue($request->submitted_at->equalTo(CarbonImmutable::parse('2026-10-21 09:30:00', 'America/New_York')));
        $this->assertSame('MIR-000001', $request->number, 'The first number is allocated by the server (it happens to equal the form value here).');
        $this->assertSame('Real Name', $request->snapshot['submitter']['name']);
    }

    public function test_numbers_are_unique_and_increase(): void
    {
        $member = $this->emsMember();
        $numbers = [];
        foreach (range(1, 4) as $i) {
            $numbers[] = $this->submitRequest($member, [['form' => 'airway-bag', 'item_key' => 'airway.top.bvm', 'quantity' => $i]])['number'];
        }
        $this->assertSame(['MIR-000001', 'MIR-000002', 'MIR-000003', 'MIR-000004'], $numbers);
    }

    public function test_the_snapshot_is_immutable_and_survives_a_catalog_rename(): void
    {
        $member = $this->emsMember();
        $uuid = $this->submitRequest($member, [['form' => 'airway-bag', 'item_key' => 'airway.top.gloves', 'quantity' => 4]])['uuid'];
        $request = $this->requestModel($uuid);
        $hash = $request->snapshot_sha256;

        $this->assertSame($hash, hash('sha256', json_encode($request->snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));

        $item = SourceFormItem::query()->where('item_key', 'airway.top.gloves')->firstOrFail();
        $item->update(['label' => 'Exam Gloves (renamed)', 'min_text' => '9 SETS']);
        $item->catalogItem->update(['name' => 'Renamed catalog item']);

        $fresh = $this->requestModel($uuid);
        $this->assertSame('Gloves', $fresh->lines->first()->label);
        $this->assertSame('3 SETS', $fresh->lines->first()->min_text);
        $this->assertSame('set', $fresh->lines->first()->unit);
        $this->assertSame('Gloves', $fresh->snapshot['lines'][0]['label']);
        $this->assertSame($hash, $fresh->snapshot_sha256);

        foreach (['snapshot' => ['tampered' => true], 'number' => 'MIR-999999', 'member_note' => 'edited', 'submitter_name' => 'Nope'] as $column => $value) {
            try {
                $fresh->update([$column => $value]);
                $this->fail("{$column} should be immutable");
            } catch (LogicException $exception) {
                $this->assertStringContainsString('cannot be changed', $exception->getMessage());
            }
        }
        $this->expectException(LogicException::class);
        $fresh->lines->first()->update(['requested_qty' => 99]);
    }

    public function test_history_rows_refuse_update_and_delete(): void
    {
        $member = $this->emsMember();
        $uuid = $this->submitRequest($member, [['form' => 'airway-bag', 'item_key' => 'airway.top.bvm', 'quantity' => 1]])['uuid'];
        $event = $this->requestModel($uuid)->events()->firstOrFail();

        foreach ([fn () => $event->update(['action' => 'x']), fn () => $event->delete(), fn () => $this->requestModel($uuid)->delete(), fn () => $this->requestModel($uuid)->lines->first()->delete()] as $attempt) {
            try {
                $attempt();
                $this->fail('A history row was changed.');
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_submission_makes_one_files_copy_that_only_the_owner_can_open(): void
    {
        $member = $this->emsMember('Paramedic', 'Pat Medic');
        $uuid = $this->submitRequest($member, [
            ['form' => 'airway-bag', 'item_key' => 'airway.top.stethoscope', 'quantity' => 2],
            ['form' => 'als-med-bag', 'item_key' => 'als.rescue.narcs-box', 'flag' => true],
        ], ['member_note' => 'Restock before the weekend'])['uuid'];

        $request = $this->requestModel($uuid);
        $record = OperationalFormRecord::query()->findOrFail($request->operational_form_record_id);
        $this->assertSame('medical_inventory_request', $record->form_type);
        $this->assertSame('completed', $record->status);
        $this->assertSame($member->employee_profile_id, $record->employee_id);
        $this->assertSame("Medical Inventory Request {$request->number} · Rescue 2", $record->title);
        $document = $record->documents()->sole();
        $this->assertSame('application/pdf', $document->mime_type);
        $this->assertSame('ems-mir-dompdf', $document->generator_version);
        $this->assertGreaterThanOrEqual(1, $document->page_count);
        Storage::disk($document->storage_disk)->assertExists($document->storage_path);
        $this->assertStringStartsWith('%PDF', Storage::disk($document->storage_disk)->get($document->storage_path));
        $this->assertSame($request->snapshot, $document->source_snapshot);

        $this->assertSame(1, OperationalFormRecord::query()->where('form_type', 'medical_inventory_request')->count());

        $this->actingAs($member)->get('/employee/forms/api/documents/'.$document->id.'/download')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->actingAs($member)->getJson('/employee/forms/api/ems/requests/'.$uuid)->assertOk()
            ->assertJsonPath('data.files_copy.status', 'ready')->assertJsonPath('data.files_copy.document_id', $document->id);

        $other = $this->emsMember();
        $this->actingAs($other)->get('/employee/forms/api/documents/'.$document->id.'/download')->assertNotFound();
        $this->actingAs($other)->getJson('/employee/forms/api/records/'.$record->id)->assertNotFound();
    }

    public function test_a_missing_files_copy_is_rebuilt_on_demand_from_the_snapshot(): void
    {
        $member = $this->emsMember();
        $uuid = $this->submitRequest($member, [['form' => 'airway-bag', 'item_key' => 'airway.top.bvm', 'quantity' => 3]])['uuid'];
        $document = OperationalFormDocument::query()->sole();
        Storage::disk($document->storage_disk)->delete($document->storage_path);

        $this->actingAs($member)->getJson('/employee/forms/api/ems/requests/'.$uuid)->assertOk();

        $document->refresh();
        Storage::disk($document->storage_disk)->assertExists($document->storage_path);
        $this->assertSame(1, OperationalFormDocument::query()->count());
        $this->assertSame(1, OperationalFormRecord::query()->count());
    }

    public function test_the_files_copy_is_read_only_and_cannot_be_edited_or_regenerated_as_an_ics_or_froc_form(): void
    {
        $member = $this->emsMember();
        $uuid = $this->submitRequest($member, [['form' => 'airway-bag', 'item_key' => 'airway.top.bvm', 'quantity' => 1]])['uuid'];
        $record = OperationalFormRecord::query()->sole();
        $this->actingAs($member);

        $this->patchJson('/employee/forms/api/records/'.$record->id, ['revision' => 1, 'data' => ['incident' => ['name' => 'x']]])
            ->assertStatus(409)->assertJsonPath('code', 'read_only');
        $this->postJson('/employee/forms/api/records/'.$record->id.'/generate')->assertStatus(409)->assertJsonPath('code', 'read_only');
        $this->postJson('/employee/forms/api/records/'.$record->id.'/complete')->assertStatus(409)->assertJsonPath('code', 'read_only');
        $this->deleteJson('/employee/forms/api/records/'.$record->id)->assertStatus(409);
        $this->postJson('/employee/forms/api/records/'.$record->id.'/froc/import', ['revision' => 1, 'unit_id' => 'E1', 'notes' => 'Responded to a call', 'merge_mode' => 'fill_empty_and_append', 'idempotency_key' => 'import-key-0001'])->assertStatus(404);
        $this->postJson('/employee/forms/api/records', ['form_type' => 'medical_inventory_request', 'title' => 'x'])->assertStatus(422);

        // The existing list and detail endpoints present it without errors.
        $list = $this->getJson('/employee/forms/api/records')->assertOk();
        $this->assertSame('medical_inventory_request', $list->json('records.0.form_type'));
        $this->assertCount(1, $list->json('records.0.documents'));
        $this->getJson('/employee/forms/api/records/'.$record->id)->assertOk()->assertJsonPath('record.status', 'completed');
        $this->assertNotNull($uuid);
    }

    public function test_the_member_sees_only_their_own_requests_and_a_safe_history(): void
    {
        $member = $this->emsMember();
        $other = $this->emsMember();
        $mine = $this->submitRequest($member, [['form' => 'airway-bag', 'item_key' => 'airway.top.bvm', 'quantity' => 1]]);
        $theirs = $this->submitRequest($other, [['form' => 'airway-bag', 'item_key' => 'airway.top.bvm', 'quantity' => 1]]);

        $this->actingAs($member);
        $list = $this->getJson('/employee/forms/api/ems/requests')->assertOk();
        $this->assertSame([$mine['uuid']], collect($list->json('data'))->pluck('id')->all());
        $this->getJson('/employee/forms/api/ems/requests/'.$theirs['uuid'])->assertNotFound();
        $this->postJson('/employee/forms/api/ems/requests/'.$theirs['uuid'].'/messages', ['body' => 'hi'], ['Idempotency-Key' => 'abcdefgh12'])->assertNotFound();
        $this->postJson('/employee/forms/api/ems/requests/'.$theirs['uuid'].'/cancel', [], ['Idempotency-Key' => 'abcdefgh13'])->assertNotFound();

        $detail = $this->getJson('/employee/forms/api/ems/requests/'.$mine['uuid'])->assertOk();
        $this->assertTrue($detail->json('data.can_cancel'));
        $this->assertArrayNotHasKey('supply_candidates', $detail->json('data.lines.0'));
        $this->assertArrayNotHasKey('snapshot_sha256', $detail->json('data'));
        $this->assertSame(['submitted'], collect($detail->json('data.history'))->pluck('action')->all());
    }

    public function test_writes_need_an_idempotency_key(): void
    {
        $member = $this->emsMember();
        $this->actingAs($member);
        $uuid = $this->submitRequest($member, [['form' => 'airway-bag', 'item_key' => 'airway.top.bvm', 'quantity' => 1]])['uuid'];

        $this->postJson('/employee/forms/api/ems/requests/'.$uuid.'/messages', ['body' => 'hello'])
            ->assertStatus(422)->assertJsonPath('error.code', 'idempotency_key_invalid');
    }
}
