<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkgroupSurvey extends Model
{
    protected $fillable = [
        'parent_survey_id', 'workgroup_id', 'workgroup_session_id', 'title', 'description', 'status',
        'is_anonymous', 'eligibility_mode', 'demographic_fields',
        'minimum_subgroup_size', 'revision', 'opens_at', 'closes_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_anonymous' => 'boolean',
            'demographic_fields' => 'array',
            'opens_at' => 'datetime',
            'closes_at' => 'datetime',
        ];
    }

    public function workgroup(): BelongsTo { return $this->belongsTo(Workgroup::class); }
    public function parentSurvey(): BelongsTo { return $this->belongsTo(self::class, 'parent_survey_id'); }
    public function session(): BelongsTo { return $this->belongsTo(WorkgroupSession::class, 'workgroup_session_id'); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function questions(): HasMany { return $this->hasMany(WorkgroupSurveyQuestion::class, 'survey_id')->orderBy('position'); }
    public function participants(): HasMany { return $this->hasMany(WorkgroupSurveyParticipant::class, 'survey_id'); }
    public function responses(): HasMany { return $this->hasMany(WorkgroupSurveyResponse::class, 'survey_id'); }
    public function reports(): HasMany { return $this->hasMany(WorkgroupSurveyReport::class, 'survey_id'); }
    public function revisions(): HasMany { return $this->hasMany(self::class, 'parent_survey_id'); }

    public function isOpen(): bool
    {
        return $this->status === 'active'
            && ($this->opens_at === null || $this->opens_at->isPast())
            && ($this->closes_at === null || $this->closes_at->isFuture());
    }

    public function hasResponses(): bool { return $this->responses()->whereNotNull('submitted_at')->exists(); }
}
