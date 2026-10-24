<?php

declare(strict_types=1);

namespace Tests\Feature\Inspections;

use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusDefectEvent;
use App\Models\ApparatusInspection;
use App\Models\ApparatusInspectionException;
use App\Models\ApparatusIssueMessage;
use App\Models\User;
use App\Notifications\Channels\BudgetedMailChannel;
use App\Notifications\Inspections\InspectionEventNotification;
use App\Notifications\Inspections\InspectionNotificationKind;
use App\Services\Defects\DefectCorrectiveActionService;
use App\Services\Inspections\InspectionNotificationDispatcher;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\Feature\Defects\Concerns\BuildsContinuityFixtures;
use Tests\TestCase;

final class InspectionNotificationDispatcherTest extends TestCase
{
    use BuildsContinuityFixtures;
    use RefreshDatabase;

    private InspectionNotificationDispatcher $dispatcher;

    private DefectCorrectiveActionService $actions;

    private Apparatus $rig;

    private User $reporter;

    private User $admin;

    private ApparatusInspection $inspection;

    private ApparatusDefect $defect;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->dispatcher = app(InspectionNotificationDispatcher::class);
        $this->actions = app(DefectCorrectiveActionService::class);
        $this->rig = $this->rig();
        $this->reporter = $this->member('Riley Reporter');
        $this->admin = $this->reviewer();
        $this->inspection = $this->inspectionBy($this->rig, $this->reporter);
        $this->defect = $this->openDefect($this->rig, $this->inspection, $this->reporter);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function resolveAs(User $actor, ?string $key = null): ApparatusDefect
    {
        return $this->actions->resolve($this->defect->id, $actor, [
            'resolution_type' => 'replacement_installed',
            'resolution_notes' => 'Replaced from stock.',
        ], $key);
    }

    public function test_resolution_copy_and_links_match_the_approved_wording(): void
    {
        $assigned = $this->reviewer('admin', 'Assigned Admin');
        $this->actions->updateAction($this->defect->id, $this->admin, [
            'action_status' => 'action_pending',
            'details' => ['assigned_to' => $assigned->id],
        ]);
        Carbon::setTestNow('2026-10-22 14:00:00');

        $this->resolveAs($this->admin);

        Notification::assertSentTo($this->reporter, InspectionEventNotification::class, function (InspectionEventNotification $n): bool {
            $text = $n->title.' '.$n->body;

            return $n->kind === 'issue_resolved'
                && $n->title === 'Engine 2: previously reported issue resolved'
                && $n->body === 'The missing portable radio battery reported on Oct 19 has been replaced. Logistics marked the issue resolved on Oct 22.'
                && ! str_contains($text, '—') && ! str_contains($text, '–')
                && $n->url === '/employee/my-vehicle-inspections/'.$this->inspection->id.'#issue-'.$this->defect->id;
        });
        // The assigned administrator follows the issue too and lands on the canonical admin page.
        Notification::assertSentTo($assigned, InspectionEventNotification::class, fn (InspectionEventNotification $n): bool => $n->kind === 'issue_resolved'
            && $n->url === '/admin/inspections/'.$this->inspection->id.'#issue-'.$this->defect->id);
        Notification::assertNotSentTo($this->admin, InspectionEventNotification::class);
    }

    public function test_the_database_payload_uses_the_filament_format_with_a_view_action(): void
    {
        $this->resolveAs($this->admin);

        Notification::assertSentTo($this->reporter, InspectionEventNotification::class, function (InspectionEventNotification $n): bool {
            $payload = $n->toDatabase($this->reporter);

            return $payload['format'] === 'filament'
                && $payload['title'] === $n->title
                && $payload['actions'][0]['url'] === $n->url
                && $payload['iconColor'] === 'success';
        });
    }

