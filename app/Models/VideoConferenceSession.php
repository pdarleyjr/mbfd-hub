<?php

namespace App\Models;

use App\Enums\VideoConferencing\ConferenceRoomType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VideoConferenceSession extends Model
{
    use HasUlids;

    protected $fillable = [
        'type', 'logical_key', 'active_key', 'livekit_room_name', 'target_station',
        'scheduled_for', 'created_by_employee_id', 'started_at', 'ended_at',
        'provisioned_at',
    ];

    protected $casts = [
        'type' => ConferenceRoomType::class,
        'scheduled_for' => 'immutable_datetime',
        'started_at' => 'immutable_datetime',
        'provisioned_at' => 'immutable_datetime',
        'ended_at' => 'immutable_datetime',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by_employee_id');
    }

    public function participations(): HasMany
    {
        return $this->hasMany(VideoConferenceParticipation::class, 'session_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(VideoConferenceEvent::class, 'session_id');
    }
}
