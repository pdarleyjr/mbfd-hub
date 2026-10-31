<?php

declare(strict_types=1);

namespace Tests\Feature\Mail;

use App\Models\Mail\MailDraft;
use App\Models\Mail\MailThread;
use App\Models\OutboundEmail;
use App\Services\Mail\MailActionRefused;
use App\Services\Mail\MailboxActions;
use App\Services\Mail\MailDraftService;
use App\Services\Mail\MailIndexSweeper;
use App\Services\Mail\MailSendResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Mail\Concerns\BuildsMailFixtures;
use Tests\Feature\Mail\Concerns\FakesMailSending;
use Tests\TestCase;

final class MailDraftSendTest extends TestCase
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

    /** @param array<string, mixed> $fields */
    private function readyDraft(\App\Models\User $user, array $fields = []): MailDraft
    {
        $draft = $this->drafts()->open($user, 'new');

        return $this->drafts()->save($user, $draft, $fields + [
            'to' => ['recipient@example.test'], 'subject' => 'Hydrant schedule', 'body_html' => '<p>Please see <strong>attached</strong> plan.</p>',
        ], 1);
    }

    public function test_a_new_message_is_sent_through_the_dispatcher_and_recorded(): void
    {
        $user = $this->mailAdmin();
        $draft = $this->readyDraft($user, ['cc' => ['cc@example.test'], 'bcc' => ['quiet@example.test'], 'body_html' => '<p>Hello <strong>team</strong></p><script>alert(1)</script>']);

        $result = $this->drafts()->send($user, $draft);

        self::assertSame(MailSendResult::ACCEPTED, $result->status);
        self::assertTrue($result->isAccepted());
        $payload = $this->lastProviderPayload();
        self::assertSame('info@mbfdhub.com', is_array($payload['from']) ? $payload['from']['address'] : $payload['from']);
        self::assertSame(['recipient@example.test'], $payload['to']);
        self::assertSame(['cc@example.test'], $payload['cc']);
        self::assertSame(['quiet@example.test'], $payload['bcc']);
        self::assertSame('Hydrant schedule', $payload['subject']);
        self::assertSame('Hello team', $payload['text'], 'an accurate text alternative');
        self::assertStringContainsString('<strong>team</strong>', $payload['html']);
        self::assertStringNotContainsString('<script', $payload['html']);
        self::assertArrayNotHasKey('headers', $payload);

        $email = OutboundEmail::query()->sole();
        self::assertSame($email->id, $result->outbound->id);
        self::assertSame('admin_compose', $email->source_type);
        self::assertSame('draft:'.$draft->id, $email->source_id);
        self::assertSame($user->id, $email->initiated_by_user_id);
        self::assertNull($email->parent_inbound_email_id);
        $fresh = $draft->fresh();
        self::assertSame(['sent', $email->id], [$fresh->state, $fresh->outbound_email_id]);
        self::assertNotNull($fresh->thread_id);
        self::assertSame('Hydrant schedule', MailThread::query()->findOrFail($fresh->thread_id)->subject);
    }

    public function test_a_reply_threads_with_the_original_carries_headers_and_never_reopens(): void
    {
        $user = $this->mailAdmin();
        $original = $this->inbound(['subject' => 'Question', 'text_body' => 'Can we meet?']);
        $thread = $this->threadOf($original);
        app(MailboxActions::class)->archive($user, $thread);
        $draft = $this->drafts()->open($user, 'reply', $thread->id);
        $draft = $this->drafts()->save($user, $draft, ['body_html' => '<p>Yes, Tuesday.</p>'], 1);

        $result = $this->drafts()->send($user, $draft);

        self::assertTrue($result->isAccepted());
        $payload = $this->lastProviderPayload();
        self::assertSame($original->provider_message_id, $payload['headers']['In-Reply-To']);
        self::assertStringEndsWith($original->provider_message_id, $payload['headers']['References']);
        self::assertSame('Re: Question', $payload['subject']);
        $email = OutboundEmail::query()->sole();
        self::assertSame(['admin_reply', $original->id], [$email->source_type, $email->parent_inbound_email_id]);
        self::assertEquals($thread->id, $this->threadOf($email)->id);
        self::assertSame(2, $thread->fresh()->message_count);
        self::assertNotNull($thread->fresh()->archived_at, 'sending never reopens a conversation');
    }

    public function test_a_forward_is_its_own_conversation_with_no_headers_and_only_chosen_files(): void
    {
        $user = $this->mailAdmin();
        $original = $this->inbound(['subject' => 'Plans', 'text_body' => 'See the plan']);
        $dir = 'inbound-email-attachments/'.hash('sha256', $original->provider_message_id).'/';
        Storage::disk('local')->put($dir.'p.txt', 'plan file');
        Storage::disk('local')->put($dir.'q.txt', 'other file');
        $original->forceFill(['attachment_metadata' => [
            ['filename' => 'plan.txt', 'mime_type' => 'text/plain', 'size' => 9, 'disk' => 'local', 'path' => $dir.'p.txt'],
            ['filename' => 'other.txt', 'mime_type' => 'text/plain', 'size' => 10, 'disk' => 'local', 'path' => $dir.'q.txt'],
        ]])->save();
        $draft = $this->drafts()->open($user, 'forward', $this->threadOf($original)->id);
        $draft = $this->drafts()->save($user, $draft, ['to' => ['colleague@example.test']], 1);
        $this->drafts()->addForwardedAttachment($user, $draft, 0);

        $result = $this->drafts()->send($user, $draft);

        self::assertTrue($result->isAccepted());
        $payload = $this->lastProviderPayload();
        self::assertArrayNotHasKey('headers', $payload);
        self::assertSame('Fwd: Plans', $payload['subject']);
        self::assertStringContainsString('See the plan', $payload['text']);
        self::assertCount(1, $payload['attachments']);
        self::assertSame('plan.txt', $payload['attachments'][0]['filename']);
        self::assertSame('plan file', base64_decode($payload['attachments'][0]['content']));
        $email = OutboundEmail::query()->sole();
        self::assertSame(['admin_forward', $original->id], [$email->source_type, $email->parent_inbound_email_id]);
        self::assertNotEquals($this->threadOf($original)->id, $this->threadOf($email)->id);
        self::assertSame([], Storage::disk('local')->allFiles('mail-draft-attachments'), 'the draft copies are gone once sent');
        self::assertSame('plan file', Storage::disk('local')->get($dir.'p.txt'));
    }

    public function test_uploaded_attachments_are_sent_and_the_draft_copies_removed(): void
    {
        $user = $this->mailAdmin();
        $draft = $this->readyDraft($user);
        $path = tempnam(sys_get_temp_dir(), 'mailtest');
        file_put_contents($path, "%PDF-1.4\n%%EOF\n");
        $this->drafts()->addAttachment($user, $draft, new UploadedFile($path, 'plan.pdf', 'application/pdf', null, true));

        $result = $this->drafts()->send($user, $draft->fresh());

        self::assertTrue($result->isAccepted());
        $payload = $this->lastProviderPayload();
        self::assertSame('plan.pdf', $payload['attachments'][0]['filename']);
        self::assertSame('application/pdf', $payload['attachments'][0]['type']);
        self::assertSame("%PDF-1.4\n%%EOF\n", base64_decode($payload['attachments'][0]['content']));
        self::assertSame([], \App\Models\Mail\MailDraftAttachment::query()->get()->all());
        self::assertCount(1, OutboundEmail::query()->sole()->attachment_metadata);
    }

    public function test_sending_twice_sends_once(): void
    {
        $user = $this->mailAdmin();
        $draft = $this->readyDraft($user);

        $first = $this->drafts()->send($user, $draft);
        $second = $this->drafts()->send($user, $draft);
        $third = $this->drafts()->send($user, $draft->fresh());

        self::assertTrue($first->isAccepted());
        self::assertTrue($second->isAccepted());
        self::assertTrue($third->isAccepted());
        self::assertSame($first->outbound->id, $second->outbound->id);
        self::assertSame(1, $this->providerCalls);
        self::assertSame(1, OutboundEmail::query()->count());
    }

    public function test_a_draft_already_being_sent_is_not_sent_again(): void
    {
        $user = $this->mailAdmin();
        $draft = $this->readyDraft($user);
        $draft->forceFill(['state' => 'sending'])->save(); // the first click is mid-flight

        $result = $this->drafts()->send($user, $draft);

        self::assertTrue($result->isRefused());
        self::assertStringContainsString('already being sent', $result->message);
        self::assertSame(0, $this->providerCalls);
        self::assertSame(0, OutboundEmail::query()->count());
        self::assertSame('sending', $draft->fresh()->state);
    }

    public function test_the_existing_ledger_guard_stops_a_second_send_of_the_same_draft_even_if_the_draft_row_was_reopened(): void
    {
        $user = $this->mailAdmin();
        $draft = $this->readyDraft($user);
        $this->outbound(['source_type' => 'admin_compose', 'source_id' => 'draft:'.$draft->id, 'status' => 'acceptance_unknown', 'accepted_at' => null, 'message_id' => null, 'provider_message_id' => null]);

        $result = $this->drafts()->send($user, $draft);

        self::assertTrue($result->isUnconfirmed());
        self::assertSame(0, $this->providerCalls);
        self::assertSame(1, OutboundEmail::query()->count());
        self::assertSame('unconfirmed', $draft->fresh()->state);
    }

    public function test_a_legacy_reply_with_the_same_number_does_not_block_a_new_draft(): void
    {
        $user = $this->mailAdmin();
        $draft = $this->readyDraft($user);
        // ComposeEmail stored the original message id as source_id; the draft key is namespaced so they cannot collide.
        $this->outbound(['source_type' => 'admin_reply', 'source_id' => (string) $draft->id]);

        self::assertTrue($this->drafts()->send($user, $draft)->isAccepted());
        self::assertSame(1, $this->providerCalls);
    }

    public function test_a_budget_pause_defers_and_keeps_the_draft_open_then_a_retry_goes_through(): void
    {
        $user = $this->mailAdmin();
        $draft = $this->readyDraft($user);
        $this->staleBudget();

        $result = $this->drafts()->send($user, $draft);

        self::assertTrue($result->isDeferred());
        self::assertSame(300, $result->retryAfterSeconds);
        self::assertStringContainsString('Nothing was sent', $result->message);
        self::assertSame('open', $draft->fresh()->state);
        self::assertSame(0, $this->providerCalls);
        self::assertSame('failed_pre_acceptance', OutboundEmail::query()->sole()->status);

        $this->openBudget();
        $retry = $this->drafts()->send($user, $draft->fresh());

        self::assertTrue($retry->isAccepted());
        self::assertSame(1, $this->providerCalls);
        self::assertSame(2, OutboundEmail::query()->count(), 'the released attempt stays in the ledger as history');
    }

    public function test_an_ambiguous_provider_answer_is_unconfirmed_locks_the_draft_and_is_never_retried(): void
    {
        $user = $this->mailAdmin();
        $draft = $this->readyDraft($user);
        $this->providerFailsAmbiguously();

        $result = $this->drafts()->send($user, $draft);

        self::assertTrue($result->isUnconfirmed(), $result->status.': '.$result->message);
        self::assertSame(1, $this->providerCalls);
        $email = OutboundEmail::query()->sole();
        self::assertSame($email->id, $result->outbound->id);
        self::assertSame('acceptance_unknown', $email->status);
        $fresh = $draft->fresh();
        self::assertSame(['unconfirmed', $email->id], [$fresh->state, $fresh->outbound_email_id]);

        $this->providerRecovers();
        $again = $this->drafts()->send($user, $fresh);
        self::assertTrue($again->isUnconfirmed(), 'a second press does not resend');
        self::assertSame(0, $this->providerCalls);
        self::assertSame(1, OutboundEmail::query()->count());
        $this->expectException(MailActionRefused::class);
        $this->drafts()->save($user, $fresh, ['subject' => 'Edited after the fact'], $fresh->version);
    }

    public function test_a_definite_provider_refusal_says_nothing_was_sent_keeps_the_draft_open_and_can_be_retried(): void
    {
        $user = $this->mailAdmin();
        $draft = $this->readyDraft($user);
        $this->providerRefuses(422, 'E_RECIPIENT_SUPPRESSED');

        $result = $this->drafts()->send($user, $draft);

        self::assertSame(MailSendResult::REFUSED, $result->status, $result->message);
        self::assertStringContainsString('nothing was sent', $result->message);
        self::assertStringContainsString('suppression list', $result->message);
        self::assertStringNotContainsString('Private provider context', $result->message);
        self::assertSame('open', $draft->fresh()->state);
        self::assertSame('rejected', OutboundEmail::query()->sole()->status);

        $this->providerRecovers();
        $fixed = $this->drafts()->save($user, $draft->fresh(), ['to' => ['other@example.test']], (int) $draft->fresh()->version);
        $again = $this->drafts()->send($user, $fixed);

        self::assertTrue($again->isAccepted(), $again->status.': '.$again->message);
        self::assertSame(1, $this->providerCalls);
        self::assertSame(2, OutboundEmail::query()->count());
    }

    public function test_a_refused_sending_permission_says_to_ask_an_administrator(): void
    {
        $user = $this->mailAdmin();
        $draft = $this->readyDraft($user);
        $this->providerRefuses(403, '10000');

        $result = $this->drafts()->send($user, $draft);

        self::assertSame(MailSendResult::REFUSED, $result->status, $result->message);
        self::assertStringContainsString('Ask an administrator', $result->message);
        self::assertSame('open', $draft->fresh()->state);
    }

    public function test_an_unconfirmed_draft_can_be_discarded_after_the_author_checks_sent(): void
    {
        $user = $this->mailAdmin();
        $draft = $this->readyDraft($user);
        $this->providerFailsAmbiguously();
        $this->drafts()->send($user, $draft);

        $this->drafts()->discard($user, $draft->fresh());

        self::assertSame(0, MailDraft::query()->count());
        self::assertSame(1, OutboundEmail::query()->count(), 'the ledger row is history and stays');
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function refusals(): array
    {
        return [
            'no recipient' => [['to' => []], 'at least one recipient'],
            'invalid address' => [['to' => ['not-an-address']], 'not a valid email address'],
            'invalid cc' => [['cc' => ['x@']], 'not a valid email address'],
            'six people' => [['to' => ['a@example.test', 'b@example.test', 'c@example.test'], 'cc' => ['d@example.test', 'e@example.test'], 'bcc' => ['f@example.test']], 'Department Updates'],
            'no subject' => [['subject' => '  '], 'Add a subject'],
            'invisible subject' => [['subject' => "\u{200B}\u{2060} \u{200B}"], 'Add a subject'],
            'empty body' => [['body_html' => '<p> </p><script>alert(1)</script>'], 'Write a message'],
        ];
    }

    /** @param array<string, mixed> $fields */
    #[\PHPUnit\Framework\Attributes\DataProvider('refusals')]
    public function test_an_unready_draft_is_refused_with_a_readable_reason_and_stays_open(array $fields, string $reason): void
    {
        $user = $this->mailAdmin();
        $draft = $this->readyDraft($user);
        $draft = $this->drafts()->save($user, $draft, $fields, $draft->version);

        $result = $this->drafts()->send($user, $draft);

        self::assertTrue($result->isRefused());
        self::assertStringContainsString($reason, $result->message);
        self::assertSame('open', $draft->fresh()->state);
        self::assertSame(0, $this->providerCalls);
        self::assertSame(0, OutboundEmail::query()->count());
    }

    public function test_the_refusal_for_too_many_people_names_the_effective_limit(): void
    {
        config()->set('communications.cloudflare.max_recipient_units_per_minute', 3);
        $user = $this->mailAdmin();
        $draft = $this->readyDraft($user, ['to' => ['a@example.test', 'b@example.test'], 'cc' => ['c@example.test', 'd@example.test']]);

        $result = $this->drafts()->send($user, $draft);

        self::assertTrue($result->isRefused());
        self::assertStringContainsString('at most 3 people', $result->message);
        self::assertStringContainsString('Department Updates', $result->message);
    }

    public function test_recipients_are_deduplicated_across_fields_before_counting(): void
    {
        $user = $this->mailAdmin();
        $draft = $this->readyDraft($user, ['to' => ['A@example.test', 'a@example.test'], 'cc' => ['a@example.test', 'b@example.test'], 'bcc' => ['B@example.test', 'c@example.test', 'd@example.test', 'e@example.test']]);

        self::assertTrue($this->drafts()->send($user, $draft)->isAccepted());
        $payload = $this->lastProviderPayload();
        self::assertSame(['a@example.test'], $payload['to']);
        self::assertSame(['b@example.test'], $payload['cc']);
        self::assertSame(['c@example.test', 'd@example.test', 'e@example.test'], $payload['bcc']);
    }

    public function test_an_oversize_message_is_refused_before_it_reaches_the_provider(): void
    {
        $user = $this->mailAdmin();
        config()->set('communications.cloudflare.max_message_bytes', 70000);
        $draft = $this->readyDraft($user, ['body_html' => '<p>'.str_repeat('word ', 2000).'</p>']);
        config()->set('communications.cloudflare.max_message_bytes', 66000);

        $result = $this->drafts()->send($user, $draft);

        self::assertTrue($result->isRefused());
        self::assertStringContainsString('too large', $result->message);
        self::assertSame('open', $draft->fresh()->state);
    }

    public function test_a_missing_attachment_file_is_a_clear_refusal_not_a_crash(): void
    {
        $user = $this->mailAdmin();
        $draft = $this->readyDraft($user);
        $path = tempnam(sys_get_temp_dir(), 'mailtest');
        file_put_contents($path, 'text');
        $attachment = $this->drafts()->addAttachment($user, $draft, new UploadedFile($path, 'a.txt', 'text/plain', null, true));
        Storage::disk('local')->delete($attachment->path);

        $result = $this->drafts()->send($user, $draft->fresh());

        self::assertTrue($result->isRefused());
        self::assertStringContainsString('no longer available', $result->message);
        self::assertSame('open', $draft->fresh()->state);
    }

    public function test_sending_requires_view_and_send_access_at_the_moment_of_sending(): void
    {
        $user = $this->mailAdmin();
        $draft = $this->readyDraft($user);
        $user->permissions()->detach(); // the capability is revoked after the draft was written
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        try {
            $this->drafts()->send($user->fresh(), $draft);
            self::fail('send worked after the capability was revoked');
        } catch (MailActionRefused $refused) {
            self::assertSame(403, $refused->getStatusCode());
        }
        self::assertSame(0, $this->providerCalls);
        self::assertSame('open', $draft->fresh()->state);
    }

    public function test_a_reply_to_a_message_that_cleanup_deleted_is_refused_and_keeps_the_draft(): void
    {
        $user = $this->mailAdmin();
        $original = $this->inbound();
        $draft = $this->drafts()->open($user, 'reply', $this->threadOf($original)->id);
        $draft = $this->drafts()->save($user, $draft, ['body_html' => '<p>Thanks</p>'], 1);
        $original->delete();

        $result = $this->drafts()->send($user, $draft);

        self::assertTrue($result->isRefused());
        self::assertStringContainsString('no longer available', $result->message);
        self::assertSame('open', $draft->fresh()->state);
        self::assertSame(0, $this->providerCalls);
    }

    public function test_a_reply_is_refused_when_its_conversation_was_trashed_after_the_draft_was_written(): void
    {
        $user = $this->mailAdmin();
        $original = $this->inbound();
        $thread = $this->threadOf($original);
        $draft = $this->drafts()->open($user, 'reply', $thread->id);
        app(MailboxActions::class)->trash($user, $thread);

        $result = $this->drafts()->send($user, $this->drafts()->save($user, $draft, ['body_html' => '<p>Hi</p>'], 1));

        self::assertTrue($result->isRefused());
        self::assertSame(0, $this->providerCalls);
    }

    public function test_notes_can_never_reach_an_outbound_message(): void
    {
        $user = $this->mailAdmin();
        $original = $this->inbound(['subject' => 'Contract']);
        $thread = $this->threadOf($original);
        app(MailboxActions::class)->addNote($user, $thread, 'NOTE-MARKER-4471 do not tell them the budget');
        $draft = $this->drafts()->open($user, 'reply', $thread->id);
        $draft = $this->drafts()->save($user, $draft, ['body_html' => '<p>Thank you.</p>'], 1);

        $this->drafts()->send($user, $draft);

        self::assertStringNotContainsString('NOTE-MARKER-4471', json_encode($this->lastProviderPayload(), JSON_THROW_ON_ERROR));
        $row = OutboundEmail::query()->sole();
        self::assertStringNotContainsString('NOTE-MARKER-4471', json_encode($row->getAttributes(), JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('NOTE-MARKER-4471', json_encode($draft->fresh()->getAttributes(), JSON_THROW_ON_ERROR));
        Http::assertSentCount(1);
    }

    public function test_no_code_that_builds_outbound_content_names_the_notes_model_or_table(): void
    {
        $builders = ['MailDraftService', 'MailComposer', 'MailSendResult', 'MailHtmlSanitizer', 'MailText', 'AddressParser', 'MailAttachmentLocator'];
        foreach ($builders as $class) {
            $source = (string) file_get_contents(base_path("app/Services/Mail/{$class}.php"));
            foreach (['MailThreadNote', 'mail_thread_notes', '->notes(', 'notes()'] as $needle) {
                self::assertStringNotContainsString($needle, $source, "{$class} must not read thread notes");
            }
        }
    }

    public function test_nothing_in_the_send_path_logs_addresses_subjects_or_bodies(): void
    {
        $user = $this->mailAdmin();
        $draft = $this->readyDraft($user, ['to' => ['private.person@leak.test'], 'subject' => 'Confidential subject', 'body_html' => '<p>Confidential body</p>']);
        $this->providerFailsAmbiguously();
        Log::spy();

        $this->drafts()->send($user, $draft);

        Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context = []): bool {
            $flat = json_encode([$message, $context]);

            return $message === 'mail_draft_send_problem'
                && ! str_contains($flat, 'leak.test') && ! str_contains($flat, 'Confidential');
        })->atLeast()->once();
    }

    public function test_the_sweep_settles_a_draft_stuck_in_sending_from_the_ledger_and_never_resends(): void
    {
        $user = $this->mailAdmin();
        $crashedBeforeLedger = $this->readyDraft($user);
        $crashedAfterAccept = $this->readyDraft($user);
        $crashedUnknown = $this->readyDraft($user);
        foreach ([$crashedBeforeLedger, $crashedAfterAccept, $crashedUnknown] as $draft) {
            $draft->forceFill(['state' => 'sending'])->save();
        }
        $accepted = $this->outbound(['source_id' => 'draft:'.$crashedAfterAccept->id]);
        $unknown = $this->outbound(['source_id' => 'draft:'.$crashedUnknown->id, 'status' => 'acceptance_unknown', 'accepted_at' => null, 'message_id' => null, 'provider_message_id' => null]);
        MailDraft::query()->update(['updated_at' => now()->subMinutes(30)]);

        $stats = app(MailIndexSweeper::class)->run();

        self::assertSame(3, $stats['drafts_settled']);
        self::assertSame('open', $crashedBeforeLedger->fresh()->state);
        self::assertSame(['sent', $accepted->id], [$crashedAfterAccept->fresh()->state, $crashedAfterAccept->fresh()->outbound_email_id]);
        self::assertSame(['unconfirmed', $unknown->id], [$crashedUnknown->fresh()->state, $crashedUnknown->fresh()->outbound_email_id]);
        self::assertSame(0, $this->providerCalls);
    }

    public function test_the_sweep_reopens_a_stuck_draft_the_provider_never_took_and_removes_sent_draft_files(): void
    {
        $user = $this->mailAdmin();
        $neverTaken = $this->readyDraft($user);
        $crashedAfterAccept = $this->readyDraft($user);
        foreach ([$neverTaken, $crashedAfterAccept] as $draft) {
            $draft->forceFill(['state' => 'sending'])->save();
        }
        $this->outbound(['source_id' => 'draft:'.$neverTaken->id, 'status' => 'failed_pre_acceptance', 'accepted_at' => null, 'message_id' => null, 'provider_message_id' => null]);
        $this->outbound(['source_id' => 'draft:'.$crashedAfterAccept->id]);
        Storage::disk('local')->put('mail-draft-attachments/kept.txt', 'upload');
        $file = $crashedAfterAccept->attachments()->create(['filename' => 'kept.txt', 'mime_type' => 'text/plain', 'size' => 6, 'disk' => 'local', 'path' => 'mail-draft-attachments/kept.txt', 'sha256' => hash('sha256', 'upload')]);
        MailDraft::query()->update(['updated_at' => now()->subMinutes(30)]);

        app(MailIndexSweeper::class)->run();

        self::assertSame('open', $neverTaken->fresh()->state);
        self::assertSame('sent', $crashedAfterAccept->fresh()->state);
        self::assertFalse(Storage::disk('local')->exists($file->path));
    }

    public function test_a_draft_that_is_only_a_little_stuck_is_left_alone(): void
    {
        $user = $this->mailAdmin();
        $draft = $this->readyDraft($user);
        $draft->forceFill(['state' => 'sending'])->save();

        app(MailIndexSweeper::class)->run();

        self::assertSame('sending', $draft->fresh()->state);
    }
}