    public function test_a_member_is_not_notified_a_second_time_when_the_same_event_is_dispatched_again(): void
    {
        $this->resolveAs($this->admin);
        $event = ApparatusDefectEvent::query()->sole();

        $this->dispatcher->issueResolved($this->defect->fresh(), $event);
        $this->dispatcher->issueResolved($this->defect->fresh(), $event);

        Notification::assertSentToTimes($this->reporter, InspectionEventNotification::class, 1);
        $this->assertSame(1, DB::table('inspection_notification_deliveries')->count());
    }

    public function test_inactive_participants_and_the_actor_are_skipped(): void
    {
        $inactive = $this->member('Ivy Inactive', active: false);
        $this->participant($this->defect, $inactive, 'confirmed');
        $actorParticipant = $this->member('Alex Actor');
        $this->participant($this->defect, $actorParticipant, 'confirmed');
        $actorParticipant->assignRole(Role::findOrCreate('logistics_admin', 'web'));

        $this->resolveAs($actorParticipant);

        Notification::assertSentTo($this->reporter, InspectionEventNotification::class);
        Notification::assertNotSentTo($inactive, InspectionEventNotification::class);
        Notification::assertNotSentTo($actorParticipant, InspectionEventNotification::class);
    }

    /**
     * users.employee_id and users.employee_profile_id are unique, so two stored accounts for
     * one person cannot exist today. The rule is still enforced on the recipient set, which
     * is exercised here with in-memory accounts.
     */
    private function account(int $id, string $personnelNumber, ?string $lastLogin): User
    {
        $user = new User;
        $user->forceFill([
            'id' => $id,
            'name' => 'Account '.$id,
            'account_status' => 'active',
            'employee_id' => $personnelNumber,
            'employee_profile_id' => null,
            'last_login_at' => $lastLogin,
        ]);
        $user->syncOriginal();

        return $user;
    }

    public function test_several_accounts_of_one_person_collapse_to_the_most_recently_active_one(): void
    {
        $older = $this->account(9001, 'E-DUAL', '2026-10-01 08:00:00');
        $newer = $this->account(9002, 'E-DUAL', '2026-10-20 08:00:00');
        $neverLoggedIn = $this->account(9003, 'E-DUAL', null);
        $someoneElse = $this->account(9004, 'E-OTHER', null);

        $recipients = $this->dispatcher->distinctRecipients(collect([$older, $newer, $neverLoggedIn, $someoneElse]));

        $this->assertSame([9002, 9004], $recipients->pluck('id')->sort()->values()->all());
    }

    public function test_inactive_accounts_never_win_the_collapse(): void
    {
        $inactiveButNewer = $this->account(9101, 'E-DUAL', '2026-10-21 08:00:00');
        $inactiveButNewer->forceFill(['account_status' => 'disabled'])->syncOriginal();
        $active = $this->account(9102, 'E-DUAL', '2026-10-01 08:00:00');

        $recipients = $this->dispatcher->distinctRecipients(collect([$inactiveButNewer, $active]));

        $this->assertSame([9102], $recipients->pluck('id')->all());
    }

    public function test_the_actors_other_account_is_not_a_recipient_either(): void
    {
        $asAdmin = $this->account(9201, 'E-ADMIN', '2026-10-21 08:00:00');
        $asReporter = $this->account(9202, 'E-ADMIN', '2026-10-02 08:00:00');
        $colleague = $this->account(9203, 'E-COLLEAGUE', null);

        $recipients = $this->dispatcher->distinctRecipients(collect([$asReporter, $colleague]), $asAdmin);

        $this->assertSame([9203], $recipients->pluck('id')->all());
    }

    public function test_a_known_issue_seen_again_never_notifies(): void
    {
        $this->assertNotContains('observed_again', InspectionNotificationKind::all());

        $event = ApparatusDefectEvent::query()->create([
            'apparatus_defect_id' => $this->defect->id,
            'actor_user_id' => $this->reporter->id,
            'event_type' => 'observed_again',
        ]);
        // No member-visible text on the event: there is nothing to tell anyone.
        $this->dispatcher->issueMemberUpdate($this->defect, $event);

        Notification::assertNothingSent();
        $this->assertSame(0, DB::table('inspection_notification_deliveries')->count());
    }

