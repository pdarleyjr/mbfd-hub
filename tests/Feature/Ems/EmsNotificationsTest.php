<?php

declare(strict_types=1);

namespace Tests\Feature\Ems;

use App\Models\Ems\InventoryRequestMessage;
use App\Models\User;
use App\Notifications\Inspections\InspectionEventNotification;
use App\Services\Ems\EmsNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Ems\Concerns\BuildsEmsWorld;
use Tests\TestCase;

final class EmsNotificationsTest extends TestCase
{
    use BuildsEmsWorld;
    use RefreshDatabase;

    private User $member;

    private User $seatCaptain;

    private User $emsAdmin;

    private User $viewer;

    private User $otherCaptain;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildEmsWorld();
        $this->member = $this->emsMember('Paramedic', 'Pat Medic');
        $this->seatCaptain = $this->emsCaptain('Seat Captain');
        $this->emsCaptain5Seat($this->seatCaptain);
        $this->emsAdmin = $this->emsAdmin(['admin.ems.manage'], 'Ema Nager');
        $this->viewer = $this->emsAdmin(['admin.ems.view'], 'View Only');
        $this->otherCaptain = $this->emsCaptain('Other Captain');
        Notification::fake();
    }

    protected function tearDown(): void
    {
        $this->tearDownEmsWorld();
        parent::tearDown();
    }

    private function sentTimes(User $user, string $kind, ?string $eventKey = null): int
    {
        return Notification::sent($user, InspectionEventNotification::class)
            ->filter(fn (InspectionEventNotification $n): bool => $n->kind === $kind && ($eventKey === null || $n->eventKey === $eventKey))
            ->count();
    }

    private function ledger(string $eventKey): \Illuminate\Support\Collection
    {
        return DB::table('inspection_notification_deliveries')->where('event_key', $eventKey)->get();
    }

    private function newRequest(?User $by = null): array
    {
        return $this->submitRequest($by ?? $this->member, [['form' => 'als-med-bag', 'item_key' => 'als.trauma.tape', 'quantity' => 2]], ['member_note' => 'Need it today']);
    }

    public function test_a_new_request_notifies_the_on_duty_captain_5_and_ems_admins_once_each_but_nobody_else(): void
    {
        $made = $this->newRequest();
        $id = $this->requestModel($made['uuid'])->id;
        $key = "ems:request:{$id}:submitted";

        $this->assertSame(1, $this->sentTimes($this->seatCaptain, 'ems_request_submitted', $key));
        $this->assertSame(1, $this->sentTimes($this->emsAdmin, 'ems_request_submitted', $key));
        $this->assertSame(0, $this->sentTimes($this->viewer, 'ems_request_submitted'));
        $this->assertSame(0, $this->sentTimes($this->otherCaptain, 'ems_request_submitted'));
        $this->assertSame(0, $this->sentTimes($this->member, 'ems_request_submitted'), 'The submitter is the actor.');

        $rows = $this->ledger($key);
        $this->assertCount(2, $rows);
        $this->assertSame([$this->seatCaptain->id, $this->emsAdmin->id], $rows->pluck('recipient_user_id')->map(fn ($i) => (int) $i)->sort()->values()->all());
        $this->assertSame(['dispatched'], $rows->pluck('status')->unique()->all());
        $this->assertSame('ems_request_submitted', $rows->first()->notification_type);
        $this->assertLessThanOrEqual(150, strlen($key));

        $notification = Notification::sent($this->seatCaptain, InspectionEventNotification::class)->first();
        $this->assertStringContainsString($made['number'], $notification->title);
        $this->assertStringContainsString('Pat Medic', $notification->body);
        $this->assertStringContainsString('Rescue 2', $notification->body);
        $this->assertStringContainsString($made['uuid'], $notification->url);
        $adminNotification = Notification::sent($this->emsAdmin, InspectionEventNotification::class)->first();
        $this->assertStringStartsWith('/admin/ems', $adminNotification->url, 'Admin-only EMS people land on the admin page.');
    }

    public function test_a_retried_submission_and_a_second_run_of_the_notifier_do_not_notify_again(): void
    {
        $payload = [
            'client_submission_id' => (string) \Illuminate\Support\Str::uuid(),
            'target' => ['kind' => 'rescue_unit', 'apparatus_id' => $this->rescue2->id],
            'lines' => [['form' => 'als-med-bag', 'item_key' => 'als.trauma.tape', 'quantity' => 2]],
        ];
        $this->emsPost($this->member, '/employee/forms/api/ems/requests', $payload)->assertCreated();
        $this->emsPost($this->member, '/employee/forms/api/ems/requests', $payload)->assertOk();
        $request = $this->requestModel($this->emsPost($this->member, '/employee/forms/api/ems/requests', $payload)->json('data.id'));

        DB::transaction(fn () => app(EmsNotifier::class)->requestSubmitted($request));

        $this->assertSame(1, $this->sentTimes($this->seatCaptain, 'ems_request_submitted'));
        $this->assertSame(1, $this->sentTimes($this->emsAdmin, 'ems_request_submitted'));
        $this->assertCount(2, $this->ledger("ems:request:{$request->id}:submitted"));
    }

    public function test_the_actor_is_never_notified_even_when_they_are_on_the_staff_list(): void
    {
        $made = $this->newRequest($this->seatCaptain);
        $id = $this->requestModel($made['uuid'])->id;

        $this->assertSame(0, $this->sentTimes($this->seatCaptain, 'ems_request_submitted'));
        $this->assertSame(1, $this->sentTimes($this->emsAdmin, 'ems_request_submitted'));
        $this->assertCount(1, $this->ledger("ems:request:{$id}:submitted"));
    }

    public function test_a_message_notifies_the_other_side_once_per_message(): void
    {
        $made = $this->newRequest();
        $uuid = $made['uuid'];
        $id = $this->requestModel($uuid)->id;

        $staffMessage = $this->emsPost($this->otherCaptain, "/ems/api/requests/{$uuid}/messages", ['body' => 'Which bag is this for?'])->assertCreated();
        $messageKey = 'ems:message:'.$staffMessage->json('data.id');
        $this->assertSame(1, $this->sentTimes($this->member, 'ems_message', $messageKey));
        $this->assertSame(0, $this->sentTimes($this->otherCaptain, 'ems_message'), 'The author is not notified.');
        $this->assertSame(0, $this->sentTimes($this->seatCaptain, 'ems_message'), 'A staff message goes to the member only.');

        $reply = $this->emsPost($this->member, "/employee/forms/api/ems/requests/{$uuid}/messages", ['body' => 'The jump bag'])->assertCreated();
        $replyKey = 'ems:message:'.$reply->json('data.id');
        foreach ([$this->seatCaptain, $this->emsAdmin, $this->otherCaptain] as $staff) {
            $this->assertSame(1, $this->sentTimes($staff, 'ems_message', $replyKey), "{$staff->name} hears the member's reply once");
        }
        $this->assertSame(0, $this->sentTimes($this->member, 'ems_message', $replyKey));
        $this->assertSame(0, $this->sentTimes($this->viewer, 'ems_message'));

        // The same message can never notify the same person twice.
        $message = InventoryRequestMessage::query()->findOrFail($reply->json('data.id'));
        DB::transaction(fn () => app(EmsNotifier::class)->messagePosted($message));
        $this->assertSame(1, $this->sentTimes($this->seatCaptain, 'ems_message', $replyKey));
        $this->assertSame(1, $this->ledger($messageKey)->count());
        $this->assertSame(3, $this->ledger($replyKey)->count());
        $this->assertNotNull($id);
    }

    public function test_the_requester_hears_about_issued_and_closed_but_not_about_preparing_or_reopening(): void
    {
        $made = $this->newRequest();
        $uuid = $made['uuid'];
        $id = $this->requestModel($uuid)->id;
        $line = $this->actingAs($this->otherCaptain)->getJson("/ems/api/requests/{$uuid}")->json('data.lines.0');

        $this->emsPost($this->otherCaptain, "/ems/api/requests/{$uuid}/prepare", ['lines' => [['line_id' => $line['id'], 'prepared_qty' => 2]]])->assertOk();
        $this->assertSame(0, $this->sentTimes($this->member, 'ems_request_update'));

        $this->emsPost($this->otherCaptain, "/ems/api/requests/{$uuid}/issue", ['issuances' => [['line_id' => $line['id'], 'quantity' => 2]]])->assertOk();
        $this->assertSame(1, $this->sentTimes($this->member, 'ems_request_update'));
        $issuedEvent = $this->requestModel($uuid)->events()->where('to_status', 'issued')->sole();
        $key = "ems:request:{$id}:status:{$issuedEvent->id}";
        $this->assertSame(1, $this->sentTimes($this->member, 'ems_request_update', $key));
        $this->assertLessThanOrEqual(150, strlen($key));
        $this->assertStringContainsString('issued', Notification::sent($this->member, InspectionEventNotification::class)->first()->body);

        $this->emsPost($this->otherCaptain, "/ems/api/requests/{$uuid}/close", ['reason' => 'Issued in full'])->assertOk();
        $this->assertSame(2, $this->sentTimes($this->member, 'ems_request_update'));
        $this->emsPost($this->otherCaptain, "/ems/api/requests/{$uuid}/reopen", ['reason' => 'Crew wants more'])->assertOk();
        $this->assertSame(2, $this->sentTimes($this->member, 'ems_request_update'));

        $this->assertSame(0, $this->sentTimes($this->seatCaptain, 'ems_request_update'), 'Status changes go to the requester only.');
        $this->assertSame(0, $this->sentTimes($this->otherCaptain, 'ems_request_update'));
    }

    public function test_a_queued_ledger_row_can_be_redelivered_exactly_for_its_recipient(): void
    {
        $made = $this->newRequest();
        $id = $this->requestModel($made['uuid'])->id;
        $key = "ems:request:{$id}:submitted";
        DB::table('inspection_notification_deliveries')->where('event_key', $key)->update(['status' => 'queued']);

        Notification::fake();
        $sent = app(EmsNotifier::class)->redeliver($key, 'ems_request_submitted', $this->seatCaptain->id);

        $this->assertTrue($sent);
        $this->assertSame(1, $this->sentTimes($this->seatCaptain, 'ems_request_submitted', $key));
        $this->assertSame(0, $this->sentTimes($this->emsAdmin, 'ems_request_submitted'), 'Only the recipient being retried is notified.');
        $this->assertFalse(app(EmsNotifier::class)->redeliver($key, 'ems_request_submitted', $this->viewer->id));
        $this->assertFalse(app(EmsNotifier::class)->redeliver('captain5:inspection:1:findings', 'x', $this->seatCaptain->id));
    }
}
