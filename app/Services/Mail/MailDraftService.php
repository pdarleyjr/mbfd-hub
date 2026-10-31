<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Exceptions\EmailBudgetExhausted;
use App\Exceptions\MailDraftConflict;
use App\Models\InboundEmail;
use App\Models\Mail\MailDraft;
use App\Models\Mail\MailDraftAttachment;
use App\Models\Mail\MailThread;
use App\Models\Mail\MailThreadMessage;
use App\Models\OutboundEmail;
use App\Models\User;
use App\Services\Communications\CloudflareCostGuard;
use App\Services\Communications\CloudflareEmailDispatcher;
use App\Services\Communications\EmailConversation;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

/**
 * Drafts and the send path of the Email Center.
 *
 * A draft is private to its author. It lives in mail_drafts, never outbound_emails: that table is the send
 * ledger and every row there carries budget meaning. Sending is the only place a draft becomes an
 * outbound_emails row, and it goes through CloudflareEmailDispatcher::send() so the budget guard, the ledger
 * and the duplicate protection stay in one place.
 *
 * Send safety, in order:
 *  1. The draft is claimed under a row lock (open -> sending). A second click, a second tab or a retry sees
 *     "sending", "sent" or "unconfirmed" and returns that answer instead of sending again.
 *  2. The ledger carries source_id "draft:<id>", stable for the draft's life. If a row for that id already exists
 *     and did not fail before acceptance, the message is never sent a second time.
 *  3. A budget pause (EmailBudgetExhausted) proves nothing reached the provider: the draft reopens and the
 *     caller is told when to try again.
 *  4. Any other failure after a ledger row exists is ambiguous. The draft locks as "unconfirmed", linked to that
 *     row, and nothing ever retries it automatically. Only a failure that provably happened before the provider
 *     (no ledger row, or one released as failed_pre_acceptance) reopens the draft.
 *
 * Notes are internal. Nothing in this class, or in MailComposer, reads the thread notes model.
 */
final class MailDraftService
{
    private const MAX_RECIPIENT_ENTRIES = 50;

    private const MAX_BODY_BYTES = 200000;

    /** Headroom for JSON framing, headers and the text alternative inside the provider's total size cap. */
    private const MESSAGE_OVERHEAD_BYTES = 65536;

    private const UPLOAD_TYPE_BY_EXTENSION = [
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'csv' => 'text/csv',
    ];

    public function __construct(
        private readonly MailAccess $access,
        private readonly MailComposer $composer,
        private readonly MailHtmlSanitizer $sanitizer,
        private readonly MailText $text,
        private readonly AddressParser $addresses,
        private readonly EmailConversation $conversation,
        private readonly MailAttachmentLocator $locator,
        private readonly MailThreadIndexer $indexer,
        private readonly CloudflareEmailDispatcher $dispatcher,
        private readonly CloudflareCostGuard $costGuard,
    ) {}

    /** The most recipients one message may have: the smaller of the per-message cap and the per-minute cap. */
    public function effectiveRecipientLimit(): int
    {
        // One definition, owned by the cost guard that enforces it (per-message cap, per-minute cap, hard five).
        return max(1, $this->costGuard->effectiveRecipientLimit());
    }

    // ---------------------------------------------------------------------------------------------
    // Open, save, discard
    // ---------------------------------------------------------------------------------------------

