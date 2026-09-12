<?php

declare(strict_types=1);

namespace App\Filament\Workgroup\Pages;

use App\Models\User;
use App\Models\WorkgroupSurvey;
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
        $this->survey = app(WorkgroupAccess::class)->scopeSurveys(WorkgroupSurvey::with('questions'), $user)->find($this->surveyId);
        abort_unless($this->survey instanceof WorkgroupSurvey, 404);
        $response = app(SurveyResponseService::class)->draftFor($this->survey, $user)->load('answers');
        $this->submitted = $response->submitted_at !== null;
        $this->answers = $response->answers->mapWithKeys(fn ($answer): array => [(string) $answer->survey_question_id => $answer->answer['value'] ?? null])->all();
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
        Notification::make()->success()->title('Survey submitted')->body('Thank you. Your answers are de-identified in reporting.')->send();
    }

    private function requiredSurvey(): WorkgroupSurvey { abort_unless($this->survey instanceof WorkgroupSurvey, 404); return $this->survey; }
    private function user(): User { $user = auth()->user(); abort_unless($user instanceof User, 404); return $user; }
    private function responseService(): SurveyResponseService { return app(SurveyResponseService::class); }
}
