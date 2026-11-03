<?php

declare(strict_types=1);

namespace App\Models\AiAutomation;

use Illuminate\Database\Eloquent\Model;

/** One Workers AI request, reserved before the call so the daily and monthly caps hold. */
class AiModelCall extends Model
{
    public const STATE_RESERVED = 'reserved';

    public const STATE_COMPLETED = 'completed';

    public const STATE_FAILED = 'failed';

    protected $table = 'ai_model_calls';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['call_date' => 'date'];
    }
}
