<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Models\InboundEmail;
use App\Models\OutboundEmail;
use Illuminate\Support\Facades\Storage;

/**
 * Resolves "attachment N of message M" to a file, and nothing else. The path comes from the message's own
 * stored metadata but is re-proved here: private local disk only, inside the directory that message owns,
 * no parent-directory segments. A claimed MIME type is never trusted; callers serve every file as a download.
 */
final class MailAttachmentLocator
{
    /** @return array{disk: string, path: string, filename: string, mime_type: string, size: int}|null */
    public function locate(InboundEmail|OutboundEmail $email, int $index): ?array
    {
        $metadata = $email->getAttribute('attachment_metadata'); // stored JSON: its shape is proved below, never assumed
        if (! is_array($metadata) || ! array_key_exists($index, $metadata) || ! is_array($metadata[$index])) {
            return null;
        }
        $entry = $metadata[$index];
        $path = $entry['path'] ?? null;
        $filename = $entry['filename'] ?? null;
        $prefix = $email instanceof InboundEmail
            ? 'inbound-email-attachments/'.hash('sha256', (string) $email->provider_message_id).'/'
            : 'outbound-email-attachments/'.$email->getKey().'/';

        if (($entry['disk'] ?? null) !== 'local' || ! is_string($path) || ! is_string($filename) || $filename === ''
            || ! str_starts_with($path, $prefix) || str_contains($path, '..') || str_contains($path, "\0")
            || str_contains(substr($path, strlen($prefix)), '/') || ! Storage::disk('local')->exists($path)) {
            return null;
        }

        return [
            'disk' => 'local',
            'path' => $path,
            'filename' => $filename,
            'mime_type' => (string) ($entry['mime_type'] ?? $entry['type'] ?? 'application/octet-stream'),
            'size' => (int) ($entry['size'] ?? 0),
        ];
    }

    /**
     * @return list<array{index: int, filename: string, mime_type: string, size: int, available: bool}>
     */
    public function listing(InboundEmail|OutboundEmail $email): array
    {
        $rows = [];
        foreach ((array) $email->getAttribute('attachment_metadata') as $index => $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $rows[] = [
                'index' => (int) $index,
                'filename' => (string) ($entry['filename'] ?? 'attachment'),
                'mime_type' => (string) ($entry['mime_type'] ?? $entry['type'] ?? 'application/octet-stream'),
                'size' => (int) ($entry['size'] ?? 0),
                'available' => $this->locate($email, (int) $index) !== null,
            ];
        }

        return $rows;
    }
}