    public function open(User $user, string $mode, ?int $threadId = null, ?string $sourceKind = null, ?int $sourceId = null): MailDraft
    {
        $this->authorize($user);
        if (! in_array($mode, MailDraft::MODES, true)) {
            throw ValidationException::withMessages(['mode' => 'Unknown draft type.']);
        }
        if ($mode === MailDraft::MODE_NEW) {
            return MailDraft::query()->create([
                'user_id' => $user->getKey(), 'thread_id' => null, 'mode' => $mode, 'to' => [], 'cc' => [], 'bcc' => [],
                'subject' => '', 'body_html' => '', 'version' => 1, 'state' => MailDraft::STATE_OPEN,
            ]);
        }

        [$source, $thread] = $this->resolveSource($threadId, $sourceKind, $sourceId);
        if ($thread->isAutomated()) {
            throw MailActionRefused::automatedThread();
        }
        if ($thread->isTrashed()) {
            throw MailActionRefused::trashedThread();
        }
        if (! $this->conversation->canRespond($source)) {
            throw MailActionRefused::forbidden('This kind of message cannot be replied to or forwarded.');
        }

        $kind = $source instanceof InboundEmail ? 'inbound' : 'outbound';
        $existing = MailDraft::query()->where('user_id', $user->getKey())->where('mode', $mode)
            ->where('source_kind', $kind)->where('source_id', $source->getKey())->where('state', MailDraft::STATE_OPEN)->orderBy('id')->first();
        if ($existing !== null) {
            return $existing;
        }

        $prefill = $this->composer->prefill($mode, $source);

        return MailDraft::query()->create([
            'user_id' => $user->getKey(), 'thread_id' => $thread->getKey(), 'mode' => $mode,
            'source_kind' => $kind, 'source_id' => $source->getKey(),
            'to' => $prefill['to'], 'cc' => $prefill['cc'], 'bcc' => [],
            'subject' => $prefill['subject'], 'body_html' => $this->sanitizer->composer($prefill['body_html']),
            'version' => 1, 'state' => MailDraft::STATE_OPEN,
        ]);
    }

