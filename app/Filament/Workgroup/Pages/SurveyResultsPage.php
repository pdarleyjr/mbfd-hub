<?php

declare(strict_types=1);

namespace App\Filament\Workgroup\Pages;

use App\Models\User;
use App\Models\WorkgroupSurvey;
use App\Models\WorkgroupSurveyReport;
use App\Services\Workgroup\SurveyAnalyticsService;
use App\Services\Workgroup\SurveyExecutiveNarrativeService;
use App\Support\Workgroups\WorkgroupAccess;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class SurveyResultsPage extends Page
{
    protected static string $view = 'filament-workgroup.pages.survey-results';
    protected static ?string $title = 'Survey Results';
    protected static bool $shouldRegisterNavigation = false;

    public int $surveyId;
    public ?WorkgroupSurvey $survey = null;
    /** @var array<string,mixed> */
    public array $analytics = [];
    public ?WorkgroupSurveyReport $report = null;

    public function mount(): void
    {
        $this->surveyId = (int) request()->integer('surveyId');
        $user = auth()->user();
        abort_unless($user instanceof User && $this->surveyId > 0, 404);
        $this->survey = app(WorkgroupAccess::class)->scopeManageSurveys(WorkgroupSurvey::with('questions'), $user)->find($this->surveyId);
        abort_unless($this->survey instanceof WorkgroupSurvey, 404);
        $this->analytics = app(SurveyAnalyticsService::class)->calculate($this->survey);
    }

    /** Explicit action only; no page-load AI invocation. */
    public function generateExecutiveReport(): void
    {
        $result = app(SurveyExecutiveNarrativeService::class)->generate($this->requiredSurvey(), $this->user());
        $this->report = $result['report'];
        Notification::make()->title($result['generated'] ? 'Executive report generated' : 'Deterministic report saved; AI narrative unavailable')->{$result['generated'] ? 'success' : 'warning'}()->send();
    }

    private function requiredSurvey(): WorkgroupSurvey { abort_unless($this->survey instanceof WorkgroupSurvey, 404); return $this->survey; }
    private function user(): User { $user = auth()->user(); abort_unless($user instanceof User, 404); return $user; }
}

