<?php

declare(strict_types=1);

namespace App\Models\AiAutomation;

use Illuminate\Database\Eloquent\Model;

/** The single master switch row. Read from the database every time; never cached. */
class AiAutomationSetting extends Model
{
    protected $table = 'ai_automation_settings';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'epoch' => 'integer'];
    }
}
