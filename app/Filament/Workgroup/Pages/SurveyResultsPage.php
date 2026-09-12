<?php

declare(strict_types=1);

namespace App\Filament\Workgroup\Pages;

use App\Models\User;
use App\Models\WorkgroupMember;
use App\Models\WorkgroupSurvey;
use App\Models\WorkgroupSurveyParticipant;
use App\Models\WorkgroupSurveyReport;
use App\Services\Workgroup\SurveyAnalyticsService;
use App\Services\Workgroup\SurveyExecutiveNarrativeService;
use App\Support\Workgroups\WorkgroupAccess;
use App\Support\Workgroups\WorkgroupContext;
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

    /** @var list<array{id: int, name: string, eligible: bool, submitted: bool, included: bool}> */
    public array $roster = [];

    public function mount(): void
    {
        $this->surveyId = (int) request()->integer('surveyId');
        $user = auth()->user();
        abort_unless($user instanceof User && $this->surveyId > 0, 404);
        $current = app(WorkgroupContext::class)->requireCurrent($user);
        /** @var WorkgroupSurvey|null $survey */
        $survey = app(WorkgroupAccess::class)->scopeManageSurveys(WorkgroupSurvey::query()->with('questions'), $user)
            ->where('workgroup_id', $current->id)
            ->find($this->surveyId);
        $this->survey = $survey;
        abort_unless($this->survey instanceof WorkgroupSurvey, 404);
        $this->analytics = app(SurveyAnalyticsService::class)->calculate($this->survey);
        $this->refreshRoster();
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

    public function downloadPrintableHtml()
    {
        $survey = $this->requiredSurvey();
        $html = view('filament-workgroup.pages.survey-results-pdf', [
            'survey' => $survey,
            'analytics' => app(SurveyAnalyticsService::class)->calculate($survey),
        ])->render();

        return response()->streamDownload(
            static fn () => print $html,
            'workgroup-survey-'.$survey->id.'-'.now()->format('Y-m-d').'.html',
            ['Content-Type' => 'text/html; charset=UTF-8'],
        );
    }

    public function synchronizeRoster(): void
    {
        $survey = $this->requiredSurvey();
        app(WorkgroupAccess::class)->requireManageSurvey($this->user(), $survey);
        WorkgroupMember::query()->where('workgroup_id', $survey->workgroup_id)->where('is_active', true)->each(function (WorkgroupMember $member) use ($survey): void {
            WorkgroupSurveyParticipant::query()->firstOrCreate(
                ['survey_id' => $survey->id, 'workgroup_member_id' => $member->id],
                ['is_eligible' => true, 'include_in_analysis' => $member->count_evaluations],
            );
        });
        $this->refreshRoster();
        $this->refreshAnalytics();
        Notification::make()->success()->title('Participant roster synchronized')->send();
    }

    public function toggleEligibility(int $participantId): void
    {
        $participant = $this->requiredParticipant($participantId);
        $participant->update(['is_eligible' => ! $participant->is_eligible]);
        $this->refreshRoster();
        $this->refreshAnalytics();
    }

    public function toggleAnalysisInclusion(int $participantId): void
    {
        $participant = $this->requiredParticipant($participantId);
        app(WorkgroupAccess::class)->requireManageSurvey($this->user(), $this->requiredSurvey());
        $participant->update(['include_in_analysis' => ! $participant->include_in_analysis]);
        $this->refreshRoster();
        $this->refreshAnalytics();
    }

    private function requiredSurvey(): WorkgroupSurvey
    {
        abort_unless($this->survey instanceof WorkgroupSurvey, 404);
        app(WorkgroupAccess::class)->requireManageSurvey($this->user(), $this->survey);
        $current = app(WorkgroupContext::class)->requireCurrent($this->user());
        abort_unless($current->id === $this->survey->workgroup_id, 404);

        return $this->survey;
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 404);

        return $user;
    }

    private function requiredParticipant(int $participantId): WorkgroupSurveyParticipant
    {
        app(WorkgroupAccess::class)->requireManageSurvey($this->user(), $this->requiredSurvey());
        /** @var WorkgroupSurveyParticipant|null $participant */
        $participant = $this->requiredSurvey()->participants()->find($participantId);
        abort_unless($participant instanceof WorkgroupSurveyParticipant, 404);

        return $participant;
    }

    private function refreshAnalytics(): void
    {
        $survey = $this->requiredSurvey()->fresh();
        abort_unless($survey instanceof WorkgroupSurvey, 404);
        $this->survey = $survey;
        $this->analytics = app(SurveyAnalyticsService::class)->calculate($survey);
    }

    private function refreshRoster(): void
    {
        $this->roster = $this->requiredSurvey()->participants()->with('member.user')->orderBy('id')->get()
            ->map(fn (WorkgroupSurveyParticipant $participant): array => [
                'id' => $participant->id,
                'name' => $participant->member->name,
                'eligible' => $participant->is_eligible,
                'submitted' => $participant->submitted_at !== null,
                'included' => $participant->include_in_analysis,
            ])->all();
    }
}
