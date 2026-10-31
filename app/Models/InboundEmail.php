<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property array<string, mixed>|null $safe_headers
 * @property list<array{filename: string, mime_type: string, size: int, disk: string, path: string}>|null $attachment_metadata
 * @property \Carbon\CarbonImmutable $received_at
 * @property list<array{filename: ?string, mime_type: ?string, size: int, reason: string}>|null $skipped_attachments
 * @property list<string>|null $references
 * @property array{envelope_from: ?string, authentication_results: ?string, arc_authentication_results: ?string, received_spf: ?string, cloudflare_claims?: array{authentication_results?: int, arc_authentication_results?: int}|null, truncated?: bool}|null $transport_evidence
 */
final class InboundEmail extends Model
{
    protected $fillable = [
        'provider_message_id', 'from_address', 'from_display_name', 'to_address', 'subject',
        'received_at', 'text_body', 'sanitized_html_body', 'safe_headers', 'attachment_metadata',
        'in_reply_to', 'references', 'parent_outbound_email_id', 'read_at', 'archived_at', 'processing_status',
        'transport_evidence', 'skipped_attachments',
    ];

    protected function casts(): array
    {
        return [
            'received_at' => 'immutable_datetime',
            'safe_headers' => 'array',
            'attachment_metadata' => 'array',
            'references' => 'array',
            'transport_evidence' => 'array',
            'skipped_attachments' => 'array',
            'read_at' => 'immutable_datetime',
            'archived_at' => 'immutable_datetime',
        ];
    }
}
