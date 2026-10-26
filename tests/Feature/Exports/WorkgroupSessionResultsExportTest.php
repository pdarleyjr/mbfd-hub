<?php

declare(strict_types=1);

namespace Tests\Feature\Exports;

use App\Filament\Workgroup\Pages\SessionResultsPage;
use App\Models\CandidateProduct;
use App\Models\EvaluationCategory;
use App\Models\EvaluationSubmission;
use App\Models\User;
use App\Models\Workgroup;
use App\Models\WorkgroupMember;
use App\Models\WorkgroupSession;
use App\Services\Exports\TableExportService;
use App\Services\Workgroup\EvaluationService;
use App\Support\Workgroups\WorkgroupContext;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Smalot\PdfParser\Parser;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class WorkgroupSessionResultsExportTest extends ExportTestCase
{
    private function context(User $user, string $name): array
    {
        $workgroup = Workgroup::query()->create(['name' => $name, 'is_active' => true, 'created_by' => $user->id]);
        $member = WorkgroupMember::query()->create(['user_id' => $user->id, 'workgroup_id' => $workgroup->id,
            'role' => 'member', 'is_active' => true, 'count_evaluations' => true]);
        $session = WorkgroupSession::query()->create(['workgroup_id' => $workgroup->id, 'name' => $name.' session',
            'status' => 'active', 'start_date' => '2026-10-01', 'end_date' => '2026-10-31']);
        $category = EvaluationCategory::query()->firstOrCreate(['name' => 'Synthetic tools'], ['is_active' => true, 'is_rankable' => true]);
        $product = CandidateProduct::query()->create(['workgroup_session_id' => $session->id, 'category_id' => $category->id,
            'name' => $name.' spreader', 'manufacturer' => 'Synthetic brand', 'competitor_group' => 'spreader']);
        EvaluationSubmission::query()->create(['workgroup_member_id' => $member->id, 'candidate_product_id' => $product->id,
            'status' => 'submitted', 'overall_score' => 4, 'capability_score' => 4, 'usability_score' => 3,
            'affordability_score' => 4, 'maintainability_score' => 4, 'deployability_score' => 5, 'advance_recommendation' => 'yes']);

        return compact('workgroup', 'member', 'session', 'product');
    }

    public function test_member_excel_exports_selected_session_tables_without_other_workgroup_data(): void
    {
        $viewer = $this->userWith();
        $context = $this->context($viewer, 'Visible');
        $feedbackCategory = EvaluationCategory::query()->create(['name' => 'Synthetic feedback', 'is_active' => true, 'is_rankable' => false]);
        foreach ([['Visible ram', $context['product']->category_id, 'Second brand', 'ram'],
            ['Visible T1', $context['product']->category_id, 'Synthetic brand', 'standalone'],
            ['Visible feedback', $feedbackCategory->id, 'Synthetic brand', 'standalone']] as [$name, $category, $brand, $group]) {
            $product = CandidateProduct::query()->create(['workgroup_session_id' => $context['session']->id, 'category_id' => $category,
                'name' => $name, 'manufacturer' => $brand, 'competitor_group' => $group]);
            $submission = EvaluationSubmission::query()->create(['workgroup_member_id' => $context['member']->id,
                'candidate_product_id' => $product->id, 'status' => 'submitted', 'overall_score' => 3]);
            if ($category === $feedbackCategory->id) {
                $submission->comments()->create(['category_id' => $feedbackCategory->id, 'comment' => 'Synthetic visible feedback']);
            }
        }
        $this->context($this->userWith(), 'Other');
        $this->actingAs($viewer);
        app(WorkgroupContext::class)->select($viewer, $context['workgroup']->id);
        Filament::setCurrentPanel(Filament::getPanel('workgroups'));
        $page = Livewire::test(SessionResultsPage::class)->callAction('exportData', data: ['format' => 'xlsx']);
        $text = json_encode($this->dataRows($this->workbook($page)), JSON_THROW_ON_ERROR);
        foreach (['Session progress', 'Category rankings', 'Tool comparison', 'Brand comparison', 'Visible spreader', 'Capability',
            'Package purchase comparison', 'Standalone product analysis', 'Non-rankable feedback', 'Synthetic visible feedback'] as $label) {
            self::assertStringContainsString($label, $text);
        }
        self::assertStringNotContainsString('Other spreader', $text);
    }

    public function test_pdf_contains_the_selected_session_and_explicit_date_range(): void
    {
        $context = $this->context($this->admin, 'Visible');
        app(WorkgroupContext::class)->select($this->admin, $context['workgroup']->id);
        Filament::setCurrentPanel(Filament::getPanel('workgroups'));
        $page = Livewire::test(SessionResultsPage::class)->callAction('exportData', data: ['format' => 'pdf']);
        $text = (new Parser)->parseContent($this->downloadedBytes($page))->getText();
        foreach (['Visible session', '2026-10-01', '2026-10-31', 'Visible spreader'] as $label) {
            self::assertStringContainsString($label, $text);
        }
    }

    public function test_membership_revoked_after_opening_results_prevents_both_exports(): void
    {
        $viewer = $this->userWith();
        $context = $this->context($viewer, 'Visible');
        $this->actingAs($viewer);
        app(WorkgroupContext::class)->select($viewer, $context['workgroup']->id);
        Filament::setCurrentPanel(Filament::getPanel('workgroups'));
        $page = Livewire::test(SessionResultsPage::class)->instance();
        $context['member']->update(['is_active' => false]);
        self::assertFalse($page->canExportData($viewer));
        foreach (['xlsx', 'pdf'] as $format) {
            try {
                app(TableExportService::class)->downloadDataset($viewer, SessionResultsPage::class, $format,
                    fn (): bool => $page->canExportData($viewer),
                    static fn () => throw new \LogicException('Denied evaluation data must not be read.'));
                self::fail('Revoked membership allowed an export.');
            } catch (HttpException $exception) {
                self::assertSame(403, $exception->getStatusCode());
            }
        }
    }

    public function test_finalists_filter_for_response_threshold_before_selecting_the_top_two(): void
    {
        $context = $this->context($this->admin, 'Visible');
        $eligible = CandidateProduct::query()->create(['workgroup_session_id' => $context['session']->id,
            'category_id' => $context['product']->category_id, 'name' => 'Eligible lower score', 'manufacturer' => 'Synthetic brand']);
        $higher = CandidateProduct::query()->create(['workgroup_session_id' => $context['session']->id,
            'category_id' => $context['product']->category_id, 'name' => 'Second higher score', 'manufacturer' => 'Synthetic brand']);
        EvaluationSubmission::query()->create(['workgroup_member_id' => $context['member']->id,
            'candidate_product_id' => $higher->id, 'status' => 'submitted', 'overall_score' => 3.5]);
        foreach ([3, 3, 3] as $score) {
            $user = $this->userWith();
            $member = WorkgroupMember::query()->create(['user_id' => $user->id, 'workgroup_id' => $context['workgroup']->id,
                'role' => 'member', 'is_active' => true, 'count_evaluations' => true]);
            EvaluationSubmission::query()->create(['workgroup_member_id' => $member->id, 'candidate_product_id' => $eligible->id,
                'status' => 'submitted', 'overall_score' => $score]);
        }
        app(EvaluationService::class)->setMinimumResponseThreshold(3);
        app(WorkgroupContext::class)->select($this->admin, $context['workgroup']->id);
        Filament::setCurrentPanel(Filament::getPanel('workgroups'));
        $page = Livewire::test(SessionResultsPage::class)->callAction('exportData', data: ['format' => 'xlsx']);
        $rows = collect($this->dataRows($this->workbook($page)))->filter(fn (array $row): bool => $row[0] === 'Finalists');
        self::assertNotEmpty($rows);
        self::assertSame(['Eligible lower score'], $rows->pluck(3)->unique()->values()->all());
        self::assertSame([3], $rows->pluck(2)->unique()->values()->all());
    }

    public function test_existing_csv_export_filenames_use_the_eastern_day_near_utc_midnight(): void
    {
        $context = $this->context($this->admin, 'Visible');
        $this->travelTo(CarbonImmutable::parse('2026-10-26T00:30:00Z'));
        $this->actingAs($this->admin);
        app(WorkgroupContext::class)->select($this->admin, $context['workgroup']->id);
        Filament::setCurrentPanel(Filament::getPanel('workgroups'));
        $page = Livewire::test(SessionResultsPage::class);
        $page->call('exportTableCsv', 'spreaders')->assertFileDownloaded('spreaders_results_2026-10-25.csv');
        $page->call('exportCategoryRankingsCsv', 'Synthetic tools')->assertFileDownloaded('synthetic_tools_rankings_2026-10-25.csv');
        $page->call('exportCompetitorGroupsCsv')->assertFileDownloaded('competitor_group_rankings_2026-10-25.csv');
        $page->call('exportFinalistsCsv')->assertFileDownloaded('finalists_2026-10-25.csv');
    }
}
