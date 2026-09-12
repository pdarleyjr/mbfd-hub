<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Workgroup;
use App\Models\WorkgroupSurvey;
use App\Support\Workgroups\BackToBasicsSurveyBlueprint;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SeedBackToBasicsSurvey extends Command
{
    protected $signature = 'workgroup-surveys:seed-back-to-basics';
    protected $description = 'Create the Back to Basics survey once as a draft after an exact workgroup lookup.';

    public function handle(): int
    {
        $workgroups = Workgroup::query()->where('name', 'Back to Basics - Train the Trainer')->get();
        if ($workgroups->count() !== 1) {
            $this->error($workgroups->isEmpty() ? 'Target workgroup was not found; no survey was created.' : 'Target workgroup is ambiguous; no survey was created.');

            return self::FAILURE;
        }

        $workgroup = $workgroups->sole();
        $existing = WorkgroupSurvey::query()->where('workgroup_id', $workgroup->id)->where('title', BackToBasicsSurveyBlueprint::TITLE)->first();
        if ($existing !== null) {
            $count = $existing->questions()->count();
            if ($count !== 15) {
                $this->error("Existing survey has {$count} questions; refusing to overwrite it.");

                return self::FAILURE;
            }
            $this->info('Back to Basics survey already exists with 15 top-level questions; no changes made.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($workgroup): void {
            $survey = WorkgroupSurvey::create(BackToBasicsSurveyBlueprint::metadata() + ['workgroup_id' => $workgroup->id]);
            foreach (BackToBasicsSurveyBlueprint::questions() as $question) {
                $survey->questions()->create($question);
            }
        });

        $this->info('Created Back to Basics survey as a draft with exactly 15 top-level questions.');

        return self::SUCCESS;
    }
}

