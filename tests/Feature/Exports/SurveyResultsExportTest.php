<?php

declare(strict_types=1);

namespace Tests\Feature\Exports;

use App\Filament\Workgroup\Pages\SurveyResultsPage;
use App\Models\SecurityActionEvent;
use App\Models\User;
use App\Models\Workgroup;
use App\Models\WorkgroupMember;
use App\Models\WorkgroupSurvey;
use App\Services\Exports\ExportDataset;
use App\Services\Exports\TableExportService;
use App\Services\Workgroup\SurveyAnalyticsService;
use App\Services\Workgroup\SurveyResponseService;
use App\Support\Workgroups\WorkgroupContext;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Smalot\PdfParser\Parser;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class SurveyResultsExportTest extends ExportTestCase
{
    /** @return array{User, WorkgroupSurvey, WorkgroupMember} */
    private function surveyFixture(string $instant = '2026-10-26T12:00:00Z'): array
    {
        $this->travelTo(CarbonImmutable::parse($instant));
        $manager = $this->userWith('admin.access');
        $manager->update(['name' => 'Roster Manager']);
        $workgroup = Workgroup::query()->create(['name' => 'Export survey workgroup', 'created_by' => $manager->id]);
        $member = WorkgroupMember::query()->create(['user_id' => $manager->id, 'workgroup_id' => $workgroup->id, 'role' => 'facilitator', 'is_active' => true, 'count_evaluations' => true]);
        $survey = WorkgroupSurvey::query()->create([
            'workgroup_id' => $workgroup->id, 'title' => 'Export survey', 'revision' => 1, 'status' => 'active', 'is_anonymous' => true, 'minimum_subgroup_size' => 3,
            'demographic_fields' => [['key' => 'rank', 'label' => 'Rank', 'options' => ['Sensitive small category']]],
        ]);
        $question = $survey->questions()->create([
            'position' => 1, 'prompt' => 'Rate readiness', 'type' => 'single', 'is_required' => true,
            'configuration' => ['options' => [['key' => 'excellent', 'label' => 'Excellent', 'score' => 5]]],
        ]);
        app(SurveyResponseService::class)->submit($survey, $manager, [(string) $question->id => 'excellent'], ['rank' => 'Sensitive small category']);
        $this->actingAs($manager);
        app(WorkgroupContext::class)->select($manager, $workgroup->id);
        Filament::setCurrentPanel(Filament::getPanel('workgroups'));

        return [$manager, $survey->fresh(['questions', 'workgroup']), $member];
    }

    #[DataProvider('formats')]
    public function test_results_and_separate_roster_export_fresh_aggregates_without_anonymous_links(string $format): void
    {
        [$manager, $survey] = $this->surveyFixture();
        $analytics = app(SurveyAnalyticsService::class)->calculate($survey);
        self::assertTrue($analytics['demographics'][0]['items'][0]['suppressed']);
        $token = $survey->participants()->sole()->response_token;
        $component = Livewire::withQueryParams(['surveyId' => $survey->id])->test(SurveyResultsPage::class)
            ->assertSee('Oct 26, 2026 8:00 AM EDT')
            ->set('analytics.summary.eligible_participants', 9999)
            ->set('roster', [['id' => 1, 'name' => 'Forged cached roster', 'eligible' => true, 'submitted' => true, 'included' => true]])
            ->callAction('exportData', ['format' => $format]);
        if ($format === 'pdf') {
            $text = (new Parser)->parseContent($this->downloadedBytes($component))->getText();
        } else {
            $book = $this->workbook($component);
            $text = json_encode($book->getSheet(0)->toArray(null, true, true, false), JSON_THROW_ON_ERROR);
        }
        foreach (['Rate readiness', 'Excellent', 'Participant roster', 'Roster Manager', 'Included in analysis'] as $value) {
            self::assertStringContainsString($value, $text);
        }
        foreach (['Sensitive small category', $token, 'Forged cached roster', '9999', 'participant_token', 'response_token'] as $value) {
            self::assertStringNotContainsString($value, $text);
        }
        self::assertSame(1, $survey->responses()->count());
        self::assertNull($survey->responses()->sole()->workgroup_member_id);
        self::assertSame(1, SecurityActionEvent::query()->where('action', 'table_export')->where('result', 'allowed')->count());
    }

    #[DataProvider('formats')]
    public function test_export_is_refused_after_management_membership_is_revoked(string $format): void
    {
        [$manager, $survey, $member] = $this->surveyFixture();
        $page = app(SurveyResultsPage::class);
        $page->survey = $survey;
        self::assertTrue($page->canExportData($manager));
        $member->update(['role' => 'member']);
        $this->assertDenied($page, $manager, $format);
    }

    #[DataProvider('formats')]
    public function test_a_forged_survey_or_workgroup_model_cannot_cross_the_current_context(string $format): void
    {
        [$manager, $survey] = $this->surveyFixture();
        $other = Workgroup::query()->create(['name' => 'Other managed workgroup', 'created_by' => $manager->id]);
        WorkgroupMember::query()->create(['user_id' => $manager->id, 'workgroup_id' => $other->id, 'role' => 'facilitator', 'is_active' => true]);
        $foreign = WorkgroupSurvey::query()->create(['workgroup_id' => $other->id, 'title' => 'Foreign survey', 'status' => 'draft']);
        $page = app(SurveyResultsPage::class);
        $page->survey = $foreign;
        $page->survey->workgroup_id = $survey->workgroup_id;
        $this->assertDenied($page, $manager, $format);
    }

    private function assertDenied(SurveyResultsPage $page, User $actor, string $format): void
    {
        self::assertFalse($page->canExportData($actor));
        try {
            app(TableExportService::class)->downloadDataset($actor, SurveyResultsPage::class, $format,
                fn (): bool => $page->canExportData($actor),
                static fn (): ExportDataset => throw new \LogicException('Denied survey export read data'));
            self::fail('Export unexpectedly allowed.');
        } catch (HttpException $exception) {
            self::assertSame(403, $exception->getStatusCode());
        }
        self::assertSame(1, SecurityActionEvent::query()->where('action', 'table_export')->where('result', 'denied')->count());
    }

    public function test_legacy_survey_download_filenames_roll_over_at_eastern_midnight(): void
    {
        [, $survey] = $this->surveyFixture('2026-10-26T03:59:59Z');
        $page = app(SurveyResultsPage::class);
        $page->survey = $survey;

        foreach (['2026-10-26T03:59:59Z' => '2026-10-25', '2026-10-26T04:00:00Z' => '2026-10-26'] as $instant => $day) {
            $this->travelTo(CarbonImmutable::parse($instant));
            foreach (['exportCsv' => 'csv', 'downloadPdf' => 'pdf', 'downloadPrintableHtml' => 'html'] as $method => $extension) {
                $response = $page->$method();
                self::assertStringContainsString('workgroup-survey-'.$survey->id.'-'.$day.'.'.$extension, $response->headers->get('Content-Disposition'));
            }
        }
    }

    public static function formats(): array
    {
        return [['xlsx'], ['pdf']];
    }
}
