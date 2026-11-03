<?php

declare(strict_types=1);

namespace App\Models\EmployeeMessaging;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One append-only entry in an employee <-> Logistics conversation. Plain text only; never edited
 * or deleted by a normal workflow. author_side is set by the server from the path that wrote it
 * (member inbox or Logistics inbox), never from the browser.
 *
 * @property int $id
 * @property int $thread_id
 * @property int|null $author_user_id
 * @property string $author_side
 * @property string $author_name
 * @property string $body
 * @property string $client_request_id
 * @property int|null $ai_intake_event_id set only on assistant messages
 * @property \Illuminate\Support\Carbon|null $created_at
 */
class EmployeeLogisticsMessage extends Model
{
    public const SIDE_MEMBER = 'member';

    public const SIDE_LOGISTICS = 'logistics';

    /** The AI Response assistant (docs/ai-response-automation.md): never a person on the team, never a staff user id. */
    public const SIDE_ASSISTANT = 'assistant';

    protected $table = 'employee_logistics_messages';

    protected $guarded = ['*'];

    protected $casts = [
        'thread_id' => 'integer',
        'author_user_id' => 'integer',
    ];

    public function thread(): BelongsTo
    {
        return $this->belongsTo(EmployeeLogisticsThread::class, 'thread_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    public function isFromLogistics(): bool
    {
        return $this->author_side === self::SIDE_LOGISTICS;
    }

    public function isFromAssistant(): bool
    {
        return $this->author_side === self::SIDE_ASSISTANT;
    }
}
