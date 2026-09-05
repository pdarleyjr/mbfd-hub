<?php

declare(strict_types=1);

namespace App\Services\Communications;

use App\Models\OutboundEmail;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Throwable;

final class CloudflareEmailDispatcher
{
    public function __construct(private readonly CloudflareCostGuard $costGuard) {}

    /**
     * @param  list<string>  $to
     * @param  list<string>  $cc
     * @param  list<string>  $bcc
     * @param  list<array{filename: string, type: string, content: string}>  $attachments
     */
    public function send(
        array $to,
        string $subject,
        ?string $text,
        ?string $html,
        string $sourceType,
        ?string $sourceId = null,
        ?User $actor = null,
        array $cc = [],
        array $bcc = [],
        ?string $replyTo = null,
        array $attachments = [],
    ): OutboundEmail {
        $to = $this->normalizeAddresses($to);
        $cc = array_values(array_diff($this->normalizeAddresses($cc), $to));
        $bcc = array_values(array_diff($this->normalizeAddresses($bcc), $to, $cc));
        $recipients = [...$to, ...$cc, ...$bcc];
        if ($recipients === [] || count($recipients) > (int) config('communications.cloudflare.max_recipients_per_message', 10)) {
            throw new InvalidArgumentException('The Cloudflare recipient limit was exceeded.');
        }
        foreach ($recipients as $recipient) {
            if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
                throw new InvalidArgumentException('An outbound recipient is invalid.');
            }
        }

        $from = strtolower(trim((string) config('communications.cloudflare.from_address', 'info@mbfdhub.com')));
        if (filter_var($from, FILTER_VALIDATE_EMAIL) === false
            || ($replyTo !== null && filter_var($replyTo, FILTER_VALIDATE_EMAIL) === false)
            || mb_strlen($subject) > 998
            || (blank($text) && blank($html))) {
            throw new InvalidArgumentException('The outbound message envelope or content is invalid.');
        }

        $accountId = (string) config('communications.cloudflare.account_id', '');
        $apiToken = (string) config('communications.cloudflare.api_token', '');
        if (preg_match('/^[a-f0-9]{32}$/i', $accountId) !== 1 || $apiToken === '') {
            throw new InvalidArgumentException('Cloudflare Email Sending is not securely configured.');
        }

        [$attachmentPayload, $attachmentMetadata] = $this->prepareAttachments($attachments);
        $payload = array_filter([
            'from' => $from,
            'to' => $to,
            'cc' => $cc,
            'bcc' => $bcc,
            'reply_to' => $replyTo,
            'subject' => $subject,
            'text' => $text,
            'html' => $html,
            'attachments' => $attachmentPayload,
        ], fn (mixed $value): bool => $value !== null && $value !== [] && $value !== '');
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        if (strlen($encoded) > (int) config('communications.cloudflare.max_message_bytes', 4500000)) {
            throw new InvalidArgumentException('The outbound message is too large.');
        }

        $now = CarbonImmutable::now();
        $email = OutboundEmail::query()->create([
            'provider' => 'cloudflare',
            'initiated_by_user_id' => $actor?->getKey(),
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'from_address' => $payload['from'],
            'reply_to' => $replyTo,
            'to_recipients' => $to,
            'cc_recipients' => $cc,
            'bcc_recipients' => $bcc,
            'subject' => $subject,
            'text_body' => $text,
            'html_body' => $html,
            'attachment_metadata' => $attachmentMetadata,
            'recipient_count' => count($recipients),
            'chargeable_budget_units' => count($recipients),
            'status' => 'pending',
            'queued_at' => $now,
        ]);

        $this->costGuard->reserve($email, $now);
        $endpoint = "https://api.cloudflare.com/client/v4/accounts/{$accountId}/email/sending/send";

        try {
            $email->forceFill(['status' => 'submitted', 'submitted_at' => $now])->save();
            $response = Http::acceptJson()
                ->withToken($apiToken)
                ->timeout(20)
                ->post($endpoint, $payload)
                ->throw();
            $providerMessageId = (string) data_get($response->json(), 'result.message_id', '');
            if ($providerMessageId === '') {
                throw new RequestException($response);
            }

            $email = $this->costGuard->markAccepted($email, $providerMessageId, CarbonImmutable::now());
            $delivered = (array) data_get($response->json(), 'result.delivered', []);
            $queued = (array) data_get($response->json(), 'result.queued', []);
            $bounces = (array) data_get($response->json(), 'result.permanent_bounces', []);
            $suppressed = (array) data_get($response->json(), 'result.suppressed_recipients', []);
            if (count($delivered) === count($recipients)) {
                $email->forceFill(['status' => 'delivered', 'delivered_at' => CarbonImmutable::now()])->save();
            } elseif ($bounces !== [] || $suppressed !== []) {
                $email->forceFill([
                    'status' => 'accepted_with_delivery_issues',
                    'failure_reason' => 'Cloudflare reported a permanent bounce or suppressed recipient after acceptance.',
                ])->save();
            } elseif ($queued !== []) {
                $email->forceFill(['status' => 'queued'])->save();
            }

            return $email;
        } catch (Throwable $exception) {
            $this->costGuard->releaseBeforeAcceptance($email, 'Cloudflare rejected the message before acceptance.', CarbonImmutable::now());
            throw $exception;
        }
    }

    /** @param list<string> $addresses @return list<string> */
    private function normalizeAddresses(array $addresses): array
    {
        return array_values(array_unique(array_map(
            fn (string $address): string => strtolower(trim($address)),
            $addresses,
        )));
    }

    /**
     * @param  list<array{filename: string, type: string, content: string}>  $attachments
     * @return array{list<array{filename: string, type: string, disposition: string, content: string}>, list<array{filename: string, type: string, size: int}>}
     */
    private function prepareAttachments(array $attachments): array
    {
        if (count($attachments) > (int) config('communications.cloudflare.max_attachments', 5)) {
            throw new InvalidArgumentException('Too many outbound attachments were supplied.');
        }

        $allowedTypes = (array) config('communications.allowed_attachment_mime_types', []);
        $maximumBytes = (int) config('communications.cloudflare.max_attachment_bytes', 3500000);
        $totalBytes = 0;
        $payload = [];
        $metadata = [];

        foreach ($attachments as $attachment) {
            $filename = trim($attachment['filename']);
            $type = strtolower(trim($attachment['type']));
            $content = $attachment['content'];
            $safeFilename = basename(str_replace('\\', '/', $filename));
            $decoded = base64_decode($content, true);
            if ($safeFilename === '' || in_array($safeFilename, ['.', '..'], true)
                || $safeFilename !== $filename || ! in_array($type, $allowedTypes, true)
                || $decoded === false) {
                throw new InvalidArgumentException('An outbound attachment is invalid or unsafe.');
            }

            $size = strlen($decoded);
            $totalBytes += $size;
            if ($totalBytes > $maximumBytes) {
                throw new InvalidArgumentException('The outbound attachment limit was exceeded.');
            }

            $payload[] = [
                'filename' => $safeFilename,
                'type' => $type,
                'disposition' => 'attachment',
                'content' => $content,
            ];
            $metadata[] = ['filename' => $safeFilename, 'type' => $type, 'size' => $size];
        }

        return [$payload, $metadata];
    }
}
