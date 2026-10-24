<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\Employee;
use App\Models\User;
use App\Notifications\Inspections\InspectionEventNotification;
use App\Notifications\Inspections\InspectionNotificationCategory;
use App\Notifications\Inspections\InspectionNotificationKind;
use App\Services\Captain5\Captain5Notifier;
use App\Services\Defects\DefectCorrectiveActionService;
use App\Services\Inspections\InspectionNotificationDispatcher;
use App\Services\Notifications\NotificationCategoryRegistry;
use App\Services\Security\Captain5Access;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Defects\Concerns\BuildsContinuityFixtures;
use Tests\TestCase;

/**
 * Inspection and Captain 5 notifications obey the one evaluator: the category of every kind comes
 * from one table, access is re-checked when the worker delivers, and the dispatcher ledger and the
 * retry command keep working.
 */
final class InspectionAndCaptain5RoutingTest extends TestCase
{
    use BuildsContinuityFixtures;
    use RefreshDatabase;

    /** A member whose rank makes them an automatic Captain 5 officer. */
    private function officer(string $rank = 'Lieutenant', string $name = 'Olive Officer'): User
    {
        $user = $this->member($name);
        Employee::query()->whereKey($user->employee_profile_id)->update(['rank' => $rank]);
        app(Captain5Access::class)->flush();

        return $user->fresh();
    }

    private function demote(User $user): void
    {
        Employee::query()->whereKey($user->employee_profile_id)->update(['rank' => 'Firefighter']);
        app(Captain5Access::class)->flush();
    }

    /** Run every queued per-channel notification job exactly as the worker would. */
    private function runQueuedNotifications(): int
    {
        $jobs = Queue::pushed(SendQueuedNotifications::class);
        $jobs->each(fn (SendQueuedNotifications $job) => $job->handle(app(ChannelManager::class)));

        return $jobs->count();
    }

    private function captain5Alert(string $kind = Captain5Notifier::KIND_NEW_FINDINGS, string $eventKey = 'captain5:inspection:1:findings'): InspectionEventNotification
    {
        return new InspectionEventNotification($kind, 'E2: new EMS equipment issue', 'A member reported a missing item.', '/daily/captain-5?issue=1', $eventKey);
    }

    public function test_every_known_kind_maps_to_a_registry_category_from_the_one_table(): void
    {
        $registry = app(NotificationCategoryRegistry::class);
        $table = InspectionNotificationKind::categoryTable();
        $captain5Kinds = [
            Captain5Notifier::KIND_NEW_FINDINGS, Captain5Notifier::KIND_ESCALATED, Captain5Notifier::KIND_ISSUE_UPDATE,
            Captain5Notifier::KIND_ISSUE_RESOLVED, Captain5Notifier::KIND_MESSAGE, Captain5Notifier::KIND_MEMBER_REPLY,
            Captain5Notifier::KIND_COMMUNICATION,
        ];

        foreach ([...InspectionNotificationKind::all(), ...$captain5Kinds] as $kind) {
            $this->assertArrayHasKey($kind, $table, "{$kind} needs a line in the category table");
            $this->assertNotNull($registry->get($table[$kind]), "{$kind} maps to an unknown category");
        }
        foreach ($captain5Kinds as $kind) {
            $this->assertSame('captain5_ems', $table[$kind], $kind);
        }
        $this->assertSame('vehicle_inspections', $table[InspectionNotificationKind::REVIEW_REQUIRED]);
        $this->assertSame('member_request_updates', $table[InspectionNotificationKind::INSPECTION_DECIDED]);
        $this->assertSame('captain5_assignment_requests', $table['captain5_acting_request']);
        $this->assertSame('member_request_updates', $table['captain5_acting_decision']);
    }

