<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Models\InboundEmail;
use App\Models\Mail\MailDraft;
use App\Models\OutboundEmail;
use App\Services\Communications\EmailConversation;
use App\Support\Mail\MailSignature;

/**
 * Works out who a reply goes to, what it is called, and what it quotes. It reads only the message being
 * answered (the authoritative row). It must never read thread notes: nothing here can see them, and a test
 * proves no mail-sending code names the notes model.
 *
 * Differences from the reference project, on purpose: addresses come from a real RFC 5322 parser instead of a
 * comma split; Reply-To is honoured; the Hub's own addresses and the original sender are taken out of Cc
 * case-insensitively; "Re:" is added once; and a forward starts with no recipients and no threading headers.
 */
final class MailComposer
{
    private const QUOTE_LIMIT = 20000;

    public function __construct(
        private readonly EmailConversation $conversation,
        private readonly AddressParser $addresses,
        private readonly MailText $text,
    ) {}

    /** @return list<string> lower-cased addresses that belong to the Hub itself */
    public function ownAddresses(): array
    {
        return array_values(array_filter(array_unique([
            strtolower(trim((string) config('communications.cloudflare.from_address'))),
            strtolower(trim((string) config('communications.inbound.address'))),
        ])));
    }

    /** @return array{to: list<string>, cc: list<string>, subject: string, body_html: string} */
    public function prefill(string $mode, InboundEmail|OutboundEmail $source): array
    {
        $own = $this->ownAddresses();
        if ($mode === MailDraft::MODE_FORWARD) {
            return [
                'to' => [], 'cc' => [],
                'subject' => $this->text->forwardSubject($source->subject),
                'body_html' => $this->forwardBlock($source),
            ];
        }

        [$toList, $ccList, $senderAddress] = $this->audience($source, $mode === MailDraft::MODE_REPLY_ALL);
        $to = array_values(array_diff($toList, $own));
        // Never copy the Hub itself, the person the reply already goes to, or the sender (who may differ from Reply-To).
        $cc = array_values(array_diff($ccList, $own, $to, $senderAddress === null ? [] : [$senderAddress]));
        if ($to === [] && $senderAddress !== null && ! in_array($senderAddress, $own, true)) {
            $to = [$senderAddress];
        }

        return [
            'to' => $to, 'cc' => $cc,
            'subject' => $this->text->replySubject($source->subject),
            'body_html' => $this->replyQuote($source),
        ];
    }

    /** @return array<string, string> In-Reply-To and References for a reply, none for a forward */
    public function headers(string $mode, InboundEmail|OutboundEmail $source): array
    {
        return $this->conversation->headers($source, $mode === MailDraft::MODE_FORWARD ? 'forward' : 'reply');
    }

    /** @return array{0: list<string>, 1: list<string>, 2: ?string} to, cc candidates, original sender address */
    private function audience(InboundEmail|OutboundEmail $source, bool $all): array
    {
        if ($source instanceof InboundEmail) {
            $headers = (array) $source->safe_headers;
            $sender = $this->addresses->addresses($source->from_address)[0] ?? null;
            $replyTo = $this->addresses->addresses($headers['reply-to'] ?? null);
            $to = $replyTo !== [] ? $replyTo : ($sender === null ? [] : [$sender]);
            $cc = $all ? $this->addresses->addresses([$source->to_address, $headers['to'] ?? [], $headers['cc'] ?? []]) : [];

            return [$to, $cc, $sender];
        }

        // Answering a message we sent: the people we wrote to. Bcc recipients are never revived.
        $to = $this->addresses->addresses($source->to_recipients ?? []);
        $cc = $all ? $this->addresses->addresses($source->cc_recipients ?? []) : [];

        return [$to, $cc, null];
    }

    private function replyQuote(InboundEmail|OutboundEmail $source): string
    {
        $when = ($source instanceof InboundEmail ? $source->received_at : ($source->queued_at ?? $source->created_at))
            ?->timezone((string) config('app.timezone'))->format('D, M j, Y \a\t g:i A');
        $who = $source instanceof InboundEmail
            ? trim(($source->from_display_name ? $source->from_display_name.' ' : '').'<'.$source->from_address.'>')
            : '<'.$source->from_address.'>';

        return MailSignature::TYPING_SPACE.'<p>On '.e((string) $when).', '.e($who).' wrote:</p><blockquote>'.$this->quotedBody($source).'</blockquote>';
    }

    private function forwardBlock(InboundEmail|OutboundEmail $source): string
    {
        $when = ($source instanceof InboundEmail ? $source->received_at : ($source->queued_at ?? $source->created_at))
            ?->timezone((string) config('app.timezone'))->format('D, M j, Y \a\t g:i A');
        $to = $source instanceof InboundEmail
            ? implode(', ', $this->addresses->addresses([$source->to_address, (array) ($source->safe_headers['to'] ?? [])]))
            : implode(', ', $this->addresses->addresses($source->to_recipients ?? []));

        return MailSignature::TYPING_SPACE.'<p>---------- Forwarded message ----------<br>From: '.e((string) $source->from_address)
            .'<br>Date: '.e((string) $when).'<br>Subject: '.e((string) $source->subject).'<br>To: '.e($to).'</p>'
            .'<p>'.$this->escapedLines($this->originalText($source)).'</p>';
    }

    private function quotedBody(InboundEmail|OutboundEmail $source): string
    {
        return '<p>'.$this->escapedLines($this->originalText($source)).'</p>';
    }

    private function originalText(InboundEmail|OutboundEmail $source): string
    {
        $text = $source->text_body;
        if ($text === null || trim($text) === '') {
            $html = $source instanceof InboundEmail ? $source->sanitized_html_body : $source->html_body;
            $text = $this->text->fromHtml((string) $html);
        }

        return mb_substr((string) $text, 0, self::QUOTE_LIMIT);
    }

    /** Escaped, one <br> per line. The composer sanitizer still runs over the result when the draft is saved. */
    private function escapedLines(string $text): string
    {
        return implode('<br>', array_map(static fn (string $line): string => e($line), preg_split('/\R/u', trim($text)) ?: []));
    }
}
