<?php

declare(strict_types=1);

namespace Tests\Feature\Inspections;

use App\Enums\AccountStatus;
use App\Models\ApparatusDefect;
use App\Models\User;
use App\Notifications\Inspections\InspectionEventNotification;
use App\Services\Defects\DefectCorrectiveActionService;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Defects\Concerns\BuildsContinuityFixtures;
use Tests\TestCase;

final class RetryInspectionNotificationsCommandTest extends TestCase
{
    use BuildsContinuityFixtures;
    use RefreshDatabase;

    private User $reporter;

    private ApparatusDefect $defect;

    protected function setUp(): void
    {
        parent::setUp();
        $rig = $this->rig();
        $this->reporter = $this->member('Riley Reporter');
        $admin = $this->reviewer();
        $inspection = $this->inspectionBy($rig, $this->reporter);
        $this->defect = $this->openDefect($rig, $inspection, $this->reporter);

        // The first send happens normally, then the ledger is rewound as if the process died
        // between commit and dispatch: the row is still `queued`.
        Notification::fake();
        app(DefectCorrectiveActionService::class)->resolve($this->defect->id, $admin, [
            'resolution_type' => 'replacement_installed',
            'resolution_notes' => 'Replaced.',
        ]);
        $this->setLedger(['status' => 'queued', 'attempts' => 0], minutesOld: 11);
        Notification::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param array<string, mixed> $values */
    private function setLedger(array $values, int $minutesOld = 0): void
    {
        DB::table('inspection_notification_deliveries')->update([
            ...$values,
            'updated_at' => now()->subMinutes($minutesOld),
        ]);
    }

    private function ledger(): object
    {
        return DB::table('inspection_notification_deliveries')->sole();
    }

    public function test_a_row_stuck_in_queued_is_sent_once_and_marked_dispatched(): void
    {
        $this->artisan('inspections:retry-notifications')->assertSuccessful();

        Notification::assertSentToTimes($this->reporter, InspectionEventNotification::class, 1);
        Notification::assertSentTo($this->reporter, InspectionEventNotification::class, fn (InspectionEventNotification $n): bool => $n->kind === 'issue_resolved'
            && $n->title === 'Engine 2: previously reported issue resolved');
        $this->assertSame('dispatched', $this->ledger()->status);
        $this->assertSame(1, (int) $this->ledger()->attempts);

        // Idempotent: a second run finds nothing left to do.
        $this->artisan('inspections:retry-notifications')->assertSuccessful();
        Notification::assertSentToTimes($this->reporter, InspectionEventNotification::class, 1);
    }

    public function test_a_row_younger_than_ten_minutes_is_left_alone(): void
    {
        $this->setLedger(['status' => 'queued'], minutesOld: 3);

        $this->artisan('inspections:retry-notifications')->assertSuccessful();

        Notification::assertNothingSent();
        $this->assertSame('queued', $this->ledger()->status);
        $this->assertSame(0, (int) $this->ledger()->attempts);
    }

    public function test_rows_already_dispatched_or_failed_are_never_resent(): void
    {
        foreach (['dispatched', 'failed'] as $status) {
            $this->setLedger(['status' => $status], minutesOld: 60);
            $this->artisan('inspections:retry-notifications')->assertSuccessful();
        }

        Notification::assertNothingSent();
    }

    public function test_an_undeliverable_row_is_tried_at_most_three_times_then_marked_failed(): void
    {
        $this->reporter->forceFill(['account_status' => AccountStatus::Disabled])->save();
        Carbon::setTestNow(now());

        foreach ([1, 2] as $attempt) {
            $this->artisan('inspections:retry-notifications')->assertSuccessful();
            $this->assertSame('queued', $this->ledger()->status);
            $this->assertSame($attempt, (int) $this->ledger()->attempts);
            Carbon::setTestNow(now()->addMinutes(11));
        }

        $this->artisan('inspections:retry-notifications')->assertSuccessful();

        $this->assertSame('failed', $this->ledger()->status);
        $this->assertSame(3, (int) $this->ledger()->attempts);
        Notification::assertNothingSent();

        Carbon::setTestNow(now()->addMinutes(11));
        $this->artisan('inspections:retry-notifications')->assertSuccessful();
        $this->assertSame(3, (int) $this->ledger()->attempts);
    }

    public function test_a_row_that_exhausted_its_attempts_while_still_queued_is_failed_without_sending(): void
    {
        $this->setLedger(['status' => 'queued', 'attempts' => 3], minutesOld: 30);

        $this->artisan('inspections:retry-notifications')->assertSuccessful();

        $this->assertSame('failed', $this->ledger()->status);
        Notification::assertNothingSent();
    }

    public function test_the_command_is_scheduled_every_ten_minutes(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains((string) $event->command, 'inspections:retry-notifications'));

        $this->assertNotNull($event);
        $this->assertSame('*/10 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }
}