    public function test_a_captain_5_alert_is_not_delivered_once_the_officer_loses_access(): void
    {
        Queue::fake();
        $stays = $this->officer(name: 'Stays Officer');
        $loses = $this->officer(name: 'Loses Officer');

        $stays->notify($this->captain5Alert());
        $loses->notify($this->captain5Alert());
        $this->demote($loses);
        $this->assertSame(2, $this->runQueuedNotifications(), 'both were queued while eligible');

        $this->assertSame(1, $stays->notifications()->count());
        $this->assertSame(0, $loses->notifications()->count(), 'the worker re-checked Captain 5 access and delivered nothing');
    }

    public function test_a_member_never_hears_captain_5_operational_alerts(): void
    {
        Queue::fake();
        $firefighter = $this->member('Fred Firefighter');

        $firefighter->notify($this->captain5Alert());
        $this->runQueuedNotifications();

        $this->assertSame(0, $firefighter->notifications()->count());
    }

    public function test_a_members_own_captain_5_update_stays_a_member_update_but_an_officer_who_lost_access_gets_nothing(): void
    {
        Queue::fake();
        $rig = $this->rig();
        $reporter = $this->officer(name: 'Riley Reporter');
        $inspection = $this->inspectionBy($rig, $reporter);
        $defect = $this->openDefect($rig, $inspection, $reporter);
        $working = $this->officer(name: 'Wendy Working');
        $this->participant($defect, $working, 'captain5', $inspection);
        $eventKey = 'captain5:defect:'.$defect->id.':update:1';

        $this->assertSame('member_request_updates', InspectionNotificationCategory::for(Captain5Notifier::KIND_ISSUE_UPDATE, $reporter, $eventKey), 'the reporter owns it');
        $this->assertSame('captain5_ems', InspectionNotificationCategory::for(Captain5Notifier::KIND_ISSUE_UPDATE, $working, $eventKey), 'an officer working it');

        $reporter->notify($this->captain5Alert(Captain5Notifier::KIND_ISSUE_UPDATE, $eventKey));
        $working->notify($this->captain5Alert(Captain5Notifier::KIND_ISSUE_UPDATE, $eventKey));
        $this->demote($reporter);
        $this->demote($working);
        $this->runQueuedNotifications();

        $this->assertSame(1, $reporter->notifications()->count(), 'their own report is still theirs after a demotion');
        $this->assertSame(0, $working->notifications()->count(), 'the officer lost Captain 5 access');
    }

    public function test_acting_requests_reach_only_people_who_configure_captain_5(): void
    {
        Queue::fake();
        // The approvers Captain5Notifier::actingRequested() asks: Admin Panel users with the Captain 5
        // admin capability (or super_admin). Rank alone no longer decides.
        $chief = $this->officer('Division Chief', 'Dana Chief');
        foreach (['admin.access', \App\Services\Security\Captain5Access::PERMISSION_ADMIN] as $permission) {
            \Spatie\Permission\Models\Permission::findOrCreate($permission, 'web');
            $chief->givePermissionTo($permission);
        }
        $captain = $this->officer('Captain', 'Cal Captain');
        $firefighter = $this->member('Fred Firefighter');
        $request = new InspectionEventNotification('captain5_acting_request', 'Acting Captain 5 request', 'A member asked to act.', '/admin/captain-5', 'captain5:acting:1:request');

        foreach ([$chief, $captain, $firefighter] as $recipient) {
            $recipient->notify($request);
        }
        $this->runQueuedNotifications();

        $this->assertSame(1, $chief->notifications()->count());
        $this->assertSame(0, $captain->notifications()->count(), 'viewing Captain 5 is not enough to approve duty');
        $this->assertSame(0, $firefighter->notifications()->count());
    }

    public function test_the_requesting_member_hears_the_decision_as_an_update_about_their_own_request(): void
    {
        Queue::fake();
        $member = $this->member('Fred Firefighter');

        $member->notify(new InspectionEventNotification('captain5_acting_decision', 'Your request was approved', 'You are acting Captain 5.', '/daily/captain-5', 'captain5:acting:1:decision'));
        $this->runQueuedNotifications();

        $this->assertSame(1, $member->notifications()->count());
        $this->assertSame('member_request_updates', $member->notifications()->sole()->data['provenance']['category']);
    }

