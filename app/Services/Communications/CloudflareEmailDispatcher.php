<?php

declare(strict_types=1);

namespace App\Services\Communications;

use App\Exceptions\EmailBudgetExhausted;
use App\Models\OutboundEmail;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

final class CloudflareEmailDispatcher
{
    public function __construct(private readonly CloudflareCostGuard $costGuard, private readonly OutboundDeliveryLedger $deliveryLedger) {}

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
        array $headers = [],
        ?int $parentOutboundEmailId = null,
        ?int $parentInboundEmailId = null,
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

        foreach ($headers as $name => $value) {
            if (! in_array($name, ['In-Reply-To', 'References'], true) || ! is_string($value)
                || preg_match('/[\r\n]/', $value) || strlen($value) > 2048) {
                throw new InvalidArgumentException('Invalid email threading headers.');
            }
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
            'headers' => $headers,
        ], fn (mixed $value): bool => $value !== null && $value !== [] && $value !== '');
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        if (strlen($encoded) > (int) config('communications.cloudflare.max_message_bytes', 4500000)) {
            throw new InvalidArgumentException('The outbound message is too large.');
        }

        $now = CarbonImmutable::now();
        // The provider receives the original message, but communications viewers
        // must never be able to retrieve account-security bearer tokens.
        $sensitive = in_array($sourceType, [
            'password_reset',
            'city_email_verification',
            'city_email_changed',
            'identity_recovery',
            'identity_administrative_recovery',
            'member_onboarding_invitation',
        ], true);
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
            'text_body' => $sensitive ? '[Sensitive account-security message omitted]' : $text,
            'html_body' => $sensitive ? null : $html,
            'attachment_metadata' => $attachmentMetadata,
            'recipient_count' => count($recipients),
            'chargeable_budget_units' => count($recipients),
            'status' => 'pending',
            'queued_at' => $now,
            'parent_outbound_email_id' => $parentOutboundEmailId,
            'parent_inbound_email_id' => $parentInboundEmailId,
            'in_reply_to' => $headers['In-Reply-To'] ?? null,
            'references' => isset($headers['References']) ? preg_split('/\s+/', trim($headers['References'])) : null,
        ]);

        $this->deliveryLedger->initialize($email);
        try {
            if (! $sensitive && $attachmentPayload !== []) {
                foreach ($attachmentPayload as $index => $attachment) {
                    $path = 'outbound-email-attachments/'.$email->id.'/'.Str::uuid();
                    if (! Storage::disk('local')->put($path, base64_decode($attachment['content'], true))) {
                        throw new \RuntimeException('Unable to securely store an outbound attachment.');
                    }
                    $attachmentMetadata[$index]['disk'] = 'local';
                    $attachmentMetadata[$index]['path'] = $path;
                }
                $email->forceFill(['attachment_metadata' => $attachmentMetadata])->save();
            }
            $email = $this->costGuard->reserve($email, $now);
        } catch (EmailBudgetExhausted $exception) {
            $this->costGuard->releaseBeforeAcceptance($email, 'Email sending safety checks blocked delivery.', $now);
            $email->recipients()->update(['status' => 'failed', 'is_terminal' => true, 'failed_at' => $now]);
            throw $exception;
        } catch (Throwable $exception) {
            $this->costGuard->releaseBeforeAcceptance($email, 'Message preparation failed before provider submission.', $now);
            $email->recipients()->update(['status' => 'failed', 'is_terminal' => true, 'failed_at' => $now]);
            throw $exception;
        }
        $endpoint = "https://api.cloudflare.com/client/v4/accounts/{$accountId}/email/sending/send";

        try {
            $email->forceFill(['status' => 'submitted', 'submitted_at' => $now])->save();
            $email->recipients()->update(['status' => 'submitted']);
            $response = Http::acceptJson()
                ->withToken($apiToken)
                ->timeout(20)
                ->post($endpoint, $payload)
                ->throw();
            $providerMessageId = (string) data_get($response->json(), 'result.message_id', '');
            if ($providerMessageId === '' || $response->json('success') !== true) {
                throw new RequestException($response);
            }

            $email = $this->costGuard->markAccepted($email, $providerMessageId, CarbonImmutable::now());
            if (preg_match('/^<[^<>\s@]+@[^<>\s@]+>$/', $providerMessageId) === 1) {
                $email->forceFill(['message_id' => $providerMessageId])->save();
            }
            $email = $this->deliveryLedger->initialResponse($email, (array) $response->json('result'), CarbonImmutable::now());

            return $email;
        } catch (Throwable $exception) {
            // A lost response (or a failed local save) is not evidence that the
            // provider rejected the message. Keep its budget and never retry here.
            $this->costGuard->markUncertain($email, CarbonImmutable::now());
            if ($email->fresh()->accepted_at === null) {
                $email->recipients()->where('is_terminal', false)->update(['status' => 'unknown']);
                $rejection = null;
                if ($exception instanceof RequestException) {
                    $statusCode = $exception->response->status();
                    if (in_array($statusCode, [401, 403], true)) {
                        $rejection = ['failed', 'HTTP_'.$statusCode, 'Provider denied authentication or sending permission.'];
                    } elseif (in_array($statusCode, [400, 422], true)) {
                        $errors = collect((array) $exception->response->json('errors'));
                        if ($errors->contains(fn (mixed $error): bool => is_array($error)
                            && in_array('E_RECIPIENT_SUPPRESSED', [$error['code'] ?? null, $error['message'] ?? null], true))) {
                            $rejection = ['rejected', 'E_RECIPIENT_SUPPRESSED', 'Provider rejected the request because a recipient is suppressed.'];
                        } elseif ($errors->contains(fn (mixed $error): bool => is_array($error)
                            && ($error['message'] ?? null) === 'email.sending.error.invalid_request_schema')) {
                            $rejection = ['rejected', 'INVALID_REQUEST_SCHEMA', 'Provider rejected the request schema.'];
                        }
                    }
                }
                if ($rejection !== null) {
                    foreach ($recipients as $address) {
                        $this->deliveryLedger->record($email, $address, $rejection[0], 'send_response.rejected', CarbonImmutable::now(),
                            $rejection[1], $rejection[2]);
                    }
                }
            }
            if ($exception instanceof RequestException && $exception->response->status() === 429) {
                $retryAfter = $exception->response->header('Retry-After');
                $now = CarbonImmutable::now();
                $seconds = filter_var($retryAfter, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
                $date = \DateTimeImmutable::createFromFormat(DATE_RFC7231, $retryAfter);
                $until = $seconds !== false
                    ? $now->addSeconds(max(1, $seconds))
                    : ($date !== false ? CarbonImmutable::instance($date) : $now->addMinute());
                $this->costGuard->deferUntil($until->isFuture() ? $until : $now->addMinute());
            }
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
