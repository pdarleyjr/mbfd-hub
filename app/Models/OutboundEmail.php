<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property list<string>|null $to_recipients
 * @property list<string>|null $cc_recipients
 * @property list<string>|null $bcc_recipients
 * @property list<string>|null $references
 * @property list<array{filename: string, type: string, size: int, disk?: string, path?: string}>|null $attachment_metadata
 */
final class OutboundEmail extends Model
{
    protected $fillable = [
        'provider', 'provider_message_id', 'initiated_by_user_id', 'source_type', 'source_id',
        'from_address', 'reply_to', 'to_recipients', 'cc_recipients', 'bcc_recipients',
        'subject', 'text_body', 'html_body', 'attachment_metadata', 'recipient_count', 'chargeable_budget_units',
        'status', 'queued_at', 'budget_reserved_at', 'budget_released_at', 'submitted_at',
        'accepted_at', 'delivered_at', 'failed_at', 'failure_reason',
        'message_id', 'in_reply_to', 'references', 'parent_outbound_email_id', 'parent_inbound_email_id',
    ];

    protected $hidden = ['bcc_recipients'];

    protected function casts(): array
    {
        return [
            'references' => 'array',
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

    /** @return BelongsTo<User, $this> */
    public function initiatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by_user_id');
    }

    /** @return HasMany<OutboundEmailRecipient, $this> */
    public function recipients(): HasMany
    {
        return $this->hasMany(OutboundEmailRecipient::class);
    }

    /** @return HasMany<OutboundEmailDeliveryEvent, $this> */
    public function deliveryEvents(): HasMany
    {
        return $this->hasMany(OutboundEmailDeliveryEvent::class)->orderBy('occurred_at');
    }

    public function getRecipientSummaryAttribute(): string
    {
        $to = $this->to_recipients ?? [];

        return ($to[0] ?? 'No To recipient').(count($to) > 1 ? ' +'.(count($to) - 1) : '');
    }

    public function effectiveDeliveryStatus(): string
    {
        if (in_array($this->status, ['pending', 'reserved', 'submitted', 'accepted', 'queued', 'deferred', 'acceptance_unknown', 'accepted_with_delivery_issues'], true)
            && $this->created_at?->lt(now()->subDays(31)) && ! $this->recipients->contains('is_terminal', true)) {
            return 'historical_unknown';
        }

        return $this->status;
    }

    public function deliveryCounts(): array
    {
        return $this->recipients->countBy('status')->all();
    }

    public function getLastEventAtAttribute(): mixed
    {
        return $this->recipients->max('last_event_at');
    }

    public function deliveryStatusLabel(): string
    {
        return self::statusLabel($this->effectiveDeliveryStatus());
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'pending', 'reserved' => 'Preparing',
            'submitted' => 'Submitted to provider',
            'accepted', 'queued' => 'Accepted / pending delivery',
            'deferred' => 'Deferred',
            'delivered' => 'Delivered',
            'partially_delivered' => 'Partially delivered',
            'bounced' => 'Bounced / delivery failed',
            'rejected' => 'Rejected / suppressed',
            'failed', 'failed_pre_acceptance' => 'Failed',
            'complained' => 'Complained',
            'accepted_with_delivery_issues' => 'Accepted with delivery issues',
            'historical_unknown' => 'Delivery status unavailable / historical',
            default => 'Status unknown',
        };
    }

    public function deliveryStatusColor(): string
    {
        return match ($this->effectiveDeliveryStatus()) {
            'delivered' => 'success',
            'bounced', 'rejected', 'failed', 'failed_pre_acceptance', 'complained' => 'danger',
            'deferred', 'partially_delivered', 'accepted_with_delivery_issues' => 'warning',
            default => 'gray',
        };
    }

    public function deliveryStatusIcon(): string
    {
        return match ($this->effectiveDeliveryStatus()) {
            'delivered' => 'heroicon-o-check-circle',
            'bounced', 'failed', 'failed_pre_acceptance' => 'heroicon-o-x-circle',
            'rejected' => 'heroicon-o-no-symbol',
            'deferred', 'partially_delivered', 'complained', 'accepted_with_delivery_issues' => 'heroicon-o-exclamation-triangle',
            'historical_unknown', 'unknown', 'acceptance_unknown' => 'heroicon-o-question-mark-circle',
            default => 'heroicon-o-clock',
        };
    }
}
