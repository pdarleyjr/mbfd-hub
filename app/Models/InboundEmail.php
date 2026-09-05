<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class InboundEmail extends Model
{
    protected $fillable = [
        'provider_message_id', 'from_address', 'from_display_name', 'to_address', 'subject',
        'received_at', 'text_body', 'sanitized_html_body', 'safe_headers', 'attachment_metadata',
        'in_reply_to', 'references', 'read_at', 'archived_at', 'processing_status',
    ];

    protected function casts(): array
    {
        return [
            'received_at' => 'immutable_datetime',
            'safe_headers' => 'array',
            'attachment_metadata' => 'array',
            'references' => 'array',
            'read_at' => 'immutable_datetime',
            'archived_at' => 'immutable_datetime',
        ];
    }
}
