<?php

declare(strict_types=1);

namespace Tests\Feature\Ems;

use App\Models\User;
use App\Notifications\Inspections\InspectionEventNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Ems\Concerns\BuildsEmsWorld;
use Tests\TestCase;

/** `inspections:retry-notifications` hands `ems:` ledger rows to EmsNotifier, not to the inspection dispatcher. */
final class EmsRetryNotificationsTest extends TestCase
{
    use BuildsEmsWorld;
    use RefreshDatabase;

    private User $seatCaptain;

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildEmsWorld();
        $this->seatCaptain = $this->emsCaptain('Seat Captain');
        $this->emsCaptain5Seat($this->seatCaptain);
        Notification::fake();
        $made = $this->submitRequest($this->emsMember('Paramedic', 'Pat Medic'), [['form' => 'als-med-bag', 'item_key' => 'als.trauma.tape', 'quantity' => 2]]);
        $this->key = 'ems:request:'.$this->requestModel($made['uuid'])->id.':submitted';

        // The process died between commit and dispatch: the row is still queued and old.
        DB::table('inspection_notification_deliveries')->where('event_key', $this->key)
            ->update(['status' => 'queued', 'attempts' => 0, 'updated_at' => now()->subMinutes(11)]);
        Notification::fake();
    }

    protected function tearDown(): void
    {
        $this->tearDownEmsWorld();
        parent::tearDown();
    }

    public function test_a_stuck_ems_row_is_resent_once_through_the_ems_notifier(): void
    {
        $this->artisan('inspections:retry-notifications')->assertSuccessful();

        $sent = Notification::sent($this->seatCaptain, InspectionEventNotification::class)
            ->filter(fn (InspectionEventNotification $n): bool => $n->eventKey === $this->key && $n->kind === 'ems_request_submitted');
        $this->assertCount(1, $sent);
        $this->assertSame(['dispatched'], DB::table('inspection_notification_deliveries')->where('event_key', $this->key)->where('recipient_user_id', $this->seatCaptain->id)->pluck('status')->all());

        // Idempotent: a second run finds nothing left to do for that row.
        $this->artisan('inspections:retry-notifications')->assertSuccessful();
        $this->assertCount(1, Notification::sent($this->seatCaptain, InspectionEventNotification::class)->filter(fn (InspectionEventNotification $n): bool => $n->eventKey === $this->key));
    }

    public function test_an_ems_row_is_not_sent_to_a_recipient_who_lost_access(): void
    {
        DB::table('inspection_notification_deliveries')->where('event_key', $this->key)->update(['status' => 'queued', 'attempts' => 0, 'updated_at' => now()->subMinutes(11)]);
        $this->seatCaptain->forceFill(['account_status' => \App\Enums\AccountStatus::Disabled])->save();
        $this->forgetEmsMemo();

        $this->artisan('inspections:retry-notifications')->assertSuccessful();

        Notification::assertNotSentTo($this->seatCaptain, InspectionEventNotification::class);
    }
}
