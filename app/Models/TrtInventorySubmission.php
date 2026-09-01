<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class TrtInventorySubmission extends Model
{
    protected $fillable = [
        'client_submission_id',
        'session_id',
        'actor_user_id',
        'payload_hash',
        'entries_count',
    ];
}
