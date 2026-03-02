<?php

namespace App\Filament\Workgroup\Exports;

use App\Models\CandidateProduct;
use App\Models\EvaluationScore;
use App\Models\EvaluationSubmission;
use App\Models\WorkgroupSession;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;

/**
 * Exporter for full scoring details - all scores per product per evaluator.
 */
class WorkgroupScoresExporter extends Exporter
{
    protected static ?string $model = EvaluationSubmission::class;

    public static function getColumns(): array
    {
        return [
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

            ExportColumn::make('member.user.name')
                ->label('Evaluator'),

            ExportColumn::make('member.role')
                ->label('Evaluator Role'),

            ExportColumn::make('status')
                ->label('Status'),

            ExportColumn::make('total_score')
                ->label('Total Weighted Score')
                ->state(fn (EvaluationSubmission $record): ?float => $record->total_score),

            ExportColumn::make('submitted_at')
                ->label('Submitted At')
                ->formatStateUsing(fn ($state) => $state ? $state->format('Y-m-d H:i:s') : 'N/A'),

            ExportColumn::make('criterion_scores')
                ->label('Criterion Scores')
                ->state(fn (EvaluationSubmission $record): string => $this->formatCriterionScores($record)),
        ];
    }

    protected function formatCriterionScores(EvaluationSubmission $record): string
    {
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
