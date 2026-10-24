<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Jobs\FanOutNotification;
use App\Models\CapitalProject;
use App\Models\Station;
use App\Models\StationInspection;
use App\Models\Todo;
use App\Models\Training\TrainingTodo;
use App\Models\User;
use App\Notifications\HubSupportMemberNotification;
use App\Notifications\Inspections\InspectionEventNotification;
use App\Notifications\Inspections\InspectionNotificationKind;
use App\Notifications\NewSubmissionNotification;
use App\Services\Captain5\Captain5Notifier;
use App\Services\Identity\AccountSecurityService;
use App\Services\Notifications\NotificationCategoryRegistry;
use App\Services\Notifications\NotificationDispatchService;
use App\Services\Notifications\NotificationRecipientEvaluator;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Feature\Notifications\Concerns\BuildsNotificationPersonas;
use Tests\TestCase;

/**
 * Failure isolation, fan-out cost, lock-screen privacy, evaluator coverage and dedupe keys for the alerts
 * that workflow code raises.
 */
final class NotificationHardeningTest extends TestCase
{
    use BuildsNotificationPersonas;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetPermissionCache();
    }

    private function submitStationInspection(): StationInspection
    {
        $station = Station::query()->firstOrCreate(['station_number' => 1], ['name' => 'Station 1', 'address' => '1051 Jefferson Avenue', 'is_active' => true]);

        return StationInspection::create([
            'station_id' => $station->id,
            'inspector_id' => $this->member()->id,
            'inspection_date' => now()->toDateString(),
            'inspection_type' => 'annual',
            'overall_status' => 'pass',
            'form_data' => [],
        ]);
    }

    private function stationReviewer(): User
    {
        return $this->withPermissions($this->member(), 'admin.access', 'admin.stations.view');
    }

    // ---- M3: failure isolation -------------------------------------------------------------------------

    public function test_a_delivery_failure_never_undoes_the_station_inspection(): void
    {
        Exceptions::fake();
        $this->stationReviewer();
        Notification::swap(new class implements Dispatcher
        {
            public function send($notifiables, $notification): void
            {
                throw new RuntimeException('push service exploded');
            }

            public function sendNow($notifiables, $notification, ?array $channels = null): void
            {
                throw new RuntimeException('push service exploded');
            }
        });

        $inspection = $this->submitStationInspection();

        $this->assertNotNull(StationInspection::query()->find($inspection->id), 'the submission is saved');
        Exceptions::assertReported(RuntimeException::class);
    }

    public function test_a_failing_hook_body_never_undoes_the_submission(): void
    {
        Exceptions::fake();
        // A queue that cannot accept the fan-out is reported, not thrown into the save.
        Queue::shouldReceive('push', 'later', 'pushOn', 'laterOn', 'connection', 'bulk')->andThrow(new RuntimeException('queue down'));
        $this->stationReviewer();

        $inspection = $this->submitStationInspection();

        $this->assertNotNull(StationInspection::query()->find($inspection->id));
    }

    public function test_a_failure_while_cleaning_up_push_devices_never_undoes_an_account_disable(): void
    {
        Exceptions::fake();
        $user = $this->member();
        $user->pushSubscriptions()->create(['endpoint' => 'https://fcm.googleapis.com/fcm/send/x', 'public_key' => 'k', 'auth_token' => 'a']);
        Schema::rename(config('webpush.table_name'), 'push_subscriptions_offline');

        try {
            app(AccountSecurityService::class)->disable($user, 'test disable', CarbonImmutable::now());
        } finally {
            Schema::rename('push_subscriptions_offline', config('webpush.table_name'));
        }

        $this->assertNotSame('active', $user->fresh()->getRawOriginal('account_status'), 'the disable stands');
        Exceptions::assertReported(\Illuminate\Database\QueryException::class);
    }

    public function test_the_fan_out_is_queued_after_the_surrounding_transaction_commits(): void
    {
        Queue::fake([FanOutNotification::class]);
        $reviewer = $this->stationReviewer();

        DB::transaction(function (): void {
            $this->submitStationInspection();
            Queue::assertNothingPushed();
        });

        Queue::assertPushedOn('notifications', FanOutNotification::class);
        $this->assertSame(0, $reviewer->notifications()->count(), 'nothing is delivered inside the request');

        Queue::pushed(FanOutNotification::class)->each(fn (FanOutNotification $job) => $job->handle(app(NotificationDispatchService::class)));
        $this->assertSame(1, $reviewer->notifications()->count());
    }

    public function test_a_rolled_back_workflow_write_sends_no_alert(): void
    {
        Queue::fake([FanOutNotification::class]);
        $this->stationReviewer();

        try {
            DB::transaction(function (): void {
                $this->submitStationInspection();

                throw new RuntimeException('workflow failed after the hook ran');
            });
        } catch (RuntimeException) {
        }

        Queue::assertNothingPushed();
    }

    // ---- M4: fan-out cost -------------------------------------------------------------------------------

    public function test_the_category_prefilter_does_not_evaluate_the_whole_roster(): void
    {
        $reviewer = $this->stationReviewer();
        foreach (range(1, 30) as $i) {
            $this->withRole($this->member(), 'member');
        }
        $evaluator = app(NotificationRecipientEvaluator::class);

        DB::enableQueryLog();
        $narrow = $evaluator->recipients('station_inspections');
        $narrowQueries = count(DB::getQueryLog());

        DB::flushQueryLog();
        $everyone = User::query()->where(fn ($q) => $q->whereHas('roles')->orWhereHas('permissions'))->get();
        $everyoneRecipients = $evaluator->recipients('station_inspections', $everyone);
        $roster = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame([$reviewer->id], $narrow->pluck('id')->all());
        $this->assertSame($narrow->pluck('id')->all(), $everyoneRecipients->pluck('id')->all(), 'same people either way');
        $this->assertLessThan(12, $narrowQueries, 'cost no longer grows with the roster');
        $this->assertGreaterThan($narrowQueries * 3, $roster, 'the whole-roster evaluation was the expensive path');
    }

    public function test_the_prefilter_never_drops_someone_the_rule_would_accept(): void
    {
        $viaRole = $this->member();
        $role = \Spatie\Permission\Models\Role::findOrCreate('station-desk', 'web');
        $role->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate('admin.stations.view', 'web'));
        $viaRole->assignRole($role);
        $viaRole->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate('admin.access', 'web'));
        $super = $this->withRole($this->member(), 'super_admin');
        $nobody = $this->withRole($this->member(), 'member');

        $ids = app(NotificationRecipientEvaluator::class)->recipients('station_requests')->pluck('id')->all();

        $this->assertContains($viaRole->id, $ids, 'a permission granted through a role still qualifies');
        $this->assertContains($super->id, $ids);
        $this->assertNotContains($nobody->id, $ids);
    }

    // ---- M5: lock-screen privacy -----------------------------------------------------------------------

    /** @return array<string, array{string}> */
    public static function messageKinds(): array
    {
        return array_combine(InspectionEventNotification::messageBearingKinds(), array_map(fn (string $kind): array => [$kind], InspectionEventNotification::messageBearingKinds()));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('messageKinds')]
    public function test_a_message_never_reaches_the_lock_screen(string $kind): void
    {
        $user = $this->member();
        $notification = new InspectionEventNotification(
            $kind,
            'E3: message about your inspection',
            'Captain Jane Roe wrote: the SECRET-DETAIL is in locker 4 and the member Pat Doe was hurt.',
            '/employee/my-vehicle-inspections',
            'evt-1',
        );

        $push = json_encode($notification->toWebPush($user, null)->toArray());

        $this->assertStringNotContainsString('SECRET-DETAIL', (string) $push);
        $this->assertStringNotContainsString('Jane', (string) $push);
        $this->assertStringNotContainsString('Pat Doe', (string) $push);
        // The administration post's title is the author's subject line, so it never names a unit.
        $expected = $kind === Captain5Notifier::KIND_COMMUNICATION ? 'New message - open MBFD Hub to read it.' : 'New message about E3 - open MBFD Hub to read it.';
        $this->assertStringContainsString($expected, (string) $push);
        $this->assertStringContainsString('SECRET-DETAIL', $notification->toDatabase($user)['body'], 'the full text stays in the app');
        $this->assertStringContainsString('SECRET-DETAIL', $notification->toBudgetedEmail($user)['text'], 'email is unchanged');
    }

    public function test_other_inspection_alerts_keep_a_fixed_title_and_no_body_text(): void
    {
        $user = $this->member();
        $review = new InspectionEventNotification(InspectionNotificationKind::REVIEW_REQUIRED, 'E3: inspection needs approval', 'Pat Doe submitted a Daily Checkout for E3: the SCBA gauge reads low.', '/admin/x', 'evt-2');

        $push = $review->toWebPush($user, null)->toArray();

        $this->assertSame('E3: inspection needs approval', $push['title']);
        $this->assertStringNotContainsString('Pat Doe', $push['body']);
        $this->assertStringNotContainsString('SCBA', $push['body']);
    }

    public function test_an_administration_post_does_not_put_its_subject_on_the_lock_screen(): void
    {
        $user = $this->member();
        $post = new InspectionEventNotification(Captain5Notifier::KIND_COMMUNICATION, 'Admin message: Chief wants Pat Doe disciplined', 'Details here', '/daily/captain-5?tab=messages', 'captain5:communication:1');

        $push = json_encode($post->toWebPush($user, null)->toArray());

        $this->assertStringNotContainsString('Pat Doe', (string) $push);
        $this->assertStringNotContainsString('Chief', (string) $push);
    }

    public function test_a_denial_reason_or_reply_in_a_submission_alert_stays_off_the_lock_screen(): void
    {
        $user = $this->member();
        $alert = new NewSubmissionNotification('member_request_update', 'Request Denied', 'UNI-2026-001 - Denied: your sizes were wrong, Pat Doe.', '/employee/my-requests/1', messageBearing: true, pushSubject: 'UNI-2026-001');

        $push = json_encode($alert->toWebPush($user, null)->toArray());

        $this->assertStringNotContainsString('sizes', (string) $push);
        $this->assertStringContainsString('New message about UNI-2026-001', (string) $push);
        $this->assertStringContainsString('sizes were wrong', $alert->toDatabase($user)['body']);
    }

    public function test_support_ticket_and_service_ticket_replies_are_generic_on_the_lock_screen(): void
    {
        $user = $this->member();
        $support = new HubSupportMemberNotification(5, 'Resolved', 'We fixed the login by resetting your account, Pat.');
        $ticket = new \App\Notifications\ApparatusServiceTicketEmployeeNotification('AST-2026-000001', 'E3', 'completed', 'Brake chamber replaced by Mike.');

        foreach ([$support->toWebPush($user, null), $ticket->toWebPush($user, null)] as $message) {
            $push = json_encode($message->toArray());
            $this->assertStringNotContainsString('resetting', (string) $push);
            $this->assertStringNotContainsString('Brake', (string) $push);
            $this->assertStringContainsString('open MBFD Hub to read it', (string) $push);
        }
    }

    // ---- M7: nothing delivers around the evaluator ---------------------------------------------------------

    public function test_member_replies_follow_the_evaluator_and_never_reach_an_inactive_account(): void
    {
        $active = $this->member();
        $disabled = $this->member(['account_status' => 'disabled']);
        $reply = new HubSupportMemberNotification(5, 'Resolved', 'Done.');

        $this->assertSame(['database'], $reply->via($active));
        $this->assertSame([], $reply->via($disabled));
        $this->assertFalse($reply->shouldSend($disabled, 'database'));
    }

    public function test_project_alerts_reach_only_people_who_may_act_on_projects_now(): void
    {
        $manager = $this->withPermissions($this->withRole($this->member(), 'project-manager'), 'view_any_capital::project');
        $noAccess = $this->withRole($this->member(), 'project-manager');
        $disabled = $this->withPermissions($this->withRole($this->member(['account_status' => 'disabled']), 'project-manager'), 'view_any_capital::project');
        CapitalProject::factory()->create(['status' => 'in_progress', 'name' => 'Roof', 'target_completion_date' => now()->subWeek(), 'actual_completion' => null]);

        $this->artisan('projects:check-overdue')->assertSuccessful();

        $alert = $manager->notifications()->sole();
        $this->assertSame('Overdue Project', $alert->data['title']);
        $this->assertSame('project_alerts', $alert->data['provenance']['category']);
        $this->assertSame(0, $noAccess->notifications()->count());
        $this->assertSame(0, $disabled->notifications()->count());
    }

    public function test_an_admin_todo_alert_goes_to_assignees_who_can_open_the_admin_panel_once_per_assignment(): void
    {
        $creator = $this->withPermissions($this->member(), 'admin.access', 'admin.notifications.view');
        $assignee = $this->withPermissions($this->member(), 'admin.access', 'admin.notifications.view');
        $outsider = $this->member();
        $this->actingAs($creator);

        $todo = Todo::create(['title' => 'Order hoses', 'assigned_to' => [$assignee->id, $outsider->id], 'created_by' => $creator->id]);
        $this->assertSame(1, $assignee->notifications()->count());
        $this->assertSame(0, $outsider->notifications()->count(), 'no admin panel, no alert');
        $this->assertSame(0, $creator->notifications()->count());
        $this->assertSame('todo_assignments', $assignee->notifications()->sole()->data['provenance']['category']);

        // Unassigned and assigned again is a new assignment: a new alert (M9).
        $todo->update(['assigned_to' => []]);
        $todo->update(['assigned_to' => [$assignee->id]]);
        $this->assertSame(2, $assignee->notifications()->count());

        // Saving something else does not alert again.
        $todo->update(['title' => 'Order more hoses']);
        $this->assertSame(2, $assignee->notifications()->count());
    }

    public function test_a_labelled_registry_category_exists_for_every_new_sender(): void
    {
        $registry = app(NotificationCategoryRegistry::class);

        foreach ([NotificationCategoryRegistry::PROJECT_ALERTS, NotificationCategoryRegistry::EQUIPMENT_EXPIRATIONS, NotificationCategoryRegistry::TODO_ASSIGNMENTS] as $key) {
            $category = $registry->get($key);
            $this->assertNotNull($category, $key);
            $this->assertTrue($category->senderWired);
            $this->assertNotNull($category->candidates, "{$key} has a real audience prefilter");
        }
    }

    // ---- M9: dedupe keys ------------------------------------------------------------------------------

    public function test_a_training_todo_assigned_again_is_told_again_but_a_double_trigger_is_collapsed(): void
    {
        \Spatie\Permission\Models\Role::findOrCreate('training_viewer', 'web');
        $creator = $this->withRole($this->member(), 'training_viewer');
        $a = $this->withRole($this->member(), 'training_viewer');
        $b = $this->withRole($this->member(), 'training_viewer');
        $this->actingAs($creator);

        $todo = TrainingTodo::create(['title' => 'Drill', 'status' => 'pending', 'priority' => 'high', 'assigned_to' => [(string) $a->id], 'created_by' => $creator->id]);
        $this->assertSame(1, $a->notifications()->count());

        $todo->update(['assigned_to' => [(string) $b->id]]);
        $this->assertSame(1, $b->notifications()->count());
        $todo->update(['assigned_to' => [(string) $a->id]]);
        $this->assertSame(2, $a->notifications()->count(), 'returned to the first person the same day: told again');

        // The very same event dispatched twice is one alert.
        $dispatch = app(NotificationDispatchService::class);
        $key = NotificationDispatchService::eventKey('training_assignments', 'todo', $todo->id, 'assigned:fixed');
        $alert = fn () => new \App\Notifications\TrainingTodoAssignedNotification($todo->id, 'Drill', 'high', '/training');
        $dispatch->send('training_assignments', $alert(), [$a], $key);
        $dispatch->send('training_assignments', $alert(), [$a], $key);
        $this->assertSame(3, $a->notifications()->count());
    }

    // ---- L11 ------------------------------------------------------------------------------------------

    public function test_the_daily_checkout_review_copy_describes_what_it_now_sends(): void
    {
        $copy = app(NotificationCategoryRegistry::class)->get('vehicle_inspections')->description;

        $this->assertStringNotContainsString('was submitted', $copy);
        $this->assertStringContainsString('needs your approval', $copy);
        $this->assertStringContainsString('replied', $copy);
    }
}