    public function test_review_required_reaches_reviewers_with_the_hold_reasons_and_an_approval_link(): void
    {
        $other = $this->reviewer('super_admin', 'Super');
        $member = $this->member('Mel Member');
        ApparatusInspectionException::query()->create([
            'apparatus_inspection_id' => $this->inspection->id,
            'apparatus_id' => $this->rig->id,
            'field' => 'miles',
            'reason' => 'rollback',
            'status' => 'open',
            'blocks_acceptance' => true,
        ]);
        ApparatusInspectionException::query()->create([
            'apparatus_inspection_id' => $this->inspection->id,
            'apparatus_id' => $this->rig->id,
            'field' => 'member_review_flag',
            'reason' => 'member_flagged',
            'status' => 'resolved',
            'blocks_acceptance' => true,
        ]);

        $this->dispatcher->reviewRequired($this->inspection);

        foreach ([$this->admin, $other] as $reviewer) {
            Notification::assertSentTo($reviewer, InspectionEventNotification::class, fn (InspectionEventNotification $n): bool => $n->kind === 'inspection_review_required'
                && $n->url === '/admin/inspections/'.$this->inspection->id.'#approval'
                && str_contains($n->body, 'meter reading is lower')
                && ! str_contains($n->body, 'flagged'));
        }
        Notification::assertNotSentTo($member, InspectionEventNotification::class);

        // The same hold is not announced twice.
        $this->dispatcher->reviewRequired($this->inspection);
        Notification::assertSentToTimes($this->admin, InspectionEventNotification::class, 1);
    }

    public function test_an_escalated_issue_reaches_reviewers_only(): void
    {
        $this->dispatcher->issueEscalated($this->defect, $this->inspection);

        Notification::assertSentTo($this->admin, InspectionEventNotification::class, fn (InspectionEventNotification $n): bool => $n->kind === 'issue_escalated'
            && $n->title === 'Engine 2: significant damage reported'
            && $n->url === '/admin/inspections/'.$this->inspection->id.'#issue-'.$this->defect->id);
        Notification::assertNotSentTo($this->reporter, InspectionEventNotification::class);
    }

    public function test_the_submitter_hears_how_their_inspection_was_decided(): void
    {
        $this->dispatcher->inspectionDecided($this->inspection, 'approved');
        $this->dispatcher->inspectionDecided($this->inspection, 'rejected');
        $this->dispatcher->inspectionDecided($this->inspection, 'something_else');

        Notification::assertSentToTimes($this->reporter, InspectionEventNotification::class, 2);
        Notification::assertSentTo($this->reporter, InspectionEventNotification::class, fn (InspectionEventNotification $n): bool => $n->kind === 'inspection_decided'
            && $n->title === 'Engine 2: inspection approved'
            && str_contains($n->body, 'was approved'));
        // A rejected checkout does not count as done, so the message says what to do next.
        Notification::assertSentTo($this->reporter, InspectionEventNotification::class, fn (InspectionEventNotification $n): bool => $n->title === 'Engine 2: inspection rejected'
            && str_contains($n->body, 'does not count as done')
            && str_contains($n->body, 'submit a new Daily Checkout')
            && ! str_contains($n->body, 'contact Logistics'));
        Notification::assertNotSentTo($this->admin, InspectionEventNotification::class);
    }

    public function test_a_closed_no_action_issue_is_announced_without_calling_it_resolved(): void
    {
        $event = \App\Models\ApparatusDefectEvent::query()->create([
            'apparatus_defect_id' => $this->defect->id,
            'actor_user_id' => $this->admin->id,
            'event_type' => 'dismissed',
            'from_status' => 'reported',
            'to_status' => 'dismissed',
            'details' => ['reason' => 'Duplicate of the report from Oct 20.', 'previous_action_status' => 'reported'],
        ]);

        $this->dispatcher->issueClosedNoAction($this->defect, $event);

        Notification::assertSentToTimes($this->reporter, InspectionEventNotification::class, 1);
        Notification::assertSentTo($this->reporter, InspectionEventNotification::class, fn (InspectionEventNotification $n): bool => $n->kind === 'issue_member_update'
            && $n->title === 'Engine 2: issue closed, no action required'
            && str_contains($n->body, 'was closed with no action required')
            && str_contains($n->body, 'Reason: Duplicate of the report from Oct 20')
            && str_contains($n->body, 'It was not repaired or replaced.')
            && ! str_contains(strtolower($n->body), 'resolved'));
        Notification::assertNotSentTo($this->admin, InspectionEventNotification::class);
    }

