<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SingleGasMeter extends Model
{
    use HasFactory;

    protected $fillable = [
        'apparatus_id',
        'serial_number',
        'activation_date',
        'expiration_date',
    ];

    protected $casts = [
        'activation_date' => 'date',
        'expiration_date' => 'date',
    ];

    protected static function booted(): void
    {
        static::saving(function (SingleGasMeter $meter) {
            if (!$meter->expiration_date || $meter->isDirty('activation_date')) {
                $meter->expiration_date = Carbon::parse($meter->activation_date)->addYears(2);
            }
        });
    }

    public function apparatus(): BelongsTo
    {
        return $this->belongsTo(Apparatus::class);
    }

    public function isExpired(): bool
    {
        return $this->expiration_date && $this->expiration_date->isPast();
    }

    public function daysUntilExpiration(): int
    {
        if (!$this->expiration_date) {
            return 0;
        }
        return max(0, now()->diffInDays($this->expiration_date, false));
    }

    public function getStatusAttribute(): string
    {
        return $this->isExpired() ? 'Expired' : 'Valid';
    }
}
