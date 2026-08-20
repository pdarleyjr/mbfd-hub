<?php

namespace App\Models;

use App\Enums\VideoConferencing\ConferenceJoinRole;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VideoConferenceParticipation extends Model
{
    use HasUlids;

    protected $fillable = [
        'session_id', 'employee_id', 'participant_identity', 'active_identity_key',
        'join_as', 'display_name', 'launch_context_hash', 'token_issued_at', 'joined_at', 'left_at',
        'downstream_bytes', 'packets_received', 'packets_lost', 'jitter_ms', 'stats_sampled_at',
    ];

    protected $casts = [
        'join_as' => ConferenceJoinRole::class,
        'token_issued_at' => 'immutable_datetime',
        'joined_at' => 'immutable_datetime',
        'left_at' => 'immutable_datetime',
        'stats_sampled_at' => 'immutable_datetime',
        'downstream_bytes' => 'integer',
        'packets_received' => 'integer',
        'packets_lost' => 'integer',
        'jitter_ms' => 'integer',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(VideoConferenceSession::class, 'session_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
