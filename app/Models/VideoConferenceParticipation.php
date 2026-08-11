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
        'join_as', 'display_name', 'token_issued_at', 'joined_at', 'left_at',
    ];

    protected $casts = [
        'join_as' => ConferenceJoinRole::class,
        'token_issued_at' => 'immutable_datetime',
        'joined_at' => 'immutable_datetime',
        'left_at' => 'immutable_datetime',
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
