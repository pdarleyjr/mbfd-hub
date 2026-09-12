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
    private const PRODUCTION_CONFIRMATION = 'CREATE BACK TO BASICS SURVEY';

    protected $signature = 'workgroup-surveys:seed-back-to-basics
                            {--dry-run : Validate the target and report the intended mutation without changing data}
                            {--confirm-production= : Exact production creation confirmation}';

    protected $description = 'Create the Back to Basics survey once as a draft after an exact workgroup lookup.';

    public function handle(): int
    {
        $workgroups = Workgroup::query()->where('name', 'Back to Basics - Train the Trainer')->get();
        if ($workgroups->count() !== 1) {
            $this->error($workgroups->isEmpty() ? 'Target workgroup was not found; no survey was created.' : 'Target workgroup is ambiguous; no survey was created.');

            return self::FAILURE;
        }

        $workgroup = $workgroups->sole();
        $existingSurveys = WorkgroupSurvey::query()->where('workgroup_id', $workgroup->id)->where('title', BackToBasicsSurveyBlueprint::TITLE)->get();
        if ($existingSurveys->count() > 1) {
            $this->error('Multiple canonical-title surveys exist in the target workgroup; refusing to mutate data.');

            return self::FAILURE;
        }
        $existing = $existingSurveys->first();
        if ($existing !== null) {
            if (! $this->matchesBlueprint($existing)) {
                $this->error('Existing survey differs from the canonical blueprint; refusing to overwrite it.');

                return self::FAILURE;
            }
            $this->info('Back to Basics survey exactly matches the canonical blueprint; no changes made.');

            return self::SUCCESS;
        }

        if ((bool) $this->option('dry-run')) {
            $this->info('Dry run passed: the canonical draft survey with exactly 15 top-level questions would be created.');

            return self::SUCCESS;
        }

        if (app()->environment('production') && $this->option('confirm-production') !== self::PRODUCTION_CONFIRMATION) {
            $this->error('Production creation requires --confirm-production="'.self::PRODUCTION_CONFIRMATION.'"; no survey was created.');

            return self::FAILURE;
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

    private function matchesBlueprint(WorkgroupSurvey $survey): bool
    {
        $expectedMetadata = BackToBasicsSurveyBlueprint::metadata() + [
            'parent_survey_id' => null,
            'workgroup_session_id' => null,
            'eligibility_mode' => 'active_members',
            'revision' => 1,
        ];
        $actualMetadata = collect(array_keys($expectedMetadata))
            ->mapWithKeys(fn (string $key): array => [$key => $survey->getAttribute($key)])
            ->all();

        $questionFields = ['position', 'type', 'prompt', 'help_text', 'is_required', 'configuration'];
        $actualQuestions = $survey->questions()->orderBy('position')->get()
            ->map(fn ($question): array => collect($questionFields)->mapWithKeys(
                fn (string $key): array => [$key => $question->getAttribute($key)],
            )->all())
            ->all();

        return $actualMetadata === $expectedMetadata
            && $actualQuestions === BackToBasicsSurveyBlueprint::questions();
    }
}
