<?php

declare(strict_types=1);

namespace Tests\Feature\Ems;

use App\Models\Captain5\Captain5AccessOverride;
use App\Models\User;
use App\Notifications\Inspections\InspectionEventNotification;
use App\Services\Ems\EmsAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Feature\Ems\Concerns\BuildsEmsWorld;
use Tests\TestCase;

/** Review fixes: notification access, deny overrides, throttling and message caps, input validation, error envelope, CSRF. */
final class EmsSecurityHardeningTest extends TestCase
{
    use BuildsEmsWorld;
    use RefreshDatabase;

    private User $member;

    private User $staff;

    private string $uuid;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildEmsWorld();
        $this->member = $this->emsMember('Paramedic', 'Pat Medic');
        $this->staff = $this->emsCaptain('Cap Tain');
        $this->uuid = $this->submitRequest($this->member, [['form' => 'als-med-bag', 'item_key' => 'als.trauma.tape', 'quantity' => 2]])['uuid'];
    }

    protected function tearDown(): void
    {
        $this->tearDownEmsWorld();
        parent::tearDown();
    }

    private function sentTo(User $user, string $kind): int
    {
        return Notification::sent($user, InspectionEventNotification::class)->filter(fn ($n): bool => $n->kind === $kind)->count();
    }

    private function memberMessage(string $body): \Illuminate\Testing\TestResponse
    {
        return $this->emsPost($this->member, "/employee/forms/api/ems/requests/{$this->uuid}/messages", ['body' => $body]);
    }

    // ------------------------------------------------------- notification access

    public function test_people_who_can_no_longer_open_ems_stop_receiving_staff_notifications(): void
    {
        $leaver = $this->emsCaptain('Left Captain');
        $this->emsPost($leaver, "/ems/api/requests/{$this->uuid}/review")->assertOk();      // acted on the request
        $manager = $this->emsAdmin(['admin.ems.manage'], 'Ema Nager');
        $revoked = $this->emsAdmin(['admin.ems.manage'], 'Revoked Manager');
        $revoked->revokePermissionTo('admin.ems.manage');
        $locked = $this->emsAdmin(['admin.ems.manage'], 'Locked Manager');
        $locked->forceFill(['must_change_password' => true])->save();
        $leaver->forceFill(['must_change_password' => true])->save(); // no longer able to use EMS
        $this->forgetEmsMemo();
        Notification::fake();

        $this->memberMessage('Is it ready?')->assertCreated();
        $this->submitRequest($this->member, [['form' => 'airway-bag', 'item_key' => 'airway.top.bvm', 'quantity' => 1]]);

        $this->assertSame(1, $this->sentTo($manager, 'ems_message'));
        $this->assertSame(1, $this->sentTo($manager, 'ems_request_submitted'));
        foreach ([$leaver, $revoked, $locked] as $gone) {
            $this->assertSame(0, $this->sentTo($gone, 'ems_message'), "{$gone->name} should not hear the reply");
            $this->assertSame(0, $this->sentTo($gone, 'ems_request_submitted'), "{$gone->name} should not hear about new requests");
        }
    }

    public function test_a_captain5_deny_override_ends_ems_access_even_with_an_admin_grant(): void
    {
        $manager = $this->emsAdmin(['admin.ems.manage'], 'Ema Nager');
        $this->actingAs($manager)->getJson('/ems/api/requests')->assertOk();

        Captain5AccessOverride::query()->create([
            'employee_profile_id' => $manager->employee_profile_id, 'effect' => 'deny', 'reason' => 'Under review', 'set_by_user_id' => $this->staff->id,
        ]);
        $this->forgetEmsMemo();

        $access = app(EmsAccess::class);
        $this->assertFalse($access->canView($manager->fresh()));
        $this->assertFalse($access->canAct($manager->fresh()));
        $this->assertFalse($access->canConfigure($manager->fresh()));
        $this->actingAs($manager->fresh())->getJson('/ems/api/requests')->assertForbidden();

        $super = $this->emsSuperAdmin();
        $this->assertTrue($access->canAct($super));
    }

    // ------------------------------------------------------- throttles and caps

    public function test_the_routes_use_named_throttle_buckets_and_there_is_no_daily_cap(): void
    {
        $buckets = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($r): bool => str_starts_with($r->uri(), 'ems/api') || str_starts_with($r->uri(), 'employee/forms/api/ems'))
            ->flatMap(fn ($r) => collect($r->gatherMiddleware())->filter(fn ($m): bool => is_string($m) && str_starts_with($m, 'throttle:')))
            ->unique()->values()->all();

        foreach (['ems-member', 'ems-submit', 'ems-message', 'ems-cancel', 'ems-staff-read', 'ems-staff-write', 'ems-staff-config'] as $name) {
            $this->assertTrue(collect($buckets)->contains(fn (string $m): bool => str_ends_with($m, ','.$name)), "bucket {$name}");
        }
        $this->assertSame([], collect($buckets)->filter(fn (string $m): bool => preg_match('/^throttle:\d+,1$/', $m) === 1)->values()->all(), 'No unnamed throttle buckets.');
        $this->assertSame([], collect($buckets)->filter(fn (string $m): bool => preg_match('/^throttle:\d+,(\d{2,}|[2-9])/', $m) === 1)->values()->all(), 'Only per-minute limits; no daily quota.');
    }

    public function test_a_member_can_send_five_messages_in_a_row_then_waits_for_captain_5(): void
    {
        foreach (range(1, 5) as $i) {
            $this->memberMessage("Question {$i}")->assertCreated();
        }
        $this->memberMessage('Question 6')->assertStatus(429)->assertJsonPath('error.code', 'awaiting_reply')
            ->assertJsonPath('error.message', 'Captain 5 has not answered yet. Your messages are saved; you can send more after they reply.');
        $this->assertSame(5, $this->requestModel($this->uuid)->messages()->count());

        $this->emsPost($this->staff, "/ems/api/requests/{$this->uuid}/messages", ['body' => 'On it'])->assertCreated();
        $this->memberMessage('Thanks')->assertCreated();
        $this->assertSame(7, $this->requestModel($this->uuid)->messages()->count());

        // Another request has its own count, and there is no daily limit across requests.
        $other = $this->submitRequest($this->member, [['form' => 'airway-bag', 'item_key' => 'airway.top.bvm', 'quantity' => 1]])['uuid'];
        $this->emsPost($this->member, "/employee/forms/api/ems/requests/{$other}/messages", ['body' => 'Hello'])->assertCreated();
    }

    public function test_message_notifications_are_coalesced_to_one_unread_per_request_and_person(): void
    {
        $manager = $this->emsAdmin(['admin.ems.manage'], 'Ema Nager');
        $this->forgetEmsMemo();
        Notification::fake();

        $this->memberMessage('First')->assertCreated();
        $this->assertSame(1, $this->sentTo($manager, 'ems_message'));

        // Make the first one a real unread bell entry for that person.
        $notification = Notification::sent($manager, InspectionEventNotification::class)->first(fn ($n): bool => $n->kind === 'ems_message');
        DatabaseNotification::query()->create([
            'id' => (string) Str::uuid(), 'type' => InspectionEventNotification::class, 'notifiable_type' => User::class, 'notifiable_id' => $manager->id,
            'data' => ['title' => $notification->title, 'body' => $notification->body, 'actions' => [['url' => $notification->url]], 'inspection_event' => ['kind' => 'ems_message', 'event_key' => $notification->eventKey]],
        ]);

        $this->memberMessage('Second')->assertCreated();
        $this->memberMessage('Third')->assertCreated();
        $this->assertSame(1, $this->sentTo($manager, 'ems_message'), 'Still one unread message notice for this request.');

        DatabaseNotification::query()->update(['read_at' => now()]);
        $this->memberMessage('Fourth')->assertCreated();
        $this->assertSame(2, $this->sentTo($manager, 'ems_message'), 'Once read, the next message notifies again.');

        $this->assertDatabaseCount('ems_inventory_request_messages', 4);
    }

    // ------------------------------------------------------- member payload

    public function test_members_only_see_reasons_for_not_issued_closed_and_cancelled(): void
    {
        $line = $this->actingAs($this->staff)->getJson("/ems/api/requests/{$this->uuid}")->json('data.lines.0');
        $this->emsPost($this->staff, "/ems/api/requests/{$this->uuid}/issue", ['issuances' => [['line_id' => $line['id'], 'quantity' => 5, 'reason' => 'Internal: crew is short']]])->assertOk();
        $event = $this->requestModel($this->uuid)->events()->where('action', 'issued')->sole();
        $this->emsPost($this->staff, "/ems/api/requests/{$this->uuid}/correct", ['event_id' => $event->id, 'new_quantity' => 3, 'reason' => 'Internal: miscount'])->assertOk();
        $this->emsPost($this->staff, "/ems/api/requests/{$this->uuid}/close", ['reason' => 'Issued in full'])->assertOk();

        $history = collect($this->actingAs($this->member)->getJson("/employee/forms/api/ems/requests/{$this->uuid}")->json('data.history'))->keyBy('action');
        $this->assertArrayNotHasKey('reason', $history['issued']['payload']);
        $this->assertArrayNotHasKey('reason', $history['issue_corrected']['payload']);
        $this->assertSame('Issued in full', $history['closed']['payload']['reason']);
    }

    // ------------------------------------------------------- input validation and errors

    public function test_array_and_over_long_inputs_are_refused_in_plain_language(): void
    {
        $this->actingAs($this->staff);
        foreach ([
            '/ems/api/requests?q[]=x', '/ems/api/requests?status[]=submitted', '/ems/api/requests?date_from=2026-13-45', '/ems/api/requests?per_page=500',
            '/ems/api/requests?q='.str_repeat('a', 101), '/ems/api/supply/items?q[]=a', '/ems/api/supply/items?per_page=abc', '/ems/api/supply/items?below_threshold=maybe',
            '/ems/api/supply/movements?type=stolen', '/ems/api/supply/orders?status[]=x', '/ems/api/supply/snapshots?kind=nope', '/ems/api/statistics?date_to[]=2026-01-01',
        ] as $uri) {
            $response = $this->getJson($uri);
            $this->assertSame(422, $response->status(), $uri);
            $response->assertJsonPath('ok', false)->assertJsonPath('error.code', 'validation_failed');
            $this->assertNotSame('', $response->json('error.message'));
        }

        $this->emsPost($this->staff, "/ems/api/requests/{$this->uuid}/messages", ['body' => ['a']])->assertStatus(422);
        $this->emsPost($this->staff, '/ems/api/supply/counts', ['location_code' => ['A2']])->assertStatus(422);
        $this->emsPost($this->member, "/employee/forms/api/ems/requests/{$this->uuid}/cancel", ['reason' => ['x']])->assertStatus(422);
        $this->emsPost($this->member, "/employee/forms/api/ems/requests/{$this->uuid}/cancel", ['reason' => str_repeat('r', 256)])->assertStatus(422);
        $this->emsPost($this->member, '/employee/forms/api/ems/requests', [
            'client_submission_id' => (string) Str::uuid(), 'target' => ['kind' => 'rescue_unit', 'apparatus_id' => $this->rescue2->id],
            'lines' => [['form' => 'airway-bag', 'item_key' => 'airway.top.bvm', 'quantity' => [1]]],
        ])->assertStatus(422);
        $this->actingAs($this->member)->getJson('/employee/forms/api/ems/requests?per_page=999')->assertStatus(422);

        $this->actingAs($this->staff)->getJson('/ems/api/requests?q=pat&status=submitted&unit='.$this->rescue2->id.'&date_from=2020-01-01')->assertOk();
        $this->actingAs($this->staff)->getJson('/ems/api/requests?unit=detail')->assertOk();
    }

    public function test_an_unexpected_failure_returns_the_standard_envelope_without_leaking_details(): void
    {
        // A database failure inside a handler (here: the locations table is gone).
        \Illuminate\Support\Facades\DB::statement('ALTER TABLE ems_storage_locations RENAME TO ems_storage_locations_gone');

        $response = $this->actingAs($this->staff)->getJson('/ems/api/supply/locations');

        $response->assertStatus(500)->assertJsonPath('ok', false)->assertJsonPath('error.code', 'server_error');
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
        $this->assertStringNotContainsString('ems_storage_locations', $response->getContent());
        $this->assertStringContainsString('nothing was saved', $response->json('error.message'));
    }

    // ------------------------------------------------------- CSRF

    public function test_a_session_write_without_a_csrf_token_gets_419(): void
    {
        $this->actingAs($this->staff);
        $this->app->instance('env', 'production'); // CSRF is only skipped under the test environment
        try {
            $this->postJson("/ems/api/requests/{$this->uuid}/review", [], ['Idempotency-Key' => 'csrf-test-0001'])->assertStatus(419);
            $this->postJson('/employee/forms/api/ems/requests', [], ['Idempotency-Key' => 'csrf-test-0002'])->assertStatus(419);
        } finally {
            $this->app->instance('env', 'testing');
        }
        $this->assertSame('submitted', $this->requestModel($this->uuid)->status);
    }

    // ------------------------------------------------------- Files copy

    public function test_the_files_copy_is_not_requeued_for_a_member_without_a_personnel_profile(): void
    {
        $document = \App\Models\OperationalFormDocument::query()->sole();
        \Illuminate\Support\Facades\Storage::disk($document->storage_disk)->delete($document->storage_path);
        \Illuminate\Support\Facades\Queue::fake();
        \Illuminate\Support\Facades\DB::table('ems_inventory_requests')->update(['submitted_by_employee_id' => null]);

        $this->actingAs($this->member)->getJson("/employee/forms/api/ems/requests/{$this->uuid}")->assertOk();

        \Illuminate\Support\Facades\Queue::assertNothingPushed();
    }
}
