<?php

namespace App\Filament\Workgroup\Exports;

use App\Models\CandidateProduct;
use App\Models\EvaluationScore;
use App\Models\EvaluationSubmission;
use App\Models\WorkgroupSession;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Support\Facades\DB;

/**
 * Exporter for full scoring details - all scores per product per evaluator.
 * Updated to include SAVER rubric category scores and decision metadata.
 */
class WorkgroupScoresExporter extends Exporter
{
    protected static ?string $model = EvaluationSubmission::class;

    public static function getColumns(): array
    {
        return [
            // Session & Product Info
            ExportColumn::make('session.name')
                ->label('Session'),

            ExportColumn::make('candidateProduct.category.name')
                ->label('Category'),

            ExportColumn::make('candidateProduct.name')
                ->label('Product Name'),

            ExportColumn::make('candidateProduct.manufacturer')
                ->label('Manufacturer'),

            ExportColumn::make('candidateProduct.model')
                ->label('Model'),

            // Evaluator Info
            ExportColumn::make('member.user.name')
                ->label('Evaluator'),

            ExportColumn::make('member.role')
                ->label('Evaluator Role'),

            ExportColumn::make('status')
                ->label('Status'),

            // Overall Score (supports both legacy and rubric)
            ExportColumn::make('total_score')
                ->label('Overall Score')
                ->state(fn (EvaluationSubmission $record): ?float => $record->total_score),

            // SAVER Category Scores (new rubric)
            ExportColumn::make('overall_score')
                ->label('Overall Weighted Score')
                ->state(fn (EvaluationSubmission $record): ?float => $record->overall_score),

            ExportColumn::make('capability_score')
                ->label('Capability Score')
                ->state(fn (EvaluationSubmission $record): ?float => $record->capability_score),

            ExportColumn::make('usability_score')
                ->label('Usability Score')
                ->state(fn (EvaluationSubmission $record): ?float => $record->usability_score),

            ExportColumn::make('affordability_score')
                ->label('Affordability Score')
                ->state(fn (EvaluationSubmission $record): ?float => $record->affordability_score),

            ExportColumn::make('maintainability_score')
                ->label('Maintainability Score')
                ->state(fn (EvaluationSubmission $record): ?float => $record->maintainability_score),

            ExportColumn::make('deployability_score')
                ->label('Deployability Score')
                ->state(fn (EvaluationSubmission $record): ?float => $record->deployability_score),

            // Decision Metadata
            ExportColumn::make('advance_recommendation')
                ->label('Recommendation')
                ->state(fn (EvaluationSubmission $record): string => $record->recommendation_label ?? 'N/A'),

            ExportColumn::make('confidence_level')
                ->label('Confidence')
                ->state(fn (EvaluationSubmission $record): string => $record->confidence_label ?? 'N/A'),

            ExportColumn::make('has_deal_breaker')
                ->label('Has Deal-Breaker')
                ->state(fn (EvaluationSubmission $record): string => $record->has_deal_breaker ? 'Yes' : 'No'),

            ExportColumn::make('deal_breaker_note')
                ->label('Deal-Breaker Note'),

            // Rubric Info
            ExportColumn::make('rubric_version')
                ->label('Rubric Version'),

            ExportColumn::make('assessment_profile')
                ->label('Assessment Profile'),

            // Criterion Scores (legacy or new format)
            ExportColumn::make('criterion_scores')
                ->label('Criterion Scores')
                ->state(fn (EvaluationSubmission $record): string => $this->formatCriterionScores($record)),

            // Narrative Summary
            ExportColumn::make('narrative_summary')
                ->label('Narrative Summary')
                ->state(fn (EvaluationSubmission $record): string => $this->formatNarrative($record)),

            // Timestamps
            ExportColumn::make('submitted_at')
                ->label('Submitted At')
                ->formatStateUsing(fn ($state) => $state ? $state->format('Y-m-d H:i:s') : 'N/A'),
        ];
    }

    protected static function formatCriterionScores(EvaluationSubmission $record): string
    {
        // First try new rubric format
        if (!empty($record->criterion_payload['ratings'])) {
            $ratings = $record->criterion_payload['ratings'];
            $parts = [];
            foreach ($ratings as $criterionId => $rating) {
                if ($rating !== '' && $rating !== null) {
                    $parts[] = "{$criterionId}: {$rating}";
                }
            }
            return implode(' | ', $parts);
        }

        // Fallback to legacy format
        $scores = EvaluationScore::where('submission_id', $record->id)
            ->with('criterion')
            ->get();

        if ($scores->isEmpty()) {
            return 'No scores';
        }

        return $scores->map(fn($score) => 
            "{$score->criterion->name}: {$score->score}/{$score->criterion->max_score}"
        )->join(' | ');
    }

    protected static function formatNarrative(EvaluationSubmission $record): string
    {
        $narrative = $record->narrative_payload ?? [];
        
        if (empty($narrative)) {
            return '';
        }

        $parts = [];
        
        if (!empty($narrative['strongest_advantages'])) {
            $parts[] = "Pros: " . substr($narrative['strongest_advantages'], 0, 200);
        }
        
        if (!empty($narrative['biggest_weaknesses'])) {
            $parts[] = "Cons: " . substr($narrative['biggest_weaknesses'], 0, 200);
        }
        
        if (!empty($narrative['safety_concerns'])) {
            $parts[] = "Safety: " . substr($narrative['safety_concerns'], 0, 200);
        }

        return implode("\n", $parts);
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Scoring export completed. ' . number_format($export->successful_rows) . ' submissions exported.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' ' . number_format($failedRowsCount) . ' rows failed to export.';
        }

        return $body;
    }

    /**
     * Get query for all submissions with scores.
     */
    public static function getBaseQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $session = WorkgroupSession::active()->first();
        
        if (!$session) {
            return EvaluationSubmission::query()->whereRaw('1 = 0');
        }

        return EvaluationSubmission::query()
            ->whereHas('candidateProduct', fn($q) => 
                $q->where('workgroup_session_id', $session->id)
            )
            ->with([
                'candidateProduct.category',
                'candidateProduct.session',
                'member.user',
                'scores.criterion',
            ]);
    }
}
