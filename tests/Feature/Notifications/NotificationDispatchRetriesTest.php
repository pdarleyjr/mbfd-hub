<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Jobs\FanOutNotification;
use App\Models\Employee;
use App\Models\NotificationDispatchRetry;
use App\Models\User;
use App\Notifications\RosterMoveNotification;
use App\Services\Notifications\NotificationCategoryRegistry;
use App\Services\Notifications\NotificationDispatchRetries;
use App\Services\Notifications\NotificationDispatchService;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/** An alert raised while the queue is down arrives when it comes back, instead of being lost. */
final class NotificationDispatchRetriesTest extends TestCase
{
    use RefreshDatabase;

    private const CATEGORY = NotificationCategoryRegistry::ROSTER_MOVES;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Exceptions::fake();
    }

    public function test_an_alert_the_queue_refuses_is_kept_and_arrives_once_it_is_back(): void
    {
        $member = $this->member();

        $this->queueIsDown();
        $this->dispatcher()->dispatch(self::CATEGORY, $this->alert(), [$member], 'event-1');

        $this->assertSame(1, NotificationDispatchRetry::query()->count());
        Notification::assertNothingSent();
        Exceptions::assertReported(RuntimeException::class);

        $this->queueIsBack();
        $this->artisan('notifications:retry-dispatch')->assertSuccessful();

        Notification::assertSentTo($member, RosterMoveNotification::class);
        $this->assertSame(0, NotificationDispatchRetry::query()->count());
    }

    public function test_it_keeps_trying_while_the_queue_stays_down(): void
    {
        $this->queueIsDown();
        $this->dispatcher()->dispatch(self::CATEGORY, $this->alert(), [$this->member()], 'event-1');

        $this->travel(2)->minutes();
        $this->artisan('notifications:retry-dispatch')->assertSuccessful();

        $row = NotificationDispatchRetry::query()->sole();
        $this->assertSame(1, $row->attempts);
        $this->assertTrue($row->available_at->isFuture());
    }

    public function test_a_normal_dispatch_leaves_nothing_behind(): void
    {
        $member = $this->member();

        $this->dispatcher()->dispatch(self::CATEGORY, $this->alert(), [$member], 'event-1');

        $this->assertSame(0, NotificationDispatchRetry::query()->count());
        Notification::assertSentTo($member, RosterMoveNotification::class);
    }

    public function test_write_ahead_is_recorded_inside_the_transaction_and_cleared_after_the_hand_over(): void
    {
        $member = $this->member();

        DB::transaction(function () use ($member): void {
            $this->dispatcher()->dispatch(self::CATEGORY, $this->alert(), [$member], 'event-1', writeAhead: true);
            $this->assertSame(1, NotificationDispatchRetry::query()->count());
        });

        $this->assertSame(0, NotificationDispatchRetry::query()->count());
        Notification::assertSentTo($member, RosterMoveNotification::class);
    }

    public function test_write_ahead_rolls_back_with_the_write_it_belongs_to(): void
    {
        try {
            DB::transaction(function (): void {
                $this->dispatcher()->dispatch(self::CATEGORY, $this->alert(), [$this->member()], 'event-1', writeAhead: true);

                throw new RuntimeException('the write failed');
            });
        } catch (RuntimeException) {
            // Expected.
        }

        $this->assertSame(0, NotificationDispatchRetry::query()->count());
        Notification::assertNothingSent();
    }

    public function test_write_ahead_survives_a_process_that_died_before_the_hand_over(): void
    {
        $member = $this->member();
        // What is left when the process dies right after the commit: a row and no queued job.
        app(NotificationDispatchRetries::class)->hold(new FanOutNotification(self::CATEGORY, $this->alert(), [(int) $member->id], 'event-1'), NotificationDispatchRetries::WRITE_AHEAD_GRACE_SECONDS);

        // Not yet: the normal hand-over may still be happening.
        $this->artisan('notifications:retry-dispatch')->assertSuccessful();
        Notification::assertNothingSent();

        $this->travel(3)->minutes();
        $this->artisan('notifications:retry-dispatch')->assertSuccessful();

        Notification::assertSentTo($member, RosterMoveNotification::class);
    }

    public function test_offering_an_alert_twice_tells_the_member_once(): void
    {
        $member = $this->member();

        $this->dispatcher()->dispatch(self::CATEGORY, $this->alert(), [$member], 'event-1');
        app(NotificationDispatchRetries::class)->hold(new FanOutNotification(self::CATEGORY, $this->alert(), [(int) $member->id], 'event-1'));
        $this->artisan('notifications:retry-dispatch')->assertSuccessful();

        Notification::assertSentToTimes($member, RosterMoveNotification::class, 1);
    }

    public function test_an_alert_older_than_a_day_is_reported_and_dropped(): void
    {
        $member = $this->member();
        app(NotificationDispatchRetries::class)->hold(new FanOutNotification(self::CATEGORY, $this->alert(), [(int) $member->id], 'event-1'));

        $this->travel(25)->hours();
        $this->artisan('notifications:retry-dispatch')->assertSuccessful();

        $this->assertSame(0, NotificationDispatchRetry::query()->count());
        Notification::assertNothingSent();
        Exceptions::assertReported(RuntimeException::class);
    }

    public function test_a_row_that_cannot_be_read_back_is_dropped_and_does_not_block_the_rest(): void
    {
        $member = $this->member();
        NotificationDispatchRetry::query()->create(['payload' => 'not a job', 'attempts' => 0, 'available_at' => now()]);
        app(NotificationDispatchRetries::class)->hold(new FanOutNotification(self::CATEGORY, $this->alert(), [(int) $member->id], 'event-1'));

        $this->artisan('notifications:retry-dispatch')->assertSuccessful();

        $this->assertSame(0, NotificationDispatchRetry::query()->count());
        Notification::assertSentTo($member, RosterMoveNotification::class);
    }

    public function test_the_stored_alert_is_encrypted(): void
    {
        app(NotificationDispatchRetries::class)->hold(new FanOutNotification(self::CATEGORY, $this->alert(), [1], 'event-1'));

        $this->assertStringNotContainsString('Roster move', (string) DB::table('notification_dispatch_retries')->value('payload'));
    }

    public function test_a_roster_alert_delayed_past_the_end_of_the_shift_is_not_delivered(): void
    {
        $member = $this->member();
        $alert = new RosterMoveNotification('Roster move: Rescue 3', 'Was: x. Now: y.', 'event-1', now()->addHour()->getTimestamp());

        $this->assertNotSame([], $alert->via($member));
        $this->assertTrue($alert->shouldSend($member, 'database'));

        $this->travel(2)->hours();

        $this->assertSame([], $alert->via($member));
        $this->assertFalse($alert->shouldSend($member, 'database'));
    }

    private function dispatcher(): NotificationDispatchService
    {
        return app(NotificationDispatchService::class);
    }

    private function alert(): RosterMoveNotification
    {
        return new RosterMoveNotification('Roster move: Rescue 3', 'Was: Engine 1. Now: Rescue 3.', 'event-1');
    }

    private function member(): User
    {
        $employee = Employee::query()->create(['employee_id' => '90001', 'name' => 'Member', 'rank' => 'Firefighter', 'roster_status' => 'active']);

        return User::factory()->create(['account_status' => 'active', 'employee_id' => '90001', 'employee_profile_id' => $employee->id]);
    }

    private function queueIsDown(): void
    {
        $real = app(Dispatcher::class);
        $this->app->instance('real-dispatcher', $real);
        $down = Mockery::mock(Dispatcher::class);
        $down->shouldReceive('dispatch')->andThrow(new RuntimeException('Connection refused'));
        $this->app->instance(Dispatcher::class, $down);
    }

    private function queueIsBack(): void
    {
        $this->app->instance(Dispatcher::class, $this->app->make('real-dispatcher'));
    }
}
