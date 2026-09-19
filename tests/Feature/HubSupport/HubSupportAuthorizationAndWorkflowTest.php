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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use LogicException;
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
        $ticket = HubSupportTicket::factory()->for($reporter, 'reporter')->create();
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
}
