<?php

declare(strict_types=1);

namespace Tests\Feature\Survey;

use App\Models\User;
use App\Models\Workgroup;
use App\Models\WorkgroupMember;
use App\Models\WorkgroupSurvey;
use App\Models\WorkgroupSurveyParticipant;
use App\Services\Workgroup\SurveyAnalyticsService;
use App\Services\Workgroup\SurveyResponseService;
use App\Support\Workgroups\BackToBasicsSurveyBlueprint;
use App\Support\Workgroups\WorkgroupAccess;
use App\Support\Workgroups\WorkgroupContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SurveyPlatformTest extends TestCase
{
    use RefreshDatabase;

    public function test_submission_separates_anonymous_content_from_participation_and_is_immutable(): void
    {
        [$user, $survey] = $this->makeSurvey();
        $service = app(SurveyResponseService::class);
        $response = $service->submit($survey, $user, [(string) $survey->questions->first()->id => 'yes']);

        $this->assertArrayNotHasKey('workgroup_member_id', $response->getAttributes());
        $this->assertSame(1, WorkgroupSurveyParticipant::query()->where('survey_id', $survey->id)->whereNotNull('submitted_at')->count());
        $this->assertFalse(app(WorkgroupAccess::class)->canViewSurveyResponse($user, $response));
        $this->expectExceptionMessage('already been submitted');
        $service->submit($survey, $user, [(string) $survey->questions->first()->id => 'yes']);
    }

    public function test_per_survey_inclusion_defaults_once_from_evaluations_and_is_independent_afterwards(): void
    {
        [$user, $survey, $member] = $this->makeSurvey(false);
        $service = app(SurveyResponseService::class);
        $participant = $service->participantFor($survey, $user);
        $this->assertFalse($participant->include_in_analysis);

        $member->update(['count_evaluations' => true]);
        $participant->refresh();
        $this->assertFalse($participant->include_in_analysis);
        $service->setAnalysisInclusion($survey, $user, $participant, true);
        $this->assertTrue($participant->fresh()->include_in_analysis);
    }

    public function test_multi_select_limit_and_none_exclusivity_are_enforced_server_side(): void
    {
        [$user, $survey] = $this->makeSurvey();
        $question = $survey->questions()->create([
            'position' => 2, 'type' => 'multi', 'prompt' => 'Select up to two.', 'is_required' => true,
            'configuration' => ['options' => [['key' => 'a', 'label' => 'A'], ['key' => 'b', 'label' => 'B'], ['key' => 'none', 'label' => 'None']], 'max_selections' => 2, 'exclusive_option' => 'none'],
        ]);
        $service = app(SurveyResponseService::class);

        try {
            $service->saveDraft($survey, $user, [(string) $question->id => ['a', 'b', 'none']]);
            $this->fail('Selection limits must be enforced.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }

        try {
            $service->saveDraft($survey, $user, [(string) $question->id => ['a', 'none']]);
            $this->fail('Exclusive None must be enforced.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
    }

    public function test_deterministic_analytics_excludes_non_scored_options_and_suppresses_small_demographics(): void
    {
        [$user, $survey] = $this->makeSurvey();
        $question = $survey->questions->first();
        $second = $this->addMember($survey->workgroup, true);
        $service = app(SurveyResponseService::class);
        $service->submit($survey, $user, [(string) $question->id => 'excellent'], ['rank' => 'Captain']);
        $service->submit($survey, $second, [(string) $question->id => 'unsure'], ['rank' => 'Captain']);

        $metrics = app(SurveyAnalyticsService::class)->calculate($survey->fresh());
        $questionMetrics = $metrics['questions'][0]['metrics'];
        $this->assertSame(2, $questionMetrics['response_n']);
        $this->assertSame(1, $questionMetrics['scored_n']);
        $this->assertSame(5.0, $questionMetrics['mean']);
        $this->assertTrue($metrics['demographics'][0]['items'][0]['suppressed']);
        $this->assertArrayNotHasKey('count', $metrics['demographics'][0]['items'][0]);
    }

    public function test_back_to_basics_seed_is_exact_name_fail_closed_and_idempotent(): void
    {
        $this->artisan('workgroup-surveys:seed-back-to-basics')->assertExitCode(1);
        $owner = User::factory()->create();
        $workgroup = Workgroup::create(['name' => 'Back to Basics - Train the Trainer', 'created_by' => $owner->id]);
        $this->artisan('workgroup-surveys:seed-back-to-basics')->assertExitCode(0);
        $survey = WorkgroupSurvey::query()->where('workgroup_id', $workgroup->id)->where('title', BackToBasicsSurveyBlueprint::TITLE)->sole();
        $this->assertSame('draft', $survey->status);
        $this->assertTrue($survey->is_anonymous);
        $this->assertSame(15, $survey->questions()->count());
        $this->artisan('workgroup-surveys:seed-back-to-basics')->assertExitCode(0);
        $this->assertSame(15, $survey->questions()->count());
    }

    public function test_a_new_revision_retains_a_parent_link_and_cannot_rewrite_submitted_content(): void
    {
        [$user, $survey] = $this->makeSurvey();
        app(SurveyResponseService::class)->submit($survey, $user, [(string) $survey->questions->first()->id => 'excellent']);
        $revision = $survey->replicate(['created_at', 'updated_at']);
        $revision->forceFill([
            'parent_survey_id' => $survey->id,
            'revision' => $survey->revision + 1,
            'status' => 'draft',
        ])->save();

        $this->assertTrue($revision->parentSurvey->is($survey));
        $this->assertSame(1, $survey->responses()->whereNotNull('submitted_at')->count());
        $this->assertSame(0, $revision->responses()->count());
    }

    /** @return array{0: User, 1: WorkgroupSurvey, 2: WorkgroupMember} */
    private function makeSurvey(bool $countEvaluations = true): array
    {
        $user = User::factory()->create();
        $workgroup = Workgroup::create(['name' => 'Survey Workgroup', 'created_by' => $user->id]);
        $member = WorkgroupMember::create(['workgroup_id' => $workgroup->id, 'user_id' => $user->id, 'role' => 'facilitator', 'is_active' => true, 'count_evaluations' => $countEvaluations]);
        app(WorkgroupContext::class)->select($user, $workgroup->id);
        $survey = WorkgroupSurvey::create([
            'workgroup_id' => $workgroup->id, 'title' => 'Test Survey', 'status' => 'active', 'is_anonymous' => true,
            'minimum_subgroup_size' => 3, 'demographic_fields' => [['key' => 'rank', 'label' => 'Rank']],
        ]);
        $survey->questions()->create([
            'position' => 1, 'type' => 'single', 'prompt' => 'Rate it', 'is_required' => true,
            'configuration' => ['options' => [['key' => 'excellent', 'label' => 'Excellent', 'score' => 5, 'favorable' => true], ['key' => 'unsure', 'label' => 'Unsure']]],
        ]);

        return [$user, $survey->fresh(['questions', 'workgroup']), $member];
    }

    private function addMember(Workgroup $workgroup, bool $countEvaluations): User
    {
        $user = User::factory()->create();
        WorkgroupMember::create(['workgroup_id' => $workgroup->id, 'user_id' => $user->id, 'role' => 'member', 'is_active' => true, 'count_evaluations' => $countEvaluations]);

        return $user;
    }
}
