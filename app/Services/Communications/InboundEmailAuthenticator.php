<?php

declare(strict_types=1);

namespace App\Services\Communications;

use App\Models\InboundEmail;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

final class InboundEmailAuthenticator
{
    public function persist(Request $request): InboundEmail
    {
        $raw = $request->getContent();
        if (strlen($raw) > (int) config('communications.inbound.max_bytes', 5000000)) {
            throw new HttpException(413, 'Inbound email payload is too large.');
        }

        $timestamp = (string) $request->header('X-MBFD-Timestamp', '');
        $nonce = (string) $request->header('X-MBFD-Nonce', '');
        $provided = (string) $request->header('X-MBFD-Signature', '');
        $secret = (string) config('communications.inbound.secret', '');
        if ($timestamp === '' || $nonce === '' || $secret === '' || ! ctype_digit($timestamp)) {
            throw new AccessDeniedHttpException('Invalid inbound email authentication.');
        }

        $tolerance = (int) config('communications.inbound.signature_tolerance_seconds', 300);
        if (abs(now()->timestamp - (int) $timestamp) > $tolerance) {
            throw new AccessDeniedHttpException('Expired inbound email authentication.');
        }

        $expected = hash_hmac('sha256', $timestamp."\n".$nonce."\n".$raw, $secret);
        if (! hash_equals($expected, $provided)) {
            throw new AccessDeniedHttpException('Invalid inbound email authentication.');
        }

        /** @var array<string, mixed> $payload */
        $payload = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        foreach (['message_id', 'from', 'to', 'received_at'] as $required) {
            if (! is_string($payload[$required] ?? null) || trim($payload[$required]) === '') {
                throw new HttpException(422, 'Inbound email payload is incomplete.');
            }
        }
        if (! hash_equals(
            strtolower((string) config('communications.inbound.address', 'info@mbfdhub.com')),
            strtolower(trim((string) $payload['to'])),
        )) {
            throw new HttpException(422, 'Inbound email recipient is not configured.');
        }

        $attachments = $this->validateAttachments($payload['attachments'] ?? []);
        $storedPaths = [];

        try {
            return DB::transaction(function () use ($nonce, $payload, $attachments, &$storedPaths): InboundEmail {
                DB::table('inbound_email_nonces')->where('expires_at', '<=', now())->delete();
                $inserted = DB::table('inbound_email_nonces')->insertOrIgnore([
                    'nonce' => $nonce,
                    'expires_at' => now()->addSeconds((int) config('communications.inbound.nonce_ttl_seconds', 600)),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                if (! $inserted) {
                    throw new ConflictHttpException('Inbound email replay rejected.');
                }

                $html = is_string($payload['html'] ?? null) ? $payload['html'] : null;
                $email = InboundEmail::query()->firstOrCreate([
                    'provider_message_id' => $payload['message_id'],
                ], [
                    'from_address' => strtolower(trim($payload['from'])),
                    'from_display_name' => is_string($payload['from_name'] ?? null) ? $payload['from_name'] : null,
                    'to_address' => strtolower(trim($payload['to'])),
                    'subject' => is_string($payload['subject'] ?? null) ? $payload['subject'] : null,
                    'received_at' => CarbonImmutable::parse($payload['received_at']),
                    'text_body' => is_string($payload['text'] ?? null) ? $payload['text'] : null,
                    'sanitized_html_body' => $html === null ? null : $this->sanitize($html),
                    'safe_headers' => is_array($payload['safe_headers'] ?? null) ? $payload['safe_headers'] : null,
                    'in_reply_to' => is_string($payload['in_reply_to'] ?? null) ? $payload['in_reply_to'] : null,
                    'references' => is_array($payload['references'] ?? null) ? $payload['references'] : null,
                    'processing_status' => 'received',
                ]);
                if (! $email->wasRecentlyCreated || $attachments === []) {
                    return $email;
                }

                $metadata = [];
                $messageDirectory = hash('sha256', (string) $payload['message_id']);
                foreach ($attachments as $attachment) {
                    $path = "inbound-email-attachments/{$messageDirectory}/".Str::uuid().'.'.$attachment['extension'];
                    if (! Storage::disk('local')->put($path, $attachment['content'])) {
                        throw new RuntimeException('An inbound attachment could not be stored.');
                    }
                    $storedPaths[] = $path;
                    $metadata[] = [
                        'filename' => $attachment['filename'],
                        'mime_type' => $attachment['mime_type'],
                        'size' => strlen($attachment['content']),
                        'disk' => 'local',
                        'path' => $path,
                    ];
                }
                $email->forceFill(['attachment_metadata' => $metadata])->save();

                return $email;
            });
        } catch (Throwable $exception) {
            foreach ($storedPaths as $path) {
                Storage::disk('local')->delete($path);
            }

            throw $exception;
        }
    }

    private function sanitize(string $html): string
    {
        $config = (new HtmlSanitizerConfig)
            ->allowSafeElements()
            ->dropElement('img')
            ->dropElement('style');

        return (new HtmlSanitizer($config))->sanitize($html);
    }

    /**
     * @return list<array{filename: string, mime_type: string, extension: string, content: string}>
     */
    private function validateAttachments(mixed $attachments): array
    {
        if (! is_array($attachments)) {
            throw new HttpException(422, 'Inbound attachment data is invalid.');
        }
        if (count($attachments) > (int) config('communications.inbound.max_attachments', 5)) {
            throw new HttpException(422, 'Too many inbound attachments were supplied.');
        }

        $extensions = [
            'application/pdf' => 'pdf',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'image/gif' => 'gif',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'text/csv' => 'csv',
            'text/plain' => 'txt',
        ];
        $allowedTypes = (array) config('communications.allowed_attachment_mime_types', []);
        $maximumBytes = (int) config('communications.inbound.max_attachment_bytes', 3500000);
        $totalBytes = 0;
        $validated = [];

        foreach ($attachments as $attachment) {
            if (! is_array($attachment)) {
                throw new HttpException(422, 'Inbound attachment data is invalid.');
            }
            $filename = trim(is_string($attachment['filename'] ?? null) ? $attachment['filename'] : '');
            $mimeType = strtolower(trim(is_string($attachment['mime_type'] ?? null) ? $attachment['mime_type'] : ''));
            $encoded = is_string($attachment['content'] ?? null) ? $attachment['content'] : '';
            $safeFilename = basename(str_replace('\\', '/', $filename));
            $content = base64_decode($encoded, true);
            if ($safeFilename === '' || in_array($safeFilename, ['.', '..'], true)
                || $safeFilename !== $filename || ! in_array($mimeType, $allowedTypes, true)
                || ! isset($extensions[$mimeType]) || $content === false) {
                throw new HttpException(422, 'An inbound attachment is invalid or unsafe.');
            }

            $totalBytes += strlen($content);
            if ($totalBytes > $maximumBytes) {
                throw new HttpException(413, 'The inbound attachment limit was exceeded.');
            }
            $validated[] = [
                'filename' => $safeFilename,
                'mime_type' => $mimeType,
                'extension' => $extensions[$mimeType],
                'content' => $content,
            ];
        }

        return $validated;
    }
}
