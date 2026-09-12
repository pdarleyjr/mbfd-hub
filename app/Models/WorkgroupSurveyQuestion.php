<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkgroupSurveyQuestion extends Model
{
    protected $fillable = ['survey_id', 'position', 'type', 'prompt', 'help_text', 'is_required', 'configuration'];

    protected function casts(): array { return ['is_required' => 'boolean', 'configuration' => 'array']; }
    public function survey(): BelongsTo { return $this->belongsTo(WorkgroupSurvey::class, 'survey_id'); }
}
