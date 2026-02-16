<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Communication extends Model
{
    protected $fillable = [
        'user_id', 'to', 'cc', 'bcc', 'subject',
        'body_html', 'status', 'error_message', 'sent_at',
        'provider', 'provider_message_id', 'station_supply_order_id',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(StationSupplyOrder::class, 'station_supply_order_id');
    }
}
