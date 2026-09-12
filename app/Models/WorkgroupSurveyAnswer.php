<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkgroupSurveyAnswer extends Model
{
    protected $fillable = ['survey_response_id', 'survey_question_id', 'answer', 'question_snapshot'];
    protected function casts(): array { return ['answer' => 'array', 'question_snapshot' => 'array']; }
    public function response(): BelongsTo { return $this->belongsTo(WorkgroupSurveyResponse::class, 'survey_response_id'); }
    public function question(): BelongsTo { return $this->belongsTo(WorkgroupSurveyQuestion::class, 'survey_question_id'); }
}
