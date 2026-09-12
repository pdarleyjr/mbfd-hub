<?php

declare(strict_types=1);

namespace Tests\Feature\Survey;

use App\Filament\Workgroup\Pages\SurveyResultsPage;
use App\Models\User;
use App\Models\Workgroup;
use App\Models\WorkgroupMember;
use App\Models\WorkgroupSurvey;
use App\Models\WorkgroupSurveyParticipant;
use App\Services\LocalAIService;
use App\Services\Workgroup\SurveyAnalyticsService;
use App\Services\Workgroup\SurveyExecutiveNarrativeService;
use App\Services\Workgroup\SurveyResponseService;
use App\Support\Workgroups\BackToBasicsSurveyBlueprint;
use App\Support\Workgroups\WorkgroupAccess;
use App\Support\Workgroups\WorkgroupContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use LogicException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class SurveyPlatformTest extends TestCase
{
    use RefreshDatabase;

    public function test_submission_separates_anonymous_content_from_participation_and_is_immutable(): void
    {
        [$user, $survey, $member] = $this->makeSurvey();
        $service = app(SurveyResponseService::class);
        $response = $service->submit($survey, $user, [(string) $survey->questions->first()->id => 'excellent']);

        $this->assertNull($response->workgroup_member_id);
        $this->assertSame(1, WorkgroupSurveyParticipant::query()->where('survey_id', $survey->id)->whereNotNull('submitted_at')->count());
        $this->assertFalse(app(WorkgroupAccess::class)->canViewSurveyResponse($user, $response));
        $this->expectExceptionMessage('already been submitted');
        $service->submit($survey, $user, [(string) $survey->questions->first()->id => 'excellent']);
    }

    public function test_identified_surveys_retain_the_respondent_link(): void
    {
        [$user, $survey, $member] = $this->makeSurvey();
        $survey->update(['is_anonymous' => false]);

        $response = app(SurveyResponseService::class)->submit($survey, $user, [(string) $survey->questions->first()->id => 'excellent']);

        $this->assertSame($member->id, $response->workgroup_member_id);
        $this->assertTrue($response->member->is($member));
        $this->assertTrue(app(WorkgroupAccess::class)->canViewSurveyResponse($user, $response));
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
        $this->assertTrue($member->fresh()->count_evaluations);
    }

    public function test_forged_cross_workgroup_survey_access_fails_closed(): void
    {
        [, $survey] = $this->makeSurvey();
        $outsider = User::factory()->create();
        $otherWorkgroup = Workgroup::create(['name' => 'Other Survey Workgroup', 'created_by' => $outsider->id]);
        WorkgroupMember::create(['workgroup_id' => $otherWorkgroup->id, 'user_id' => $outsider->id, 'role' => 'facilitator', 'is_active' => true, 'count_evaluations' => true]);
        app(WorkgroupContext::class)->select($outsider, $otherWorkgroup->id);

        $this->expectException(HttpException::class);
        app(SurveyResponseService::class)->participantFor($survey, $outsider);
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

    public function test_other_text_is_bound_to_q2_and_never_enters_aggregate_analytics(): void
    {
        [$user, $survey] = $this->makeSurvey();
        $definition = BackToBasicsSurveyBlueprint::questions()[1];
        $question = $survey->questions()->create($definition);
        $configuration = $question->configurationData();
        $otherKey = collect($configuration['options'])->firstWhere('label', 'Other')['key'];
        $ordinaryKeys = collect($configuration['options'])->pluck('key')->reject(fn (string $key): bool => $key === $otherKey)->take(3)->values()->all();
        $service = app(SurveyResponseService::class);

        $this->assertValidationFails(fn () => $service->saveDraft($survey, $user, [
            (string) $question->id => ['selections' => [$ordinaryKeys[0]], 'other_text' => 'Forged precise detail'],
        ]));
        $this->assertValidationFails(fn () => $service->saveDraft($survey, $user, [
            (string) $question->id => ['selections' => [$otherKey], 'other_text' => 'Valid explanation', 'identity' => 'forged'],
        ]));
        $this->assertValidationFails(fn () => $service->saveDraft($survey, $user, [
            (string) $question->id => ['selections' => [...$ordinaryKeys, $otherKey], 'other_text' => 'Fourth selection'],
        ]));

        $response = $service->submit($survey, $user, [
            (string) $survey->questions->first()->id => 'excellent',
            (string) $question->id => ['selections' => [$ordinaryKeys[0], $otherKey], 'other_text' => 'Sensitive narrative detail'],
        ]);
        $this->assertSame(
            ['selections' => [$ordinaryKeys[0], $otherKey], 'other_text' => 'Sensitive narrative detail'],
            $response->answers->firstWhere('survey_question_id', $question->id)->value(),
        );
        $analyticsJson = json_encode(app(SurveyAnalyticsService::class)->calculate($survey->fresh()), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('Sensitive narrative detail', $analyticsJson);
    }

    public function test_respondent_data_is_created_only_while_open_and_submitted_content_is_read_only(): void
    {
        [$user, $openSurvey] = $this->makeSurvey();
        $service = app(SurveyResponseService::class);
        $blockedStates = [
            ['status' => 'draft'],
            ['status' => 'active', 'opens_at' => now()->addHour()],
            ['status' => 'active', 'closes_at' => now()->subHour()],
            ['status' => 'closed'],
        ];

        foreach ($blockedStates as $index => $state) {
            $survey = WorkgroupSurvey::create([
                'workgroup_id' => $openSurvey->workgroup_id,
                'title' => "Blocked survey {$index}",
                'status' => $state['status'],
                'opens_at' => $state['opens_at'] ?? null,
                'closes_at' => $state['closes_at'] ?? null,
                'is_anonymous' => true,
            ]);
            $this->assertHttpFailure(fn () => $service->draftFor($survey, $user), 422);
            $this->assertSame(0, $survey->participants()->count());
            $this->assertSame(0, $survey->responses()->count());
        }

        $questionId = (string) $openSurvey->questions->first()->id;
        $service->saveDraft($openSurvey, $user, [$questionId => 'excellent']);
        $draft = $service->draftFor($openSurvey, $user)->load('answers');
        $this->assertNull($draft->submitted_at);
        $this->assertSame('excellent', $draft->answers->sole()->value());
        $submitted = $service->submit($openSurvey, $user, [$questionId => 'excellent']);
        $openSurvey->update(['status' => 'closed']);
        $this->assertTrue($service->draftFor($openSurvey->fresh(), $user)->is($submitted));
        $this->assertHttpFailure(fn () => $service->saveDraft($openSurvey->fresh(), $user, [$questionId => 'excellent']), 422);
    }

    public function test_forged_demographic_values_are_rejected(): void
    {
        [$user, $survey] = $this->makeSurvey();

        $this->assertValidationFails(fn () => app(SurveyResponseService::class)->submit(
            $survey,
            $user,
            [(string) $survey->questions->first()->id => 'excellent'],
            ['rank' => 'Exact assignment and station'],
        ));
        $this->assertValidationFails(fn () => app(SurveyResponseService::class)->submit(
            $survey,
            $user,
            [(string) $survey->questions->first()->id => 'excellent'],
            ['rank' => ['Captain', 'Station 2']],
        ));
    }

    public function test_response_rate_uses_only_eligible_submitted_participants(): void
    {
        [$user, $survey] = $this->makeSurvey();
        $second = $this->addMember($survey->workgroup, true);
        $questionId = (string) $survey->questions->first()->id;
        $service = app(SurveyResponseService::class);
        $service->submit($survey, $user, [$questionId => 'excellent']);
        $service->submit($survey, $second, [$questionId => 'excellent']);
        $survey->participants()->whereHas('member', fn ($query) => $query->where('user_id', $second->id))->sole()->update(['is_eligible' => false]);

        $summary = app(SurveyAnalyticsService::class)->calculate($survey->fresh())['summary'];
        $this->assertSame(1, $summary['eligible_participants']);
        $this->assertSame(2, $summary['submitted_participants']);
        $this->assertSame(1, $summary['included_in_analysis']);
        $this->assertSame(1, $summary['excluded_from_analysis']);
        $this->assertSame(100.0, $summary['response_rate']);
    }

    public function test_single_multi_and_matrix_metrics_use_response_level_denominators(): void
    {
        [$user, $survey] = $this->makeSurvey();
        $second = $this->addMember($survey->workgroup, true);
        $multi = $survey->questions()->create([
            'position' => 2,
            'type' => 'multi',
            'prompt' => 'Choose options',
            'is_required' => true,
            'configuration' => ['options' => [['key' => 'a', 'label' => 'A'], ['key' => 'b', 'label' => 'B']]],
        ]);
        $matrix = $survey->questions()->create([
            'position' => 3,
            'type' => 'matrix',
            'prompt' => 'Rate rows',
            'is_required' => false,
            'configuration' => [
                'rows' => [['key' => 'row_a', 'label' => 'Row A'], ['key' => 'row_b', 'label' => 'Row B']],
                'options' => [['key' => 'good', 'label' => 'Good', 'score' => 2], ['key' => 'unknown', 'label' => 'Unknown']],
            ],
        ]);
        $singleId = (string) $survey->questions->first()->id;
        $service = app(SurveyResponseService::class);
        $service->submit($survey, $user, [$singleId => 'excellent', (string) $multi->id => ['a', 'b'], (string) $matrix->id => ['row_a' => 'good']]);
        $service->submit($survey, $second, [$singleId => 'unsure', (string) $multi->id => ['b'], (string) $matrix->id => ['row_b' => 'unknown']]);

        $questions = collect(app(SurveyAnalyticsService::class)->calculate($survey->fresh())['questions'])->keyBy('position');
        $this->assertSame(2, $questions[1]['metrics']['response_n']);
        $this->assertSame(1, $questions[1]['metrics']['scored_n']);
        $this->assertSame(2, $questions[2]['metrics']['response_n']);
        $this->assertSame(50.0, collect($questions[2]['metrics']['items'])->firstWhere('key', 'a')['percentage']);
        $this->assertSame(100.0, collect($questions[2]['metrics']['items'])->firstWhere('key', 'b')['percentage']);
        $this->assertSame(1, $questions[3]['metrics']['rows'][0]['metrics']['response_n']);
        $this->assertSame(1, $questions[3]['metrics']['rows'][1]['metrics']['response_n']);
        $this->assertSame(0, $questions[3]['metrics']['rows'][1]['metrics']['scored_n']);
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
        $this->assertSame('Current Condition', $metrics['questions'][0]['section']);
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

    public function test_back_to_basics_seed_dry_run_production_confirmation_and_drift_are_fail_closed(): void
    {
        $owner = User::factory()->create();
        $workgroup = Workgroup::create(['name' => 'Back to Basics - Train the Trainer', 'created_by' => $owner->id]);

        $this->artisan('workgroup-surveys:seed-back-to-basics', ['--dry-run' => true])->assertExitCode(0);
        $this->assertSame(0, $workgroup->surveys()->count());

        $originalEnvironment = app()->environment();
        app()->detectEnvironment(static fn (): string => 'production');
        try {
            $this->artisan('workgroup-surveys:seed-back-to-basics')->assertExitCode(1);
            $this->assertSame(0, $workgroup->surveys()->count());
            $this->artisan('workgroup-surveys:seed-back-to-basics', [
                '--confirm-production' => 'CREATE BACK TO BASICS SURVEY',
            ])->assertExitCode(0);
            $this->artisan('workgroup-surveys:seed-back-to-basics')->assertExitCode(0);
        } finally {
            app()->detectEnvironment(static fn (): string => $originalEnvironment);
        }

        $survey = $workgroup->surveys()->where('title', BackToBasicsSurveyBlueprint::TITLE)->sole();
        $survey->questions()->where('position', 2)->sole()->update([
            'configuration' => ['options' => [['key' => 'drift', 'label' => 'Drifted option']]],
        ]);
        $this->artisan('workgroup-surveys:seed-back-to-basics')->assertExitCode(1);
        $this->assertSame('Drifted option', $survey->questions()->where('position', 2)->sole()->configurationData()['options'][0]['label']);
    }

    public function test_back_to_basics_blueprint_preserves_the_required_question_contract(): void
    {
        $owner = User::factory()->create();
        $workgroup = Workgroup::create(['name' => 'Back to Basics - Train the Trainer', 'created_by' => $owner->id]);
        $this->artisan('workgroup-surveys:seed-back-to-basics')->assertExitCode(0);
        $survey = $workgroup->surveys()->where('title', BackToBasicsSurveyBlueprint::TITLE)->sole();
        $questions = BackToBasicsSurveyBlueprint::questions();
        $sections = array_column(app(SurveyAnalyticsService::class)->calculate($survey)['questions'], 'section');
        $contract = [
            'metadata' => BackToBasicsSurveyBlueprint::metadata(),
            'questions' => $questions,
            'sections' => $sections,
        ];

        $this->assertSame(range(1, 15), array_column($questions, 'position'));
        $this->assertSame([
            'Current Condition', 'Current Condition',
            'Qualification / Accountability', 'Qualification / Accountability', 'Qualification / Accountability',
            'Qualification / Accountability', 'Qualification / Accountability', 'Qualification / Accountability',
            'Officer / Tactical', 'Officer / Tactical',
            'Back to Basics Program', 'Back to Basics Program',
            'Leadership', 'Mid-Mount Equipment', 'Future Priorities',
        ], $sections);
        $this->assertSame(
            'a6d33d96ab916b07b80b625d7519841c3eec6f27fd39f645d3839533e9504303',
            hash('sha256', json_encode($contract, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
        );
    }

    public function test_a_new_revision_retains_a_parent_link_and_cannot_rewrite_submitted_content(): void
    {
        [$user, $survey] = $this->makeSurvey();
        $question = $survey->questions->first();
        app(SurveyResponseService::class)->submit($survey, $user, [(string) $question->id => 'excellent']);

        $this->expectException(\LogicException::class);
        $question->update(['prompt' => 'Reinterpreted question']);
    }

    public function test_a_question_cannot_be_added_to_a_submitted_revision(): void
    {
        [$user, $survey] = $this->makeSurvey();
        app(SurveyResponseService::class)->submit($survey, $user, [(string) $survey->questions->first()->id => 'excellent']);

        $this->expectException(LogicException::class);
        $survey->questions()->create([
            'position' => 2,
            'type' => 'single',
            'prompt' => 'Late addition',
            'is_required' => false,
            'configuration' => ['options' => [['key' => 'yes', 'label' => 'Yes']]],
        ]);
    }

    public function test_a_question_cannot_be_deleted_from_a_submitted_revision(): void
    {
        [$user, $survey] = $this->makeSurvey();
        $question = $survey->questions->first();
        app(SurveyResponseService::class)->submit($survey, $user, [(string) $question->id => 'excellent']);

        $this->expectException(LogicException::class);
        $question->delete();
    }

    public function test_a_question_cannot_be_reparented_from_a_submitted_revision(): void
    {
        [$user, $survey] = $this->makeSurvey();
        $question = $survey->questions->first();
        app(SurveyResponseService::class)->submit($survey, $user, [(string) $question->id => 'excellent']);
        $newRevision = WorkgroupSurvey::create([
            'workgroup_id' => $survey->workgroup_id,
            'parent_survey_id' => $survey->id,
            'revision' => 2,
            'title' => 'New revision',
            'status' => 'draft',
        ]);

        $this->expectException(LogicException::class);
        $question->update(['survey_id' => $newRevision->id]);
    }

    public function test_a_submitted_survey_cannot_be_deleted(): void
    {
        [$user, $survey] = $this->makeSurvey();
        app(SurveyResponseService::class)->submit($survey, $user, [(string) $survey->questions->first()->id => 'excellent']);

        $this->expectException(LogicException::class);
        $survey->delete();
    }

    public function test_anonymous_csv_pdf_print_and_ai_payloads_do_not_contain_member_identity_or_other_text(): void
    {
        [$user, $survey] = $this->makeSurvey();
        $user->update(['name' => 'Sensitive Respondent Name']);
        $question = $survey->questions->first();
        app(SurveyResponseService::class)->submit($survey, $user, [(string) $question->id => 'excellent'], ['rank' => 'Captain']);
        $this->actingAs($user);
        $survey = $survey->fresh(['questions', 'workgroup']);

        $ai = $this->mock(LocalAIService::class);
        $ai->shouldReceive('runModel')->once()->withArgs(function (string $model, array $messages): bool {
            $payload = json_encode($messages, JSON_THROW_ON_ERROR);
            $this->assertSame('mbfd-general', $model);
            $this->assertStringNotContainsString('Sensitive Respondent Name', $payload);
            $this->assertStringNotContainsString('participant_token', $payload);
            $this->assertStringNotContainsString('Captain', $payload);

            return true;
        })->andThrow(new RuntimeException('AI unavailable'));

        Filament::setCurrentPanel(Filament::getPanel('workgroups'));
        Livewire::withQueryParams(['surveyId' => $survey->id])->test(SurveyResultsPage::class)->assertOk();

        $page = app(SurveyResultsPage::class);
        $page->survey = $survey;
        $csv = $this->streamedContent($page->exportCsv());
        $printable = $this->streamedContent($page->downloadPrintableHtml());
        $pdf = (string) $page->downloadPdf()->getContent();
        foreach ([$csv, $printable, $pdf] as $artifact) {
            $this->assertStringNotContainsString('Sensitive Respondent Name', $artifact);
        }
        $this->assertStringNotContainsString('Participant roster', $printable);

        $result = app(SurveyExecutiveNarrativeService::class)->generate($survey, $user);
        $this->assertFalse($result['generated']);
        $this->assertDatabaseHas('workgroup_survey_reports', ['survey_id' => $survey->id, 'executive_narrative' => null]);
    }

    public function test_member_and_manager_roles_and_cross_workgroup_management_fail_closed(): void
    {
        [$manager, $survey] = $this->makeSurvey();
        $member = $this->addMember($survey->workgroup, true);
        $access = app(WorkgroupAccess::class);
        $this->assertTrue($access->canViewSurvey($member, $survey));
        $this->assertFalse($access->canManageSurvey($member, $survey));
        $this->assertTrue($access->canManageSurvey($manager, $survey));

        $outsider = User::factory()->create();
        $otherWorkgroup = Workgroup::create(['name' => 'Other Results Workgroup', 'created_by' => $outsider->id]);
        WorkgroupMember::create(['workgroup_id' => $otherWorkgroup->id, 'user_id' => $outsider->id, 'role' => 'facilitator', 'is_active' => true, 'count_evaluations' => true]);
        app(WorkgroupContext::class)->select($outsider, $otherWorkgroup->id);
        $this->actingAs($outsider);
        Filament::setCurrentPanel(Filament::getPanel('workgroups'));
        Livewire::withQueryParams(['surveyId' => $survey->id])->test(SurveyResultsPage::class)->assertStatus(404);

        $participant = WorkgroupSurveyParticipant::create([
            'survey_id' => $survey->id,
            'workgroup_member_id' => $survey->workgroup->members()->first()->id,
            'is_eligible' => true,
            'include_in_analysis' => true,
        ]);
        $this->assertHttpFailure(fn () => app(SurveyResponseService::class)->setAnalysisInclusion($survey, $outsider, $participant, false), 404);
        $this->assertHttpFailure(fn () => app(SurveyExecutiveNarrativeService::class)->generate($survey, $outsider), 404);
    }

    public function test_submitted_answers_use_their_schema_snapshot_even_if_storage_is_tampered_with(): void
    {
        [$user, $survey] = $this->makeSurvey();
        $question = $survey->questions->first();
        app(SurveyResponseService::class)->submit($survey, $user, [(string) $question->id => 'excellent']);

        $question->forceFill([
            'prompt' => 'Tampered question',
            'configuration' => ['options' => [['key' => 'excellent', 'label' => 'Excellent', 'score' => 1]]],
        ])->saveQuietly();

        $analytics = app(SurveyAnalyticsService::class)->calculate($survey->fresh());
        $this->assertSame('Rate it', $analytics['questions'][0]['prompt']);
        $this->assertSame(5.0, $analytics['questions'][0]['metrics']['mean']);

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
            'minimum_subgroup_size' => 3, 'demographic_fields' => [['key' => 'rank', 'label' => 'Rank', 'options' => ['Captain', 'Firefighter', 'Prefer not to answer']]],
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

    private function assertValidationFails(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected server-side survey validation to fail.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
    }

    private function assertHttpFailure(callable $callback, int $status): void
    {
        try {
            $callback();
            $this->fail("Expected HTTP {$status} failure.");
        } catch (HttpException $exception) {
            $this->assertSame($status, $exception->getStatusCode());
        }
    }

    private function streamedContent(StreamedResponse $response): string
    {
        ob_start();
        $response->sendContent();
        $content = ob_get_clean();

        return is_string($content) ? $content : '';
    }
}
