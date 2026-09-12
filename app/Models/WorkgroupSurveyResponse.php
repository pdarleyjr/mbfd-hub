<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** De-identified answer content. It intentionally has no user/member foreign key. */
class WorkgroupSurveyResponse extends Model
{
    protected $fillable = ['survey_id', 'survey_revision', 'participant_token', 'demographics', 'submitted_at'];
    protected $hidden = ['participant_token'];
    protected function casts(): array { return ['demographics' => 'array', 'submitted_at' => 'datetime']; }
    public function survey(): BelongsTo { return $this->belongsTo(WorkgroupSurvey::class, 'survey_id'); }
    public function answers(): HasMany { return $this->hasMany(WorkgroupSurveyAnswer::class, 'survey_response_id'); }
}
