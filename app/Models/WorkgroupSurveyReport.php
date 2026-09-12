<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkgroupSurveyReport extends Model
{
    protected $fillable = ['survey_id', 'survey_revision', 'included_response_count', 'analytics_hash', 'analytics', 'executive_narrative', 'gateway_request_id', 'generated_by'];

    protected function casts(): array
    {
        return ['analytics' => 'array'];
    }

    /** @return BelongsTo<WorkgroupSurvey, $this> */
    public function survey(): BelongsTo
    {
        return $this->belongsTo(WorkgroupSurvey::class, 'survey_id');
    }

    /** @return BelongsTo<User, $this> */
    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }
}
