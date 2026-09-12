<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Protected participation ledger. Never join this model to answer/report/export UI. */
/** @property int $survey_id @property string|null $response_token @property bool $is_eligible @property bool $include_in_analysis @property \Carbon\Carbon|null $submitted_at */
class WorkgroupSurveyParticipant extends Model
{
    protected $fillable = ['survey_id', 'workgroup_member_id', 'is_eligible', 'include_in_analysis', 'response_token', 'submitted_at'];
    protected $hidden = ['workgroup_member_id', 'response_token'];
    protected function casts(): array { return ['is_eligible' => 'boolean', 'include_in_analysis' => 'boolean', 'submitted_at' => 'datetime']; }
    /** @return BelongsTo<WorkgroupSurvey, $this> */
    public function survey(): BelongsTo { return $this->belongsTo(WorkgroupSurvey::class, 'survey_id'); }
    /** @return BelongsTo<WorkgroupMember, $this> */
    public function member(): BelongsTo { return $this->belongsTo(WorkgroupMember::class, 'workgroup_member_id'); }
}
