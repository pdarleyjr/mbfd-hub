<?php

declare(strict_types=1);

namespace Tests\Feature\Ems\Admin;

use App\Filament\Resources\InventoryRequestResource;
use App\Filament\Resources\InventoryRequestResource\Pages\ListInventoryRequests;
use App\Filament\Resources\InventoryRequestResource\Pages\ViewInventoryRequest;
use App\Models\Ems\InventoryRequest;
use App\Models\Ems\InventoryRequestEvent;
use App\Models\Ems\InventoryRequestMessage;
use App\Models\Ems\StockMovement;
use App\Models\User;
use App\Services\Ems\EmsException;
use App\Services\Ems\RequestWorkflowService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Feature\Ems\Concerns\BuildsEmsWorld;
use Tests\TestCase;

final class RescueInventoryResourceTest extends TestCase
{
    use BuildsEmsWorld;
    use RefreshDatabase;

    private User $member;

    private User $manager;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->buildEmsWorld();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->member = $this->emsMember('Paramedic', 'Pat Medic');
        $this->manager = $this->emsAdmin(['admin.ems.manage'], 'Manny Manager');
        $this->viewer = $this->emsAdmin(['admin.ems.view'], 'Vera Viewer');
    }

    protected function tearDown(): void
    {
        $this->tearDownEmsWorld();
        parent::tearDown();
    }

    private function actAs(User $user): void
    {
        $this->forgetEmsMemo();
        $this->actingAs($user->fresh());
    }

    /** @return array{uuid: string, number: string} */
    private function tapeRequest(int $quantity = 4, ?User $member = null): array
    {
        return $this->submitRequest($member ?? $this->member, [['form' => 'als-med-bag', 'item_key' => 'als.trauma.tape', 'quantity' => $quantity]]);
    }

    private function viewPage(string $uuid): Testable
    {
        return Livewire::test(ViewInventoryRequest::class, ['record' => $uuid]);
    }

    /** Mount an action that has a repeater, set one quantity per line key, and submit it. */
    private function submitRepeater(Testable $page, string $action, string $field, array $valuesByLineId): Testable
    {
        $page->mountAction($action);
        foreach ((array) $page->get('mountedActionsData.0.lines') as $key => $row) {
            $page->set("mountedActionsData.0.lines.{$key}.{$field}", $valuesByLineId[(int) $row['line_id']] ?? null);
        }

        return $page->callMountedAction();
    }

    /** The request as the table loads it, with its item count and quantity totals. */
    private function listed(string $uuid): InventoryRequest
    {
        return InventoryRequestResource::getEloquentQuery()->where('uuid', $uuid)->firstOrFail();
    }

    private function lineId(string $uuid, string $itemKey): int
    {
        return (int) InventoryRequest::query()->where('uuid', $uuid)->firstOrFail()->lines()->where('item_key', $itemKey)->value('id');
    }

    // ------------------------------------------------------------------ list

    public function test_the_list_shows_open_requests_by_default_and_filters_search_and_sorts(): void
    {
        $open = $this->tapeRequest();
        $other = $this->tapeRequest(2, $this->emsMember('Firefighter', 'Fred Fighter'));
        $closed = $this->tapeRequest(1);
        $this->actAs($this->manager);
        app(RequestWorkflowService::class)->close((int) $this->requestModel($closed['uuid'])->id, $this->manager, 'close-key-0001', ['reason' => 'No action required']);

        $page = Livewire::test(ListInventoryRequests::class)->call('loadTable');
        $page->assertCanSeeTableRecords([$this->requestModel($open['uuid']), $this->requestModel($other['uuid'])])
            ->assertCanNotSeeTableRecords([$this->requestModel($closed['uuid'])])
            ->assertTableColumnStateSet('qty_requested', 4, $this->listed($open['uuid']))
            ->assertTableColumnStateSet('qty_issued', 0, $this->listed($open['uuid']));

        $page->filterTable('status', ['closed'])->assertCanSeeTableRecords([$this->requestModel($closed['uuid'])])
            ->assertCanNotSeeTableRecords([$this->requestModel($open['uuid'])]);
        $page->filterTable('status', InventoryRequest::STATUSES)
            ->searchTable('Fred')->assertCanSeeTableRecords([$this->requestModel($other['uuid'])])->assertCanNotSeeTableRecords([$this->requestModel($open['uuid'])])
            ->searchTable($open['number'])->assertCanSeeTableRecords([$this->requestModel($open['uuid'])])->assertCanNotSeeTableRecords([$this->requestModel($other['uuid'])]);
        $page->searchTable('')->filterTable('apparatus_id', $this->rescue2->id)->assertCanSeeTableRecords([$this->requestModel($open['uuid'])]);
        $page->filterTable('target_kind', 'detail_event')->assertCanNotSeeTableRecords([$this->requestModel($open['uuid'])]);
    }

    public function test_the_list_filters_by_date_and_by_a_waiting_member_message(): void
    {
        $old = $this->tapeRequest();
        $new = $this->tapeRequest(2);
        InventoryRequest::query()->where('uuid', $old['uuid'])->update(['submitted_at' => '2026-01-15 15:00:00']);
        $this->actAs($this->manager);
        $oldModel = $this->requestModel($old['uuid']);
        $newModel = $this->requestModel($new['uuid']);

        $page = Livewire::test(ListInventoryRequests::class)->call('loadTable')
            ->filterTable('submitted', ['from' => '2026-01-10', 'until' => '2026-01-20'])
            ->assertCanSeeTableRecords([$oldModel])->assertCanNotSeeTableRecords([$newModel]);
        $page->filterTable('submitted', ['from' => null, 'until' => null]);

        $this->actAs($this->member);
        $this->emsPost($this->member, "/employee/forms/api/ems/requests/{$new['uuid']}/messages", ['body' => 'Do you have more tape?'])->assertSuccessful();
        $this->actAs($this->manager);
        Livewire::test(ListInventoryRequests::class)->call('loadTable')
            ->filterTable('waiting_for_reply', true)
            ->assertCanSeeTableRecords([$newModel])->assertCanNotSeeTableRecords([$oldModel]);
    }

    // ------------------------------------------------------------- view + actions

    public function test_the_view_page_shows_the_request_and_review_prepare_issue_use_the_services(): void
    {
        $this->finalizeCount($this->manager, ['sr.t.02' => 30]);
        $request = $this->tapeRequest(4);
        $lineId = $this->lineId($request['uuid'], 'als.trauma.tape');
        $this->actAs($this->manager);

        $page = $this->viewPage($request['uuid'])->assertOk()
            ->assertSee($request['number'])->assertSee('Pat Medic')->assertSee('Rescue 2')->assertSee('Tape 1')->assertSee('Submitted');

        $page->callAction('review');
        $this->assertSame('reviewed', $this->requestModel($request['uuid'])->status);
        $this->assertSame(1, InventoryRequestEvent::query()->where('action', 'reviewed')->count());

        $this->submitRepeater($this->viewPage($request['uuid']), 'prepare', 'prepared_qty', [$lineId => 4])->assertHasNoActionErrors();
        $this->assertSame('prepared', $this->requestModel($request['uuid'])->status);
        $this->assertSame(4, $this->requestModel($request['uuid'])->lines()->first()->prepared_qty);
        $this->assertSame(0, StockMovement::query()->count(), 'Preparing never moves stock.');

        $page = $this->viewPage($request['uuid']);
        $this->submitRepeater($page, 'issue', 'quantity', [$lineId => 3])->assertHasNoActionErrors();
        $model = $this->requestModel($request['uuid']);
        $this->assertSame('partially_issued', $model->status);
        $this->assertSame(3, $model->lines()->first()->issued_qty);
        $movement = StockMovement::query()->sole();
        $this->assertSame(-3, $movement->quantity_base);
        $this->assertSame($this->manager->id, $movement->actor_user_id);
        $this->viewPage($request['uuid'])->assertSee('Stock updated')->assertSee('Issued 3');

        $this->submitRepeater($this->viewPage($request['uuid']), 'issue', 'quantity', [$lineId => 1]);
        $this->assertSame('issued', $this->requestModel($request['uuid'])->status);
        $this->assertSame(26, $this->stockOf($this->manager, 'sr.t.02')['current_quantity']);
    }

    public function test_issuing_more_than_requested_needs_a_reason_and_a_missing_match_is_recorded_only(): void
    {
        $this->finalizeCount($this->manager, ['sr.t.02' => 30]);
        $request = $this->tapeRequest(2);
        $lineId = $this->lineId($request['uuid'], 'als.trauma.tape');
        $this->actAs($this->manager);

        $page = $this->viewPage($request['uuid']);
        $this->submitRepeater($page, 'issue', 'quantity', [$lineId => 5]);
        $this->assertSame(0, StockMovement::query()->count(), 'Nothing is issued until the reason is given.');
        $this->assertSame('submitted', $this->requestModel($request['uuid'])->status, 'A refused issue changes nothing, not even the review.');
        $this->assertSame(0, $this->requestModel($request['uuid'])->lines()->first()->issued_qty);

        $page->mountAction('issue');
        foreach ((array) $page->get('mountedActionsData.0.lines') as $key => $row) {
            $page->set("mountedActionsData.0.lines.{$key}.quantity", 5)->set("mountedActionsData.0.lines.{$key}.reason", 'Crew restocking a second bag');
        }
        $page->callMountedAction();
        $this->assertSame(5, $this->requestModel($request['uuid'])->lines()->first()->issued_qty);
        $this->assertTrue((bool) InventoryRequestEvent::query()->where('action', 'issued')->first()->payload['over_issue']);

        // Gauze has two candidate supply items: without a choice the issue is recorded only.
        $gauze = $this->submitRequest($this->member, [['form' => 'als-med-bag', 'item_key' => 'als.trauma.gauze', 'quantity' => 10]]);
        $gauzeLine = $this->lineId($gauze['uuid'], 'als.trauma.gauze');
        $this->actAs($this->manager);
        $this->submitRepeater($this->viewPage($gauze['uuid']), 'issue', 'quantity', [$gauzeLine => 10]);
        $event = InventoryRequestEvent::query()->where('request_line_id', $gauzeLine)->where('action', 'issued')->sole();
        $this->assertSame('unmatched', $event->payload['stock_effect']);
        $this->viewPage($gauze['uuid'])->assertSee('Recorded only: no matching supply room item');

        // Choosing one of the candidates attaches the stock change.
        $other = $this->submitRequest($this->member, [['form' => 'als-med-bag', 'item_key' => 'als.trauma.gauze', 'quantity' => 6]]);
        $otherLine = $this->lineId($other['uuid'], 'als.trauma.gauze');
        $this->actAs($this->manager);
        $page = $this->viewPage($other['uuid']);
        $page->mountAction('issue');
        foreach ((array) $page->get('mountedActionsData.0.lines') as $key => $row) {
            $this->assertCount(2, $row['candidates']);
            $page->set("mountedActionsData.0.lines.{$key}.quantity", 6)->set("mountedActionsData.0.lines.{$key}.supply_item_id", $this->supplyItem('sr.t.07')->id);
        }
        $page->callMountedAction();
        $movement = StockMovement::query()->where('request_line_id', $otherLine)->sole();
        $this->assertSame(-6, $movement->quantity_base);
        $this->assertSame($this->supplyItem('sr.t.07')->id, $movement->supply_item_id);
    }

    public function test_not_issued_close_reopen_correct_and_message_each_persist_state_and_history(): void
    {
        $this->finalizeCount($this->manager, ['sr.t.02' => 30]);
        $request = $this->submitRequest($this->member, [
            ['form' => 'als-med-bag', 'item_key' => 'als.trauma.tape', 'quantity' => 4],
            ['form' => 'als-med-bag', 'item_key' => 'als.trauma.gauze', 'quantity' => 10],
        ]);
        $tapeLine = $this->lineId($request['uuid'], 'als.trauma.tape');
        $gauzeLine = $this->lineId($request['uuid'], 'als.trauma.gauze');
        $this->actAs($this->manager);

        $this->viewPage($request['uuid'])
            ->callAction('notIssued', ['reason' => 'Not stocked at the supply room'], ['line' => $gauzeLine])->assertHasNoActionErrors();
        $gauze = $this->requestModel($request['uuid'])->lines()->where('id', $gauzeLine)->first();
        $this->assertSame('not_issued', $gauze->line_status);
        $this->assertSame('Not stocked at the supply room', $gauze->not_issued_reason);

        $this->submitRepeater($this->viewPage($request['uuid']), 'issue', 'quantity', [$tapeLine => 4]);
        $this->assertSame('issued', $this->requestModel($request['uuid'])->status);
        $this->assertSame(26, $this->stockOf($this->manager, 'sr.t.02')['current_quantity']);

        // Correct the issuance: 4 should have been 3, so one unit goes back on the shelf.
        $issued = InventoryRequestEvent::query()->where('action', 'issued')->sole();
        $this->viewPage($request['uuid'])->callAction('correct', ['event_id' => $issued->id, 'new_quantity' => 3, 'reason' => 'Miscounted at the shelf'])->assertHasNoActionErrors();
        $this->assertSame(3, $this->requestModel($request['uuid'])->lines()->where('id', $tapeLine)->value('issued_qty'));
        $this->assertSame(27, $this->stockOf($this->manager, 'sr.t.02')['current_quantity']);
        $this->assertSame(1, InventoryRequestEvent::query()->where('action', 'issue_corrected')->count());
        $this->assertSame(4, (int) $issued->fresh()->payload['quantity'], 'The original entry is untouched.');

        $this->viewPage($request['uuid'])->callAction('message', ['body' => 'Your tape is at the front desk.'])->assertHasNoActionErrors();
        $message = InventoryRequestMessage::query()->sole();
        $this->assertSame('Your tape is at the front desk.', $message->body);
        $this->assertSame('ems_admin', $message->author_context);
        $this->viewPage($request['uuid'])->assertSee('Your tape is at the front desk.')->assertSee('EMS Admin');

        $this->viewPage($request['uuid'])->callAction('close', ['reason_choice' => 'Issued in full'])->assertHasNoActionErrors();
        $closed = $this->requestModel($request['uuid']);
        $this->assertSame('closed', $closed->status);
        $this->assertSame('Issued in full', $closed->close_reason);
        $this->viewPage($request['uuid'])->assertActionHidden('issue')->assertActionVisible('reopen');

        $this->viewPage($request['uuid'])->callAction('reopen', ['reason' => 'Crew asked for one more'])->assertHasNoActionErrors();
        $this->assertNotSame('closed', $this->requestModel($request['uuid'])->status);
        $this->assertSame(['closed', 'reopened'], InventoryRequestEvent::query()->whereIn('action', ['closed', 'reopened'])->orderBy('id')->pluck('action')->all());

        $this->viewPage($request['uuid'])->callAction('close', ['reason_choice' => '__other', 'other_reason' => 'Handled at the station'])->assertHasNoActionErrors();
        $this->assertSame('Handled at the station', $this->requestModel($request['uuid'])->close_reason);
    }

    public function test_a_failed_service_call_keeps_the_modal_open_with_a_plain_message(): void
    {
        $request = $this->tapeRequest(2);
        $this->actAs($this->manager);
        $page = $this->viewPage($request['uuid']);
        $page->mountAction('issue');
        foreach ((array) $page->get('mountedActionsData.0.lines') as $key => $row) {
            $page->set("mountedActionsData.0.lines.{$key}.quantity", 9);
        }
        $page->callMountedAction()->assertNotified('Nothing was changed');
        $this->assertSame(0, $this->requestModel($request['uuid'])->lines()->first()->issued_qty);
    }

    // ------------------------------------------------------------------ access

    public function test_a_read_only_viewer_sees_everything_but_no_actions_and_the_server_refuses_them(): void
    {
        $request = $this->tapeRequest(2);
        $lineId = $this->lineId($request['uuid'], 'als.trauma.tape');
        $this->actAs($this->viewer);

        Livewire::test(ListInventoryRequests::class)->call('loadTable')->assertCanSeeTableRecords([$this->requestModel($request['uuid'])]);
        $page = $this->viewPage($request['uuid'])->assertOk()->assertSee($request['number']);
        foreach (['review', 'prepare', 'issue', 'close', 'message'] as $name) {
            $page->assertActionHidden($name);
        }

        // Forcing the calls anyway changes nothing.
        $page->call('mountAction', 'review')->call('callMountedAction');
        $page->call('mountAction', 'issue')->call('callMountedAction');
        $this->assertSame('submitted', $this->requestModel($request['uuid'])->status);
        $this->assertSame(0, InventoryRequestEvent::query()->where('action', 'reviewed')->count());

        // The service itself refuses a viewer.
        try {
            app(RequestWorkflowService::class)->review((int) $this->requestModel($request['uuid'])->id, $this->viewer->fresh(), (string) Str::uuid());
            $this->fail('A viewer acted on a request.');
        } catch (EmsException $exception) {
            $this->assertSame(403, $exception->status);
        }
        $this->assertSame(0, StockMovement::query()->count());
        $this->assertNotNull($lineId);
    }

    public function test_the_resource_has_no_create_edit_or_delete(): void
    {
        $this->actAs($this->manager);
        $this->assertTrue(InventoryRequestResource::canViewAny());
        $this->assertFalse(InventoryRequestResource::canCreate());
        $request = $this->requestModel($this->tapeRequest()['uuid']);
        $this->actAs($this->manager);
        $this->assertFalse(InventoryRequestResource::canEdit($request));
        $this->assertFalse(InventoryRequestResource::canDelete($request));
        $this->assertSame(['index', 'view'], array_keys(InventoryRequestResource::getPages()));
    }
}
