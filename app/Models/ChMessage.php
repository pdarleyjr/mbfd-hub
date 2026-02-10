<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Chatify Message Model
 * 
 * This model represents messages in the Chatify chat system.
 * It stores messages between users with their content and status.
 */
class ChMessage extends Model
{
    protected $table = 'ch_messages';

    protected $fillable = [
        'from_id',
        'to_id',
        'body',
        'seen',
    ];

    protected $casts = [
        'seen' => 'boolean',
    ];

    /**
     * Get the sender of the message.
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_id');
    }

    /**
     * Get the recipient of the message.
     */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_id');
    }
}