    public function test_a_clarification_request_reaches_the_submitter_and_an_internal_one_reaches_nobody(): void
    {
        $visible = ApparatusIssueMessage::query()->create([
            'apparatus_inspection_id' => $this->inspection->id,
            'author_user_id' => $this->admin->id,
            'visibility' => 'member',
            'body' => 'Was the radio in the cab or the compartment?',
        ]);
        $internal = ApparatusIssueMessage::query()->create([
            'apparatus_inspection_id' => $this->inspection->id,
            'author_user_id' => $this->admin->id,
            'visibility' => 'internal',
            'body' => 'He keeps misreporting this one.',
        ]);

        $this->dispatcher->clarificationRequested($this->inspection, $internal);
        Notification::assertNothingSent();

        $this->dispatcher->clarificationRequested($this->inspection, $visible);
        Notification::assertSentTo($this->reporter, InspectionEventNotification::class, fn (InspectionEventNotification $n): bool => $n->kind === 'clarification_requested'
            && str_contains($n->body, 'Was the radio in the cab')
            && str_contains($n->url, '/employee/my-vehicle-inspections/'.$this->inspection->id));
        Notification::assertNotSentTo($this->admin, InspectionEventNotification::class);
    }

    public function test_a_rolled_back_domain_change_sends_nothing_and_leaves_no_ledger_row(): void
    {
        try {
            DB::transaction(function (): void {
                $event = ApparatusDefectEvent::query()->create([
                    'apparatus_defect_id' => $this->defect->id,
                    'actor_user_id' => $this->admin->id,
                    'event_type' => 'resolved',
                ]);
                $this->dispatcher->issueResolved($this->defect, $event);
                throw new RuntimeException('Domain write failed after queuing.');
            });
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }

        Notification::assertNothingSent();
        $this->assertSame(0, DB::table('inspection_notification_deliveries')->count());
    }

    public function test_a_dispatch_failure_is_recorded_and_never_thrown(): void
    {
        $this->app->instance(\Illuminate\Contracts\Notifications\Dispatcher::class, new class implements \Illuminate\Contracts\Notifications\Dispatcher
        {
            public function send($notifiables, $notification): void
            {
                throw new RuntimeException('Push gateway unreachable.');
            }

            public function sendNow($notifiables, $notification, ?array $channels = null): void
            {
                throw new RuntimeException('Push gateway unreachable.');
            }
        });

        $this->dispatcher->inspectionDecided($this->inspection, 'approved');

        $this->assertSame('failed', DB::table('inspection_notification_deliveries')->where('recipient_user_id', $this->reporter->id)->value('status'));
    }

    public function test_channels_follow_the_existing_subscription_preferences(): void
    {
        $notification = new InspectionEventNotification('issue_resolved', 'T', 'B', '/employee');
        $this->assertSame(['database'], $notification->via($this->reporter));

        $this->reporter->employeeProfile->update(['city_email' => 'riley@example.test']);
        $this->reporter->notificationSubscriptions()->create([
            'event_key' => User::NOTIFICATION_PREFERENCE_MEMBER_REQUEST_UPDATES,
            'database_enabled' => true,
            'webpush_enabled' => true,
            'email_enabled' => true,
        ]);

        // Web push also needs a registered device; the city email needs the preference and an address.
        $this->assertSame(['database', BudgetedMailChannel::class], $notification->via($this->reporter->fresh()));
        $this->assertSame('T', $notification->toBudgetedEmail($this->reporter)['subject']);
    }
}
