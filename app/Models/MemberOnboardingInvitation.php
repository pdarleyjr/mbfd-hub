<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property \Carbon\CarbonImmutable|null $expires_at
 * @property \Carbon\CarbonImmutable|null $sent_at
 * @property \Carbon\CarbonImmutable|null $redeemed_at
 * @property \Carbon\CarbonImmutable|null $consumed_at
 */
final class MemberOnboardingInvitation extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['token_hash', 'redeemed_binding_hash'];

    /** @return BelongsTo<OutboundEmail, $this> */
    public function outboundEmail(): BelongsTo
    {
        return $this->belongsTo(OutboundEmail::class);
    }

    public function emailDeliveryLabel(): string
    {
        $email = $this->outboundEmail;
        if ($email !== null) {
            return $email->deliveryStatusLabel();
        }
        if ($this->delivery_status === 'queued' && ($this->sent_at ?? $this->created_at)?->lessThan(now()->subDays(31))) {
            return 'Delivery status unavailable / historical';
        }

        return match ($this->delivery_status) {
            'pending' => 'Preparing',
            'failed' => 'Delivery failed',
            'queued' => 'Pending delivery',
            default => 'Delivery status unavailable',
        };
    }

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'employee_profile_id' => 'integer',
            'security_version' => 'integer',
            'expires_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
            'redeemed_at' => 'immutable_datetime',
            'consumed_at' => 'immutable_datetime',
        ];
    }
}