    /**
     * @param  array{to?: mixed, cc?: mixed, bcc?: mixed, subject?: mixed, body_html?: mixed}  $fields
     *
     * @throws MailDraftConflict when the draft changed since $expectedVersion
     */
    public function save(User $user, MailDraft $draft, array $fields, int $expectedVersion): MailDraft
    {
        $this->authorizeOwner($user, $draft);
        $changes = [];
        foreach (['to', 'cc', 'bcc'] as $field) {
            if (array_key_exists($field, $fields)) {
                $changes[$field] = json_encode($this->recipientEntries($fields[$field]), JSON_THROW_ON_ERROR);
            }
        }
        if (array_key_exists('subject', $fields)) {
            $subject = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $fields['subject']));
            if (mb_strlen($subject) > 998) {
                throw ValidationException::withMessages(['subject' => 'The subject is too long (998 characters at most).']);
            }
            $changes['subject'] = $subject;
        }
        if (array_key_exists('body_html', $fields)) {
            $body = (string) $fields['body_html'];
            if (strlen($body) > self::MAX_BODY_BYTES) {
                throw ValidationException::withMessages(['body_html' => 'The message is too long to save.']);
            }
            $changes['body_html'] = $this->sanitizer->composer($body);
        }

        $updated = MailDraft::query()->whereKey($draft->getKey())->where('user_id', $user->getKey())
            ->where('state', MailDraft::STATE_OPEN)->where('version', $expectedVersion)
            ->update($changes + ['version' => $expectedVersion + 1, 'updated_at' => now()]);

        $current = MailDraft::query()->find($draft->getKey());
        if ($current === null) {
            throw MailActionRefused::draftNotFound();
        }
        if ($updated === 0) {
            if ($current->state !== MailDraft::STATE_OPEN) {
                throw MailActionRefused::draftLocked($this->lockedMessage($current));
            }
            throw new MailDraftConflict($current, $expectedVersion);
        }

        return $current;
    }

    public function addAttachment(User $user, MailDraft $draft, UploadedFile $file): MailDraftAttachment
    {
        $this->authorizeOwner($user, $draft);
        $this->requireOpen($draft);
        // Checked before the file is read, so an oversized upload never reaches memory.
        if ($file->getSize() > $this->attachmentByteCap()) {
            throw ValidationException::withMessages(['attachment' => 'Attachments may total '.number_format($this->attachmentByteCap() / 1048576, 1).' MB in all. Remove a file or send it another way.']);
        }
        if (! $file->isValid() || ($path = $file->getRealPath()) === false || ($content = file_get_contents($path)) === false) {
            throw ValidationException::withMessages(['attachment' => 'That file could not be read. Try attaching it again.']);
        }
        $name = $this->safeFilename($file->getClientOriginalName());
        $type = $this->detectedType($file, $name, $content);

        return $this->storeAttachment($draft, $name, $type, $content);
    }

    /** Copies one attachment of the message being forwarded into the draft. Only chosen files are ever forwarded. */
    public function addForwardedAttachment(User $user, MailDraft $draft, int $index): MailDraftAttachment
    {
        $this->authorizeOwner($user, $draft);
        $this->requireOpen($draft);
        if ($draft->mode !== MailDraft::MODE_FORWARD) {
            throw ValidationException::withMessages(['attachment' => 'Only a forward can include the original message\'s files.']);
        }
        $source = $this->sourceOf($draft);
        $located = $source === null ? null : $this->locator->locate($source, $index);
        if ($located === null) {
            throw ValidationException::withMessages(['attachment' => 'That attachment is no longer available. Upload a new copy instead.']);
        }
        $content = Storage::disk($located['disk'])->get($located['path']);
        if (! is_string($content)) {
            throw ValidationException::withMessages(['attachment' => 'That attachment is no longer available. Upload a new copy instead.']);
        }
        $existing = $draft->attachments()->where('sha256', hash('sha256', $content))->where('filename', $this->safeFilename($located['filename']))->first();

        return $existing ?? $this->storeAttachment($draft, $this->safeFilename($located['filename']), strtolower($located['mime_type']), $content);
    }

    public function removeAttachment(User $user, MailDraftAttachment $attachment): void
    {
        $draft = MailDraft::query()->find($attachment->draft_id);
        if ($draft === null) {
            throw MailActionRefused::draftNotFound();
        }
        $this->authorizeOwner($user, $draft);
        $this->requireOpen($draft);
        Storage::disk($attachment->disk)->delete($attachment->path);
        $attachment->delete();
        $draft->forceFill(['updated_at' => now()])->save();
    }

    public function discard(User $user, MailDraft $draft): void
    {
        $this->authorizeOwner($user, $draft);
        $fresh = MailDraft::query()->find($draft->getKey());
        if ($fresh === null) {
            return;
        }
        if ($fresh->state === MailDraft::STATE_SENDING) {
            throw MailActionRefused::draftLocked('This message is being sent right now.');
        }
        $this->deleteFiles($fresh);
        $fresh->delete();
    }

    // ---------------------------------------------------------------------------------------------
    // Send
    // ---------------------------------------------------------------------------------------------

    public function send(User $user, MailDraft $draft): MailSendResult
    {
        $this->authorizeOwner($user, $draft);

        $claim = DB::transaction(function () use ($draft): MailDraft|MailSendResult {
            $row = MailDraft::query()->lockForUpdate()->find($draft->getKey());
            if ($row === null) {
                throw MailActionRefused::draftNotFound();
            }

            if ($row->state === MailDraft::STATE_OPEN) {
                $row->forceFill(['state' => MailDraft::STATE_SENDING])->save();

                return $row;
            }

            return match ($row->state) {
                MailDraft::STATE_SENDING => MailSendResult::refused('This message is already being sent.'),
                MailDraft::STATE_SENT => $row->outbound !== null
                    ? MailSendResult::accepted($row->outbound, 'This message was already sent.')
                    : MailSendResult::refused('This message was already sent.'),
                default => MailSendResult::unconfirmed($row->outbound),
            };
        });
        if ($claim instanceof MailSendResult) {
            return $claim;
        }
        $draft = $claim;

        if (($prior = $this->ledgerRow($draft)) !== null && ! self::nothingWasSent($prior)) {
            // A previous attempt may have reached the provider: never send the same draft twice.
            return $this->settleFromLedger($draft, $prior);
        }

        try {
            $payload = $this->prepare($user, $draft);
        } catch (DomainException $refusal) {
            $this->setState($draft, MailDraft::STATE_OPEN);

            return MailSendResult::refused($refusal->getMessage());
        } catch (Throwable $exception) {
            $this->setState($draft, MailDraft::STATE_OPEN);
            $this->report('prepare', $draft, $exception);

            return MailSendResult::refused('This message could not be prepared, so nothing was sent. Try again.');
        }

        try {
            $email = $this->dispatcher->send(
                to: $payload['to'], subject: $payload['subject'], text: $payload['text'], html: $payload['html'],
                sourceType: $payload['sourceType'], sourceId: $draft->ledgerSourceId(), actor: $user,
                cc: $payload['cc'], bcc: $payload['bcc'], replyTo: null,
                attachments: $payload['attachments'], headers: $payload['headers'],
                parentOutboundEmailId: $payload['parentOutbound'], parentInboundEmailId: $payload['parentInbound'],
            );
        } catch (EmailBudgetExhausted $exception) {
            $this->setState($draft, MailDraft::STATE_OPEN);

            return MailSendResult::deferred($this->pauseMessage($exception->retryAfterSeconds), $exception->retryAfterSeconds);
        } catch (InvalidArgumentException $exception) {
            // The dispatcher validates before it writes anything, so nothing was created or sent.
            $this->setState($draft, MailDraft::STATE_OPEN);

            return MailSendResult::refused($this->dispatcherRefusal($exception));
        } catch (Throwable $exception) {
            $this->report('send', $draft, $exception);
            $row = $this->ledgerRow($draft);
            if ($row === null || self::nothingWasSent($row)) {
                $this->setState($draft, MailDraft::STATE_OPEN);

                return MailSendResult::refused($row === null || $row->status === 'failed_pre_acceptance'
                    ? 'This message could not be prepared, so nothing was sent. Try again.'
                    : $this->providerRefusal($row));
            }

            return $this->settleFromLedger($draft, $row);
        }

        return $this->finishAccepted($draft, $email);
    }

    // ---------------------------------------------------------------------------------------------
    // Preparing a send
    // ---------------------------------------------------------------------------------------------

    /**
     * Everything the dispatcher needs, or a DomainException whose message is the member-readable reason.
     *
     * @return array{to: list<string>, cc: list<string>, bcc: list<string>, subject: string, text: string, html: string, sourceType: string, attachments: list<array{filename: string, type: string, content: string}>, headers: array<string, string>, parentInbound: ?int, parentOutbound: ?int}
     */
    private function prepare(User $user, MailDraft $draft): array
    {
        if (! $this->access->canSend($user) || ! $this->access->canView($user)) {
            throw new DomainException('You do not have permission to send email.');
        }
        [$to, $cc, $bcc] = $this->recipients($draft);

        $subject = trim((string) $draft->subject);
        // Blank includes invisible characters (zero-width spaces, control codes), which the dispatcher also rejects.
        if (preg_replace('/[\p{Z}\p{C}]+/u', '', $subject) === '') {
            throw new DomainException('Add a subject before sending.');
        }
        if (mb_strlen($subject) > 998) {
            throw new DomainException('The subject is too long (998 characters at most).');
        }

        $html = $this->sanitizer->composer((string) $draft->body_html);
        $text = $this->text->fromHtml($html);
        if ($text === '') {
            throw new DomainException('Write a message before sending.');
        }

        $source = null;
        $headers = [];
        $parentInbound = null;
        $parentOutbound = null;
        if ($draft->mode !== MailDraft::MODE_NEW) {
            $source = $this->sourceOf($draft);
            if ($source === null) {
                throw new DomainException('The message you are answering is no longer available, so this draft cannot be sent as a reply. Copy your text into a new message.');
            }
            $thread = $this->threadOfSource($source);
            if ($thread === null || $thread->isAutomated() || $thread->isTrashed() || ! $this->conversation->canRespond($source)) {
                throw new DomainException('This conversation can no longer be answered from here.');
            }
            $headers = $this->composer->headers($draft->mode, $source);
            $parentInbound = $source instanceof InboundEmail ? (int) $source->getKey() : null;
            $parentOutbound = $source instanceof OutboundEmail ? (int) $source->getKey() : null;
        }

        $attachments = $this->attachmentPayload($draft);
        $encodedSize = strlen($html) + strlen($text) + array_sum(array_map(static fn (array $a): int => strlen($a['content']), $attachments));
        if ($encodedSize > (int) config('communications.cloudflare.max_message_bytes', 4500000) - self::MESSAGE_OVERHEAD_BYTES) {
            throw new DomainException('This message is too large to send. Remove or shrink an attachment.');
        }

        return [
            'to' => $to, 'cc' => $cc, 'bcc' => $bcc, 'subject' => $subject, 'text' => $text, 'html' => $html,
            'sourceType' => match ($draft->mode) {
                MailDraft::MODE_FORWARD => 'admin_forward',
                MailDraft::MODE_NEW => 'admin_compose',
                default => 'admin_reply',
            },
            'attachments' => $attachments, 'headers' => $headers, 'parentInbound' => $parentInbound, 'parentOutbound' => $parentOutbound,
        ];
    }

    /** @return array{0: list<string>, 1: list<string>, 2: list<string>} */
    private function recipients(MailDraft $draft): array
    {
        $parsed = [];
        foreach (['to', 'cc', 'bcc'] as $field) {
            $addresses = [];
            foreach ((array) $draft->{$field} as $entry) {
                $found = $this->addresses->addresses($entry);
                if ($found === []) {
                    throw new DomainException('"'.mb_substr(trim((string) $entry), 0, 80).'" is not a valid email address. Fix or remove it.');
                }
                array_push($addresses, ...$found);
            }
            $parsed[$field] = array_values(array_unique($addresses));
        }
        $to = $parsed['to'];
        $cc = array_values(array_diff($parsed['cc'], $to));
        $bcc = array_values(array_diff($parsed['bcc'], $to, $cc));
        if ($to === []) {
            throw new DomainException('Add at least one recipient in To.');
        }
        $limit = $this->effectiveRecipientLimit();
        if (count($to) + count($cc) + count($bcc) > $limit) {
            throw new DomainException("The Email Center sends to at most {$limit} people at a time. For a message to a larger group, use Department Updates.");
        }

        return [$to, $cc, $bcc];
    }

    /** @return list<array{filename: string, type: string, content: string}> */
    private function attachmentPayload(MailDraft $draft): array
    {
        $payload = [];
        foreach ($draft->attachments as $attachment) {
            $content = Storage::disk($attachment->disk)->get($attachment->path);
            if (! is_string($content)) {
                throw new DomainException('An attachment ('.$attachment->filename.') is no longer available. Remove it or attach it again.');
            }
            $payload[] = ['filename' => $attachment->filename, 'type' => $attachment->mime_type, 'content' => base64_encode($content)];
        }

        return $payload;
    }

    // ---------------------------------------------------------------------------------------------
    // Outcomes
    // ---------------------------------------------------------------------------------------------

    private function finishAccepted(MailDraft $draft, OutboundEmail $email): MailSendResult
    {
        $thread = null;
        try {
            $thread = $this->indexer->indexOutbound($email);
        } catch (Throwable $exception) {
            $this->report('index', $draft, $exception); // the sweep will place it
        }
        $draft->forceFill([
            'state' => MailDraft::STATE_SENT, 'outbound_email_id' => $email->getKey(),
            'thread_id' => $thread?->getKey() ?? $draft->thread_id,
        ])->save();
        // The dispatcher keeps its own private copy of every sent attachment.
        $this->deleteFiles($draft);

        return MailSendResult::accepted($email);
    }

    private function settleFromLedger(MailDraft $draft, OutboundEmail $row): MailSendResult
    {
        if ($row->accepted_at !== null) {
            return $this->finishAccepted($draft, $row);
        }
        $draft->forceFill(['state' => MailDraft::STATE_UNCONFIRMED, 'outbound_email_id' => $row->getKey()])->save();

        return MailSendResult::unconfirmed($row);
    }

    /**
     * True when the ledger proves the provider never took this message: it failed before submission, or the provider
     * answered with a definite refusal. Anything uncertain stays false, so it is never sent twice.
     */
    public static function nothingWasSent(OutboundEmail $row): bool
    {
        return $row->accepted_at === null && in_array($row->status, ['failed_pre_acceptance', 'rejected', 'failed'], true);
    }

    private function providerRefusal(OutboundEmail $row): string
    {
        $codes = $row->recipients()->pluck('failure_code');

        return match (true) {
            $codes->contains('E_RECIPIENT_SUPPRESSED') => 'The email service will not deliver to one of these addresses because it is on its suppression list, so nothing was sent. Remove that address and try again.',
            $codes->contains('HTTP_401'), $codes->contains('HTTP_403') => 'The email service refused this server\'s access, so nothing was sent. Your draft is kept. Ask an administrator.',
            default => 'The email service refused this message, so nothing was sent. Check the recipients, subject and attachments.',
        };
    }

    private function ledgerRow(MailDraft $draft): ?OutboundEmail
    {
        return OutboundEmail::query()->where('source_id', $draft->ledgerSourceId())
            ->whereIn('source_type', MailThreadIndexer::HUMAN_SOURCES)->orderByDesc('id')->first();
    }

    private function pauseMessage(?int $retryAfterSeconds): string
    {
        return $retryAfterSeconds === null
            ? 'Nothing was sent. The email safety limit has been reached; ask an administrator to check Communications Usage. Your draft is kept.'
            : 'Nothing was sent. Email sending is paused for now. Your draft is kept; try again in about '.max(1, (int) ceil($retryAfterSeconds / 60)).' minute(s).';
    }

    private function dispatcherRefusal(InvalidArgumentException $exception): string
    {
        return match (true) {
            str_contains($exception->getMessage(), 'recipient limit') => 'There are too many recipients. For a message to a larger group, use Department Updates.',
            str_contains($exception->getMessage(), 'too large'), str_contains($exception->getMessage(), 'attachment limit') => 'This message is too large to send. Remove or shrink an attachment.',
            str_contains($exception->getMessage(), 'attachment') => 'An attachment is not allowed. Remove it and try again.',
            str_contains($exception->getMessage(), 'not securely configured') => 'Email sending is not set up on this server, so nothing was sent. Ask an administrator.',
            default => 'The email service refused this message, so nothing was sent. Check the recipients, subject and attachments.',
        };
    }

    // ---------------------------------------------------------------------------------------------
    // Sources, authorization, files
    // ---------------------------------------------------------------------------------------------

    /** @return array{0: InboundEmail|OutboundEmail, 1: MailThread} */
    private function resolveSource(?int $threadId, ?string $sourceKind, ?int $sourceId): array
    {
        $thread = null;
        if ($threadId !== null) {
            $thread = MailThread::query()->find($threadId);
            if ($thread === null) {
                throw MailActionRefused::forbidden('That conversation was not found.');
            }
        }

        if ($sourceKind === null || $sourceId === null) {
            if ($thread === null) {
                throw ValidationException::withMessages(['source' => 'Choose the message to answer.']);
            }
            // Default: the newest message people wrote to us, else the newest thing we sent.
            $members = MailThreadMessage::query()->where('thread_id', $thread->getKey())->orderByDesc('message_at')->orderByDesc('id')->get();
            $pick = $members->first(fn (MailThreadMessage $member): bool => $member->inbound_email_id !== null) ?? $members->first();
            if ($pick === null) {
                throw MailActionRefused::forbidden('That conversation has no messages to answer.');
            }
            [$sourceKind, $sourceId] = $pick->inbound_email_id !== null ? ['inbound', (int) $pick->inbound_email_id] : ['outbound', (int) $pick->outbound_email_id];
        }
        if (! in_array($sourceKind, ['inbound', 'outbound'], true)) {
            throw ValidationException::withMessages(['source' => 'Unknown message type.']);
        }

        $source = ($sourceKind === 'inbound' ? InboundEmail::query() : OutboundEmail::query())->find($sourceId);
        if ($source === null) {
            throw MailActionRefused::forbidden('That message is no longer available.');
        }
        $actual = $this->threadOfSource($source);
        if ($actual === null) {
            $this->indexer->{$sourceKind === 'inbound' ? 'indexInbound' : 'indexOutbound'}($source);
            $actual = $this->threadOfSource($source);
        }
        if ($actual === null || ($thread !== null && $actual->getKey() !== $thread->getKey())) {
            throw MailActionRefused::forbidden('That message does not belong to this conversation.');
        }

        return [$source, $actual];
    }

    private function sourceOf(MailDraft $draft): InboundEmail|OutboundEmail|null
    {
        if ($draft->source_kind === null || $draft->source_id === null) {
            return null;
        }

        return ($draft->source_kind === 'inbound' ? InboundEmail::query() : OutboundEmail::query())->find($draft->source_id);
    }

    private function threadOfSource(InboundEmail|OutboundEmail $source): ?MailThread
    {
        $membership = MailThreadMessage::query()
            ->where($source instanceof InboundEmail ? 'inbound_email_id' : 'outbound_email_id', $source->getKey())->first();

        return $membership === null ? null : MailThread::query()->find($membership->thread_id);
    }

    private function authorize(User $user): void
    {
        if (! $this->access->canSend($user) || ! $this->access->canView($user)) {
            throw MailActionRefused::forbidden('You do not have permission to write email.');
        }
    }

    private function authorizeOwner(User $user, MailDraft $draft): void
    {
        $this->authorize($user);
        // The row is re-read: a stale in-memory model must not decide who owns a draft.
        $owner = MailDraft::query()->whereKey($draft->getKey())->value('user_id');
        if ($owner === null || (int) $owner !== (int) $user->getKey()) {
            throw MailActionRefused::draftNotFound();
        }
    }

    private function requireOpen(MailDraft $draft): void
    {
        $state = MailDraft::query()->whereKey($draft->getKey())->value('state');
        if ($state !== MailDraft::STATE_OPEN) {
            throw MailActionRefused::draftLocked('This draft can no longer be changed.');
        }
    }

    private function lockedMessage(MailDraft $draft): string
    {
        return match ($draft->state) {
            MailDraft::STATE_SENDING => 'This message is being sent right now.',
            MailDraft::STATE_SENT => 'This message was already sent.',
            default => 'This draft is locked because its delivery could not be confirmed. Check Sent before writing it again.',
        };
    }

    private function setState(MailDraft $draft, string $state): void
    {
        MailDraft::query()->whereKey($draft->getKey())->update(['state' => $state, 'updated_at' => now()]);
        $draft->refresh();
    }

    /** @return list<string> */
    private function recipientEntries(mixed $value): array
    {
        $entries = [];
        foreach (is_array($value) ? $value : [$value] as $entry) {
            if (is_array($entry) && isset($entry['address'])) {
                $entry = $entry['address'];
            }
            if (! is_string($entry)) {
                continue;
            }
            $entry = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $entry));
            if ($entry === '') {
                continue;
            }
            if (mb_strlen($entry) > 320) {
                throw ValidationException::withMessages(['recipients' => 'An address is far too long to be valid.']);
            }
            $entries[mb_strtolower($entry)] ??= $entry;
        }
        if (count($entries) > self::MAX_RECIPIENT_ENTRIES) {
            throw ValidationException::withMessages(['recipients' => 'Too many recipients. For a larger group, use Department Updates.']);
        }

        return array_values($entries);
    }

    // ---------------------------------------------------------------------------------------------
    // Attachment storage
    // ---------------------------------------------------------------------------------------------

    private function storeAttachment(MailDraft $draft, string $filename, string $mime, string $content): MailDraftAttachment
    {
        $allowed = (array) config('communications.allowed_attachment_mime_types', []);
        if (! in_array($mime, $allowed, true)) {
            throw ValidationException::withMessages(['attachment' => 'That file type cannot be sent. Allowed: PDF, Word, Excel, CSV, plain text, PNG, JPEG and GIF.']);
        }
        if ($content === '') {
            throw ValidationException::withMessages(['attachment' => 'That file is empty.']);
        }
        $max = (int) config('communications.cloudflare.max_attachments', 5);
        if ($draft->attachments()->count() >= $max) {
            throw ValidationException::withMessages(['attachment' => "A message can carry at most {$max} files."]);
        }
        $used = (int) $draft->attachments()->sum('size');
        $cap = $this->attachmentByteCap();
        if ($used + strlen($content) > $cap) {
            throw ValidationException::withMessages(['attachment' => 'Attachments may total '.number_format($cap / 1048576, 1).' MB in all. Remove a file or send it another way.']);
        }

        $path = 'mail-draft-attachments/'.$draft->getKey().'/'.Str::uuid();
        if (! Storage::disk('local')->put($path, $content)) {
            throw ValidationException::withMessages(['attachment' => 'That file could not be saved. Try again.']);
        }
        $row = $draft->attachments()->create([
            'filename' => $filename, 'mime_type' => $mime, 'size' => strlen($content), 'disk' => 'local',
            'path' => $path, 'sha256' => hash('sha256', $content),
        ]);
        $draft->forceFill(['updated_at' => now()])->save();

        return $row;
    }

    /** Raw bytes allowed in total: the attachment cap, shrunk so the base64 form still fits the provider's message cap. */
    public function attachmentByteCap(): int
    {
        $configured = (int) config('communications.cloudflare.max_attachment_bytes', 3500000);
        $fitsMessage = (int) floor(((int) config('communications.cloudflare.max_message_bytes', 4500000) - self::MESSAGE_OVERHEAD_BYTES) * 3 / 4);

        return max(0, min($configured, $fitsMessage));
    }

    private function deleteFiles(MailDraft $draft): void
    {
        foreach ($draft->attachments()->get() as $attachment) {
            Storage::disk($attachment->disk)->delete($attachment->path);
        }
        $draft->attachments()->delete();
    }

    private function safeFilename(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', '', $name));
        $name = mb_substr($name, 0, 200);

        return $name === '' || in_array($name, ['.', '..'], true) ? 'attachment' : $name;
    }

    /** The type the bytes actually are, not what the browser claimed. */
    private function detectedType(UploadedFile $file, string $name, string $content): string
    {
        $detected = strtolower((string) $file->getMimeType());
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $declared = self::UPLOAD_TYPE_BY_EXTENSION[$extension] ?? null;

        // libmagic often sees an Office file as a plain zip, and a CSV as plain text; the signature still has to match.
        if ($declared !== null && in_array($detected, ['application/zip', 'application/octet-stream', 'text/plain', $declared], true)) {
            $isZip = str_starts_with($content, "PK\x03\x04");
            if ($extension === 'csv' ? ($detected === 'text/plain' || $detected === $declared) : $isZip) {
                return $declared;
            }
        }

        return $detected;
    }

    private function report(string $stage, MailDraft $draft, Throwable $exception): void
    {
        // Class and ids only. An exception message can embed addresses, subjects or bodies.
        Log::warning('mail_draft_send_problem', ['stage' => $stage, 'draft' => $draft->getKey(), 'exception' => $exception::class]);
    }
}
