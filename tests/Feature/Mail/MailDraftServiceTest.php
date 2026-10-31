<?php

declare(strict_types=1);

namespace Tests\Feature\Mail;

use App\Exceptions\MailDraftConflict;
use App\Models\InboundEmail;
use App\Models\Mail\MailDraft;
use App\Models\Mail\MailDraftAttachment;
use App\Models\Mail\MailThread;
use App\Models\User;
use App\Services\Mail\MailActionRefused;
use App\Services\Mail\MailboxActions;
use App\Services\Mail\MailDraftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Mail\Concerns\BuildsMailFixtures;
use Tests\Feature\Mail\Concerns\FakesMailSending;
use Tests\TestCase;

final class MailDraftServiceTest extends TestCase
{
    use BuildsMailFixtures;
    use FakesMailSending;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareSending();
    }

    private function drafts(): MailDraftService
    {
        return app(MailDraftService::class);
    }

    /** A real file on disk (not the framework's fake), so MIME detection reads the actual bytes. */
    private function realUpload(string $name, string $content, string $claimed = 'application/pdf'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'mailtest');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, $claimed, null, true);
    }

    private const PDF = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";

    // --- opening -----------------------------------------------------------------------------

    public function test_a_new_draft_starts_empty_and_private(): void
    {
        $user = $this->mailAdmin();

        $draft = $this->drafts()->open($user, 'new');

        self::assertSame($user->id, $draft->user_id);
        self::assertSame(['new', 'open', 1], [$draft->mode, $draft->state, $draft->version]);
        self::assertSame([], $draft->to);
        self::assertNull($draft->thread_id);
    }

    public function test_a_reply_prefills_recipient_subject_and_an_escaped_quote_and_is_reused(): void
    {
        $user = $this->mailAdmin();
        $email = $this->inbound([
            'from_address' => 'chief@example.test', 'from_display_name' => 'Chief Rivera', 'subject' => 'Budget',
            'text_body' => "Line one\n<script>alert(1)</script>", 'safe_headers' => ['to' => ['info@mbfdhub.com'], 'cc' => [], 'reply-to' => 'office@example.test'],
        ]);
        $thread = $this->threadOf($email);

        $draft = $this->drafts()->open($user, 'reply', $thread->id);

        self::assertSame(['office@example.test'], $draft->to, 'Reply-To wins over From');
        self::assertSame([], $draft->cc);
        self::assertSame('Re: Budget', $draft->subject);
        self::assertSame($thread->id, $draft->thread_id);
        self::assertSame(['inbound', $email->id], [$draft->source_kind, $draft->source_id]);
        self::assertStringContainsString('Chief Rivera', $draft->body_html);
        self::assertStringContainsString('Line one', $draft->body_html);
        self::assertStringNotContainsString('<script', $draft->body_html);
        self::assertStringContainsString('&lt;script&gt;', $draft->body_html);
        self::assertSame($draft->id, $this->drafts()->open($user, 'reply', $thread->id)->id, 'opening the same reply again resumes it');
        self::assertSame($email->id, $this->drafts()->open($user, 'reply', null, 'inbound', $email->id)->source_id);
    }

    public function test_re_is_added_once_and_reply_all_removes_the_hub_and_the_sender_case_insensitively(): void
    {
        $user = $this->mailAdmin();
        $email = $this->inbound([
            'from_address' => 'chief@example.test', 'subject' => 'RE: Budget', 'to_address' => 'info@mbfdhub.com',
            'safe_headers' => ['to' => ['INFO@mbfdhub.com', 'deputy@example.test'], 'cc' => ['Chief@Example.test', 'captain@example.test', 'deputy@example.test'], 'reply-to' => null],
        ]);

        $all = $this->drafts()->open($user, 'reply_all', $this->threadOf($email)->id);

        self::assertSame('RE: Budget', $all->subject);
        self::assertSame(['chief@example.test'], $all->to);
        self::assertEqualsCanonicalizing(['deputy@example.test', 'captain@example.test'], $all->cc);
    }

    public function test_reply_all_understands_names_with_commas_in_stored_headers(): void
    {
        $user = $this->mailAdmin();
        $email = $this->inbound([
            'from_address' => 'chief@example.test',
            'safe_headers' => ['to' => '"Doe, John" <john@example.test>, Mary <mary@example.test>', 'cc' => '', 'reply-to' => null],
        ]);

        $all = $this->drafts()->open($user, 'reply_all', $this->threadOf($email)->id);

        self::assertEqualsCanonicalizing(['john@example.test', 'mary@example.test'], $all->cc);
    }

    public function test_replying_to_a_message_we_sent_goes_to_those_we_wrote_to_and_never_revives_bcc(): void
    {
        $user = $this->mailAdmin();
        $sent = $this->outbound([
            'subject' => 'Plan', 'to_recipients' => ['one@example.test'], 'cc_recipients' => ['two@example.test'], 'bcc_recipients' => ['quiet@example.test'],
        ]);

        $all = $this->drafts()->open($user, 'reply_all', $this->threadOf($sent)->id);

        self::assertSame(['one@example.test'], $all->to);
        self::assertSame(['two@example.test'], $all->cc);
        self::assertSame([], $all->bcc);
        self::assertStringNotContainsString('quiet@example.test', json_encode($all->toArray(), JSON_THROW_ON_ERROR));
    }

    public function test_a_forward_has_no_recipients_the_original_text_and_fwd_once(): void
    {
        $user = $this->mailAdmin();
        $email = $this->inbound(['subject' => 'Fwd: Notice', 'text_body' => 'Original words here', 'from_address' => 'a@example.test']);

        $draft = $this->drafts()->open($user, 'forward', $this->threadOf($email)->id);

        self::assertSame([], $draft->to);
        self::assertSame('Fwd: Notice', $draft->subject);
        self::assertStringContainsString('Original words here', $draft->body_html);
        self::assertStringContainsString('Forwarded message', $draft->body_html);
        self::assertStringContainsString('a@example.test', html_entity_decode($draft->body_html));
    }

    public function test_opening_is_refused_for_automated_trashed_foreign_and_unanswerable_sources(): void
    {
        $user = $this->mailAdmin();
        $roster = $this->withoutIndexing(function (): InboundEmail {
            $email = $this->inbound(['from_address' => 'donotreply@miamibeachfl.gov']);
            $this->rosterLedgerFor($email);

            return $email;
        });
        $rosterThread = app(\App\Services\Mail\MailThreadIndexer::class)->indexInbound($roster);
        $systemThread = $this->threadOf($this->outbound(['source_type' => 'password_reset']));
        $trashed = $this->threadOf($this->inbound());
        app(MailboxActions::class)->trash($user, $trashed);
        $one = $this->inbound();
        $two = $this->inbound();
        // A reply to a notification type nobody has reviewed for quoting.
        $unreviewed = $this->outbound(['source_type' => 'App\\Notifications\\SomethingNew']);
        $unreviewedReply = $this->inbound(['in_reply_to' => $unreviewed->message_id, 'references' => [$unreviewed->message_id]]);

        foreach ([
            fn () => $this->drafts()->open($user, 'reply', $rosterThread->id),
            fn () => $this->drafts()->open($user, 'forward', $rosterThread->id),
            fn () => $this->drafts()->open($user, 'reply', $systemThread->id),
            fn () => $this->drafts()->open($user, 'reply', $trashed->id),
            fn () => $this->drafts()->open($user, 'reply', $this->threadOf($one)->id, 'inbound', $two->id),
            fn () => $this->drafts()->open($user, 'reply', $this->threadOf($unreviewed)->id, 'outbound', $unreviewed->id),
            fn () => $this->drafts()->open($user, 'reply', 999999),
            fn () => $this->drafts()->open($user, 'reply', null, 'inbound', 999999),
        ] as $attempt) {
            try {
                $attempt();
                self::fail('open() should have been refused');
            } catch (MailActionRefused $refused) {
                self::assertContains($refused->getStatusCode(), [403, 409]);
            }
        }
        foreach ([fn () => $this->drafts()->open($user, 'bogus'), fn () => $this->drafts()->open($user, 'reply')] as $attempt) {
            try {
                $attempt();
                self::fail('open() should have been refused');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertSame(0, MailDraft::query()->count());
        self::assertNotNull($unreviewedReply);
    }

    public function test_only_members_who_can_both_view_and_send_can_open_drafts(): void
    {
        $thread = $this->threadOf($this->inbound());
        foreach ([$this->mailViewer(), $this->mailUser(['admin.communications.send']), $this->mailUser([])] as $user) {
            try {
                $this->drafts()->open($user, 'new');
                self::fail('a draft was opened without access');
            } catch (MailActionRefused $refused) {
                self::assertSame(403, $refused->getStatusCode());
            }
            $this->expectsRefusal(fn () => $this->drafts()->open($user, 'reply', $thread->id));
        }
        self::assertSame(0, MailDraft::query()->count());
    }

    private function expectsRefusal(callable $call, int $status = 403): void
    {
        try {
            $call();
            self::fail('expected a refusal');
        } catch (MailActionRefused $refused) {
            self::assertSame($status, $refused->getStatusCode());
        }
    }

    // --- privacy -----------------------------------------------------------------------------

    public function test_a_draft_is_private_to_its_author_for_every_operation(): void
    {
        $alice = $this->mailAdmin();
        $bob = $this->mailAdmin();
        $draft = $this->drafts()->open($alice, 'new');
        $this->drafts()->save($alice, $draft, ['to' => ['x@example.test'], 'subject' => 'Private', 'body_html' => '<p>secret plan</p>'], 1);
        $attachment = $this->drafts()->addAttachment($alice, $draft->fresh(), $this->realUpload('plan.pdf', self::PDF));
        $before = $draft->fresh()->getAttributes();

        foreach ([
            fn () => $this->drafts()->save($bob, $draft, ['subject' => 'Hijacked'], 2),
            fn () => $this->drafts()->send($bob, $draft),
            fn () => $this->drafts()->discard($bob, $draft),
            fn () => $this->drafts()->addAttachment($bob, $draft, $this->realUpload('x.pdf', self::PDF)),
            fn () => $this->drafts()->removeAttachment($bob, $attachment),
        ] as $attempt) {
            $this->expectsRefusal($attempt, 404);
        }

        self::assertSame($before, $draft->fresh()->getAttributes());
        self::assertSame(1, MailDraftAttachment::query()->count());
        Storage::disk('local')->assertExists($attachment->path);
        self::assertSame(0, $this->providerCalls);
        self::assertCount(0, app(\App\Services\Mail\MailboxQuery::class)->drafts($bob));
    }

    // --- saving ------------------------------------------------------------------------------

    public function test_save_updates_fields_bumps_the_version_and_sanitizes_the_body(): void
    {
        $user = $this->mailAdmin();
        $draft = $this->drafts()->open($user, 'new');
        $this->travel(5)->seconds();

        $saved = $this->drafts()->save($user, $draft, [
            'to' => ['One@Example.test', 'one@example.test', ' ', 'Two <two@example.test>'],
            'cc' => ['cc@example.test'], 'bcc' => ['bcc@example.test'],
            'subject' => "Line\r\nBreak", 'body_html' => '<p>Hi</p><script>alert(1)</script><a href="javascript:alert(2)" onclick="x()">go</a>',
        ], 1);

        self::assertSame(2, $saved->version);
        self::assertSame(['One@Example.test', 'Two <two@example.test>'], $saved->to, 'raw entries are kept so the author can fix a typo; validity is checked at send');
        self::assertSame('Line Break', $saved->subject);
        self::assertStringContainsString('<p>Hi</p>', $saved->body_html);
        foreach (['<script', 'javascript:', 'onclick'] as $needle) {
            self::assertStringNotContainsString($needle, $saved->body_html);
        }
        self::assertGreaterThan($draft->updated_at, $saved->updated_at);
    }

    public function test_a_stale_save_throws_a_conflict_that_carries_the_current_draft(): void
    {
        $user = $this->mailAdmin();
        $draft = $this->drafts()->open($user, 'new');
        $this->drafts()->save($user, $draft, ['subject' => 'From tab one'], 1);

        try {
            $this->drafts()->save($user, $draft, ['subject' => 'From tab two'], 1);
            self::fail('a stale save must conflict');
        } catch (MailDraftConflict $conflict) {
            self::assertSame(1, $conflict->expectedVersion);
            self::assertSame(2, $conflict->current->version);
            self::assertSame('From tab one', $conflict->current->subject);
        }
        self::assertSame('From tab one', $draft->fresh()->subject, 'the losing save changed nothing');
    }

    public function test_save_validates_lengths_and_refuses_locked_drafts(): void
    {
        $user = $this->mailAdmin();
        $draft = $this->drafts()->open($user, 'new');

        foreach ([['subject' => str_repeat('s', 999)], ['to' => array_map(fn (int $i): string => "person{$i}@example.test", range(1, 51))], ['to' => [str_repeat('a', 321)]]] as $fields) {
            try {
                $this->drafts()->save($user, $draft, $fields, 1);
                self::fail('invalid fields were saved');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertSame(1, $draft->fresh()->version);

        $draft->forceFill(['state' => 'unconfirmed'])->save();
        $this->expectsRefusal(fn () => $this->drafts()->save($user, $draft, ['subject' => 'x'], 1), 409);
    }

    public function test_recipient_limit_is_the_smaller_of_the_message_and_per_minute_caps(): void
    {
        config()->set('communications.cloudflare.max_recipients_per_message', 10);
        config()->set('communications.cloudflare.max_recipient_units_per_minute', 5);
        self::assertSame(5, $this->drafts()->effectiveRecipientLimit());
        config()->set('communications.cloudflare.max_recipient_units_per_minute', 3);
        self::assertSame(3, $this->drafts()->effectiveRecipientLimit());
        config()->set('communications.cloudflare.max_recipients_per_message', 2);
        self::assertSame(2, $this->drafts()->effectiveRecipientLimit());
        config()->set('communications.cloudflare.max_recipients_per_message', 20);
        config()->set('communications.cloudflare.max_recipient_units_per_minute', 20);
        self::assertSame(5, $this->drafts()->effectiveRecipientLimit(), 'the cost guard hard-caps a minute at five');
    }

    // --- attachments -------------------------------------------------------------------------

    public function test_a_real_pdf_is_stored_privately_with_its_hash_and_a_safe_name(): void
    {
        $user = $this->mailAdmin();
        $draft = $this->drafts()->open($user, 'new');

        $attachment = $this->drafts()->addAttachment($user, $draft, $this->realUpload('..\\..\\evil/../plan.pdf', self::PDF, 'application/octet-stream'));

        self::assertSame('plan.pdf', $attachment->filename);
        self::assertSame('application/pdf', $attachment->mime_type, 'the detected type, not the claimed one');
        self::assertSame(strlen(self::PDF), $attachment->size);
        self::assertSame(hash('sha256', self::PDF), $attachment->sha256);
        self::assertStringStartsWith('mail-draft-attachments/'.$draft->id.'/', $attachment->path);
        self::assertStringNotContainsString('..', $attachment->path);
        self::assertSame(self::PDF, Storage::disk('local')->get($attachment->path));
    }

    public function test_spoofed_and_disallowed_files_are_refused_by_what_they_are_not_what_they_claim(): void
    {
        $user = $this->mailAdmin();
        $draft = $this->drafts()->open($user, 'new');
        $cases = [
            'html as pdf' => $this->realUpload('invoice.pdf', '<html><script>alert(1)</script></html>', 'application/pdf'),
            'svg as png' => $this->realUpload('logo.png', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>', 'image/png'),
            'exe as txt' => $this->realUpload('notes.txt', "MZ\x90\x00\x03\x00\x00\x00", 'text/plain'),
            'script as docx' => $this->realUpload('letter.docx', '#!/bin/sh'."\n".'rm -rf /', 'application/octet-stream'),
            'empty' => $this->realUpload('empty.txt', '', 'text/plain'),
        ];

        foreach ($cases as $label => $file) {
            try {
                $this->drafts()->addAttachment($user, $draft, $file);
                self::fail("{$label} was accepted");
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertSame(0, MailDraftAttachment::query()->count());
        self::assertSame([], Storage::disk('local')->allFiles('mail-draft-attachments'));
    }

    public function test_office_and_csv_files_are_accepted_when_their_signature_matches(): void
    {
        $user = $this->mailAdmin();
        $draft = $this->drafts()->open($user, 'new');

        $docx = $this->drafts()->addAttachment($user, $draft, $this->realUpload('letter.docx', "PK\x03\x04".str_repeat("\0", 64), 'application/octet-stream'));
        $csv = $this->drafts()->addAttachment($user, $draft, $this->realUpload('rows.csv', "a,b\n1,2\n", 'application/vnd.ms-excel'));

        self::assertSame('application/vnd.openxmlformats-officedocument.wordprocessingml.document', $docx->mime_type);
        self::assertSame('text/csv', $csv->mime_type);
    }

    public function test_count_and_total_size_limits_apply_and_the_cap_leaves_room_for_base64(): void
    {
        $user = $this->mailAdmin();
        $draft = $this->drafts()->open($user, 'new');
        config()->set('communications.cloudflare.max_attachments', 2);
        $this->drafts()->addAttachment($user, $draft, $this->realUpload('a.txt', 'one', 'text/plain'));
        $this->drafts()->addAttachment($user, $draft, $this->realUpload('b.txt', 'two', 'text/plain'));
        try {
            $this->drafts()->addAttachment($user, $draft, $this->realUpload('c.txt', 'three', 'text/plain'));
            self::fail('a third file was accepted');
        } catch (ValidationException $e) {
            self::assertStringContainsString('at most 2', $e->getMessage());
        }

        config()->set('communications.cloudflare.max_attachments', 5);
        config()->set('communications.cloudflare.max_attachment_bytes', 3500000);
        config()->set('communications.cloudflare.max_message_bytes', 4500000);
        self::assertLessThan(3500000, $this->drafts()->attachmentByteCap(), 'base64 grows files by a third; 3.5 MB of files cannot fit a 4.5 MB message');
        self::assertLessThanOrEqual((int) floor((4500000 - 65536) * 3 / 4), $this->drafts()->attachmentByteCap());
        config()->set('communications.cloudflare.max_attachment_bytes', 10);
        try {
            $this->drafts()->addAttachment($user, $draft, $this->realUpload('big.txt', str_repeat('x', 11), 'text/plain'));
            self::fail('an oversize file was accepted');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_remove_and_discard_delete_the_files(): void
    {
        $user = $this->mailAdmin();
        $draft = $this->drafts()->open($user, 'new');
        $one = $this->drafts()->addAttachment($user, $draft, $this->realUpload('a.txt', 'one', 'text/plain'));
        $two = $this->drafts()->addAttachment($user, $draft, $this->realUpload('b.txt', 'two', 'text/plain'));

        $this->drafts()->removeAttachment($user, $one);
        Storage::disk('local')->assertMissing($one->path);
        Storage::disk('local')->assertExists($two->path);

        $this->drafts()->discard($user, $draft);
        Storage::disk('local')->assertMissing($two->path);
        self::assertSame(0, MailDraft::query()->count());
        self::assertSame(0, MailDraftAttachment::query()->count());
    }

    public function test_forwarding_copies_only_the_files_chosen_and_never_modifies_the_originals(): void
    {
        $user = $this->mailAdmin();
        $email = $this->inbound(['subject' => 'Docs']);
        $dir = 'inbound-email-attachments/'.hash('sha256', $email->provider_message_id).'/';
        Storage::disk('local')->put($dir.'a.txt', 'first file');
        Storage::disk('local')->put($dir.'b.txt', 'second file');
        $email->forceFill(['attachment_metadata' => [
            ['filename' => 'first.txt', 'mime_type' => 'text/plain', 'size' => 10, 'disk' => 'local', 'path' => $dir.'a.txt'],
            ['filename' => 'second.txt', 'mime_type' => 'text/plain', 'size' => 11, 'disk' => 'local', 'path' => $dir.'b.txt'],
        ]])->save();
        $draft = $this->drafts()->open($user, 'forward', $this->threadOf($email)->id);
        self::assertCount(0, $draft->attachments, 'nothing is forwarded until chosen');

        $copy = $this->drafts()->addForwardedAttachment($user, $draft, 1);
        $again = $this->drafts()->addForwardedAttachment($user, $draft, 1);

        self::assertSame($copy->id, $again->id, 'choosing the same file twice adds it once');
        self::assertSame('second.txt', $copy->filename);
        self::assertSame('second file', Storage::disk('local')->get($copy->path));
        self::assertStringStartsWith('mail-draft-attachments/', $copy->path);
        self::assertSame('first file', Storage::disk('local')->get($dir.'a.txt'));
        self::assertSame('second file', Storage::disk('local')->get($dir.'b.txt'));
        self::assertCount(1, $draft->fresh()->attachments);
        $this->expectException(ValidationException::class);
        $this->drafts()->addForwardedAttachment($user, $draft, 7);
    }

    public function test_forwarded_attachments_only_work_on_a_forward(): void
    {
        $user = $this->mailAdmin();
        $email = $this->inbound();
        $reply = $this->drafts()->open($user, 'reply', $this->threadOf($email)->id);

        $this->expectException(ValidationException::class);
        $this->drafts()->addForwardedAttachment($user, $reply, 0);
    }

    // --- misc ---------------------------------------------------------------------------------

    public function test_discarding_a_draft_that_is_being_sent_is_refused(): void
    {
        $user = $this->mailAdmin();
        $draft = $this->drafts()->open($user, 'new');
        $draft->forceFill(['state' => 'sending'])->save();

        $this->expectsRefusal(fn () => $this->drafts()->discard($user, $draft), 409);
        self::assertSame(1, MailDraft::query()->count());
    }

    public function test_threads_are_unaffected_by_drafting(): void
    {
        $user = $this->mailAdmin();
        $thread = $this->threadOf($this->inbound());
        $this->drafts()->open($user, 'reply', $thread->id);

        self::assertSame(1, MailThread::query()->count());
        self::assertSame(1, $thread->fresh()->message_count);
        self::assertInstanceOf(User::class, $user);
    }
}
