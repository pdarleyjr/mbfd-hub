<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** De-identified answer content. It intentionally has no user/member foreign key. */
/** @property int $id @property int $survey_id @property int|null $workgroup_member_id @property string $participant_token @property array<string, mixed>|null $demographics @property \Carbon\Carbon|null $submitted_at @property \Illuminate\Database\Eloquent\Collection<int, WorkgroupSurveyAnswer> $answers */
class WorkgroupSurveyResponse extends Model
{
    protected $fillable = ['survey_id', 'workgroup_member_id', 'survey_revision', 'participant_token', 'demographics', 'submitted_at'];

    protected $hidden = ['participant_token'];

    protected function casts(): array
    {
        return ['demographics' => 'array', 'submitted_at' => 'datetime'];
    }

    public function demographicValue(string $key): mixed
    {
        $demographics = $this->getAttribute('demographics');

        return is_array($demographics) ? ($demographics[$key] ?? null) : null;
    }

    /** @return BelongsTo<WorkgroupSurvey, $this> */
    public function survey(): BelongsTo
    {
        return $this->belongsTo(WorkgroupSurvey::class, 'survey_id');
    }

    /** @return BelongsTo<WorkgroupMember, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(WorkgroupMember::class, 'workgroup_member_id');
    }

    /** @return HasMany<WorkgroupSurveyAnswer, $this> */
    public function answers(): HasMany
    {
        return $this->hasMany(WorkgroupSurveyAnswer::class, 'survey_response_id');
    }
}
