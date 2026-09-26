<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class OutboundEmailDeliveryEvent extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['failure_detail'];

    protected function casts(): array
    {
        return ['occurred_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<OutboundEmailRecipient, $this> */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(OutboundEmailRecipient::class, 'outbound_email_recipient_id');
    }
}
