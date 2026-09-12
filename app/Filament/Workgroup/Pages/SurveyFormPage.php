<?php

declare(strict_types=1);

namespace App\Filament\Workgroup\Pages;

use App\Models\User;
use App\Models\WorkgroupSurvey;
use App\Models\WorkgroupSurveyAnswer;
use App\Services\Workgroup\SurveyResponseService;
use App\Support\Workgroups\WorkgroupAccess;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class SurveyFormPage extends Page
{
    protected static string $view = 'filament-workgroup.pages.survey-form';

    protected static ?string $title = 'Survey';

    protected static bool $shouldRegisterNavigation = false;

    public int $surveyId;

    public ?WorkgroupSurvey $survey = null;

    public array $answers = [];

    public array $demographics = [];

    public bool $submitted = false;

    public function mount(): void
    {
        $this->surveyId = (int) request()->integer('surveyId');
        $user = auth()->user();
        abort_unless($user instanceof User && $this->surveyId > 0, 404);
        /** @var WorkgroupSurvey|null $survey */
        $survey = app(WorkgroupAccess::class)->scopeSurveys(WorkgroupSurvey::query()->with('questions'), $user)->find($this->surveyId);
        $this->survey = $survey;
        abort_unless($this->survey instanceof WorkgroupSurvey, 404);
        $response = app(SurveyResponseService::class)->draftFor($this->survey, $user)->load('answers');
        $this->submitted = $response->submitted_at !== null;
        $this->answers = $response->answers->mapWithKeys(function (WorkgroupSurveyAnswer $answer): array {
            $value = $answer->value();
            $question = $this->survey?->questions->firstWhere('id', $answer->survey_question_id);
            if ($question?->type === 'multi' && ($question->configurationData()['allow_other_text'] ?? false) && is_array($value) && array_is_list($value)) {
                $value = ['selections' => $value, 'other_text' => ''];
            }

            return [(string) $answer->survey_question_id => $value];
        })->all();
        foreach ($this->survey->questions as $question) {
            if ($question->type === 'multi' && ! array_key_exists((string) $question->id, $this->answers)) {
                $this->answers[(string) $question->id] = ($question->configurationData()['allow_other_text'] ?? false)
                    ? ['selections' => [], 'other_text' => '']
                    : [];
            }
        }
        $this->demographics = $response->demographics ?? [];
    }

    public function saveDraft(): void
    {
        $this->responseService()->saveDraft($this->requiredSurvey(), $this->user(), $this->answers, $this->demographics);
        Notification::make()->success()->title('Draft saved')->send();
    }

    public function submit(): void
    {
        $this->responseService()->submit($this->requiredSurvey(), $this->user(), $this->answers, $this->demographics);
        $this->submitted = true;
        Notification::make()->success()->title('Survey submitted')->body($this->requiredSurvey()->is_anonymous ? 'Thank you. Your answers are de-identified in reporting.' : 'Thank you. Your response has been recorded.')->send();
    }

    public function answeredQuestionCount(): int
    {
        return collect($this->answers)->filter(function (mixed $value): bool {
            return $value !== null && $value !== '' && $value !== []
                && (! is_array($value) || ! array_key_exists('selections', $value) || $value['selections'] !== []);
        })->count();
    }

    public function questionCount(): int
    {
        return $this->requiredSurvey()->questions->count();
    }

    private function requiredSurvey(): WorkgroupSurvey
    {
        abort_unless($this->survey instanceof WorkgroupSurvey, 404);

        return $this->survey;
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 404);

        return $user;
    }

    private function responseService(): SurveyResponseService
    {
        return app(SurveyResponseService::class);
    }
}
