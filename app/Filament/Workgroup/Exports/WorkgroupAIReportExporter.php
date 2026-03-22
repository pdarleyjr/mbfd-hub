<?php

namespace App\Filament\Workgroup\Exports;

use App\Models\CandidateProduct;
use App\Models\EvaluationSubmission;
use App\Models\WorkgroupSession;
use App\Services\Workgroup\WorkgroupAIService;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Support\Facades\Cache;

/**
 * AI-Enhanced Evaluation Report Exporter
 *
 * Exports all products with their scores PLUS AI-generated analytical summaries.
 * Each row includes: category, product name, manufacturer, aggregate scores,
 * ranking within category, and an AI-generated analysis paragraph.
 *
 * This exporter is designed for the Health & Safety Committee presentation.
 */
class WorkgroupAIReportExporter extends Exporter
{
    protected static ?string $model = CandidateProduct::class;

    public static function getColumns(): array
    {
        return [
            // Identification
            ExportColumn::make('category.name')
                ->label('Category'),

            ExportColumn::make('name')
                ->label('Product Name'),

            ExportColumn::make('manufacturer')
                ->label('Manufacturer'),

            ExportColumn::make('model')
                ->label('Model'),

            // Aggregate Scores
            ExportColumn::make('avg_overall_score')
                ->label('Avg Overall Score (0-5)')
                ->state(fn (CandidateProduct $record): string => static::getAvgScore($record, 'overall_score')),

            ExportColumn::make('avg_capability')
                ->label('Avg Capability')
                ->state(fn (CandidateProduct $record): string => static::getAvgScore($record, 'capability_score')),

            ExportColumn::make('avg_usability')
                ->label('Avg Usability')
                ->state(fn (CandidateProduct $record): string => static::getAvgScore($record, 'usability_score')),

            ExportColumn::make('avg_affordability')
                ->label('Avg Affordability')
                ->state(fn (CandidateProduct $record): string => static::getAvgScore($record, 'affordability_score')),

            ExportColumn::make('avg_maintainability')
                ->label('Avg Maintainability')
                ->state(fn (CandidateProduct $record): string => static::getAvgScore($record, 'maintainability_score')),

            ExportColumn::make('avg_deployability')
                ->label('Avg Deployability')
                ->state(fn (CandidateProduct $record): string => static::getAvgScore($record, 'deployability_score')),

            // Evaluation Stats
            ExportColumn::make('evaluator_count')
                ->label('# Evaluators')
                ->state(fn (CandidateProduct $record): int =>
                    $record->submissions()->where('status', 'submitted')->count()
                ),

            ExportColumn::make('category_rank')
                ->label('Category Rank')
                ->state(fn (CandidateProduct $record): string => static::getCategoryRank($record)),

            ExportColumn::make('finalist_votes')
                ->label('Advance to Finalist (votes)')
                ->state(fn (CandidateProduct $record): string => static::getFinalistVotes($record)),

            ExportColumn::make('deal_breaker_count')
                ->label('Deal-Breaker Reports')
                ->state(fn (CandidateProduct $record): int =>
                    $record->submissions()->where('status', 'submitted')->where('has_deal_breaker', true)->count()
                ),

            // AI Analysis
            ExportColumn::make('ai_analysis_summary')
                ->label('AI Analysis Summary')
                ->state(fn (CandidateProduct $record): string => static::getAISummary($record)),

            // Combined Narrative (from all evaluators)
            ExportColumn::make('evaluator_pros')
                ->label('Evaluator-Noted Strengths')
                ->state(fn (CandidateProduct $record): string => static::getAggregatedNarrative($record, 'strongest_advantages')),

            ExportColumn::make('evaluator_cons')
                ->label('Evaluator-Noted Weaknesses')
                ->state(fn (CandidateProduct $record): string => static::getAggregatedNarrative($record, 'biggest_weaknesses')),

            ExportColumn::make('safety_concerns')
                ->label('Safety Concerns')
                ->state(fn (CandidateProduct $record): string => static::getAggregatedNarrative($record, 'safety_concerns')),

            // Export metadata
            ExportColumn::make('exported_at')
                ->label('Report Generated')
                ->state(fn (): string => now()->format('Y-m-d H:i:s T')),
        ];
    }

    protected static function getAvgScore(CandidateProduct $record, string $field): string
    {
        $avg = $record->submissions()
            ->where('status', 'submitted')
            ->avg($field);
        return $avg !== null ? number_format((float) $avg, 2) : 'N/A';
    }

