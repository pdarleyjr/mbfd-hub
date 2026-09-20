<?php

declare(strict_types=1);

namespace Tests\Feature\HubSupport;

use App\Enums\AccountStatus;
use App\Enums\HubSupportTicketStatus;
use App\Models\HubSupportTicket;
use App\Models\User;
use App\Models\UserNotificationSubscription;
use App\Notifications\HubSupportMemberNotification;
use App\Notifications\NewSubmissionNotification;
use App\Services\HubSupport\HubSupportTicketSubmissionService;
use App\Services\HubSupport\HubSupportTicketWorkflowService;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use LogicException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class HubSupportAuthorizationAndWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Notification::fake();
    }

    public function test_direct_member_and_admin_urls_enforce_ownership_and_independent_capabilities(): void
    {
        $owner = User::factory()->create(['account_status' => AccountStatus::Active]);
        $other = User::factory()->create(['account_status' => AccountStatus::Active]);
        $ticket = app(HubSupportTicketSubmissionService::class)->submit($owner, [
            'client_submission_id' => 'ec3de41e-362c-4c91-912a-75c1831dab87',
            'description' => 'The page fails to load.',
        ], [UploadedFile::fake()->image('screen.png')])->ticket;
        $attachment = $ticket->attachments->first();

        $this->actingAsCanonicalUser($other)->get(route('hub-support.show', $ticket))->assertForbidden();
        $this->get(route('hub-support.attachments.download', $attachment))->assertForbidden();
        $this->get('/admin/hub-support-tickets/'.$ticket->id)->assertForbidden();
        $this->get(route('admin.hub-support-attachments.download', $attachment))->assertForbidden();

        $this->actingAsCanonicalUser($owner)->get(route('hub-support.show', $ticket))->assertOk();
        $download = $this->get(route('hub-support.attachments.download', $attachment))->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox");
        $cacheControl = (string) $download->headers->get('Cache-Control');
        foreach (['private', 'no-store', 'max-age=0'] as $directive) {
            self::assertStringContainsString($directive, $cacheControl);
        }
        $this->get('/admin/hub-support-tickets/'.$ticket->id)->assertForbidden();

        $viewer = User::factory()->create(['account_status' => AccountStatus::Active]);
        foreach (['admin.access', 'admin.support.view'] as $permission) {
            $viewer->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $this->actingAsCanonicalUser($viewer)->get(route('admin.hub-support-attachments.download', $attachment))->assertOk();
        $this->get('/admin/hub-support-tickets/'.$ticket->id)->assertOk();
        self::assertFalse($viewer->can('update', $ticket));

        $manager = User::factory()->create(['account_status' => AccountStatus::Active]);
        foreach (['admin.access', 'admin.support.view', 'admin.support.manage'] as $permission) {
            $manager->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        self::assertTrue($manager->can('update', $ticket));

        $manageOnly = User::factory()->create(['account_status' => AccountStatus::Active]);
        foreach (['admin.access', 'admin.support.manage'] as $permission) {
            $manageOnly->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        self::assertTrue($manageOnly->can('view', $ticket));
        $this->actingAsCanonicalUser($manageOnly)->get('/admin/hub-support-tickets/'.$ticket->id)->assertOk();
        $this->get(route('admin.hub-support-attachments.download', $attachment))->assertOk();
    }

    public function test_public_response_and_status_change_notify_canonical_user_without_exposing_internal_note(): void
    {
        $reporter = User::factory()->create(['account_status' => AccountStatus::Active]);
        $manager = User::factory()->create(['account_status' => AccountStatus::Active]);
        foreach (['admin.access', 'admin.support.view', 'admin.support.manage'] as $permission) {
            $manager->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $ticket = HubSupportTicket::factory()->for($reporter, 'reporter')->create([
            'status' => HubSupportTicketStatus::Acknowledged,
        ]);
        $service = app(HubSupportTicketWorkflowService::class);

        $updated = $service->transition($ticket, $manager, HubSupportTicketStatus::WaitingForReporter, [
            'public_response' => 'Did this happen before you selected the PDF?',
            'internal_note' => 'Check the upload service trace.',
        ]);

        self::assertSame(HubSupportTicketStatus::WaitingForReporter, $updated->status);
        Notification::assertSentTo($reporter, HubSupportMemberNotification::class);
        $this->actingAsCanonicalUser($reporter)->get(route('hub-support.show', $ticket))
            ->assertOk()->assertSee('Did this happen before you selected the PDF?')
            ->assertDontSee('Check the upload service trace.');

        $this->post(route('hub-support.reply', $ticket), ['response' => 'After I selected it.'])
            ->assertRedirect();
        self::assertSame(HubSupportTicketStatus::Acknowledged, $ticket->refresh()->status);
        self::assertNotNull($ticket->acknowledged_at);
        self::assertSame('reporter_reply', $ticket->updates()->reorder()->latest('id')->first()->metadata['event']);

        $this->expectException(LogicException::class);
        $ticket->updates()->first()->update(['internal_note' => 'Rewritten']);
    }

    public function test_workflow_requires_an_authorized_actor_and_authorized_assignee(): void
    {
        $reporter = User::factory()->create(['account_status' => AccountStatus::Active]);
        $ticket = HubSupportTicket::factory()->for($reporter, 'reporter')->create();
        $unauthorized = User::factory()->create(['account_status' => AccountStatus::Active]);
        $service = app(HubSupportTicketWorkflowService::class);

        try {
            $service->transition($ticket, $unauthorized, HubSupportTicketStatus::Acknowledged);
            self::fail('An unauthorized user updated a support ticket.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            self::assertSame(403, $exception->getStatusCode());
        }

        $manager = User::factory()->create(['account_status' => AccountStatus::Active]);
        foreach (['admin.access', 'admin.support.view', 'admin.support.manage'] as $permission) {
            $manager->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        try {
            $service->transition($ticket, $manager, HubSupportTicketStatus::Acknowledged, [
                'assigned_to_user_id' => $unauthorized->id,
            ]);
            self::fail('An unauthorized user was assigned a support ticket.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('assigned_to_user_id', $exception->errors());
        }
    }

    public function test_reopening_clears_current_terminal_state_without_rewriting_history(): void
    {
        $reporter = User::factory()->create(['account_status' => AccountStatus::Active]);
        $manager = User::factory()->create(['account_status' => AccountStatus::Active]);
        foreach (['admin.access', 'admin.support.view', 'admin.support.manage'] as $permission) {
            $manager->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $ticket = HubSupportTicket::factory()->for($reporter, 'reporter')->create([
            'status' => HubSupportTicketStatus::Resolved,
            'resolved_at' => now(),
            'resolution_summary' => 'Previously fixed.',
        ]);

        app(HubSupportTicketWorkflowService::class)->transition($ticket, $manager, HubSupportTicketStatus::InProgress);

        $ticket->refresh();
        self::assertSame(HubSupportTicketStatus::InProgress, $ticket->status);
        self::assertNull($ticket->resolved_at);
        self::assertNull($ticket->resolution_summary);
        self::assertSame('status_transition', $ticket->updates()->latest('id')->first()->metadata['event']);
    }

    #[DataProvider('validAdministrativeTransitions')]
    public function test_service_enforces_every_intended_administrative_transition(HubSupportTicketStatus $from, HubSupportTicketStatus $to, array $data): void
    {
        $reporter = User::factory()->create(['account_status' => AccountStatus::Active]);
        $manager = $this->supportManager();
        $ticket = HubSupportTicket::factory()->for($reporter, 'reporter')->create(['status' => $from]);

        $updated = app(HubSupportTicketWorkflowService::class)->transition($ticket, $manager, $to, $data);

        self::assertSame($to, $updated->status);
    }

    #[DataProvider('invalidAdministrativeTransitions')]
    public function test_service_rejects_every_forbidden_administrative_shortcut(HubSupportTicketStatus $from, HubSupportTicketStatus $to): void
    {
        $reporter = User::factory()->create(['account_status' => AccountStatus::Active]);
        $ticket = HubSupportTicket::factory()->for($reporter, 'reporter')->create(['status' => $from]);

        $this->expectException(ValidationException::class);
        app(HubSupportTicketWorkflowService::class)->transition($ticket, $this->supportManager(), $to);
    }

    public function test_resolved_reporter_reply_reopens_the_current_ticket_without_rewriting_its_resolution_history(): void
    {
        $reporter = User::factory()->create(['account_status' => AccountStatus::Active]);
        $ticket = HubSupportTicket::factory()->for($reporter, 'reporter')->create([
            'status' => HubSupportTicketStatus::Resolved,
            'resolved_at' => now(),
            'resolution_summary' => 'The form service was restored.',
        ]);

        $this->actingAsCanonicalUser($reporter)->post(route('hub-support.reply', $ticket), [
            'response' => 'It still does not work for me.',
        ])->assertRedirect();

        $ticket->refresh();
        self::assertSame(HubSupportTicketStatus::InProgress, $ticket->status);
        self::assertNull($ticket->resolved_at);
        self::assertNull($ticket->closed_at);
        self::assertNull($ticket->resolution_summary);
        $reply = $ticket->updates()->latest('id')->firstOrFail();
        self::assertSame(HubSupportTicketStatus::Resolved, $reply->previous_status);
        self::assertSame(HubSupportTicketStatus::InProgress, $reply->status);
        self::assertSame('reporter_reply', $reply->metadata['event']);
    }

    public function test_member_notification_failure_does_not_masquerade_as_a_failed_committed_workflow_change(): void
    {
        $reporter = User::factory()->create(['account_status' => AccountStatus::Active]);
        $ticket = HubSupportTicket::factory()->for($reporter, 'reporter')->create([
            'status' => HubSupportTicketStatus::InProgress,
        ]);
        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('send')->once()->andThrow(new \RuntimeException('notification transport unavailable'));
        app()->instance(Dispatcher::class, $dispatcher);

        $updated = app(HubSupportTicketWorkflowService::class)->transition($ticket, $this->supportManager(), HubSupportTicketStatus::Resolved, [
            'resolution_summary' => 'The form service was restored.',
        ]);

        self::assertSame(HubSupportTicketStatus::Resolved, $updated->status);
        self::assertDatabaseHas('hub_support_ticket_updates', [
            'hub_support_ticket_id' => $ticket->id,
            'previous_status' => HubSupportTicketStatus::InProgress->value,
            'status' => HubSupportTicketStatus::Resolved->value,
        ]);
    }

    public function test_resolved_member_notification_uses_the_safe_resolution_summary(): void
    {
        $reporter = User::factory()->create(['account_status' => AccountStatus::Active]);
        $ticket = HubSupportTicket::factory()->for($reporter, 'reporter')->create([
            'status' => HubSupportTicketStatus::InProgress,
        ]);

        app(HubSupportTicketWorkflowService::class)->transition($ticket, $this->supportManager(), HubSupportTicketStatus::Resolved, [
            'resolution_summary' => 'We restored the form service and verified the submission path.',
        ]);

        Notification::assertSentTo($reporter, HubSupportMemberNotification::class, function (HubSupportMemberNotification $notification) use ($reporter): bool {
            return $notification->toDatabase($reporter)['body'] === 'We restored the form service and verified the submission path.';
        });
    }

    public function test_authorized_admin_can_view_only_sanitized_structured_diagnostic_details(): void
    {
        $reporter = User::factory()->create(['account_status' => AccountStatus::Active]);
        $ticket = app(HubSupportTicketSubmissionService::class)->submit($reporter, [
            'client_submission_id' => '0432bb8b-aee9-431a-93fc-0f667ff6837c',
            'description' => 'The form failed after I selected Submit.',
            'diagnostics' => ['events' => [
                [
                    'type' => 'error',
                    'timestamp' => '2026-09-19T23:30:00.000Z',
                    'name' => 'TypeError',
                    'message' => 'authorization: Bearer private-token',
                    'source' => '/employee/forms?token=private',
                    'line' => 42,
                    'column' => 7,
                    'stack' => 'TypeError at /employee/forms?token=private',
                ],
                [
                    'type' => 'request',
                    'timestamp' => '2026-09-19T23:30:01.000Z',
                    'method' => 'POST',
                    'path' => '/employee/forms/api/records?csrf=private',
                    'status' => 500,
                    'duration' => 130,
                    'request_body' => 'never rendered',
                ],
            ]],
        ])->ticket;
        $manager = $this->supportManager();

        $this->actingAsCanonicalUser($manager)->get('/admin/hub-support-tickets/'.$ticket->id)
            ->assertOk()
            ->assertSee('Technical details')
            ->assertSee('TypeError')
            ->assertSee('/employee/forms')
            ->assertSee('HTTP 500')
            ->assertDontSee('private-token')
            ->assertDontSee('csrf=private')
            ->assertDontSee('never rendered');
    }

    public function test_only_authorized_and_opted_in_admins_receive_submission_alerts(): void
    {
        $reporter = User::factory()->create(['account_status' => AccountStatus::Active]);
        $subscribedUnauthorized = User::factory()->create(['account_status' => AccountStatus::Active]);
        $authorizedOff = User::factory()->create(['account_status' => AccountStatus::Active]);
        $authorizedOn = User::factory()->create(['account_status' => AccountStatus::Active]);
        foreach ([$subscribedUnauthorized, $authorizedOn] as $user) {
            UserNotificationSubscription::query()->create([
                'user_id' => $user->id, 'event_key' => User::NOTIFICATION_PREFERENCE_HUB_SUPPORT_TICKETS,
                'database_enabled' => true, 'webpush_enabled' => false, 'email_enabled' => false,
            ]);
        }
        foreach ([$authorizedOff, $authorizedOn] as $user) {
            $user->givePermissionTo(Permission::findOrCreate('admin.access', 'web'));
            $user->givePermissionTo(Permission::findOrCreate('admin.support.view', 'web'));
        }

        app(HubSupportTicketSubmissionService::class)->submit($reporter, [
            'client_submission_id' => 'e262d491-9d89-4203-b5c1-e47eb5ef6831',
            'description' => 'I cannot finish this task.',
        ]);

        Notification::assertNotSentTo($subscribedUnauthorized, NewSubmissionNotification::class);
        Notification::assertNotSentTo($authorizedOff, NewSubmissionNotification::class);
        Notification::assertSentTo($authorizedOn, NewSubmissionNotification::class);
        self::assertSame(false, UserNotificationSubscription::query()
            ->where('user_id', $authorizedOn->id)->first()->email_enabled);
    }

    private function supportManager(): User
    {
        $manager = User::factory()->create(['account_status' => AccountStatus::Active]);
        foreach (['admin.access', 'admin.support.view', 'admin.support.manage'] as $permission) {
            $manager->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }

        return $manager;
    }

    /** @return iterable<string, array{HubSupportTicketStatus, HubSupportTicketStatus, array<string, string>}> */
    public static function validAdministrativeTransitions(): iterable
    {
        yield 'new acknowledged' => [HubSupportTicketStatus::New, HubSupportTicketStatus::Acknowledged, []];
        yield 'new in progress' => [HubSupportTicketStatus::New, HubSupportTicketStatus::InProgress, []];
        yield 'acknowledged in progress' => [HubSupportTicketStatus::Acknowledged, HubSupportTicketStatus::InProgress, []];
        yield 'acknowledged waiting' => [HubSupportTicketStatus::Acknowledged, HubSupportTicketStatus::WaitingForReporter, ['public_response' => 'Please confirm the button you selected.']];
        yield 'acknowledged resolved' => [HubSupportTicketStatus::Acknowledged, HubSupportTicketStatus::Resolved, ['resolution_summary' => 'The form service was restored.']];
        yield 'in progress waiting' => [HubSupportTicketStatus::InProgress, HubSupportTicketStatus::WaitingForReporter, ['public_response' => 'Please confirm the button you selected.']];
        yield 'in progress resolved' => [HubSupportTicketStatus::InProgress, HubSupportTicketStatus::Resolved, ['resolution_summary' => 'The form service was restored.']];
        yield 'waiting in progress' => [HubSupportTicketStatus::WaitingForReporter, HubSupportTicketStatus::InProgress, []];
        yield 'waiting resolved' => [HubSupportTicketStatus::WaitingForReporter, HubSupportTicketStatus::Resolved, ['resolution_summary' => 'The form service was restored.']];
        yield 'resolved closed' => [HubSupportTicketStatus::Resolved, HubSupportTicketStatus::Closed, []];
        yield 'resolved reopen' => [HubSupportTicketStatus::Resolved, HubSupportTicketStatus::InProgress, []];
        yield 'closed reopen' => [HubSupportTicketStatus::Closed, HubSupportTicketStatus::InProgress, []];
    }

    /** @return iterable<string, array{HubSupportTicketStatus, HubSupportTicketStatus}> */
    public static function invalidAdministrativeTransitions(): iterable
    {
        yield 'new waiting' => [HubSupportTicketStatus::New, HubSupportTicketStatus::WaitingForReporter];
        yield 'new resolved' => [HubSupportTicketStatus::New, HubSupportTicketStatus::Resolved];
        yield 'new closed' => [HubSupportTicketStatus::New, HubSupportTicketStatus::Closed];
        yield 'acknowledged closed' => [HubSupportTicketStatus::Acknowledged, HubSupportTicketStatus::Closed];
        yield 'in progress closed' => [HubSupportTicketStatus::InProgress, HubSupportTicketStatus::Closed];
        yield 'waiting closed' => [HubSupportTicketStatus::WaitingForReporter, HubSupportTicketStatus::Closed];
    }
}
