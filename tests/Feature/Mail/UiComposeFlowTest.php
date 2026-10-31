<?php

declare(strict_types=1);

namespace Tests\Feature\Mail;

use App\Livewire\Mail\MessageComposer;
use App\Models\Mail\MailDraft;
use App\Models\Mail\MailThreadNote;
use App\Models\OutboundEmail;
use App\Services\Mail\MailboxActions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Feature\Mail\Concerns\FakesMailSending;
use Tests\Feature\Mail\Concerns\UsesEmailCenterUi;
use Tests\TestCase;

/** Writing and sending through the composer, with the provider faked so the real dispatcher and cost guard run. */
final class UiComposeFlowTest extends TestCase
{
    use FakesMailSending;
    use RefreshDatabase;
    use UsesEmailCenterUi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootEmailCenterUi();
        $this->prepareSending();
    }

    private function signature(): array
    {
        return ['Email Center Admin', 'Captain', 'Miami Beach Fire Department'];
    }

    private function writer(): \App\Models\User
    {
        $user = $this->mailAdmin();
        $user->forceFill(['name' => 'Email Center Admin', 'rank' => 'Captain'])->save();

        return $user->fresh();
    }

    public function test_a_new_message_opens_a_draft_with_an_editable_signature_and_tells_the_page(): void
    {
        $user = $this->writer();
        $this->signIn($user);

        $composer = Livewire::test(MessageComposer::class)->assertDispatched('mail-draft-opened');

        $draft = MailDraft::query()->sole();
        $this->assertSame($user->id, (int) $draft->user_id);
        $this->assertSame('new', $draft->mode);
        foreach ($this->signature() as $line) {
            $this->assertStringContainsString($line, (string) $draft->body_html);
        }
        $composer->assertSet('draftId', $draft->id)->assertSee('Up to 5 files');
    }

    public function test_reopening_does_not_pile_up_blank_drafts_or_sign_twice(): void
    {
        $this->signIn($this->writer());

        Livewire::test(MessageComposer::class);
        Livewire::test(MessageComposer::class);

        $this->assertSame(1, MailDraft::query()->count());
        $this->assertSame(1, substr_count((string) MailDraft::query()->sole()->body_html, 'Miami Beach Fire Department'));
    }

    public function test_a_reply_prefills_recipients_subject_and_quote_but_never_reads_internal_notes(): void
    {
        $user = $this->writer();
        $thread = $this->conversation('Fuel order', 'Please confirm Tuesday.');
        app(MailboxActions::class)->addNote($user, $thread, 'SECRET-NOTE-TEXT do not share');
        $this->signIn($user);
        $source = (int) $thread->messages()->first()->inbound_email_id;

        Livewire::test(MessageComposer::class, ['mode' => 'reply', 'threadId' => $thread->id, 'source' => "inbound:{$source}"])
            ->assertSet('subject', 'Re: Fuel order')
            ->assertSet('to', ['chief@example.test'])
            ->assertSet('mode', 'reply')
            ->assertDontSee('SECRET-NOTE-TEXT');

        $draft = MailDraft::query()->sole();
        $this->assertStringContainsString('Please confirm Tuesday.', (string) $draft->body_html);
        $this->assertStringNotContainsString('SECRET-NOTE-TEXT', (string) $draft->body_html);
    }

    public function test_autosave_stores_recipients_subject_and_body_and_advances_the_version(): void
    {
        $this->signIn($this->writer());
        $composer = Livewire::test(MessageComposer::class);
        $draft = MailDraft::query()->sole();
        $before = $draft->version;

        $composer->set('to', ['a@example.test', 'B@Example.test'])->set('cc', ['c@example.test'])->set('bcc', ['d@example.test'])
            ->set('subject', 'Weekly roster')->set('data.body', '<p>Hello <strong>team</strong></p>')
            ->call('autosave')
            ->assertSet('saveError', null)->assertSet('conflict', false)
            ->assertSet('version', $before + 1);

        $draft->refresh();
        $this->assertSame(['a@example.test', 'B@Example.test'], $draft->to);
        $this->assertSame(['d@example.test'], $draft->bcc);
        $this->assertSame('Weekly roster', $draft->subject);
        $this->assertSame('<p>Hello <strong>team</strong></p>', $draft->body_html);
        $this->assertSame(0, OutboundEmail::query()->count(), 'a draft is never a ledger row');
    }

    public function test_hostile_markup_in_the_editor_is_sanitized_on_save(): void
    {
        $this->signIn($this->writer());

        Livewire::test(MessageComposer::class)->set('data.body', '<p>ok</p><script>alert(1)</script><img src=x onerror=alert(2)><a href="javascript:alert(3)">x</a>')->call('autosave');

        $body = (string) MailDraft::query()->sole()->body_html;
        foreach (['<script', 'onerror', 'javascript:', '<img'] as $needle) {
            $this->assertStringNotContainsString($needle, $body);
        }
    }

    public function test_a_second_window_that_saved_first_is_reported_as_a_conflict_and_both_choices_work(): void
    {
        $user = $this->writer();
        $this->signIn($user);
        $composer = Livewire::test(MessageComposer::class);
        $draft = MailDraft::query()->sole();
        MailDraft::query()->whereKey($draft->id)->update(['subject' => 'Typed in the other window', 'version' => $draft->version + 1]);

        $composer->set('subject', 'Typed here')->call('autosave')
            ->assertSet('conflict', true)->assertSee('changed somewhere else');
        $this->assertSame('Typed in the other window', MailDraft::query()->sole()->subject);

        $composer->call('useSavedVersion')->assertSet('conflict', false)->assertSet('subject', 'Typed in the other window');

        $composer->set('subject', 'Typed here again');
        MailDraft::query()->whereKey($draft->id)->increment('version');
        $composer->call('autosave')->assertSet('conflict', true)->call('keepMyVersion')->assertSet('conflict', false);
        $this->assertSame('Typed here again', MailDraft::query()->sole()->subject);
    }

    public function test_a_draft_survives_a_reload_and_nobody_else_can_open_it(): void
    {
        $user = $this->writer();
        $this->signIn($user);
        Livewire::test(MessageComposer::class)->set('subject', 'Keep me')->set('to', ['x@example.test'])->call('autosave');
        $draft = MailDraft::query()->sole();

        Livewire::test(MessageComposer::class, ['mode' => 'new', 'resume' => $draft->id])
            ->assertSet('subject', 'Keep me')->assertSet('to', ['x@example.test'])->assertSet('draftId', $draft->id);

        $this->signIn($this->mailUser(['admin.email.view', 'admin.email.send']));
        Livewire::test(MessageComposer::class, ['mode' => 'new', 'resume' => $draft->id])
            ->assertNotSet('draftId', $draft->id)->assertNotSet('subject', 'Keep me');
        $this->assertSame(2, MailDraft::query()->count(), 'the stranger got a draft of their own');
    }

    public function test_a_successful_send_says_so_creates_one_ledger_row_and_locks_the_draft(): void
    {
        $user = $this->writer();
        $this->signIn($user);

        $composer = Livewire::test(MessageComposer::class)
            ->set('to', ['person@example.test'])->set('cc', ['copy@example.test'])->set('bcc', ['quiet@example.test'])
            ->set('subject', 'Station 2 visit')->set('data.body', '<p>See you Friday.</p>')
            ->call('send')
            ->assertSet('result.status', 'accepted')
            ->assertSee('Sent')->assertSee('View in Sent')->assertDispatched('mail-unread-changed');

        $email = OutboundEmail::query()->sole();
        $this->assertSame('admin_compose', $email->source_type);
        $this->assertSame('MailDraft', class_basename(MailDraft::class));
        $this->assertSame(MailDraft::STATE_SENT, MailDraft::query()->sole()->state);
        $payload = $this->lastProviderPayload();
        $this->assertSame(['person@example.test'], (array) $payload['to']);
        $this->assertStringContainsString('See you Friday.', (string) $payload['text']);
        $composer->assertSet('draftState', MailDraft::STATE_SENT);

        // A second press never sends a second message.
        $composer->call('send');
        $this->assertSame(1, OutboundEmail::query()->count());
    }

    public function test_the_sent_message_never_contains_an_internal_note_or_a_note_about_the_thread(): void
    {
        $user = $this->writer();
        $thread = $this->conversation('Boat ramp', 'Can you repair it?');
        app(MailboxActions::class)->addNote($user, $thread, 'NOTE-MARKER-9f3 call the city first');
        $this->signIn($user);
        $source = (int) $thread->messages()->first()->inbound_email_id;

        Livewire::test(MessageComposer::class, ['mode' => 'reply_all', 'threadId' => $thread->id, 'source' => "inbound:{$source}"])
            ->set('data.body', '<p>We will repair it.</p>')->call('send')->assertSet('result.status', 'accepted');

        $this->assertSame(1, MailThreadNote::query()->count());
        $email = OutboundEmail::query()->sole();
        $everything = json_encode([$email->toArray(), $email->getAttribute('bcc_recipients'), $this->lastProviderPayload()], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('NOTE-MARKER-9f3', $everything);
        $this->assertStringNotContainsString('call the city first', $everything);
        $this->assertSame($source, (int) $email->parent_inbound_email_id);
    }

    public function test_nothing_in_the_composer_or_the_page_class_reads_the_notes(): void
    {
        foreach (['app/Livewire/Mail/MessageComposer.php', 'app/Filament/Admin/Pages/EmailCenter.php', 'resources/views/livewire/mail/message-composer.blade.php', 'app/Support/Mail/MailSignature.php'] as $path) {
            $source = (string) file_get_contents(base_path($path));
            foreach (['MailThreadNote', 'mail_thread_notes', '->notes(', 'notes()', 'MailThreadDetail'] as $needle) {
                $this->assertStringNotContainsString($needle, $source, "{$path} must not touch internal notes");
            }
        }
    }

    public function test_a_refused_send_says_why_in_plain_words_and_keeps_the_draft_open(): void
    {
        $this->signIn($this->writer());
        $composer = Livewire::test(MessageComposer::class)->set('subject', 'No one to send to')->set('data.body', '<p>Hi</p>');

        $composer->call('send')->assertSet('result.status', 'refused')->assertSee('Add at least one recipient in To')->assertSee('Nothing left the Hub');
        $this->assertSame(MailDraft::STATE_OPEN, MailDraft::query()->sole()->state);
        $this->assertSame(0, OutboundEmail::query()->count());
        Http::assertNothingSent();

        $composer->set('to', ['not-an-address'])->call('send')->assertSee('is not a valid email address');
        $composer->set('to', ['ok@example.test'])->set('subject', "\u{200B}")->call('send')->assertSee('Add a subject before sending');
        $composer->call('dismissResult')->assertSet('result', null);
    }

    public function test_too_many_recipients_explains_the_limit_and_points_to_department_updates(): void
    {
        $this->signIn($this->writer());
        $limit = app(\App\Services\Mail\MailDraftService::class)->effectiveRecipientLimit();
        $many = array_map(fn (int $n): string => "p{$n}@example.test", range(1, $limit + 1));

        Livewire::test(MessageComposer::class)->assertSee("at most {$limit}")->assertSee('Department Updates')
            ->set('to', $many)->set('subject', 'Everyone')->set('data.body', '<p>Hi</p>')
            ->call('send')
            ->assertSet('result.status', 'refused')
            ->assertSee("at most {$limit} people")->assertSee('Department Updates');
        $this->assertSame(0, OutboundEmail::query()->count());
    }

    public function test_a_budget_pause_is_deferred_with_the_draft_kept_and_nothing_sent(): void
    {
        $this->signIn($this->writer());
        $this->staleBudget();

        Livewire::test(MessageComposer::class)->set('to', ['p@example.test'])->set('subject', 'Paused')->set('data.body', '<p>Hi</p>')
            ->call('send')
            ->assertSet('result.status', 'deferred')
            ->assertSee('Not sent yet')->assertSee('Nothing was sent')->assertSee('Your draft is kept');
        $this->assertSame(MailDraft::STATE_OPEN, MailDraft::query()->sole()->state);
        Http::assertNothingSent();
    }

    public function test_an_ambiguous_provider_failure_is_unconfirmed_locks_the_draft_and_is_never_retried(): void
    {
        $this->signIn($this->writer());
        $this->providerFailsAmbiguously();

        $composer = Livewire::test(MessageComposer::class)->set('to', ['p@example.test'])->set('subject', 'Maybe sent')->set('data.body', '<p>Hi</p>')
            ->call('send')
            ->assertSet('result.status', 'unconfirmed')
            ->assertSee('Not confirmed')->assertSee('may or may not have been sent')->assertSee('will not be resent automatically')
            ->assertSet('draftState', MailDraft::STATE_UNCONFIRMED);
        $this->assertSame(MailDraft::STATE_UNCONFIRMED, MailDraft::query()->sole()->state);

        $calls = $this->providerCalls;
        $this->providerRecovers();
        $composer->call('send')->call('autosave');
        $this->assertSame(0, $this->providerCalls, 'nothing is resent after an unconfirmed send (was '.$calls.' before)');
    }

    public function test_attachments_are_validated_listed_and_removable_and_limits_are_shown_up_front(): void
    {
        $this->signIn($this->writer());
        $composer = Livewire::test(MessageComposer::class)->assertSee('Up to 5 files')->assertSee('PDF, Word, Excel, CSV');

        $composer->set('uploads', [UploadedFile::fake()->createWithContent('plan.txt', 'Plan text')])->assertSee('plan.txt')->assertSet('attachError', null);
        $this->assertSame(1, MailDraft::query()->sole()->attachments()->count());

        $composer->set('uploads', [UploadedFile::fake()->createWithContent('payload.exe', 'MZ binary')])->assertSee('cannot be sent');
        $this->assertSame(1, MailDraft::query()->sole()->attachments()->count());

        $attachment = MailDraft::query()->sole()->attachments()->sole();
        $composer->call('removeAttachment', $attachment->id)->assertDontSee('plan.txt');
        $this->assertSame(0, MailDraft::query()->sole()->attachments()->count());
    }

    public function test_discard_deletes_the_draft_and_close_keeps_it(): void
    {
        $this->signIn($this->writer());

        $kept = Livewire::test(MessageComposer::class)->set('subject', 'Keep')->call('close')->assertDispatched('mail-compose-closed');
        $this->assertSame('Keep', MailDraft::query()->sole()->subject);

        $kept->call('discard')->assertDispatched('mail-compose-closed');
        $this->assertSame(0, MailDraft::query()->count());
    }

    public function test_only_people_who_may_send_can_compose_and_a_revoked_writer_is_refused_on_the_next_call(): void
    {
        $this->signIn($this->mailViewer());
        Livewire::test(MessageComposer::class)->assertForbidden();

        $user = $this->writer();
        $this->signIn($user);
        $composer = Livewire::test(MessageComposer::class);
        $user->permissions()->detach();
        $user->roles()->detach();
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $composer->call('autosave')->assertForbidden();
    }

    public function test_suggestions_come_from_the_directory_for_writers_only(): void
    {
        $writer = $this->writer();
        $colleague = $this->mailViewer();
        $colleague->forceFill(['name' => 'Pat Quartermaster', 'email' => 'pat.quartermaster@miamibeachfl.gov'])->save();
        $this->signIn($writer);

        $composer = Livewire::test(MessageComposer::class);
        $found = $composer->instance()->suggest('quartermaster');

        $this->assertSame('pat.quartermaster@miamibeachfl.gov', $found[0]['address']);
        $this->assertSame([], $composer->instance()->suggest('q'), 'one letter suggests nothing');
    }

    public function test_the_composer_has_no_poll_so_a_refresh_cannot_hide_unsaved_typing(): void
    {
        $this->signIn($this->writer());

        $html = Livewire::test(MessageComposer::class)->html();

        $this->assertStringNotContainsString('wire:poll', $html);
        $this->assertStringContainsString('data-mbfd-unsaved-work-guard', $html);
    }
}
