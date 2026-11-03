<?php

declare(strict_types=1);

namespace App\Models\AiAutomation;

use App\Models\Ems\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;

/** Who turned AI automations ON or OFF, and how much queued work the OFF stopped. */
class AiAutomationSwitchEvent extends Model
{
    use AppendOnly;

    public const UPDATED_AT = null;

    protected $table = 'ai_automation_switch_events';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['from_enabled' => 'boolean', 'to_enabled' => 'boolean'];
    }
}
