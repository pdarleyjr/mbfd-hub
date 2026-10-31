<?php

declare(strict_types=1);

namespace App\Livewire\Mail;

use App\Exceptions\MailDraftConflict;
use App\Models\InboundEmail;
use App\Models\Mail\MailDraft;
use App\Models\Mail\MailDraftAttachment;
use App\Models\Mail\MailThreadMessage;
use App\Models\OutboundEmail;
use App\Models\User;
use App\Services\Mail\AddressParser;
use App\Services\Mail\MailAccess;
use App\Services\Mail\MailActionRefused;
use App\Services\Mail\MailAttachmentLocator;
use App\Services\Mail\MailDraftService;
use App\Services\Mail\MailHtmlSanitizer;
use App\Services\Mail\MailRecipientDirectory;
use App\Services\Mail\MailSendResult;
use App\Services\Mail\MailText;
use App\Support\Mail\MailSignature;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;
use Throwable;

/**
 * Writing a message. Every keystroke ends up in a private draft (MailDraftService), never in the send ledger,
 * so a refresh, a dropped connection or a second window cannot lose it. This component deliberately has no
 * poll: a poll is a Livewire call, and the PWA update system treats a finished call as "saved" for the fields
 * inside the component, which would hide unsaved typing. Notes are internal and this class never reads them.
 *
 * @property Form $form
 */
final class MessageComposer extends Component implements HasForms
{
    use InteractsWithForms;
    use WithFileUploads;

    #[Locked]
    public int $draftId = 0;

    #[Locked]
    public int $version = 1;

    #[Locked]
    public string $mode = MailDraft::MODE_NEW;

    #[Locked]
    public ?int $threadId = null;

    /** The draft's own state: open, or unconfirmed when a send could not be confirmed. Sent drafts show the result. */
    #[Locked]
    public string $draftState = MailDraft::STATE_OPEN;

    /** @var list<string> */
    public array $to = [];

    /** @var list<string> */
    public array $cc = [];

    /** @var list<string> */
    public array $bcc = [];

    public string $subject = '';

    /** @var array<string, mixed>|null */
    public ?array $data = ['body' => ''];

    /** @var list<mixed> */
    public array $uploads = [];

    public ?string $savedAt = null;

    public ?string $saveError = null;

    public ?string $attachError = null;

    public bool $conflict = false;

    /** The member's own confirmation that a message to a group of this size has had every recipient reviewed. */
    public bool $reviewedRecipients = false;

    /** From this many people a send needs that confirmation (what the old Compose Email form also asked for). */
    public const LARGE_SEND = 5;

    /** Set when the draft could not be opened at all. */
    public ?string $openError = null;

    /** @var array{status: string, message: string, thread: ?int, retry: ?int}|null */
    public ?array $result = null;

    public function mount(string $mode = MailDraft::MODE_NEW, ?int $threadId = null, ?string $source = null, ?int $resume = null): void
    {
        $user = $this->authorizeSender();
        try {
            $draft = $this->openDraft($user, $mode, $threadId, $source, $resume);
        } catch (MailActionRefused|ValidationException $refusal) {
            $this->openError = $refusal instanceof ValidationException
                ? (string) collect($refusal->errors())->flatten()->first()
                : $refusal->getMessage();
            $this->form->fill(['body' => '']);

            return;
        }
        $this->fillFrom($draft);
        $this->dispatch('mail-draft-opened', id: $draft->getKey());
    }

