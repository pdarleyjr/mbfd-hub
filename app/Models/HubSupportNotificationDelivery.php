<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $audience
 * @property string $channel
 * @property array<string, string> $payload
 * @property int $attempts
 * @property \Illuminate\Support\Carbon|null $last_attempted_at
 * @property \Illuminate\Support\Carbon|null $delivered_at
 * @property \Illuminate\Support\Carbon|null $cancelled_at
 * @property string|null $last_error
 * @property-read HubSupportTicketUpdate $ticketUpdate
 * @property-read User $recipient
 */
final class HubSupportNotificationDelivery extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['payload' => 'array', 'attempts' => 'integer', 'last_attempted_at' => 'datetime', 'delivered_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    /** @return BelongsTo<HubSupportTicketUpdate, $this> */
    public function ticketUpdate(): BelongsTo
    {
        return $this->belongsTo(HubSupportTicketUpdate::class, 'hub_support_ticket_update_id');
    }

    /** @return BelongsTo<User, $this> */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }
}
