<?php

declare(strict_types=1);

namespace App\Support\Mail;

/**
 * Identifies the sender in system-generated mail, and nothing else.
 *
 * Text gets a short plain sign-off. A notification that already sends HTML gets a minimal, valid,
 * accessible document around its existing content and a one-line reason the recipient received it.
 * There are no images, no tracking and no promotional styling. Existing content (links, tokens,
 * wording) is passed through byte for byte. Human-composed mail is never altered here.
 */
final class TransactionalMessageFormatter
{
    private const DEFAULT_ORGANIZATION = 'Miami Beach Fire Department';

    private const PRODUCT = 'MBFD Hub';

    private const REASON = 'You are receiving this because MBFD Hub is set to send this type of notification to your email address.';

    public function organization(): string
    {
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string) config('communications.cloudflare.from_name', self::DEFAULT_ORGANIZATION)));

        return $name === '' ? self::DEFAULT_ORGANIZATION : $name;
    }

    /** Appends the sign-off only. Used for account-security mail, which stays text-only. */
    public function signOffText(string $text): string
    {
        return $text.$this->signOff();
    }

    /**
     * @param  array{subject: string, text?: ?string, html?: ?string}  $payload
     * @return array{subject: string, text?: ?string, html?: ?string}
     */
    public function notification(array $payload): array
    {
        if (isset($payload['text'])) {
            $payload['text'] = $payload['text'].$this->signOff()."\n".self::REASON;
        }
        if (isset($payload['html'])) {
            $payload['html'] = $this->document($payload['subject'], $payload['html']);
        }

        return $payload;
    }

    private function signOff(): string
    {
        return "\n\n--\n".$this->organization()."\n".self::PRODUCT;
    }

    private function document(string $subject, string $content): string
    {
        $organization = e($this->organization());

        return '<!DOCTYPE html>'."\n"
            .'<html lang="en">'."\n"
            .'<head>'."\n"
            .'<meta charset="utf-8">'."\n"
            .'<meta name="viewport" content="width=device-width, initial-scale=1">'."\n"
            .'<title>'.e($subject).'</title>'."\n"
            .'</head>'."\n"
            .'<body style="margin:0;padding:16px;font-family:Arial,Helvetica,sans-serif;font-size:16px;line-height:1.5;color:#1a1a1a;background-color:#ffffff;">'."\n"
            .$content."\n"
            .'<hr style="border:0;border-top:1px solid #cccccc;margin:24px 0 12px;">'."\n"
            .'<p style="margin:0;font-size:14px;color:#4d4d4d;">'.$organization.', '.e(self::PRODUCT).'</p>'."\n"
            .'<p style="margin:4px 0 0;font-size:14px;color:#4d4d4d;">'.e(self::REASON).'</p>'."\n"
            .'</body>'."\n"
            .'</html>'."\n";
    }
}
