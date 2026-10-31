<?php

declare(strict_types=1);

namespace Tests\Feature\Mail;

use App\Livewire\Mail\MessageReader;
use App\Models\Mail\MailThread;
use App\Models\Mail\MailThreadNote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Feature\Mail\Concerns\UsesEmailCenterUi;
use Tests\TestCase;

/** The reading pane: the whole conversation, what it shows about each message, and what each level may do to it. */
final class UiReaderTest extends TestCase
{
    use RefreshDatabase;
    use UsesEmailCenterUi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootEmailCenterUi();
        Storage::fake('local');
    }

    /** One inbound, our reply with a Bcc, and a second inbound, all threaded together. @return array{0: MailThread, 1: \App\Models\InboundEmail, 2: \App\Models\OutboundEmail} */
    private function longConversation(?int $authorId = null): array
    {
        $first = $this->inbound(['subject' => 'Hose test', 'text_body' => 'First words from the vendor.', 'from_address' => 'vendor@example.test', 'from_display_name' => 'Vendor', 'received_at' => now()->subHours(3)]);
        $reply = $this->outbound([
            'source_type' => 'admin_reply', 'initiated_by_user_id' => $authorId, 'subject' => 'Re: Hose test', 'text_body' => 'Our answer in the middle.',
            'to_recipients' => ['vendor@example.test'], 'cc_recipients' => ['copy@example.test'], 'bcc_recipients' => ['quiet@example.test'],
            'in_reply_to' => $first->provider_message_id, 'references' => [$first->provider_message_id], 'parent_inbound_email_id' => $first->id,
            'queued_at' => now()->subHours(2),
        ]);
        $this->inbound([
            'subject' => 'Re: Re: Hose test', 'text_body' => 'Last words, newest.', 'from_address' => 'vendor@example.test',
            'in_reply_to' => $reply->message_id, 'references' => [$first->provider_message_id, $reply->message_id], 'received_at' => now()->subHour(),
        ]);

        return [$this->threadOf($first), $first, $reply];
    }

    public function test_the_whole_conversation_is_shown_oldest_first_with_sender_and_recipient_details(): void
    {
        $this->signIn($user = $this->mailAdmin());
        [$thread] = $this->longConversation($user->id);

        Livewire::test(MessageReader::class, ['threadId' => $thread->id])
            ->assertSeeInOrder(['First words from the vendor.', 'Our answer in the middle.', 'Last words, newest.'])
            ->assertSee('3 messages')
            ->assertSee('vendor@example.test')
            ->assertSee('copy@example.test')
            ->assertSee('Sender and recipient details');
    }

    public function test_opening_a_conversation_marks_it_read_for_that_member_only(): void
    {
        [$thread] = $this->longConversation();
        $reader = $this->mailAdmin();
        $other = $this->mailViewer();
        $this->signIn($reader);

        Livewire::test(MessageReader::class, ['threadId' => $thread->id])->assertDispatched('mail-unread-changed');

        $this->assertSame(0, app(\App\Services\Mail\MailboxQuery::class)->unreadCount($reader));
        $this->assertSame(1, app(\App\Services\Mail\MailboxQuery::class)->unreadCount($other));
    }

    public function test_bcc_is_shown_only_to_the_person_who_sent_the_message(): void
    {
        $author = $this->mailAdmin();
        [$thread] = $this->longConversation($author->id);

        $this->signIn($author);
        Livewire::test(MessageReader::class, ['threadId' => $thread->id])->assertSee('quiet@example.test')->assertSee('only you can see this');

        $this->signIn($this->mailUser(['admin.email.view', 'admin.email.send']));
        Livewire::test(MessageReader::class, ['threadId' => $thread->id])->assertDontSee('quiet@example.test');
    }

    public function test_delivery_is_listed_per_recipient_in_the_architectures_words_and_never_claims_the_inbox(): void
    {
        $author = $this->mailAdmin();
        [$thread, , $reply] = $this->longConversation($author->id);
        $ledger = app(\App\Services\Communications\OutboundDeliveryLedger::class);
        $at = \Carbon\CarbonImmutable::now();
        $ledger->record($reply, 'vendor@example.test', 'delivered', 'newEmailSending', $at);
        $ledger->record($reply, 'copy@example.test', 'bounced', 'newEmailSending', $at, 'E_BOUNCE');
        $ledger->record($reply, 'quiet@example.test', 'deferred', 'newEmailSending', $at);
        $this->signIn($author);

        $html = Livewire::test(MessageReader::class, ['threadId' => $thread->id])
            ->assertSee('Accepted by receiving server')->assertSee('Bounced')->assertSee('Deferred')
            ->assertSee('cannot show which mailbox folder')
            ->html();

        $this->assertDoesNotMatchRegularExpression('/delivered to (the )?inbox/i', $html);
        foreach (\App\Support\Mail\MailPresentation::DELIVERY_STATUSES as $label) {
            $this->assertNotSame('', $label);
        }
        // A viewer sees the same labels for the recipients on the message, but never the Bcc row.
        $this->signIn($this->mailViewer());
        Livewire::test(MessageReader::class, ['threadId' => $thread->id])->assertSee('Bounced')->assertDontSee('quiet@example.test');
    }

    public function test_every_status_has_exactly_the_documented_wording(): void
    {
        $words = fn (string $status, ?string $code = null, ?string $type = null): string => \App\Support\Mail\MailPresentation::recipientLabel($status, $code, $type);

        $this->assertSame('Queued', $words('pending'));
        $this->assertSame('Submitted', $words('submitted'));
        $this->assertSame('Accepted by Cloudflare', $words('accepted'));
        $this->assertSame('Deferred', $words('deferred'));
        $this->assertSame('Accepted by receiving server', $words('delivered'));
        $this->assertSame('Failed', $words('failed'));
        $this->assertSame('Rejected', $words('rejected', 'INVALID'));
        $this->assertSame('Bounced', $words('bounced'));
        $this->assertSame('Suppressed', $words('rejected', 'E_RECIPIENT_SUPPRESSED'));
        $this->assertSame('Unconfirmed', $words('anything-else'));
    }

    public function test_message_content_is_never_printed_unescaped_and_html_goes_only_through_the_sandboxed_frame(): void
    {
        $email = $this->inbound(['subject' => 'Markup', 'text_body' => '<script>alert(1)</script> & <b>bold</b>', 'sanitized_html_body' => null]);
        $rich = $this->inbound(['subject' => 'Rich', 'text_body' => 'plain', 'sanitized_html_body' => '<p>Hello</p>']);
        $this->signIn($this->mailAdmin());

        $text = Livewire::test(MessageReader::class, ['threadId' => $this->threadOf($email)->id])->html();
        $this->assertStringNotContainsString('<script>alert(1)</script>', $text);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; &amp; &lt;b&gt;bold&lt;/b&gt;', $text);

        $html = Livewire::test(MessageReader::class, ['threadId' => $this->threadOf($rich)->id])->html();
        $this->assertStringContainsString('sandbox="allow-popups allow-popups-to-escape-sandbox"', $html);
        $this->assertStringContainsString("/admin/email/messages/inbound/{$rich->id}/body", $html);
        $this->assertStringNotContainsString('allow-scripts', $html);
        $this->assertStringNotContainsString('allow-same-origin', $html);
        $this->assertStringNotContainsString('<p>Hello</p>', $html, 'the HTML body is never inlined into the page');
    }

    public function test_no_email_center_view_uses_unescaped_output(): void
    {
        $files = array_merge(
            glob(resource_path('views/livewire/mail/*.blade.php')) ?: [],
            glob(resource_path('views/components/mail/*.blade.php')) ?: [],
            [resource_path('views/filament/admin/pages/email-center.blade.php'), resource_path('views/filament/admin/pages/email-center-health.blade.php')],
        );
        $this->assertNotEmpty($files);
        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            $this->assertStringNotContainsString('{!!', $source, basename($file));
            $this->assertStringNotContainsString('@php(print', $source, basename($file));
        }
    }

    public function test_attachments_and_parts_that_could_not_be_kept_are_listed(): void
    {
        $email = $this->inbound(['subject' => 'Files']);
        $path = 'inbound-email-attachments/'.hash('sha256', $email->provider_message_id).'/list.txt';
        Storage::disk('local')->put($path, 'abc');
        $email->forceFill([
            'attachment_metadata' => [['filename' => 'list.txt', 'mime_type' => 'text/plain', 'size' => 3, 'disk' => 'local', 'path' => $path]],
            'skipped_attachments' => [['filename' => 'logo.webp', 'mime_type' => 'image/webp', 'size' => 9, 'reason' => 'unsupported_type']],
        ])->save();
        $this->signIn($this->mailViewer());

        Livewire::test(MessageReader::class, ['threadId' => $this->threadOf($email)->id])
            ->assertSee('list.txt')
            ->assertSeeHtml("/admin/email/messages/inbound/{$email->id}/attachments/0")
            ->assertSee('could not be kept')->assertSee('logo.webp');
    }

    public function test_a_viewer_can_star_and_mark_unread_but_every_shared_action_is_refused_with_a_sentence(): void
    {
        [$thread] = $this->longConversation();
        $this->signIn($this->mailViewer());

        $reader = Livewire::test(MessageReader::class, ['threadId' => $thread->id])
            ->call('toggleStar')->assertSet('error', null)->assertSee('Starred.')
            ->call('markUnread')->assertSee('Marked as unread.')
            ->assertDontSee('data-mail-action="archive"', false);
        foreach (['archive', 'trash', 'restoreThread', 'togglePriority', 'snooze', 'addLabel', 'addNote'] as $action) {
            $reader->call($action)->assertSet('error', fn ($error) => is_string($error) && $error !== '');
        }

        $this->assertNull(MailThread::query()->findOrFail($thread->id)->archived_at);
        $this->assertNull(MailThread::query()->findOrFail($thread->id)->trashed_at);
        $this->assertSame(0, MailThreadNote::query()->count());
    }

    public function test_send_level_can_archive_label_assign_and_note_but_not_trash(): void
    {
        [$thread] = $this->longConversation();
        $sender = $this->mailUser(['admin.email.view', 'admin.email.send']);
        $this->signIn($sender);

        Livewire::test(MessageReader::class, ['threadId' => $thread->id])
            ->call('archive')->assertSee('Archived.')
            ->call('unarchive')->assertSee('Moved back to the Inbox.')
            ->call('togglePriority')->assertSee('Marked high priority.')
            ->set('labelName', 'Logistics')->call('addLabel')->assertSee('Logistics')
            ->call('removeLabel', 'Logistics')->assertSee('removed')
            ->call('assign', (string) $sender->id)->assertSee('Assigned to '.$sender->name)
            ->call('assign', '')->assertSee('Assignee removed')
            ->set('noteBody', 'Called the vendor.')->call('addNote')->assertSee('Called the vendor.')->assertSee('Internal only. Never sent.')
            ->call('trash')->assertSet('error', fn ($error) => is_string($error) && $error !== '');
        $this->assertNull(MailThread::query()->findOrFail($thread->id)->trashed_at);
    }

    public function test_manage_level_can_trash_and_restore(): void
    {
        [$thread] = $this->longConversation();
        $this->signIn($this->mailAdmin());

        Livewire::test(MessageReader::class, ['threadId' => $thread->id])
            ->call('trash')->assertSee('Moved to Trash.')->assertSee('In Trash')->assertDontSee('wire:click="compose(\'reply\')"', false)
            ->call('restoreThread')->assertSee('Restored from Trash.')->assertDontSee('In Trash');
    }

    public function test_automated_mail_is_read_only_at_every_level_including_a_super_administrator(): void
    {
        $automated = $this->outbound(['source_type' => 'password_reset', 'subject' => 'Reset', 'text_body' => 'x']);
        $thread = $this->threadOf($automated);
        $super = $this->mailUser([]);
        $super->assignRole(\Spatie\Permission\Models\Role::findOrCreate('super_admin', 'web'));
        $this->signIn($super->fresh());

        $reader = Livewire::test(MessageReader::class, ['threadId' => $thread->id])
            ->assertSee('Automated message')->assertSee('read-only')
            ->assertDontSeeHtml('data-mail-action="reply"')->assertDontSeeHtml('data-mail-action="archive"')->assertDontSeeHtml('mail-notes');
        foreach (['toggleStar', 'archive', 'trash', 'togglePriority', 'addNote'] as $action) {
            $reader->call($action)->assertSet('error', fn ($error) => is_string($error) && $error !== '');
        }
        $this->assertNull(MailThread::query()->findOrFail($thread->id)->archived_at);
    }

    public function test_a_missing_conversation_says_so_and_a_revoked_member_is_refused(): void
    {
        $user = $this->mailViewer();
        $this->signIn($user);
        Livewire::test(MessageReader::class, ['threadId' => 987654])->assertSee('could not be found');

        [$thread] = $this->longConversation();
        $reader = Livewire::test(MessageReader::class, ['threadId' => $thread->id]);
        $user->permissions()->detach();
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $reader->call('toggleStar')->assertForbidden();
    }

    public function test_the_history_describes_each_action_in_a_sentence(): void
    {
        [$thread] = $this->longConversation();
        $admin = $this->mailAdmin();
        $this->signIn($admin);

        $html = Livewire::test(MessageReader::class, ['threadId' => $thread->id])
            ->call('archive')->call('unarchive')->call('togglePriority')
            ->html();

        $this->assertStringContainsString($admin->name.' archived this conversation.', $html);
        $this->assertStringContainsString($admin->name.' moved this conversation back to the Inbox.', $html);
        $this->assertStringContainsString($admin->name.' set the priority to high.', $html);
    }
}
