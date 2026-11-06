<?php

declare(strict_types=1);

namespace Tests\Feature\Ems;

use App\Models\Ems\InventoryRequest;
use App\Models\OperationalFormRecord;
use App\Models\User;
use App\Notifications\Inspections\InspectionEventNotification;
use App\Services\Ems\MemberCopyService;
use App\Services\Ems\RequestPdfRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Ems\Concerns\BuildsEmsWorld;
use Tests\TestCase;

/** Submitting on each form: the family is stored, snapshotted, kept by corrections and named in copies and notifications. */
final class FormFamilySubmissionTest extends TestCase
{
    use BuildsEmsWorld;
    use RefreshDatabase;

    private const URI = '/employee/forms/api/ems/requests';

    private const TAPE_ALS = ['form' => 'als-med-bag', 'item_key' => 'als.trauma.tape'];

    private const GAUZE_NON_TRANSPORT = ['form' => 'als-non-transport', 'item_key' => 'nt.gauze'];

    private const OXYGEN_BOAT = ['form' => 'fire-boat-6-als', 'item_key' => 'fb.oxygen'];

    private const TAPE_BLS = ['form' => 'bls-detail-bag', 'item_key' => 'bls.tape'];

    private const BVM_HANDTEVY = ['form' => 'handtevy', 'item_key' => 'hv.grey.bvm'];

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildEmsWorld();
        $this->importFamilyLists();
        $this->member = $this->emsMember('Paramedic', 'Pat Medic');
    }

    protected function tearDown(): void
    {
        $this->tearDownEmsWorld();
        parent::tearDown();
    }

    /** @param list<array<string, mixed>> $lines */
    private function send(array $lines, ?string $family = null, array $extra = [], ?User $as = null): \Illuminate\Testing\TestResponse
    {
        $payload = $extra + [
            'client_submission_id' => (string) Str::uuid(),
            'target' => ['kind' => 'rescue_unit', 'apparatus_id' => $this->rescue2->id],
            'lines' => $lines,
        ];
        if ($family !== null) {
            $payload['family'] = $family;
        }

        return $this->emsPost($as ?? $this->member, self::URI, $payload);
    }

    /** @return iterable<string, array{string, string, array<string, mixed>}> */
    public static function families(): iterable
    {
        yield 'als transport' => ['als-transport', 'ALS Transport Inventory Form', self::TAPE_ALS + ['quantity' => 2]];
        yield 'als non transport' => ['als-non-transport', 'ALS Non Transport Inventory Form', self::GAUZE_NON_TRANSPORT + ['quantity' => 3]];
        yield 'fire boat 6' => ['fire-boat-6', 'Fire Boat 6 Medical Inventory Form', self::OXYGEN_BOAT + ['quantity' => 1]];
        yield 'bls detail' => ['bls-detail', 'BLS Detail Medical Inventory Form', self::TAPE_BLS + ['quantity' => 2]];
    }

    /**
     * @dataProvider families
     *
     * @param  array<string, mixed>  $line
     */
    public function test_a_request_is_stored_and_snapshotted_with_its_family(string $family, string $title, array $line): void
    {
        $response = $this->send([$line], $family)->assertCreated();

        $short = collect(\App\Services\Ems\FormFamilies::all())->firstWhere('key', $family)['short_title'];
        $response->assertJsonPath('data.form_family', $family)->assertJsonPath('data.form_family_title', $title)->assertJsonPath('data.form_family_short', $short);
        $model = $this->requestModel($response->json('data.id'));
        $this->assertSame($family, $model->form_family);
        $this->assertSame($family, $model->snapshot['form_family']);
        $this->assertSame($title, $model->snapshot['form_family_title']);
        $this->assertSame('mbfd.ems.mir_snapshot.v1', $model->snapshot['schema'], 'The snapshot schema name does not change.');
        $this->assertContains($line['form'], array_keys($model->source_form_versions));
        $this->assertSame([], array_diff(array_keys($model->source_form_versions), \App\Services\Ems\FormFamilies::bagsFor($family)), 'Only the own lists of the form are versioned.');
        $this->assertSame([$line['form']], array_unique($model->lines()->pluck('bag_key')->all()));
        $this->assertMatchesRegularExpression('/^MIR-\d{6}$/', $model->number);
    }

    public function test_one_counter_numbers_every_family(): void
    {
        $numbers = [
            $this->send([self::TAPE_ALS + ['quantity' => 1]], 'als-transport')->json('data.number'),
            $this->send([self::TAPE_BLS + ['quantity' => 1]], 'bls-detail')->json('data.number'),
            $this->send([self::OXYGEN_BOAT + ['quantity' => 1]], 'fire-boat-6')->json('data.number'),
            $this->send([self::TAPE_ALS + ['quantity' => 1]])->json('data.number'),
        ];

        $this->assertSame(['MIR-000001', 'MIR-000002', 'MIR-000003', 'MIR-000004'], $numbers);
    }

    public function test_a_payload_without_a_family_is_als_transport_exactly_as_before(): void
    {
        $response = $this->send([self::TAPE_ALS + ['quantity' => 2]])->assertCreated();

        $response->assertJsonPath('data.form_family', 'als-transport')->assertJsonPath('data.lines.0.item_key', 'als.trauma.tape');
        $this->assertSame('als-transport', $this->requestModel($response->json('data.id'))->form_family);
        // An empty family means none.
        $this->send([self::TAPE_ALS + ['quantity' => 1]], '')->assertCreated()->assertJsonPath('data.form_family', 'als-transport');
    }

    public function test_handtevy_belongs_to_both_als_forms_and_nothing_is_counted_twice(): void
    {
        $transport = $this->send([self::BVM_HANDTEVY + ['quantity' => 1], self::TAPE_ALS + ['quantity' => 1]], 'als-transport')->assertCreated();
        $nonTransport = $this->send([self::BVM_HANDTEVY + ['quantity' => 2], self::GAUZE_NON_TRANSPORT + ['quantity' => 1]], 'als-non-transport')->assertCreated();

        $this->assertSame(['als-med-bag', 'handtevy'], array_column($transport->json('data.lines'), 'bag_key'));
        $this->assertSame(['als-non-transport', 'handtevy'], array_column($nonTransport->json('data.lines'), 'bag_key'));
        // One identity per request: the same Handtevy item twice on one request is still refused.
        $this->send([self::BVM_HANDTEVY + ['quantity' => 1], self::BVM_HANDTEVY + ['quantity' => 2]], 'als-non-transport')
            ->assertStatus(422)->assertJsonPath('error.code', 'duplicate_item');
        // A form's lines never cross into the other: Handtevy is not on Fire Boat 6 or BLS Detail.
        $this->send([self::BVM_HANDTEVY + ['quantity' => 1]], 'fire-boat-6')->assertStatus(422)->assertJsonPath('error.code', 'unknown_item');
        $this->send([self::BVM_HANDTEVY + ['quantity' => 1]], 'bls-detail')->assertStatus(422)->assertJsonPath('error.code', 'unknown_item');
        $this->assertSame(2, InventoryRequest::query()->count());
    }

    public function test_a_line_from_another_familys_list_is_refused_and_nothing_is_saved(): void
    {
        $this->send([self::TAPE_ALS + ['quantity' => 1]], 'bls-detail')->assertStatus(422)->assertJsonPath('error.code', 'unknown_item');
        $this->send([self::TAPE_BLS + ['quantity' => 1]], 'als-transport')->assertStatus(422)->assertJsonPath('error.code', 'unknown_item');
        $this->send([self::GAUZE_NON_TRANSPORT + ['quantity' => 1]])->assertStatus(422)->assertJsonPath('error.code', 'unknown_item');
        // One good line with one from the wrong list saves nothing, not even the good one.
        $this->send([self::OXYGEN_BOAT + ['quantity' => 1], self::TAPE_BLS + ['quantity' => 1]], 'fire-boat-6')->assertStatus(422)->assertJsonPath('error.code', 'unknown_item');
        // A name that is on no list at all is the plain validation refusal it always was.
        $this->send([['form' => 'nonsense-list', 'item_key' => 'x', 'quantity' => 1]], 'als-transport')->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');

        $this->assertSame(0, InventoryRequest::query()->count());
    }

    public function test_an_unknown_family_and_an_unavailable_family_are_refused(): void
    {
        $this->send([self::TAPE_ALS + ['quantity' => 1]], 'nope')->assertStatus(422)->assertJsonPath('error.code', 'unknown_family');
        $this->send([self::TAPE_ALS + ['quantity' => 1]], str_repeat('x', 41))->assertStatus(422);

        // Lists that were never imported: the member is told it is not available yet, and nothing is saved.
        \App\Models\Ems\SourceForm::query()->where('key', 'bls-detail-bag')->update(['active' => false]);
        $this->send([self::TAPE_BLS + ['quantity' => 1]], 'bls-detail')->assertStatus(503)->assertJsonPath('error.code', 'forms_unavailable');
        $this->assertSame(0, InventoryRequest::query()->count());
    }

    public function test_a_family_form_still_needs_an_item_or_a_note_and_a_unit(): void
    {
        $this->send([], 'bls-detail')->assertStatus(422)->assertJsonPath('error.code', 'empty_request');
        $this->send([], 'bls-detail', ['member_note' => 'Need a bag restocked'])->assertCreated()->assertJsonPath('data.form_family', 'bls-detail');
        $this->send([self::TAPE_BLS + ['quantity' => 1]], 'bls-detail', ['target' => ['kind' => 'detail_event', 'event_assignment' => 'Air show']])
            ->assertCreated()->assertJsonPath('data.target_label', 'Detail / Special Event');
        $this->send([self::TAPE_BLS + ['quantity' => 1]], 'bls-detail', ['target' => ['kind' => 'rescue_unit', 'apparatus_id' => 99999]])->assertStatus(422)->assertJsonPath('error.code', 'invalid_unit');
    }

    public function test_the_line_cap_is_nine_hundred(): void
    {
        $this->assertSame(900, \App\Services\Ems\RequestFormDefinition::MAX_LINES);
        $lines = array_map(fn (int $n): array => ['form' => 'als-med-bag', 'item_key' => 'x.'.$n, 'quantity' => 1], range(1, 901));

        $this->send($lines)->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    }

    public function test_replaying_a_family_submission_returns_the_same_request(): void
    {
        $payload = [
            'client_submission_id' => (string) Str::uuid(), 'family' => 'fire-boat-6',
            'target' => ['kind' => 'rescue_unit', 'apparatus_id' => $this->rescue2->id],
            'lines' => [self::OXYGEN_BOAT + ['quantity' => 1]],
        ];

        $first = $this->emsPost($this->member, self::URI, $payload)->assertCreated()->assertJsonPath('meta.replayed', false);
        $again = $this->emsPost($this->member, self::URI, $payload)->assertOk()->assertJsonPath('meta.replayed', true);

        $this->assertSame($first->json('data.id'), $again->json('data.id'));
        $this->assertSame('fire-boat-6', $again->json('data.form_family'));
        $this->assertSame(1, InventoryRequest::query()->count());
        $this->assertSame(1, OperationalFormRecord::query()->where('form_type', 'medical_inventory_request')->count());
    }

    // ------------------------------------------------------------------ corrections

    private function returned(string $family, array $line): string
    {
        $captain = $this->emsCaptain('Cap Tain');
        $uuid = $this->send([$line], $family)->assertCreated()->json('data.id');
        $this->emsPost($captain, "/ems/api/requests/{$uuid}/reject", ['reason' => 'Check the count'])->assertOk();

        return $uuid;
    }

    public function test_a_correction_keeps_the_family_of_the_form_it_corrects(): void
    {
        $original = $this->returned('bls-detail', self::TAPE_BLS + ['quantity' => 2]);

        // The page names the same family, or (an older page) none: both land on the original's family.
        $this->actingAs($this->member);
        $this->getJson(self::URI.'/'.$original)->assertOk()->assertJsonPath('data.form_family', 'bls-detail')->assertJsonPath('data.can_revise', true);
        $revision = $this->send([self::TAPE_BLS + ['quantity' => 5]], null, ['revises' => $original])->assertCreated();

        $revision->assertJsonPath('data.form_family', 'bls-detail')->assertJsonPath('data.revision_number', 2);
        $this->assertSame('bls-detail', $this->requestModel($revision->json('data.id'))->form_family);
        $this->assertSame(InventoryRequest::SUPERSEDED, $this->requestModel($original)->status);
    }

    public function test_a_correction_that_names_the_same_family_is_accepted(): void
    {
        $original = $this->returned('fire-boat-6', self::OXYGEN_BOAT + ['quantity' => 1]);

        $this->send([self::OXYGEN_BOAT + ['quantity' => 2]], 'fire-boat-6', ['revises' => $original])->assertCreated()->assertJsonPath('data.form_family', 'fire-boat-6');
    }

    public function test_a_correction_that_names_a_different_family_is_refused_and_the_original_waits(): void
    {
        $original = $this->returned('bls-detail', self::TAPE_BLS + ['quantity' => 2]);

        $this->send([self::TAPE_ALS + ['quantity' => 1]], 'als-transport', ['revises' => $original])
            ->assertStatus(422)->assertJsonPath('error.code', 'family_mismatch');
        $this->assertSame(InventoryRequest::RETURNED, $this->requestModel($original)->status);
        $this->assertSame(1, InventoryRequest::query()->count());

        // Lines from the wrong list on a correction with no family are refused against the original's family.
        $this->send([self::TAPE_ALS + ['quantity' => 1]], null, ['revises' => $original])->assertStatus(422)->assertJsonPath('error.code', 'unknown_item');
        $this->assertSame(InventoryRequest::RETURNED, $this->requestModel($original)->status);
    }

    public function test_another_members_correction_is_still_a_plain_cannot_revise(): void
    {
        $original = $this->returned('bls-detail', self::TAPE_BLS + ['quantity' => 2]);
        $other = $this->emsMember();

        $this->send([self::TAPE_ALS + ['quantity' => 1]], 'als-transport', ['revises' => $original], $other)
            ->assertStatus(409)->assertJsonPath('error.code', 'cannot_revise');
    }

    public function test_a_request_that_has_no_family_is_als_transport_for_every_reader(): void
    {
        $uuid = $this->send([self::TAPE_ALS + ['quantity' => 1]])->assertCreated()->json('data.id');
        // What a rolled-back image writes, and what a row from before the four forms holds.
        DB::table('ems_inventory_requests')->where('uuid', $uuid)->update(['form_family' => null]);

        $this->actingAs($this->member);
        $this->getJson(self::URI.'/'.$uuid)->assertOk()->assertJsonPath('data.form_family', 'als-transport')->assertJsonPath('data.form_family_title', 'ALS Transport Inventory Form');
        $row = collect($this->getJson(self::URI)->assertOk()->json('data'))->firstWhere('id', $uuid);
        $this->assertSame('als-transport', $row['form_family']);
        $this->assertSame('ALS Transport', $row['form_family_short']);
        $this->assertSame('als-transport', $this->requestModel($uuid)->family()['key']);
    }

    public function test_a_correction_of_a_request_with_no_family_is_als_transport(): void
    {
        $captain = $this->emsCaptain('Cap Tain');
        $uuid = $this->send([self::TAPE_ALS + ['quantity' => 1]])->assertCreated()->json('data.id');
        DB::table('ems_inventory_requests')->where('uuid', $uuid)->update(['form_family' => null]);
        $this->emsPost($captain, "/ems/api/requests/{$uuid}/reject", ['reason' => 'Check the count'])->assertOk();

        $this->send([self::TAPE_ALS + ['quantity' => 3]], null, ['revises' => $uuid])->assertCreated()->assertJsonPath('data.form_family', 'als-transport');
    }

    // ---------------------------------------------------------------- migration

    public function test_the_migration_marks_existing_requests_als_transport_and_refuses_to_run_backwards_in_production(): void
    {
        $this->send([self::TAPE_BLS + ['quantity' => 1]], 'bls-detail')->assertCreated();
        $this->send([self::TAPE_ALS + ['quantity' => 1]])->assertCreated();
        $migration = require base_path('database/migrations/2026_11_05_140000_add_form_family_to_ems_inventory_requests.php');

        // Back to the state before the migration, then forward: every row that already existed becomes ALS Transport.
        $migration->down();
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('ems_inventory_requests', 'form_family'));
        $migration->up();

        $this->assertSame(['als-transport', 'als-transport'], InventoryRequest::query()->orderBy('id')->pluck('form_family')->all());
        $this->assertSame(0, DB::table('ems_inventory_requests')->whereNull('form_family')->count());

        $this->app['env'] = 'production';
        try {
            $this->expectException(\RuntimeException::class);
            $migration->down();
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    // ---------------------------------------------------------------- member copy

    public function test_new_records_are_named_for_their_form_and_the_pdf_heading_matches(): void
    {
        Storage::fake((string) config('filesystems.private', 'local'));
        $uuid = $this->send([self::OXYGEN_BOAT + ['quantity' => 1]], 'fire-boat-6', ['target' => ['kind' => 'detail_event']])->assertCreated()->json('data.id');
        $request = $this->requestModel($uuid);

        $this->actingAs($this->member);
        $record = $this->getJson('/employee/forms/api/records')->assertOk()->json('records.0');
        $this->assertSame("Fire Boat 6 Medical Inventory Form {$request->number} · Detail / Special Event", $record['title']);
        $this->assertSame('medical_inventory_request', $record['form_type'], 'The record type does not change.');
        $this->assertSame('fire-boat-6', OperationalFormRecord::query()->find($record['id'])->data['medical_inventory_request']['form_family']);

        $html = view('pdf.medical-inventory-request', [
            'number' => $request->number, 'formTitle' => MemberCopyService::formTitle($request), 'submittedAt' => 'now', 'submitterName' => 'x', 'submitterRank' => null,
            'targetLabel' => 'x', 'eventAssignment' => null, 'memberNote' => null, 'lineCount' => 1, 'bags' => [], 'snapshotSha' => str_repeat('a', 64), 'logo' => null,
        ])->render();
        $this->assertStringContainsString('<h1>Fire Boat 6 Medical Inventory Form</h1>', $html);
        $this->assertStringContainsString('EMS Division - Fire Boat 6 Medical Inventory Form', $html);
    }

    public function test_existing_records_and_old_snapshots_are_never_retitled(): void
    {
        Storage::fake((string) config('filesystems.private', 'local'));
        $uuid = $this->send([self::TAPE_ALS + ['quantity' => 1]])->assertCreated()->json('data.id');
        $request = $this->requestModel($uuid);
        $record = OperationalFormRecord::query()->findOrFail($request->operational_form_record_id);

        // Make it what a record made before the four forms looks like: the old title, an old snapshot, no family.
        $legacyTitle = "Medical Inventory Request {$request->number} · Rescue 2";
        $record->forceFill(['title' => $legacyTitle])->save();
        $snapshot = $request->snapshot;
        unset($snapshot['form_family'], $snapshot['form_family_title']);
        DB::table('ems_inventory_requests')->where('id', $request->id)->update(['form_family' => null, 'snapshot' => json_encode($snapshot)]);
        $request = $request->fresh();
        $this->assertSame('Medical Inventory Request', MemberCopyService::formTitle($request));
        $this->assertSame($legacyTitle, app(MemberCopyService::class)->title($request), 'A request with an old snapshot keeps the old name.');

        // The file goes missing and the on-demand path rebuilds it: the record keeps its name and its heading is the old one.
        $document = $record->latestDocument()->first();
        Storage::disk($document->storage_disk)->delete($document->storage_path);
        $this->assertFalse(app(MemberCopyService::class)->isComplete($request));
        $this->actingAs($this->member);
        $this->getJson(self::URI.'/'.$uuid)->assertOk();
        app(MemberCopyService::class)->ensure($request);

        $this->assertTrue(app(MemberCopyService::class)->isComplete($request->fresh()));
        $this->assertSame($legacyTitle, $record->fresh()->title);
        $this->assertSame(1, OperationalFormRecord::query()->where('form_type', 'medical_inventory_request')->count());
        $this->assertGreaterThan(0, strlen(app(RequestPdfRenderer::class)->render($request->fresh())->output()), 'An old snapshot still renders.');
    }

    public function test_a_family_record_keeps_its_name_when_it_is_regenerated(): void
    {
        Storage::fake((string) config('filesystems.private', 'local'));
        $uuid = $this->send([self::TAPE_BLS + ['quantity' => 1]], 'bls-detail')->assertCreated()->json('data.id');
        $request = $this->requestModel($uuid);
        $record = OperationalFormRecord::query()->findOrFail($request->operational_form_record_id);
        $title = $record->title;
        $document = $record->latestDocument()->first();
        Storage::disk($document->storage_disk)->delete($document->storage_path);

        app(MemberCopyService::class)->ensure($request);

        $this->assertSame("BLS Detail Medical Inventory Form {$request->number} · Rescue 2", $title);
        $this->assertSame($title, $record->fresh()->title);
    }

    // ---------------------------------------------------------------- notifications

    public function test_notifications_name_the_form_and_keep_their_links_and_keys(): void
    {
        Notification::fake();
        $seat = $this->emsCaptain('Seat Captain');
        $this->emsCaptain5Seat($seat);

        $uuid = $this->send([self::TAPE_BLS + ['quantity' => 2]], 'bls-detail')->assertCreated()->json('data.id');
        $id = $this->requestModel($uuid)->id;
        $sent = Notification::sent($seat, InspectionEventNotification::class)->first(fn (InspectionEventNotification $n): bool => $n->kind === 'ems_request_submitted');
        $this->assertNotNull($sent);
        $this->assertSame("ems:request:{$id}:submitted", $sent->eventKey);
        $this->assertStringContainsString('BLS Detail Medical Inventory Form', $sent->title);
        $this->assertStringContainsString('BLS Detail Medical Inventory Form', $sent->body);
        $this->assertStringContainsString('/daily/captain-5', $sent->url);

        // Returned: the member hears which form, and the link opens that request, which opens in its own form.
        $this->emsPost($seat, "/ems/api/requests/{$uuid}/reject", ['reason' => 'Check the count'])->assertOk();
        $returned = Notification::sent($this->member, InspectionEventNotification::class)->first(fn (InspectionEventNotification $n): bool => $n->kind === 'ems_request_update');
        $this->assertNotNull($returned);
        $this->assertStringContainsString('BLS Detail Medical Inventory Form', $returned->title);
        $this->assertStringContainsString('BLS Detail Medical Inventory Form', $returned->body);
        $this->assertSame('/employee/forms?ems_request='.$uuid, $returned->url);
    }
}
