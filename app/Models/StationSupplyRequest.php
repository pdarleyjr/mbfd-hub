<?php

namespace App\Models;

use App\Models\Concerns\HasArchive;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StationSupplyRequest extends Model
{
    use HasArchive;

    protected $fillable = [
        'station_id',
        'actor_user_id',
        'actor_employee_id',
        'request_text',
        'status',
        'created_by_name',
        'created_by_shift',
        'admin_notes',
        'public_response',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the station this request belongs to
     */
    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    /**
     * Scope to get open requests
     */
    public function scopeOpen($query)
    {
        return $query->active()->where('status', 'open');
    }

    /**
     * Scope to get denied requests
     */
    public function scopeDenied($query)
    {
        return $query->where('status', 'denied');
    }

    /**
     * Scope to get ordered requests
     */
    public function scopeOrdered($query)
    {
        return $query->where('status', 'ordered');
    }

    /**
     * Scope to get replenished requests
     */
    public function scopeReplenished($query)
    {
        return $query->where('status', 'replenished');
    }

    /**
     * Scope to get recent requests
     */
    public function scopeRecent($query, $limit = 50)
    {
        return $query->orderBy('created_at', 'desc')->limit($limit);
    }
}
