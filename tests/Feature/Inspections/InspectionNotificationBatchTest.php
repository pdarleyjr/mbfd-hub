<?php

declare(strict_types=1);

namespace Tests\Feature\Inspections;

use App\Models\User;
use App\Notifications\Inspections\InspectionEventNotification;
use App\Notifications\Inspections\InspectionNotificationKind as Kind;
use App\Services\Inspections\InspectionNotificationBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\TestCase;

final class InspectionNotificationBatchTest extends TestCase
{
    use RefreshDatabase;

    private InspectionNotificationBatch $batch;

    /** @var list<string> */
    private array $statuses = [];

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->batch = app(InspectionNotificationBatch::class);
    }

    private function hold(User $recipient, string $kind = Kind::ISSUE_RESOLVED, string $key = 'event:1'): void
    {
        $this->batch->hold($recipient, $kind, ['title' => 'Original title', 'body' => 'Original body', 'url' => '/x'], $key, function (string $status): void {
            $this->statuses[] = $status;
        });
    }

    public function test_one_held_notice_goes_out_as_it_was_written(): void
    {
        $member = User::factory()->create(['account_status' => 'active']);

        $this->batch->collapse('E 2', 'A note', fn () => $this->hold($member));

        Notification::assertSentTo($member, InspectionEventNotification::class, static fn (InspectionEventNotification $n): bool => $n->title === 'Original title' && $n->body === 'Original body');
        $this->assertSame(['dispatched'], $this->statuses);
    }

    public function test_several_become_one_summary_per_person_and_per_kind(): void
    {
        $first = User::factory()->create(['account_status' => 'active']);
        $second = User::factory()->create(['account_status' => 'active']);

        $this->batch->collapse('E 2', 'Parts are on order', function () use ($first, $second): void {
            $this->hold($first, Kind::ISSUE_MEMBER_UPDATE, 'event:1');
            $this->hold($first, Kind::ISSUE_MEMBER_UPDATE, 'event:2');
            $this->hold($first, Kind::ISSUE_MEMBER_UPDATE, 'event:3');
            $this->hold($second, Kind::ISSUE_MEMBER_UPDATE, 'event:1');
        });

        Notification::assertSentToTimes($first, InspectionEventNotification::class, 1);
        Notification::assertSentTo($first, InspectionEventNotification::class, static fn (InspectionEventNotification $n): bool => $n->title === 'E 2: 3 reported issues updated'
            && str_contains($n->body, 'Parts are on order'));
        Notification::assertSentTo($second, InspectionEventNotification::class, static fn (InspectionEventNotification $n): bool => $n->title === 'Original title');
        $this->assertSame(['dispatched', 'dispatched', 'dispatched', 'dispatched'], $this->statuses);
    }

    public function test_what_was_held_still_goes_out_if_the_work_throws_and_holding_ends(): void
    {
        $member = User::factory()->create(['account_status' => 'active']);

        try {
            $this->batch->collapse('E 2', null, function () use ($member): void {
                $this->hold($member);

                throw new RuntimeException('stopped partway');
            });
            $this->fail('The exception should pass through.');
        } catch (RuntimeException $exception) {
            $this->assertSame('stopped partway', $exception->getMessage());
        }

        Notification::assertSentToTimes($member, InspectionEventNotification::class, 1);
        $this->assertFalse($this->batch->isHolding());
    }

    public function test_a_nested_call_joins_the_outer_one(): void
    {
        $member = User::factory()->create(['account_status' => 'active']);

        $this->batch->collapse('E 2', null, function () use ($member): void {
            $this->hold($member, Kind::ISSUE_RESOLVED, 'event:1');
            $this->batch->collapse('E 2', null, fn () => $this->hold($member, Kind::ISSUE_RESOLVED, 'event:2'));
            Notification::assertNothingSent();
            $this->assertTrue($this->batch->isHolding());
        });

        Notification::assertSentToTimes($member, InspectionEventNotification::class, 1);
    }
}