    public function test_an_unknown_kind_still_reaches_a_sender_chosen_recipient_but_never_an_inactive_account(): void
    {
        Queue::fake();
        Log::spy();
        $kind = 'future_kind_'.uniqid();
        $chosen = $this->member('Chosen Member');
        $inactive = $this->member('Former Member', active: false);

        foreach ([$chosen, $inactive] as $recipient) {
            $recipient->notify(new InspectionEventNotification($kind, 'Something changed', 'A new kind of update.', '/employee', 'future:1:event'));
        }
        $this->runQueuedNotifications();

        $this->assertSame(1, $chosen->notifications()->count(), 'the sender chose this person, so the legacy member category applies');
        $this->assertSame(0, $inactive->notifications()->count());
        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => ($context['kind'] ?? null) === $kind);
    }

    public function test_push_tags_are_per_event_and_the_same_for_every_device_of_one_event(): void
    {
        $user = $this->member('Pat Member');
        $other = $this->member('Other Member');
        $kind = InspectionNotificationKind::ISSUE_RESOLVED;
        $first = new InspectionEventNotification($kind, 'Resolved', 'Fixed.', '/employee', 'defect:1:resolved:10');
        $second = new InspectionEventNotification($kind, 'Resolved', 'Fixed.', '/employee', 'defect:2:resolved:11');

        $this->assertNotSame($first->pushTag(), $second->pushTag(), 'two events of one kind never replace each other');
        $this->assertSame($first->pushTag(), $first->toWebPush($user, null)->toArray()['tag']);
        $this->assertSame($first->toWebPush($user, null)->toArray()['tag'], $first->toWebPush($other, null)->toArray()['tag'], 'every device of one event collapses to one entry');
        $this->assertSame('mbfd-inspection-'.$kind, (new InspectionEventNotification($kind, 'x', 'y', '/employee'))->pushTag());
    }

    public function test_a_retry_replays_the_ledger_row_with_its_original_event_key(): void
    {
        $rig = $this->rig();
        $reporter = $this->member('Riley Reporter');
        $admin = $this->reviewer();
        $inspection = $this->inspectionBy($rig, $reporter);
        $defect = $this->openDefect($rig, $inspection, $reporter);
        Notification::fake();
        app(DefectCorrectiveActionService::class)->resolve($defect->id, $admin, ['resolution_type' => 'replacement_installed', 'resolution_notes' => 'Replaced.']);
        $row = DB::table('inspection_notification_deliveries')->where('recipient_user_id', $reporter->id)->sole();
        $firstTag = null;
        Notification::assertSentTo($reporter, InspectionEventNotification::class, function (InspectionEventNotification $n) use (&$firstTag): bool {
            $firstTag = $n->pushTag();

            return true;
        });
        $this->assertSame('dispatched', $row->status, 'the ledger row is still written');
        Notification::fake();

        $sent = app(InspectionNotificationDispatcher::class)->redeliver($row->event_key, $row->notification_type, $reporter->id);

        $this->assertTrue($sent);
        Notification::assertSentToTimes($reporter, InspectionEventNotification::class, 1);
        Notification::assertSentTo($reporter, InspectionEventNotification::class, fn (InspectionEventNotification $n): bool => $n->eventKey === $row->event_key
            && $n->pushTag() === $firstTag);
    }

    public function test_the_dispatcher_drops_a_candidate_who_is_no_longer_eligible_for_the_kind(): void
    {
        $dispatcher = app(InspectionNotificationDispatcher::class);
        $reviewer = $this->reviewer();
        $member = $this->member('Pat Member');

        $recipients = $dispatcher->distinctRecipients(collect([$reviewer, $member]), null, InspectionNotificationKind::REVIEW_REQUIRED);

        $this->assertSame([$reviewer->id], $recipients->pluck('id')->all(), 'a reviewer kind goes only to current reviewers');
        $this->assertCount(2, $dispatcher->distinctRecipients(collect([$reviewer, $member])), 'without a kind the caller keeps its own choice');
    }
}
