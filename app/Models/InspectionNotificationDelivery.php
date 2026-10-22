<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Ledger row: (event_key, recipient) is unique so an event notifies a recipient at most once. */
final class InspectionNotificationDelivery extends Model
{
    protected $guarded = ['id'];

    /** @return BelongsTo<User, $this> */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }
}
