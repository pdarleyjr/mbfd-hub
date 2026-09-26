<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class OutboundEmailRecipient extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['address', 'failure_detail'];

    protected function casts(): array
    {
        return ['last_event_at' => 'immutable_datetime', 'delivered_at' => 'immutable_datetime', 'failed_at' => 'immutable_datetime', 'is_terminal' => 'boolean'];
    }

    /** @return BelongsTo<OutboundEmail, $this> */
    public function outboundEmail(): BelongsTo
    {
        return $this->belongsTo(OutboundEmail::class);
    }
}
