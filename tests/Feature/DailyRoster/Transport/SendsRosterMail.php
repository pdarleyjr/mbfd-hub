<?php

declare(strict_types=1);

namespace Tests\Feature\DailyRoster\Transport;

use App\Models\DailyRoster\RosterImport;
use App\Models\InboundEmail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/** Signs synthetic mail the way the Worker does. The workbook bytes are a fake ZIP header. */
trait SendsRosterMail
{
    private const PASSING_RESULTS = 'mx.cloudflare.net; dkim=pass header.d=miamibeachfl.gov; spf=pass smtp.mailfrom=donotreply@miamibeachfl.gov; dmarc=pass header.from=miamibeachfl.gov';

    private const WORKBOOK_MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    /** @param array<string, mixed> $overrides */
    private function rosterMail(array $overrides = []): array
    {
        return array_replace([
            'message_id' => '<'.Str::random(12).'.1.2@sample.test>',
            'from' => 'donotreply@miamibeachfl.gov',
            'to' => 'info@mbfdhub.com',
            'subject' => 'Roster Report',
            'text' => null,
            'html' => null,
            'received_at' => now()->toIso8601String(),
            'envelope_from' => 'donotreply@miamibeachfl.gov',
            'authentication_evidence' => [
                'authentication_results' => self::PASSING_RESULTS,
                'arc_authentication_results' => null,
                'received_spf' => null,
                'cloudflare_claims' => ['authentication_results' => 1, 'arc_authentication_results' => 0],
                'truncated' => false,
            ],
            'in_reply_to' => null,
            'references' => [],
            'attachments' => [$this->workbookAttachment()],
        ], $overrides);
    }

    private function workbookAttachment(string $filename = 'Dailyroster.xlsx', string $bytes = "PK\x03\x04synthetic-workbook"): array
    {
        return ['filename' => $filename, 'mime_type' => self::WORKBOOK_MIME, 'content' => base64_encode($bytes)];
    }

    /** @param array<string, mixed> $payload */
    private function deliverMail(array $payload, ?string $nonce = null, ?int $timestamp = null, ?string $secret = null): TestResponse
    {
        config()->set('communications.inbound.secret', 'test-inbound-secret');
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $nonce ??= (string) Str::uuid();
        $timestamp = (string) ($timestamp ?? now()->timestamp);
        $signature = hash_hmac('sha256', $timestamp."\n".$nonce."\n".$body, $secret ?? 'test-inbound-secret');

        return $this->call('POST', '/api/v2/email/inbound', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_MBFD_TIMESTAMP' => $timestamp,
            'HTTP_X_MBFD_NONCE' => $nonce,
            'HTTP_X_MBFD_SIGNATURE' => $signature,
        ], $body);
    }

    private function ledgerFor(string $messageId): ?RosterImport
    {
        return RosterImport::query()->where('message_id_sha256', hash('sha256', $messageId))->first();
    }

    private function inboundFor(string $messageId): ?InboundEmail
    {
        return InboundEmail::query()->where('provider_message_id', $messageId)->first();
    }

    /** @return list<string> */
    private function storedAttachments(): array
    {
        return \Illuminate\Support\Facades\Storage::disk('local')->allFiles('inbound-email-attachments');
    }
}
