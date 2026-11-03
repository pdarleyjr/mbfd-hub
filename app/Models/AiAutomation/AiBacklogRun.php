<?php

declare(strict_types=1);

namespace App\Models\AiAutomation;

use Illuminate\Database\Eloquent\Model;

/**
 * @property array<string, mixed>|null $preview
 * @property array{index?: int, after_id?: int}|null $cursor
 */
class AiBacklogRun extends Model
{
    public const STATE_PREVIEWING = 'previewing';

    public const STATE_PREVIEWED = 'previewed';

    public const STATE_RUNNING = 'running';

    public const STATE_PAUSED = 'paused';

    public const STATE_COMPLETED = 'completed';

    public const STATE_CANCELLED = 'cancelled';

    protected $table = 'ai_backlog_runs';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'preview' => 'array',
            'cursor' => 'array',
            'previewed_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
