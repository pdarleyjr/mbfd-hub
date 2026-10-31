<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Models\OutboundEmail;

/**
 * What happened when a draft was sent. The four outcomes are exclusive and each carries a message written for
 * the member who pressed Send:
 *
 *  accepted    the provider took the message; the draft is sent and `outbound` is its ledger row.
 *  deferred    nothing was sent (a budget or rate pause); the draft stays open. Try again after retryAfterSeconds.
 *  unconfirmed the provider's answer was lost or ambiguous; the message may have gone. The draft is locked, the
 *              ledger row is linked, and nothing will ever resend it automatically. Check Sent first.
 *  refused     nothing was sent because the draft is not ready (recipients, subject, size, access); it stays open.
 */
final class MailSendResult
{
    public const ACCEPTED = 'accepted';

    public const DEFERRED = 'deferred';

    public const UNCONFIRMED = 'unconfirmed';

    public const REFUSED = 'refused';

    private function __construct(
        public readonly string $status,
        public readonly string $message,
        public readonly ?OutboundEmail $outbound = null,
        public readonly ?int $retryAfterSeconds = null,
    ) {}

    public static function accepted(OutboundEmail $outbound, string $message = 'Message sent.'): self
    {
        return new self(self::ACCEPTED, $message, $outbound);
    }

    public static function deferred(string $message, ?int $retryAfterSeconds): self
    {
        return new self(self::DEFERRED, $message, null, $retryAfterSeconds);
    }

    public static function unconfirmed(?OutboundEmail $outbound, string $message = 'The email service did not confirm this message, so it may or may not have been sent. Check Sent before writing it again. It will not be resent automatically.'): self
    {
        return new self(self::UNCONFIRMED, $message, $outbound);
    }

    public static function refused(string $message): self
    {
        return new self(self::REFUSED, $message);
    }

    public function isAccepted(): bool
    {
        return $this->status === self::ACCEPTED;
    }

    public function isDeferred(): bool
    {
        return $this->status === self::DEFERRED;
    }

    public function isUnconfirmed(): bool
    {
        return $this->status === self::UNCONFIRMED;
    }

    public function isRefused(): bool
    {
        return $this->status === self::REFUSED;
    }
}
