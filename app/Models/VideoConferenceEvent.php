<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VideoConferenceEvent extends Model
{
    use HasUlids;

    protected $fillable = [
        'provider_event_id', 'event_type', 'session_id', 'participant_identity', 'occurred_at',
    ];

    protected $casts = ['occurred_at' => 'immutable_datetime'];

    public function session(): BelongsTo
    {
        return $this->belongsTo(VideoConferenceSession::class, 'session_id');
    }
}
