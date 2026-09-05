<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class OutboundEmail extends Model
{
    protected $fillable = [
        'provider', 'provider_message_id', 'initiated_by_user_id', 'source_type', 'source_id',
        'from_address', 'reply_to', 'to_recipients', 'cc_recipients', 'bcc_recipients',
        'subject', 'text_body', 'html_body', 'attachment_metadata', 'recipient_count', 'chargeable_budget_units',
        'status', 'queued_at', 'budget_reserved_at', 'budget_released_at', 'submitted_at',
        'accepted_at', 'delivered_at', 'failed_at', 'failure_reason',
    ];

    protected function casts(): array
    {
        return [
            'to_recipients' => 'array',
            'cc_recipients' => 'array',
            'bcc_recipients' => 'array',
            'attachment_metadata' => 'array',
            'queued_at' => 'immutable_datetime',
            'budget_reserved_at' => 'immutable_datetime',
            'budget_released_at' => 'immutable_datetime',
            'submitted_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
        ];
    }

    public function initiatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by_user_id');
    }
}
