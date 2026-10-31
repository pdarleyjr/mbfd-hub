<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Models\InboundEmail;
use App\Models\OutboundEmail;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * One message as the page shows it. Built from the authoritative row; nothing here is stored. Bcc is filled in
 * only for the person who sent the message. HTML is never carried: it is served by the sandboxed body route.
 */
final class MailMessageView
{
    /**
     * @param  list<array{address: string, name: ?string}>  $to
     * @param  list<array{address: string, name: ?string}>  $cc
     * @param  list<array{address: string, name: ?string}>  $bcc
     * @param  list<array{index: int, filename: string, mime_type: string, size: int, available: bool}>  $attachments
     * @param  list<array{filename: ?string, mime_type: ?string, size: int, reason: string}>  $skippedAttachments
     */
    public function __construct(
        public readonly string $kind,
        public readonly int $id,
        public readonly CarbonImmutable $at,
        public readonly string $fromAddress,
        public readonly ?string $fromName,
        public readonly array $to,
        public readonly array $cc,
        public readonly array $bcc,
        public readonly ?string $replyTo,
        public readonly ?string $subject,
        public readonly string $text,
        public readonly bool $hasHtml,
        public readonly array $attachments,
        public readonly array $skippedAttachments,
        public readonly ?string $sourceType,
        public readonly bool $isHuman,
        public readonly bool $isMine,
        public readonly bool $canRespond,
        public readonly ?string $deliveryStatus,
        public readonly ?string $deliveryStatusColor,
    ) {}

    public static function fromInbound(InboundEmail $email, MailText $text, AddressParser $parser, MailAttachmentLocator $locator, bool $canRespond): self
    {
        $headers = (array) $email->safe_headers;
        $skipped = [];
        $raw = $email->getAttribute('skipped_attachments');
        $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
        foreach (is_array($decoded) ? $decoded : [] as $part) {
            if (is_array($part)) {
                $skipped[] = [
                    'filename' => is_string($part['filename'] ?? null) ? $part['filename'] : null,
                    'mime_type' => is_string($part['mime_type'] ?? null) ? $part['mime_type'] : null,
                    'size' => (int) ($part['size'] ?? 0),
                    'reason' => (string) ($part['reason'] ?? 'unsupported_type'),
                ];
            }
        }

        return new self(
            kind: 'inbound', id: (int) $email->getKey(), at: CarbonImmutable::instance($email->received_at),
            fromAddress: (string) $email->from_address, fromName: $email->from_display_name,
            to: $parser->parse([$email->to_address, $headers['to'] ?? []]), cc: $parser->parse($headers['cc'] ?? []), bcc: [],
            replyTo: $parser->addresses($headers['reply-to'] ?? null)[0] ?? null,
            subject: $email->subject,
            text: $email->text_body ?? $text->fromHtml((string) $email->sanitized_html_body),
            hasHtml: filled($email->sanitized_html_body),
            attachments: $locator->listing($email), skippedAttachments: $skipped,
            sourceType: null, isHuman: true, isMine: false, canRespond: $canRespond,
            deliveryStatus: null, deliveryStatusColor: null,
        );
    }

    public static function fromOutbound(OutboundEmail $email, User $viewer, MailText $text, AddressParser $parser, MailAttachmentLocator $locator, bool $canRespond): self
    {
        $mine = $email->initiated_by_user_id !== null && (int) $email->initiated_by_user_id === (int) $viewer->getKey();
        // bcc_recipients is hidden on the model; read it through the attribute bag only for the author.
        $bcc = $mine ? $parser->parse($email->getAttribute('bcc_recipients') ?? []) : [];

        return new self(
            kind: 'outbound', id: (int) $email->getKey(), at: CarbonImmutable::instance($email->queued_at ?? $email->created_at),
            fromAddress: (string) $email->from_address, fromName: null,
            to: $parser->parse($email->to_recipients ?? []), cc: $parser->parse($email->cc_recipients ?? []), bcc: $bcc,
            replyTo: $email->reply_to, subject: $email->subject,
            text: $email->text_body ?? $text->fromHtml((string) $email->html_body),
            hasHtml: filled($email->html_body),
            attachments: $locator->listing($email), skippedAttachments: [],
            sourceType: $email->source_type, isHuman: in_array($email->source_type, MailThreadIndexer::HUMAN_SOURCES, true),
            isMine: $mine, canRespond: $canRespond,
            deliveryStatus: self::deliveryLabel($email), deliveryStatusColor: $email->deliveryStatusColor(),
        );
    }

    /** Status wording from the architecture: never "delivered to Inbox", which no signal can prove. */
    public static function deliveryLabel(OutboundEmail $email): string
    {
        $status = $email->effectiveDeliveryStatus();
        if ($status === 'rejected' && $email->recipients()->where('failure_code', 'E_RECIPIENT_SUPPRESSED')->exists()) {
            return 'Suppressed';
        }

        return match ($status) {
            'pending', 'reserved' => 'Queued',
            'submitted' => 'Submitted',
            'accepted', 'queued', 'accepted_with_delivery_issues' => 'Accepted by Cloudflare',
            'deferred' => 'Deferred',
            'delivered', 'partially_delivered', 'complained' => 'Accepted by receiving server',
            'bounced' => 'Bounced',
            'rejected' => 'Rejected',
            'failed', 'failed_pre_acceptance' => 'Failed',
            default => 'Unconfirmed',
        };
    }
}
