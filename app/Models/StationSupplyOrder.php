<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StationSupplyOrder extends Model
{
    protected $fillable = [
        'created_by', 'sent_via', 'status', 'subject',
        'to', 'cc', 'vendor_name', 'sent_at',
        'provider_message_id', 'error_message',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StationSupplyOrderLine::class);
    }

    public function communication(): BelongsTo
    {
        return $this->belongsTo(Communication::class);
    }
}
