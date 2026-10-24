<?php

declare(strict_types=1);

namespace Tests\Feature\HubSupport;

use App\Enums\AccountStatus;
use App\Enums\HubSupportTicketStatus;
use App\Filament\Resources\HubSupportTicketResource\Pages\ListHubSupportTickets;
use App\Filament\Resources\HubSupportTicketResource\Pages\ViewHubSupportTicket;
use App\Jobs\DeliverHubSupportTicketUpdate;
use App\Models\Employee;
use App\Models\HubSupportNotificationDelivery;
use App\Models\HubSupportTicket;
use App\Models\User;
use App\Models\UserNotificationSubscription;
use App\Notifications\HubSupportMemberNotification;
use App\Notifications\NewSubmissionNotification;
use App\Services\HubSupport\HubSupportTicketRecoveryService;
use App\Services\HubSupport\HubSupportTicketSideEffectService;
use App\Services\HubSupport\HubSupportTicketSubmissionService;
use App\Services\HubSupport\HubSupportTicketWorkflowService;
use App\Services\RequestArchivalService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class HubSupportWorkflowCompletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Notification::fake();
    }

    public function test_public_updates_notify_members_while_internal_notes_stay_private(): void
    {
        $reporter = $this->member();
        $manager = $this->manager();
        $ticket = HubSupportTicket::factory()->for($reporter, 'reporter')->create(['status' => HubSupportTicketStatus::InProgress]);
        $service = app(HubSupportTicketWorkflowService::class);
        $service->transition($ticket, $manager, HubSupportTicketStatus::InProgress, ['internal_note' => 'Private investigation details.']);
        Notification::assertNothingSent();

        $service->transition($ticket, $manager, HubSupportTicketStatus::InProgress, [
            'public_response' => 'We found the upload failure and are checking the fix.', 'internal_note' => 'Private investigation details.',
        ]);
        Notification::assertSentTo($reporter, HubSupportMemberNotification::class);
        $this->actingAsCanonicalUser($reporter)->get(route('hub-support.show', $ticket))->assertOk()
            ->assertSee('We found the upload failure')->assertDontSee('Private investigation details.');
        $payloads = HubSupportNotificationDelivery::query()->pluck('payload')->toJson();
        self::assertStringNotContainsString('Private investigation', $payloads);
    }

    public function test_confirming_a_fix_shows_the_member_a_confirmation_after_the_redirect(): void
    {
        $reporter = $this->member();
        $ticket = HubSupportTicket::factory()->for($reporter, 'reporter')->create(['status' => HubSupportTicketStatus::InProgress]);
        app(HubSupportTicketWorkflowService::class)->transition($ticket, $this->manager(), HubSupportTicketStatus::Resolved, [
            'resolution_summary' => 'We restored the upload service.',
        ]);

        $this->actingAsCanonicalUser($reporter)
            ->from(route('hub-support.show', $ticket))
            ->followingRedirects()
            ->post(route('hub-support.reply', $ticket), [
                'action' => 'confirm_fixed', 'client_action_id' => (string) Str::uuid(), 'expected_update_id' => $ticket->updates()->max('id'),
            ])
            ->assertOk()
            ->assertSee('Thanks for confirming. Your report is closed.');
    }

    public function test_member_can_confirm_a_fix_then_reopen_without_losing_the_resolution(): void
    {
        $reporter = $this->member();
        $ticket = HubSupportTicket::factory()->for($reporter, 'reporter')->create(['status' => HubSupportTicketStatus::InProgress]);
        app(HubSupportTicketWorkflowService::class)->transition($ticket, $this->manager(), HubSupportTicketStatus::Resolved, [
            'resolution_summary' => 'We restored the upload service.',
        ]);
        $payload = ['action' => 'confirm_fixed', 'client_action_id' => (string) Str::uuid(), 'expected_update_id' => $ticket->updates()->max('id')];
        $this->actingAsCanonicalUser($reporter)->post(route('hub-support.reply', $ticket), $payload)->assertRedirect();
        $this->post(route('hub-support.reply', $ticket), $payload)->assertRedirect();
        self::assertSame(HubSupportTicketStatus::Closed, $ticket->refresh()->status);
        self::assertSame(1, $ticket->updates()->where('metadata->event', 'reporter_confirmed_fixed')->count());

        $this->post(route('hub-support.reply', $ticket), [
            'response' => 'The upload still fails.', 'client_action_id' => (string) Str::uuid(), 'expected_update_id' => $ticket->updates()->max('id'),
        ])->assertRedirect();
        self::assertSame(HubSupportTicketStatus::InProgress, $ticket->refresh()->status);
        self::assertNull($ticket->resolved_at);
        self::assertNull($ticket->closed_at);
        $this->get(route('hub-support.show', $ticket))->assertOk()->assertSee('We restored the upload service.')->assertSee('The upload still fails.');
    }

    public function test_retried_replies_are_idempotent_and_changed_payloads_or_stale_forms_are_rejected(): void
    {
        $reporter = $this->member();
        $ticket = HubSupportTicket::factory()->for($reporter, 'reporter')->create();
        $service = app(HubSupportTicketWorkflowService::class);
        $context = ['client_action_id' => (string) Str::uuid(), 'expected_update_id' => 0];
        $first = $service->reply($ticket, $reporter, 'It fails after choosing the file.', $context);
        self::assertSame($first->id, $service->reply($ticket, $reporter, 'It fails after choosing the file.', $context)->id);
        self::assertSame(1, $ticket->updates()->count());
        foreach ([[$context, 'Different reply'], [['expected_update_id' => 0, 'client_action_id' => (string) Str::uuid()], 'Stale reply']] as [$input, $response]) {
            try {
                $service->reply($ticket, $reporter, $response, $input);
                self::fail('A conflicting or stale reply was accepted.');
            } catch (ValidationException $exception) {
                self::assertArrayHasKey('response', $exception->errors());
            }
        }
        self::assertSame(1, $ticket->updates()->count());
    }

    public function test_archive_and_trash_block_new_replies_and_trash_restores_files_status_and_history(): void
    {
        $reporter = $this->member();
        $manager = $this->manager();
        $ticket = app(HubSupportTicketSubmissionService::class)->submit($reporter, [
            'client_submission_id' => (string) Str::uuid(), 'description' => 'The upload fails.',
        ], [UploadedFile::fake()->image('screen.png')])->ticket;
        $attachment = $ticket->attachments->sole();
        app(RequestArchivalService::class)->archive($ticket, $manager, 'Retained test.');
        $this->actingAsCanonicalUser($reporter)->post(route('hub-support.reply', $ticket), ['response' => 'A stale reply.'])->assertStatus(409);
        app(RequestArchivalService::class)->restore($ticket, $manager);
        $recovery = app(HubSupportTicketRecoveryService::class);
        $recovery->trash($ticket, $manager, 'Duplicate test.');
        $recovery->trash($ticket, $manager, 'Retry.');
        self::assertSame(HubSupportTicketStatus::New, $ticket->refresh()->status);
        self::assertSame('Duplicate test.', $ticket->delete_reason);
        self::assertSame(1, $ticket->updates()->where('metadata->event', 'trashed')->count());
        Storage::disk($attachment->disk)->assertExists($attachment->storage_path);
        $this->get(route('hub-support.show', $ticket))->assertNotFound();
        $this->post(route('hub-support.reply', $ticket), ['response' => 'Another stale reply.'])->assertNotFound();
        $this->get(route('hub-support.attachments.download', $attachment))->assertForbidden();
        $this->actingAsCanonicalUser($manager)->get(route('admin.hub-support-attachments.download', $attachment))->assertOk();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(ListHubSupportTickets::class)->call('loadTable')->assertCanNotSeeTableRecords([$ticket])
            ->filterTable('archive_state', 'trash')->assertCanSeeTableRecords([$ticket]);
        Livewire::test(ViewHubSupportTicket::class, ['record' => $ticket->id])->assertActionHidden('archive')->assertActionHidden('update_report')
            ->callAction('restore_trash')->assertHasNoActionErrors();
        self::assertFalse($ticket->refresh()->trashed());
        self::assertSame(HubSupportTicketStatus::New, $ticket->status);
        self::assertSame(1, $ticket->attachments()->count());
        $this->actingAsCanonicalUser($reporter)->get(route('hub-support.show', $ticket))->assertOk();
    }

    public function test_manager_can_delete_restore_but_even_super_admin_cannot_force_purge(): void
    {
        $ticket = HubSupportTicket::factory()->create();
        $super = $this->member();
        $super->assignRole(Role::findOrCreate('super_admin', 'web'));
        self::assertTrue($super->can('delete', $ticket));
        self::assertFalse($super->can('forceDelete', $ticket));
        app(HubSupportTicketRecoveryService::class)->trash($ticket, $super, 'Retained test.');
        self::assertFalse($super->can('update', $ticket->refresh()));
        $manager = $this->manager();
        self::assertTrue($manager->can('restore', $ticket));
        app(HubSupportTicketRecoveryService::class)->restore($ticket, $manager);
        $this->expectException(\LogicException::class);
        $ticket->forceDelete();
    }

    public function test_trash_filter_includes_archived_tickets_and_restore_preserves_the_archive(): void
    {
        $manager = $this->manager();
        $ticket = HubSupportTicket::factory()->create();
        app(RequestArchivalService::class)->archive($ticket, $manager, 'Retained history.');
        app(HubSupportTicketRecoveryService::class)->trash($ticket, $manager, 'Duplicate report.');

        $this->actingAsCanonicalUser($manager);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(ListHubSupportTickets::class)->call('loadTable')
            ->assertCanNotSeeTableRecords([$ticket])
            ->filterTable('archive_state', 'archived')->assertCanNotSeeTableRecords([$ticket])
            ->filterTable('archive_state', 'trash')->assertCanSeeTableRecords([$ticket]);

        app(HubSupportTicketRecoveryService::class)->restore($ticket, $manager);
        self::assertFalse($ticket->refresh()->trashed());
        self::assertNotNull($ticket->archived_at);
        self::assertSame(HubSupportTicketStatus::New, $ticket->status);
        Livewire::test(ListHubSupportTickets::class)->call('loadTable')
            ->filterTable('archive_state', 'archived')->assertCanSeeTableRecords([$ticket]);
    }

    public function test_pending_notification_handoffs_are_retryable_and_delivery_replay_does_not_duplicate_them(): void
    {
        Bus::fake();
        $reporter = $this->member();
        $ticket = HubSupportTicket::factory()->for($reporter, 'reporter')->create();
        $context = ['client_action_id' => (string) Str::uuid(), 'expected_update_id' => 0];
        $service = app(HubSupportTicketWorkflowService::class);
        $service->transition($ticket, $this->manager(), HubSupportTicketStatus::Acknowledged, $context);
        $delivery = HubSupportNotificationDelivery::query()->sole();
        self::assertNull($delivery->delivered_at);
        Bus::assertDispatched(DeliverHubSupportTicketUpdate::class, 1);
        $this->artisan('support:retry-notifications', ['--limit' => 1])->assertSuccessful();
        Bus::assertDispatched(DeliverHubSupportTicketUpdate::class, 2);
        app(HubSupportTicketSideEffectService::class)->deliver($delivery->id);
        app(HubSupportTicketSideEffectService::class)->deliver($delivery->id);
        Notification::assertSentToTimes($reporter, HubSupportMemberNotification::class, 1);
        self::assertSame(1, $delivery->refresh()->attempts);
        self::assertNotNull($delivery->delivered_at);
    }

    public function test_queue_handoff_failure_preserves_the_committed_update_and_pending_delivery(): void
    {
        Bus::shouldReceive('dispatch')->once()->andThrow(new \RuntimeException('queue temporarily unavailable'));
        $reporter = $this->member();
        $ticket = HubSupportTicket::factory()->for($reporter, 'reporter')->create();
        app(HubSupportTicketWorkflowService::class)->transition($ticket, $this->manager(), HubSupportTicketStatus::Acknowledged);
        self::assertSame(HubSupportTicketStatus::Acknowledged, $ticket->refresh()->status);
        $delivery = HubSupportNotificationDelivery::query()->sole();
        self::assertNull($delivery->delivered_at);
        self::assertSame(0, $delivery->attempts);
        Notification::assertNothingSent();
    }

    public function test_member_replies_notify_only_authorized_subscribed_admins(): void
    {
        $reporter = $this->member();
        $manager = $this->manager();
        UserNotificationSubscription::query()->create([
            'user_id' => $manager->id, 'event_key' => User::NOTIFICATION_PREFERENCE_HUB_SUPPORT_TICKETS,
            'database_enabled' => true, 'webpush_enabled' => false, 'email_enabled' => false,
        ]);
        $ticket = HubSupportTicket::factory()->for($reporter, 'reporter')->create();
        app(HubSupportTicketWorkflowService::class)->reply($ticket, $reporter, 'Here is what still happens.');
        Notification::assertSentTo($manager, NewSubmissionNotification::class);
        Notification::assertNotSentTo($reporter, NewSubmissionNotification::class);
    }

    public function test_rolled_back_work_does_not_enqueue_notifications_or_leave_delivery_rows(): void
    {
        Bus::fake();
        $ticket = HubSupportTicket::factory()->for($this->member(), 'reporter')->create();
        $manager = $this->manager();
        DB::beginTransaction();
        app(HubSupportTicketWorkflowService::class)->transition($ticket, $manager, HubSupportTicketStatus::Acknowledged);
        self::assertSame(1, HubSupportNotificationDelivery::query()->count());
        Bus::assertNothingDispatched();
        DB::rollBack();
        self::assertSame(0, HubSupportNotificationDelivery::query()->count());
        self::assertSame(HubSupportTicketStatus::New, $ticket->refresh()->status);
        Bus::assertNothingDispatched();
    }

    public function test_pending_delivery_is_stopped_when_a_report_is_archived(): void
    {
        Bus::fake();
        $reporter = $this->member();
        $manager = $this->manager();
        $ticket = HubSupportTicket::factory()->for($reporter, 'reporter')->create();
        app(HubSupportTicketWorkflowService::class)->transition($ticket, $manager, HubSupportTicketStatus::Acknowledged);
        $delivery = HubSupportNotificationDelivery::query()->sole();
        app(RequestArchivalService::class)->archive($ticket, $manager, 'Retained history.');

        app(HubSupportTicketSideEffectService::class)->deliver($delivery->id);

        self::assertNotNull($delivery->refresh()->cancelled_at);
        Notification::assertNothingSent();
    }

    public function test_exhausted_deliveries_require_an_authorized_audited_retry(): void
    {
        Bus::fake();
        $ticket = HubSupportTicket::factory()->for($this->member(), 'reporter')->create();
        $manager = $this->manager();
        app(HubSupportTicketWorkflowService::class)->transition($ticket, $manager, HubSupportTicketStatus::Acknowledged);
        $delivery = HubSupportNotificationDelivery::query()->sole();
        $delivery->update(['attempts' => 5, 'last_error' => \RuntimeException::class]);
        $this->artisan('support:retry-notifications')->assertSuccessful();
        Bus::assertDispatched(DeliverHubSupportTicketUpdate::class, 1);
        $service = app(HubSupportTicketSideEffectService::class);
        try {
            $service->retryFailed($ticket, $this->member());
            self::fail('An unauthorized actor retried notifications.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            self::assertSame(403, $exception->getStatusCode());
        }
        $delivery->update(['last_attempted_at' => now(), 'last_error' => null]);
        self::assertSame(0, $service->retryFailed($ticket, $manager), 'A fifth attempt still in flight must keep its claim.');
        $delivery->update(['last_error' => \RuntimeException::class]);
        self::assertSame(1, $service->retryFailed($ticket, $manager));
        self::assertSame(0, $delivery->refresh()->attempts);
        $audit = $ticket->updates()->reorder()->latest('id')->firstOrFail();
        self::assertSame('notifications_retried', $audit->metadata['event']);
        self::assertSame(5, $audit->metadata['deliveries'][0]['attempts']);
        Bus::assertDispatched(DeliverHubSupportTicketUpdate::class, 2);
    }

    public function test_channel_sql_failure_rolls_back_partial_delivery_but_retains_attempt_accounting(): void
    {
        Bus::fake();
        $reporter = $this->member();
        $ticket = HubSupportTicket::factory()->for($reporter, 'reporter')->create();
        app(HubSupportTicketWorkflowService::class)->transition($ticket, $this->manager(), HubSupportTicketStatus::Acknowledged);
        $delivery = HubSupportNotificationDelivery::query()->sole();
        Notification::shouldReceive('sendNow')->once()->andReturnUsing(function () use ($delivery, $reporter): void {
            DB::table('notifications')->insert([
                'id' => $delivery->id, 'type' => HubSupportMemberNotification::class,
                'notifiable_type' => User::class, 'notifiable_id' => $reporter->id,
                'data' => json_encode(['title' => 'Partial notification'], JSON_THROW_ON_ERROR),
            ]);
            DB::statement('SELECT support_notification_deliberate_sql_failure()');
        });
        try {
            app(HubSupportTicketSideEffectService::class)->deliver($delivery->id);
            self::fail('The failed channel was treated as delivered.');
        } catch (\Illuminate\Database\QueryException) {
            self::assertSame(1, $delivery->refresh()->attempts);
            self::assertNull($delivery->delivered_at);
            self::assertSame(\Illuminate\Database\QueryException::class, $delivery->last_error);
            self::assertDatabaseMissing('notifications', ['id' => $delivery->id]);
        }
    }

    public function test_external_channel_failure_preserves_its_evidence_and_concurrent_claim_is_not_resent(): void
    {
        Bus::fake();
        $manager = $this->manager();
        $employee = Employee::query()->create([
            'employee_id' => 'SUPPORT-NOTIFY', 'name' => 'Support Notification Test',
            'city_email' => 'support-test@miamibeachfl.gov', 'password' => 'notification-test-password', 'roster_status' => 'active',
        ]);
        $manager->forceFill(['employee_profile_id' => $employee->id, 'employee_id' => $employee->employee_id])->save();
        UserNotificationSubscription::query()->create([
            'user_id' => $manager->id, 'event_key' => User::NOTIFICATION_PREFERENCE_HUB_SUPPORT_TICKETS,
            'database_enabled' => false, 'webpush_enabled' => false, 'email_enabled' => true,
        ]);
        $reporter = $this->member();
        $ticket = HubSupportTicket::factory()->for($reporter, 'reporter')->create();
        $service = app(HubSupportTicketSideEffectService::class);
        app(HubSupportTicketWorkflowService::class)->reply($ticket, $reporter, 'The upload still fails.');
        $delivery = HubSupportNotificationDelivery::query()->sole();
        $transactionLevel = DB::transactionLevel();
        Notification::shouldReceive('sendNow')->once()->andReturnUsing(function () use ($service, $delivery, $ticket, $transactionLevel): void {
            self::assertSame($transactionLevel, DB::transactionLevel(), 'External delivery must own its evidence transaction.');
            $service->deliver($delivery->id);
            $ticket->updates()->create(['status' => $ticket->status, 'internal_note' => 'External uncertain-delivery evidence.']);
            throw new \RuntimeException('Provider acceptance is uncertain.');
        });

        try {
            $service->deliver($delivery->id);
            self::fail('An uncertain external delivery was treated as delivered.');
        } catch (\RuntimeException) {
            self::assertSame(1, $delivery->refresh()->attempts);
            self::assertNull($delivery->delivered_at);
            self::assertSame(\RuntimeException::class, $delivery->last_error);
            self::assertTrue($ticket->updates()->where('internal_note', 'External uncertain-delivery evidence.')->exists());
        }
    }

    private function member(): User
    {
        return User::factory()->create(['account_status' => AccountStatus::Active]);
    }

    private function manager(): User
    {
        $manager = $this->member();
        foreach (['admin.access', 'admin.support.view', 'admin.support.manage'] as $permission) {
            $manager->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }

        return $manager;
    }
}
