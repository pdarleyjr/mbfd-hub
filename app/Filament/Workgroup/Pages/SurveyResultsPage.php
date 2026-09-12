<?php

declare(strict_types=1);

namespace App\Filament\Workgroup\Pages;

use App\Models\User;
use App\Models\WorkgroupSurvey;
use App\Models\WorkgroupSurveyReport;
use App\Services\Workgroup\SurveyAnalyticsService;
use App\Services\Workgroup\SurveyExecutiveNarrativeService;
use App\Support\Workgroups\WorkgroupAccess;
use Barryvdh\DomPDF\Facade\Pdf;
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
        /** @var WorkgroupSurvey|null $survey */
        $survey = app(WorkgroupAccess::class)->scopeManageSurveys(WorkgroupSurvey::query()->with('questions'), $user)->find($this->surveyId);
        $this->survey = $survey;
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

    public function exportCsv()
    {
        $analytics = app(SurveyAnalyticsService::class)->calculate($this->requiredSurvey());
        $filename = 'workgroup-survey-'.$this->requiredSurvey()->id.'-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($analytics): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Question', 'Type', 'Metric', 'Value']);
            foreach ($analytics['summary'] as $metric => $value) {
                fputcsv($handle, ['Survey summary', '', $metric, $value]);
            }
            foreach ($analytics['questions'] as $question) {
                fputcsv($handle, [$question['prompt'], $question['type'], 'metrics', json_encode($question['metrics'], JSON_UNESCAPED_SLASHES)]);
            }
            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    public function downloadPdf()
    {
        $survey = $this->requiredSurvey();
        $analytics = app(SurveyAnalyticsService::class)->calculate($survey);

        return Pdf::loadView('filament-workgroup.pages.survey-results-pdf', [
            'survey' => $survey,
            'analytics' => $analytics,
        ])->setPaper('letter', 'portrait')->download('workgroup-survey-'.$survey->id.'-'.now()->format('Y-m-d').'.pdf');
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
}