    public function hydrate(): void
    {
        $this->authorizeSender();
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            RichEditor::make('body')
                ->label('Message')
                ->hiddenLabel()
                // Bold, italic, underline, lists and a link. No file attach: files go through the attachment list.
                ->toolbarButtons(['bold', 'italic', 'underline', 'bulletList', 'orderedList', 'link'])
                ->extraAttributes(['class' => 'mail-editor']),
        ])->statePath('data');
    }

    // ---------------------------------------------------------------------------------------------
    // Saving
    // ---------------------------------------------------------------------------------------------

    public function autosave(): void
    {
        $this->authorizeSender();
        if ($this->openError !== null || $this->isFinished()) {
            return;
        }
        $this->persist();
    }

    /** True once the draft can no longer be edited: sent, or locked because a send could not be confirmed. A refusal or a pause leaves it open. */
    private function isFinished(): bool
    {
        return $this->draftState !== MailDraft::STATE_OPEN
            || ($this->result !== null && ! in_array($this->result['status'], [MailSendResult::REFUSED, MailSendResult::DEFERRED], true));
    }

    /** Takes the other window's version of the draft and drops what was typed here. */
    public function useSavedVersion(): void
    {
        $user = $this->authorizeSender();
        $draft = MailDraft::query()->whereKey($this->draftId)->where('user_id', $user->getKey())->first();
        if ($draft === null) {
            $this->openError = 'That draft no longer exists.';

            return;
        }
        $this->fillFrom($draft);
        $this->conflict = false;
        $this->saveError = null;
    }

    /** Keeps what was typed here and replaces the other window's version. */
    public function keepMyVersion(): void
    {
        $this->authorizeSender();
        $current = MailDraft::query()->whereKey($this->draftId)->value('version');
        if (is_numeric($current)) {
            $this->version = (int) $current;
            $this->conflict = false;
            $this->persist();
        }
    }

    // ---------------------------------------------------------------------------------------------
    // Recipients
    // ---------------------------------------------------------------------------------------------

    /** @return list<array{address: string, name: ?string, source: string}> */
    public function suggest(string $query): array
    {
        $user = $this->authorizeSender();

        try {
            return app(MailRecipientDirectory::class)->suggest($user, $query, 8);
        } catch (MailActionRefused) {
            return [];
        }
    }

    // ---------------------------------------------------------------------------------------------
    // Attachments
    // ---------------------------------------------------------------------------------------------

    public function updatedUploads(): void
    {
        $user = $this->authorizeSender();
        $this->attachError = null;
        $draft = $this->draftModel();
        foreach ($this->uploads as $file) {
            try {
                app(MailDraftService::class)->addAttachment($user, $draft, $file);
            } catch (ValidationException $exception) {
                $this->attachError = (string) collect($exception->errors())->flatten()->first();
                break;
            } catch (MailActionRefused $refusal) {
                $this->attachError = $refusal->getMessage();
                break;
            }
        }
        $this->uploads = [];
    }

    public function addForwarded(int $index): void
    {
        $user = $this->authorizeSender();
        $this->attachError = null;
        try {
            app(MailDraftService::class)->addForwardedAttachment($user, $this->draftModel(), $index);
        } catch (ValidationException $exception) {
            $this->attachError = (string) collect($exception->errors())->flatten()->first();
        } catch (MailActionRefused $refusal) {
            $this->attachError = $refusal->getMessage();
        }
    }

    public function removeAttachment(int $attachmentId): void
    {
        $user = $this->authorizeSender();
        $this->attachError = null;
        $attachment = MailDraftAttachment::query()->where('draft_id', $this->draftId)->find($attachmentId);
        if ($attachment === null) {
            return;
        }
        try {
            app(MailDraftService::class)->removeAttachment($user, $attachment);
        } catch (MailActionRefused $refusal) {
            $this->attachError = $refusal->getMessage();
        }
    }

    // ---------------------------------------------------------------------------------------------
    // Finishing
    // ---------------------------------------------------------------------------------------------

    public function send(): void
    {
        $user = $this->authorizeSender();
        if ($this->openError !== null || $this->isFinished()) {
            return;
        }
        $this->result = null; // a new attempt replaces an earlier refusal or pause
        $people = $this->recipientCount();
        // Too many people is its own refusal (below), so the confirmation is only asked for a group that could be sent.
        if ($people >= self::LARGE_SEND && $people <= app(MailDraftService::class)->effectiveRecipientLimit() && ! $this->reviewedRecipients) {
            $this->result = [
                'status' => MailSendResult::REFUSED, 'thread' => null, 'retry' => null,
                'message' => 'Tick the box to confirm you have reviewed every recipient before sending to this many people.',
            ];

            return;
        }
        if (! $this->persist()) {
            // Nothing is sent until the latest text is safely stored; the reason is already on screen.
            return;
        }
        try {
            $outcome = app(MailDraftService::class)->send($user, $this->draftModel());
        } catch (MailActionRefused $refusal) {
            $outcome = MailSendResult::refused($refusal->getMessage());
        } catch (Throwable $exception) {
            report($exception);
            $outcome = MailSendResult::unconfirmed(null);
        }
        $this->result = [
            'status' => $outcome->status,
            'message' => $outcome->message,
            'thread' => $outcome->outbound === null ? null : $this->threadOf($outcome->outbound),
            'retry' => $outcome->retryAfterSeconds,
        ];
        if ($outcome->isAccepted()) {
            $this->draftState = MailDraft::STATE_SENT;
            $this->dispatch('mail-unread-changed');
        } elseif ($outcome->isUnconfirmed()) {
            $this->draftState = MailDraft::STATE_UNCONFIRMED;
        }
    }

    /** Dismisses a refusal or a pause so the draft can be edited and sent again. The draft was never closed. */
    public function dismissResult(): void
    {
        $this->authorizeSender();
        if ($this->result !== null && in_array($this->result['status'], [MailSendResult::REFUSED, MailSendResult::DEFERRED], true)) {
            $this->result = null;
        }
    }

    public function close(): void
    {
        $this->authorizeSender();
        $this->autosave();
        $this->dispatch('mail-compose-closed', thread: $this->threadId);
        $this->dispatch('mail-unread-changed');
    }

    /** After a send: leave the composer, optionally landing on the conversation that was just sent. */
    public function finish(bool $showSent = false): void
    {
        $this->authorizeSender();
        $thread = $this->result['thread'] ?? $this->threadId;
        $this->dispatch('mail-compose-closed', thread: $thread, showSent: $showSent && $thread !== null);
        $this->dispatch('mail-unread-changed');
    }

    public function discard(): void
    {
        $user = $this->authorizeSender();
        try {
            if ($this->draftId > 0) {
                $draft = MailDraft::query()->find($this->draftId);
                if ($draft !== null) {
                    app(MailDraftService::class)->discard($user, $draft);
                }
            }
        } catch (MailActionRefused $refusal) {
            $this->saveError = $refusal->getMessage();

            return;
        }
        $this->dispatch('mail-compose-closed', thread: $this->threadId);
        $this->dispatch('mail-unread-changed');
    }

    public function render(): View
    {
        $this->authorizeSender();
        $service = app(MailDraftService::class);
        $draft = $this->draftId > 0 ? MailDraft::query()->with('attachments')->find($this->draftId) : null;
        $limit = $service->effectiveRecipientLimit();
        $max = (int) config('communications.cloudflare.max_attachments', 5);

        return view('livewire.mail.message-composer', [
            'attachments' => $draft === null ? collect() : $draft->attachments,
            'forwardable' => $this->mode === MailDraft::MODE_FORWARD && $draft !== null ? $this->forwardableFiles($draft) : [],
            'recipientLimit' => $limit,
            'maxFiles' => $max,
            'maxBytes' => $service->attachmentByteCap(),
            'departmentUpdatesUrl' => \App\Filament\Admin\Pages\EmailCenter::departmentUpdatesUrl(),
            'heading' => match ($this->mode) {
                MailDraft::MODE_REPLY => 'Reply',
                MailDraft::MODE_REPLY_ALL => 'Reply all',
                MailDraft::MODE_FORWARD => 'Forward',
                default => 'New message',
            },
            'locked' => $this->isFinished(),
        ]);
    }

    // ---------------------------------------------------------------------------------------------

    /** Distinct people across To, Cc and Bcc, as the sender will count them. */
    private function recipientCount(): int
    {
        return count(array_unique(array_map('strtolower', app(AddressParser::class)->addresses([...$this->to, ...$this->cc, ...$this->bcc]))));
    }

    /** Writes the form to the draft. Returns true when the stored draft now matches the screen. */
    private function persist(): bool
    {
        $user = $this->authorizeSender();
        try {
            $draft = app(MailDraftService::class)->save($user, $this->draftModel(), [
                'to' => $this->to, 'cc' => $this->cc, 'bcc' => $this->bcc,
                'subject' => $this->subject, 'body_html' => (string) ($this->data['body'] ?? ''),
            ], $this->version);
        } catch (MailDraftConflict) {
            $this->conflict = true;

            return false;
        } catch (ValidationException $exception) {
            $this->saveError = (string) collect($exception->errors())->flatten()->first();

            return false;
        } catch (MailActionRefused $refusal) {
            $this->saveError = $refusal->getMessage();
            if ($refusal->getStatusCode() === 409) {
                // Another window sent or locked this draft; show what really happened to it.
                $this->draftState = (string) (MailDraft::query()->whereKey($this->draftId)->value('state') ?? MailDraft::STATE_UNCONFIRMED);
            }

            return false;
        } catch (Throwable $exception) {
            report($exception);
            $this->saveError = 'Your draft could not be saved just now. What you typed is still on screen; it will be saved again shortly.';

            return false;
        }
        $this->version = (int) $draft->version;
        $this->savedAt = CarbonImmutable::now()->utc()->toIso8601String();
        $this->saveError = null;
        $this->conflict = false;

        return true;
    }

    private function openDraft(User $user, string $mode, ?int $threadId, ?string $source, ?int $draftId): MailDraft
    {
        $service = app(MailDraftService::class);
        if ($draftId !== null) {
            $existing = MailDraft::query()->whereKey($draftId)->where('user_id', $user->getKey())
                ->whereIn('state', [MailDraft::STATE_OPEN, MailDraft::STATE_UNCONFIRMED])->first();
            if ($existing !== null) {
                return $existing;
            }
        }
        if ($mode === MailDraft::MODE_NEW) {
            $blank = $this->blankNewDraft($user);
            $draft = $blank ?? $service->open($user, MailDraft::MODE_NEW);

            return $this->withSignature($user, $draft);
        }
        [$kind, $id] = $this->sourceOf($source);
        $draft = $service->open($user, $mode, $threadId, $kind, $id);

        return $this->withSignature($user, $draft);
    }

    /** A new, untouched draft left behind by an earlier visit is reused rather than piling up. */
    private function blankNewDraft(User $user): ?MailDraft
    {
        $blank = MailDraft::query()->where('user_id', $user->getKey())->where('mode', MailDraft::MODE_NEW)
            ->where('state', MailDraft::STATE_OPEN)->where('version', '<=', 6)->whereNull('thread_id')->orderByDesc('id')->first();
        if ($blank === null || $blank->attachments()->exists() || trim((string) $blank->subject) !== '' || (array) $blank->to !== [] || (array) $blank->cc !== [] || (array) $blank->bcc !== []) {
            return null;
        }
        // The editor rewrites markup (div for p, line breaks), so "untouched" is judged by the words, not the bytes.
        $text = app(MailText::class);
        $words = static fn (string $html): string => trim((string) preg_replace('/\s+/u', ' ', $text->fromHtml($html)));
        $untouched = $words(app(MailHtmlSanitizer::class)->composer(MailSignature::insertInto('', $user)));
        $stored = $words((string) $blank->body_html);

        return $stored === '' || $stored === $untouched ? $blank : null;
    }

    private function withSignature(User $user, MailDraft $draft): MailDraft
    {
        if ((int) $draft->version !== 1 || $draft->state !== MailDraft::STATE_OPEN) {
            return $draft;
        }
        $body = MailSignature::insertInto((string) $draft->body_html, $user);
        if ($body === (string) $draft->body_html) {
            return $draft;
        }

        try {
            return app(MailDraftService::class)->save($user, $draft, ['body_html' => $body], (int) $draft->version);
        } catch (Throwable) {
            return $draft; // the signature is a convenience; the draft is usable without it
        }
    }

    private function fillFrom(MailDraft $draft): void
    {
        $this->draftId = (int) $draft->getKey();
        $this->version = (int) $draft->version;
        $this->mode = (string) $draft->mode;
        $this->threadId = $draft->thread_id === null ? null : (int) $draft->thread_id;
        $this->draftState = (string) $draft->state;
        $this->to = array_map('strval', (array) $draft->to);
        $this->cc = array_map('strval', (array) $draft->cc);
        $this->bcc = array_map('strval', (array) $draft->bcc);
        $this->subject = (string) $draft->subject;
        $this->form->fill(['body' => (string) $draft->body_html]);
        $this->savedAt = $draft->version > 1 ? CarbonImmutable::instance($draft->updated_at)->utc()->toIso8601String() : null;
        if ($this->draftState === MailDraft::STATE_UNCONFIRMED) {
            $this->result = ['status' => MailSendResult::UNCONFIRMED, 'message' => MailSendResult::unconfirmed(null)->message, 'thread' => $this->threadId, 'retry' => null];
        }
    }

    /** @return array{0: ?string, 1: ?int} */
    private function sourceOf(?string $source): array
    {
        if ($source !== null && preg_match('/^(inbound|outbound):([1-9][0-9]{0,18})$/', $source, $found) === 1) {
            return [$found[1], (int) $found[2]];
        }

        return [null, null];
    }

    private function draftModel(): MailDraft
    {
        return MailDraft::query()->find($this->draftId) ?? throw MailActionRefused::draftNotFound();
    }

    /** @return list<array{index: int, filename: string, size: int, available: bool}> */
    private function forwardableFiles(MailDraft $draft): array
    {
        $source = match ($draft->source_kind) {
            'inbound' => InboundEmail::query()->find($draft->source_id),
            'outbound' => OutboundEmail::query()->find($draft->source_id),
            default => null,
        };
        if ($source === null) {
            return [];
        }
        $taken = $draft->attachments->pluck('filename')->all();

        return collect(app(MailAttachmentLocator::class)->listing($source))
            ->reject(fn (array $file): bool => in_array($file['filename'], $taken, true))
            ->map(fn (array $file): array => ['index' => (int) $file['index'], 'filename' => (string) $file['filename'], 'size' => (int) $file['size'], 'available' => (bool) $file['available']])
            ->values()->all();
    }

    private function threadOf(OutboundEmail $email): ?int
    {
        $id = MailThreadMessage::query()->where('outbound_email_id', $email->getKey())->value('thread_id');

        return is_numeric($id) ? (int) $id : null;
    }

    private function authorizeSender(): User
    {
        $user = auth()->user();
        if (! $user instanceof User || ! app(MailAccess::class)->canSend($user)) {
            throw MailActionRefused::forbidden('You do not have permission to write email.');
        }

        return $user;
    }
}