    protected static function getCategoryRank(CandidateProduct $record): string
    {
        if (!$record->category_id) return 'N/A';

        // Get all products in same category, ordered by avg overall score
        $products = CandidateProduct::where('category_id', $record->category_id)
            ->where('workgroup_session_id', $record->workgroup_session_id)
            ->with(['submissions' => fn($q) => $q->where('status', 'submitted')])
            ->get()
            ->map(fn($p) => [
                'id'  => $p->id,
                'avg' => $p->submissions->avg('overall_score') ?? 0,
            ])
            ->sortByDesc('avg')
            ->values();

        $rank = $products->search(fn($item) => $item['id'] === $record->id);
        return $rank !== false ? '#' . ($rank + 1) . ' of ' . $products->count() : 'N/A';
    }

    protected static function getFinalistVotes(CandidateProduct $record): string
    {
        $total   = $record->submissions()->where('status', 'submitted')->count();
        $advance = $record->submissions()->where('status', 'submitted')->where('advance_recommendation', 'yes')->count();
        $maybe   = $record->submissions()->where('status', 'submitted')->where('advance_recommendation', 'maybe')->count();

        if ($total === 0) return 'N/A';
        return "Yes: {$advance}, Maybe: {$maybe}, No: " . ($total - $advance - $maybe) . " (of {$total} evaluators)";
    }

    protected static function getAISummary(CandidateProduct $record): string
    {
        // Check cache first (set by WorkgroupAIService::analyzeProduct)
        $cacheKey = "workgroup_ai_product_{$record->id}";
        $cached   = Cache::get($cacheKey);

        if ($cached && !empty($cached['analysis'])) {
            // Strip markdown headers for cleaner CSV output
            $text = $cached['analysis'];
            $text = preg_replace('/^#{1,3}\s+/m', '', $text);
            $text = preg_replace('/\*\*(.*?)\*\*/', '$1', $text);
            return substr(trim($text), 0, 2000);
        }

        // If no cached analysis, generate one now (synchronously for export)
        // Only do this if there are submitted evaluations
        $submissionCount = $record->submissions()->where('status', 'submitted')->count();
        if ($submissionCount === 0) {
            return '[No submitted evaluations — AI analysis not available]';
        }

        try {
            $service  = app(WorkgroupAIService::class);
            $result   = $service->analyzeProduct($record);
            $analysis = $result['analysis'] ?? null;

            if (!$analysis) {
                return '[AI analysis unavailable: ' . ($result['error'] ?? 'unknown error') . ']';
            }

            // Strip markdown for cleaner spreadsheet output
            $analysis = preg_replace('/^#{1,3}\s+/m', '', $analysis);
            $analysis = preg_replace('/\*\*(.*?)\*\*/', '$1', $analysis);
            return substr(trim($analysis), 0, 2000);
        } catch (\Exception $e) {
            return '[AI analysis failed: ' . $e->getMessage() . ']';
        }
    }

    protected static function getAggregatedNarrative(CandidateProduct $record, string $field): string
    {
        $submissions = $record->submissions()
            ->where('status', 'submitted')
            ->whereNotNull('narrative_payload')
            ->get();

        $texts = [];
        foreach ($submissions as $sub) {
            $narrative = $sub->narrative_payload ?? [];
            if (!empty($narrative[$field])) {
                $texts[] = trim($narrative[$field]);
            }
        }

        if (empty($texts)) return '';

        // Combine and deduplicate
        return implode(' | ', array_unique($texts));
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'AI Evaluation Report export completed. ' . number_format($export->successful_rows) . ' products exported with AI analysis.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' ' . number_format($failedRowsCount) . ' rows failed to export.';
        }

        return $body;
    }

    /**
     * Get the base query — all products from the active session.
     */
    public static function getBaseQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $session = WorkgroupSession::active()->first();

        if (!$session) {
            return CandidateProduct::query()->whereRaw('1 = 0');
        }

        return CandidateProduct::query()
            ->where('workgroup_session_id', $session->id)
            ->with([
                'category',
                'session',
                'submissions' => fn($q) => $q->where('status', 'submitted'),
            ])
            ->orderBy('category_id')
            ->orderByRaw('(
                SELECT AVG(overall_score) FROM evaluation_submissions 
                WHERE candidate_product_id = candidate_products.id 
                AND status = \'submitted\'
            ) DESC NULLS LAST');
    }
}
