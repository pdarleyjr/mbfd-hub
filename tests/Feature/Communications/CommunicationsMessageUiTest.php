<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Filament\Resources\OutboundEmailResource\Pages\ViewOutboundEmail;
use App\Livewire\Mail\MailboxList;
use App\Livewire\Mail\MessageComposer;
use App\Models\InboundEmail;
use App\Models\Mail\MailDraft;
use App\Models\Mail\MailThreadMessage;
use App\Models\OutboundEmail;
use App\Models\User;
use App\Services\Communications\EmailConversation;
use App\Services\Communications\OutboundDeliveryLedger;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Message screens and sends. The old Sent list and the old Compose form moved into the Email Center (docs/email-center-architecture.md),
 * so the same behaviours are asserted there: search that never matches Bcc, the delivery states, readable content without Bcc,
 * the paused-sending wording, and the headers, parents and recipient classes of replies and forwards. The old message page still
 * renders for a message the Email Center has not indexed, so its tests keep running against such a message.
 */
final class CommunicationsMessageUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $admin = User::factory()->create(['account_status' => 'active']);
        $admin->assignRole(Role::findOrCreate('super_admin', 'web'));
        $this->actingAs($admin);
        $this->withoutVite();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_sent_renders_scalar_to_and_search_never_matches_bcc(): void
    {
        $email = $this->email();
        // Changed: the Sent folder of the Email Center replaces the old Sent table and its table search.
        $sent = Livewire::test(MailboxList::class, ['folder' => 'sent']);
        $sent->assertSee('Operational note')->assertDontSee('private@example.test');
        Livewire::test(MailboxList::class, ['folder' => 'sent', 'search' => 'public@example.test'])->assertSee('Operational note');
        Livewire::test(MailboxList::class, ['folder' => 'sent', 'search' => 'private@example.test'])->assertDontSee('Operational note')->assertSee('No conversations match');
        $this->assertNotNull($email->fresh());
    }

    public function test_historical_states_include_reconciled_and_aged_unresolved_messages_without_confirmed_terminal_evidence(): void
    {
        $persisted = $this->email();
        $persisted->forceFill(['created_at' => now()->subDays(32)])->save();
        app(OutboundDeliveryLedger::class)->markHistorical();
        self::assertSame('historical_unknown', $persisted->fresh()->status);
        $unresolved = $this->email();
        $unresolved->forceFill(['created_at' => now()->subDays(32)])->save();
        $current = $this->email();
        $confirmed = $this->email();
        $confirmed->forceFill(['status' => 'accepted_with_delivery_issues', 'created_at' => now()->subDays(32)])->save();
        $confirmed->recipients()->create([
            'address' => 'public@example.test', 'recipient_class' => 'to', 'status' => 'bounced', 'is_terminal' => true,
        ]);
        $delivered = $this->email();
        $delivered->forceFill(['status' => 'delivered', 'created_at' => now()->subDays(32)])->save();
        $otherSource = $this->email();
        $otherSource->forceFill(['status' => 'historical_unknown', 'source_type' => 'member_onboarding_invitation', 'created_at' => now()->subDays(32)])->save();

        // Changed: the old table filter (status, then source) is gone with the old list; the classification it filtered on is
        // asserted directly, and the Email Center never turns an unresolved old message into a delivery claim.
        $historical = fn (OutboundEmail $email): bool => $email->fresh()->effectiveDeliveryStatus() === 'historical_unknown';
        self::assertTrue($historical($persisted));
        self::assertTrue($historical($unresolved));
        self::assertTrue($historical($otherSource));
        self::assertFalse($historical($current));
        self::assertFalse($historical($confirmed));
        self::assertFalse($historical($delivered));
        self::assertSame('accepted_with_delivery_issues', $confirmed->fresh()->effectiveDeliveryStatus());
        self::assertSame('delivered', $delivered->fresh()->effectiveDeliveryStatus());
        self::assertSame('Delivery status unavailable / historical', $unresolved->fresh()->deliveryStatusLabel());
        $viewer = \App\Services\Mail\MailMessageView::deliveryLabel($unresolved->fresh());
        self::assertSame('Unconfirmed', $viewer, 'an unresolved old message is shown as unconfirmed, never as delivered');
    }

    public function test_message_view_has_readable_content_without_bcc_and_sensitive_actions_are_hidden(): void
    {
        $email = $this->email();
        $this->unindexed($email); // the old page now yields to the Email Center for any message it has indexed
        Livewire::test(ViewOutboundEmail::class, ['record' => $email->id])->assertSee('Original body')->assertSee('public@example.test')
            ->assertDontSee('private@example.test')->assertActionVisible('reply')->assertActionVisible('forward');
        $email->update(['source_type' => 'member_onboarding_invitation', 'text_body' => '[Sensitive account-security message omitted]']);
        Livewire::test(ViewOutboundEmail::class, ['record' => $email->id])->assertActionHidden('reply')->assertActionHidden('forward');
    }

    public function test_the_email_center_shows_the_same_message_without_bcc_and_hides_actions_for_automated_mail(): void
    {
        $email = $this->email();
        $thread = MailThreadMessage::query()->where('outbound_email_id', $email->id)->firstOrFail()->thread_id;
        $reader = Livewire::test(\App\Livewire\Mail\MessageReader::class, ['threadId' => $thread]);
        $reader->assertSee('Original body')->assertSee('public@example.test')->assertSeeHtml('data-mail-action="reply"');

        $email->update(['source_type' => 'member_onboarding_invitation', 'text_body' => '[Sensitive account-security message omitted]']);
        app(\App\Services\Mail\MailThreadIndexer::class)->indexOutbound($email->fresh());
        $thread = MailThreadMessage::query()->where('outbound_email_id', $email->id)->firstOrFail()->thread_id;
        Livewire::test(\App\Livewire\Mail\MessageReader::class, ['threadId' => $thread])->assertDontSeeHtml('data-mail-action="reply"')->assertDontSeeHtml('data-mail-action="forward"');
    }

    public function test_source_labels_and_delivery_timeline_are_readable_and_preserve_bcc_privacy(): void
    {
        $email = $this->email();
        $email->update(['source_type' => 'App\\Notifications\\NewSubmissionNotification']);
        foreach (['to' => 'public@example.test', 'bcc' => 'private@example.test'] as $class => $address) {
            $recipient = $email->recipients()->create(['address' => $address, 'recipient_class' => $class, 'status' => 'deferred']);
            $email->deliveryEvents()->create([
                'outbound_email_recipient_id' => $recipient->id, 'identity' => hash('sha256', $class),
                'event_type' => 'newEmailSending', 'status' => 'deferred', 'occurred_at' => now(),
                'failure_detail' => $class === 'bcc' ? 'Private failure details' : 'Recipient server temporarily unavailable',
            ]);
        }
        // Changed: system notifications are listed under Automated in the Email Center, read-only.
        app(\App\Services\Mail\MailThreadIndexer::class)->indexOutbound($email->fresh());
        Livewire::test(MailboxList::class, ['folder' => 'automated'])->assertSee('Operational note');
        $this->unindexed($email); // the old message page, still reachable for a message the index has not seen
        Livewire::test(ViewOutboundEmail::class, ['record' => $email->id])->assertSee('New submission')->assertSee('Deferred')
            ->assertSee('Recipient server temporarily unavailable')->assertDontSee('newEmailSending')
            ->assertDontSee('Private failure details')->assertDontSee('private@example.test')
            ->assertActionVisible('reply')->assertActionVisible('reply_all')->assertActionVisible('forward');
        self::assertSame('Onboarding invitation', EmailConversation::sourceLabel('member_onboarding_invitation'));
        self::assertSame('Training Assignment', EmailConversation::sourceLabel('App\\Notifications\\TrainingAssignmentNotification'));
    }

    public function test_sensitive_source_is_rejected_server_side_even_for_send_authorized_admin(): void
    {
        $email = $this->email();
        $email->update(['source_type' => 'member_onboarding_invitation']);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(EmailConversation::class)->source('outbound', $email->id, auth()->user());
    }

    public function test_compose_invalid_recipient_stops_before_provider_request(): void
    {
        Http::fake();
        // Changed: the Email Center composer reports a refusal in words instead of a form error under the field.
        Livewire::test(MessageComposer::class)->set('to', ['invalid-address'])->set('subject', 'Operations')->set('data.body', '<p>Body</p>')
            ->call('send')->assertSet('result.status', 'refused')->assertSee('is not a valid email address');
        Http::assertNothingSent();
    }

    public function test_compose_says_plainly_when_sending_is_paused_and_nothing_was_sent(): void
    {
        $this->configureSending();
        \App\Models\CloudflareUsageBudget::query()->update(['reconciled_at' => null, 'provider_daily_reconciled_at' => null]);
        Http::fake();

        // Changed: the pause is shown in the composer ("Not sent yet", draft kept) instead of a toast.
        Livewire::test(MessageComposer::class)->set('to', ['public@example.test'])->set('subject', 'Operations')->set('data.body', '<p>Body</p>')
            ->call('send')->assertSet('result.status', 'deferred')->assertSee('Not sent yet')->assertSee('Nothing was sent');

        Http::assertNothingSent();
        self::assertSame(MailDraft::STATE_OPEN, MailDraft::query()->sole()->state);
    }

    public function test_reply_send_preserves_provider_headers_parent_and_private_recipient_classes(): void
    {
        $this->configureSending();
        $source = $this->email();
        $source->update(['message_id' => '<parent@example.test>', 'references' => ['<first@example.test>']]);
        Http::fake(['api.cloudflare.com/*' => Http::response(['success' => true, 'result' => ['message_id' => '<reply@example.test>', 'queued' => ['public@example.test', 'copy@example.test']]])]);
        $thread = MailThreadMessage::query()->where('outbound_email_id', $source->id)->firstOrFail()->thread_id;
        Livewire::test(MessageComposer::class, ['mode' => 'reply', 'threadId' => $thread, 'source' => "outbound:{$source->id}"])
            ->set('to', ['PUBLIC@example.test'])->set('cc', ['copy@example.test'])->set('data.body', '<p>Reply body</p>')
            ->call('send')->assertSet('result.status', 'accepted');
        Http::assertSent(fn ($request) => $request['headers']['In-Reply-To'] === '<parent@example.test>'
            && $request['headers']['References'] === '<first@example.test> <parent@example.test>'
            && $request['to'] === ['public@example.test'] && $request['cc'] === ['copy@example.test'] && ! isset($request['bcc']));
        $reply = OutboundEmail::where('id', '!=', $source->id)->sole();
        self::assertSame($source->id, $reply->parent_outbound_email_id);
        self::assertSame('admin_reply', $reply->source_type);
        // Changed: the ledger key is now the draft's own stable id, so a retry of the same draft can never send twice.
        self::assertSame('draft:'.MailDraft::query()->sole()->id, $reply->source_id);
        self::assertSame('queued', $reply->status);
        self::assertSame('<parent@example.test>', $reply->in_reply_to);
    }

    public function test_inbound_reply_and_forward_preserve_source_association_and_only_reply_has_rfc_threading_headers(): void
    {
        $this->configureSending();
        $source = InboundEmail::create([
            'provider_message_id' => '<incoming@example.test>', 'from_address' => 'sender@example.test',
            'to_address' => 'info@mbfdhub.com', 'subject' => 'Operational question', 'text_body' => 'Original body',
            'received_at' => now(), 'references' => ['<earlier@example.test>'],
        ]);
        Http::fake(['api.cloudflare.com/*' => Http::response(['success' => true, 'result' => [
            'message_id' => '<sent@example.test>', 'queued' => ['sender@example.test'],
        ]])]);
        $thread = MailThreadMessage::query()->where('inbound_email_id', $source->id)->firstOrFail()->thread_id;

        Livewire::test(MessageComposer::class, ['mode' => 'reply', 'threadId' => $thread, 'source' => "inbound:{$source->id}"])
            ->set('data.body', '<p>Reply body</p>')->call('send')->assertSet('result.status', 'accepted');
        $reply = OutboundEmail::query()->sole();
        self::assertSame('admin_reply', $reply->source_type);
        self::assertSame('draft:'.MailDraft::query()->sole()->id, $reply->source_id);
        self::assertSame($source->id, $reply->parent_inbound_email_id);
        self::assertSame('<incoming@example.test>', $reply->in_reply_to);
        self::assertSame(['<earlier@example.test>', '<incoming@example.test>'], $reply->references);
        Http::assertSent(fn ($request): bool => $request['headers']['In-Reply-To'] === '<incoming@example.test>'
            && $request['headers']['References'] === '<earlier@example.test> <incoming@example.test>');

        Livewire::test(MessageComposer::class, ['mode' => 'forward', 'threadId' => $thread, 'source' => "inbound:{$source->id}"])
            ->set('to', ['sender@example.test'])->set('data.body', '<p>Forward body</p>')
            ->call('send')->assertSet('result.status', 'accepted');
        $forward = OutboundEmail::query()->whereKeyNot($reply->id)->sole();
        self::assertSame('admin_forward', $forward->source_type);
        self::assertSame($source->id, $forward->parent_inbound_email_id);
        self::assertNull($forward->in_reply_to);
        self::assertStringStartsWith('draft:', (string) $forward->source_id);
        Http::assertSent(fn ($request): bool => $request['subject'] === 'Fwd: Operational question'
            && ! isset($request['headers']));
    }

    private function configureSending(): void
    {
        config(['communications.cloudflare.account_id' => str_repeat('a', 32), 'communications.cloudflare.api_token' => 'test-only-token']);
        \App\Models\CloudflareUsageBudget::create([
            'provider_account_id' => str_repeat('a', 32), 'cycle_start' => now()->subDay(), 'cycle_end' => now()->addDays(29),
            'provider_chargeable_used' => 0, 'provider_daily_quota' => 100, 'provider_daily_used' => 0,
            'hub_safe_ceiling' => 2850, 'worker_request_threshold' => 9000000, 'worker_cpu_ms_threshold' => 27000000,
            'worker_requests_used' => 0, 'worker_cpu_ms_used' => 0, 'reconciled_at' => now(), 'provider_daily_reconciled_at' => now(),
        ]);
    }

    private function email(): OutboundEmail
    {
        return OutboundEmail::create(['provider' => 'cloudflare', 'source_type' => 'admin_compose', 'from_address' => 'info@mbfdhub.com', 'to_recipients' => ['public@example.test'], 'bcc_recipients' => ['private@example.test'], 'subject' => 'Operational note', 'text_body' => 'Original body', 'recipient_count' => 2, 'chargeable_budget_units' => 2, 'status' => 'queued']);
    }

    /** Makes the Email Center forget a message, which is the one case the old message page still renders. */
    private function unindexed(OutboundEmail $email): void
    {
        MailThreadMessage::query()->where('outbound_email_id', $email->id)->delete();
    }
}
