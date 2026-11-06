<?php

declare(strict_types=1);

namespace Tests\Feature\Ems;

use App\Models\Ems\InventoryRequest;
use App\Models\Ems\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Ems\Concerns\BuildsEmsWorld;
use Tests\TestCase;

/**
 * Captain 5 hands a unit its supplies himself and clears the form: it is closed and archived (findable in Archived,
 * restorable), the history records who and why, and stock never moves. Reconcile stays the step that deducts.
 */
final class Captain5ClearFormTest extends TestCase
{
    use BuildsEmsWorld;
    use RefreshDatabase;

    private const TAPE = ['form' => 'als-med-bag', 'item_key' => 'als.trauma.tape'];

    private User $captain;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildEmsWorld();
        $this->captain = $this->emsCaptain('Cap Tain');
        $this->member = $this->emsMember('Firefighter', 'Fire Fighter');
    }

    protected function tearDown(): void
    {
        $this->tearDownEmsWorld();
        parent::tearDown();
    }

    /** @return \Illuminate\Support\Collection<int, array<string, mixed>> */
    private function rows(string $state): \Illuminate\Support\Collection
    {
        $this->actingAs($this->captain);

        return collect($this->getJson("/ems/api/requests?state={$state}")->assertOk()->json('data'));
    }

    public function test_clear_archives_the_form_logs_who_and_why_and_moves_no_stock(): void
    {
        $this->finalizeCount($this->captain, ['sr.t.02' => 30]);
        $movementsBefore = StockMovement::query()->count();
        $uuid = $this->submitRequest($this->member, [self::TAPE + ['quantity' => 2]])['uuid'];

        $row = $this->rows('active')->firstWhere('id', $uuid);
        $this->assertTrue($row['can_clear']);
        $this->assertFalse($row['can_restore']);

        // One tap, even before acknowledging: the acknowledgment is recorded first, then the close.
        $this->emsPost($this->captain, "/ems/api/requests/{$uuid}/close", ['reason' => 'Supplies provided. Handed over at Station 2'], 'clear-key-0001')
            ->assertOk()
            ->assertJsonPath('data.status', InventoryRequest::CLOSED)
            ->assertJsonPath('data.can_restore', true)
            ->assertJsonPath('data.can_clear', false)
            ->assertJsonPath('data.closed_by', 'Cap Tain');

        // A retry with the same key replays; nothing is written twice.
        $this->emsPost($this->captain, "/ems/api/requests/{$uuid}/close", ['reason' => 'Supplies provided. Handed over at Station 2'], 'clear-key-0001')
            ->assertOk()->assertJsonPath('meta.replayed', true);

        $model = $this->requestModel($uuid);
        $this->assertSame(InventoryRequest::CLOSED, $model->status);
        $this->assertSame('Supplies provided. Handed over at Station 2', $model->close_reason);
        $this->assertSame((int) $this->captain->id, (int) $model->closed_by_user_id);
        $this->assertSame(2, (int) $model->lines()->value('requested_qty'), 'The submitted lines are untouched.');
        $this->assertSame(0, (int) $model->lines()->sum('issued_qty'));
        $this->assertSame($movementsBefore, StockMovement::query()->count(), 'Clear never moves stock.');

        $events = $model->events()->get();
        $this->assertSame(1, $events->where('action', 'reviewed')->count());
        $closed = $events->where('action', 'closed');
        $this->assertCount(1, $closed);
        $this->assertSame('Supplies provided. Handed over at Station 2', $closed->first()->payload['reason']);
        $this->assertSame(InventoryRequest::CLOSED, $closed->first()->to_status);
        $this->assertSame('Cap Tain', $closed->first()->actor_name);

        // Gone from Active and from the "forms waiting" badge count; found in Archived, searchable, restorable.
        $this->assertFalse($this->rows('active')->pluck('id')->contains($uuid));
        $this->actingAs($this->captain);
        $this->assertSame(0, $this->getJson('/ems/api/requests?status=submitted,reviewed,prepared&per_page=1')->assertOk()->json('meta.total'));
        $archived = $this->rows('archived')->firstWhere('id', $uuid);
        $this->assertNotNull($archived);
        $this->assertTrue($archived['can_restore']);
        $this->assertSame('Cap Tain', $archived['closed_by']);
        $this->assertSame('Supplies provided. Handed over at Station 2', $archived['close_reason']);
        $this->actingAs($this->captain);
        $this->assertTrue(collect($this->getJson('/ems/api/requests?state=archived&q='.urlencode($model->number))->json('data'))->pluck('id')->contains($uuid));

        // Reconcile refuses a cleared form, so a cleared form can never also come off the shelf by accident.
        $this->emsPost($this->captain, '/ems/api/forms/reconcile', ['request_ids' => [$uuid], 'expected_last_movement_id' => (int) StockMovement::query()->max('id'), 'accept_low_stock' => true])
            ->assertStatus(409);
        $this->assertSame($movementsBefore, StockMovement::query()->count());
    }

    public function test_restore_puts_a_cleared_form_back_in_active_and_logs_it(): void
    {
        $uuid = $this->submitRequest($this->member, [self::TAPE + ['quantity' => 1]])['uuid'];
        $this->emsPost($this->captain, "/ems/api/requests/{$uuid}/close", ['reason' => 'Supplies provided'])->assertOk();

        // Clearing again is refused: it is already archived.
        $this->emsPost($this->captain, "/ems/api/requests/{$uuid}/close", ['reason' => 'Supplies provided'])->assertStatus(409);

        $this->emsPost($this->captain, "/ems/api/requests/{$uuid}/reopen", ['reason' => 'Restored to Active'])
            ->assertOk()
            ->assertJsonPath('data.status', InventoryRequest::REVIEWED)
            ->assertJsonPath('data.acknowledged', true)
            ->assertJsonPath('data.can_clear', true)
            ->assertJsonPath('data.can_reconcile', true);

        $this->assertTrue($this->rows('active')->pluck('id')->contains($uuid));
        $this->assertFalse($this->rows('archived')->pluck('id')->contains($uuid));
        $model = $this->requestModel($uuid);
        $this->assertNull($model->closed_at);
        $this->assertNull($model->close_reason);
        $reopened = $model->events()->where('action', 'reopened')->first();
        $this->assertNotNull($reopened);
        $this->assertSame('Restored to Active', $reopened->payload['reason']);
        $this->assertSame(['submitted', 'reviewed', 'closed', 'reopened'], $model->events()->pluck('action')->all(), 'Every step is kept in history.');

        // Restoring a form that is not cleared is refused.
        $this->emsPost($this->captain, "/ems/api/requests/{$uuid}/reopen", ['reason' => 'Restored to Active'])->assertStatus(409);
    }

    public function test_only_people_who_act_on_forms_can_clear_or_restore(): void
    {
        $uuid = $this->submitRequest($this->member, [self::TAPE + ['quantity' => 1]])['uuid'];

        $this->emsPost($this->member, "/ems/api/requests/{$uuid}/close", ['reason' => 'Supplies provided'])->assertForbidden();
        $this->assertSame(InventoryRequest::SUBMITTED, $this->requestModel($uuid)->status);

        $this->emsPost($this->captain, "/ems/api/requests/{$uuid}/close", ['reason' => 'Supplies provided'])->assertOk();
        $this->emsPost($this->member, "/ems/api/requests/{$uuid}/reopen", ['reason' => 'Restored to Active'])->assertForbidden();
        $this->assertSame(InventoryRequest::CLOSED, $this->requestModel($uuid)->status);
    }

    public function test_returned_and_reconciled_forms_cannot_be_cleared(): void
    {
        $returned = $this->submitRequest($this->member, [self::TAPE + ['quantity' => 1]])['uuid'];
        $this->emsPost($this->captain, "/ems/api/requests/{$returned}/reject", ['reason' => 'Recount the tape'])->assertOk();
        $this->assertFalse($this->rows('active')->firstWhere('id', $returned)['can_clear']);
        $this->emsPost($this->captain, "/ems/api/requests/{$returned}/close", ['reason' => 'Supplies provided'])
            ->assertStatus(409)->assertJsonPath('error.code', 'request_returned');

        $this->finalizeCount($this->captain, ['sr.t.02' => 30]);
        $reconciled = $this->submitRequest($this->member, [self::TAPE + ['quantity' => 1]])['uuid'];
        $this->emsPost($this->captain, "/ems/api/requests/{$reconciled}/acknowledge")->assertOk();
        $this->emsPost($this->captain, '/ems/api/forms/reconcile', ['request_ids' => [$reconciled], 'expected_last_movement_id' => (int) StockMovement::query()->max('id'), 'accept_low_stock' => true])->assertOk();
        $row = $this->rows('archived')->firstWhere('id', $reconciled);
        $this->assertFalse($row['can_restore']);
        $this->emsPost($this->captain, "/ems/api/requests/{$reconciled}/close", ['reason' => 'Supplies provided'])
            ->assertStatus(409)->assertJsonPath('error.code', 'already_reconciled');
    }
}
