<?php

declare(strict_types=1);

namespace App\Models\Training;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

/** One member's unfinished Crew Planner proposal. Read and written only through TrainingCrewPlannerDraftStore. */
final class TrainingCrewPlanDraft extends Model
{
    use Prunable;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'training_crew_plan_drafts';

    protected $guarded = [];

    protected $hidden = ['payload'];

    protected $casts = ['expires_at' => 'immutable_datetime', 'version' => 'integer'];

    /** @return Builder<self> */
    public function prunable(): Builder
    {
        return self::query()->where('expires_at', '<', now());
    }
}
