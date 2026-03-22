<?php

namespace App\Filament\Workgroup\Exports;

use App\Models\CandidateProduct;
use App\Models\EvaluationCategory;
use App\Models\EvaluationScore;
use App\Models\EvaluationSubmission;
use App\Models\WorkgroupSession;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;

/**
 * Exporter for finalists - top 2 products per rankable category.
 */
class WorkgroupFinalistsExporter extends Exporter
{
    protected static ?string $model = CandidateProduct::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('rank')
                ->label('Rank'),

            ExportColumn::make('position')
                ->label('Position')
                ->state(fn (CandidateProduct $record): string => $this->getPosition($record)),

            ExportColumn::make('category.name')
                ->label('Category'),

            ExportColumn::make('name')
                ->label('Product Name'),

            ExportColumn::make('manufacturer')
                ->label('Manufacturer'),

            ExportColumn::make('model')
                ->label('Model'),

            ExportColumn::make('weighted_score')
                ->label('Weighted Score')
                ->state(fn (CandidateProduct $record): float => $this->calculateWeightedScore($record)),

            ExportColumn::make('response_count')
                ->label('Response Count')
                ->state(fn (CandidateProduct $record): int => $this->getResponseCount($record)),

            ExportColumn::make('session.name')
                ->label('Session'),
        ];
    }

    protected function getPosition(CandidateProduct $record): string
    {
        $session = WorkgroupSession::active()->first();
        
        if (!$session) {
            return 'N/A';
        }

        $products = CandidateProduct::where('workgroup_session_id', $session->id)
            ->where('category_id', $record->category_id)
            ->get()
            ->map(function ($product) {
                $avgScore = EvaluationScore::whereHas('submission', fn($q) => 
                    $q->where('candidate_product_id', $product->id)
                        ->where('status', 'submitted')
                )
                ->join('evaluation_criteria', 'evaluation_scores.criterion_id', '=', 'evaluation_criteria.id')
                ->selectRaw('COALESCE(AVG(evaluation_scores.score * evaluation_criteria.weight), 0) as weighted_avg')
                ->value('weighted_avg');

                return [
                    'id' => $product->id,
                    'score' => $avgScore ?? 0,
                ];
            })
            ->sortByDesc('score')
            ->values();

        $position = $products->search(fn($p) => $p['id'] === $record->id) + 1;

        return match ($position) {
            1 => 'Gold Medalist',
            2 => 'Silver Medalist',
            default => "Rank {$position}",
        };
    }

    protected function calculateWeightedScore(CandidateProduct $record): float
    {
        $avgScore = EvaluationScore::whereHas('submission', fn($q) => 
            $q->where('candidate_product_id', $record->id)
                ->where('status', 'submitted')
        )
        ->join('evaluation_criteria', 'evaluation_scores.criterion_id', '=', 'evaluation_criteria.id')
        ->selectRaw('COALESCE(AVG(evaluation_scores.score * evaluation_criteria.weight), 0) as weighted_avg')
        ->value('weighted_avg');

        return round($avgScore ?? 0, 2);
    }

    protected function getResponseCount(CandidateProduct $record): int
    {
        return EvaluationSubmission::where('candidate_product_id', $record->id)
            ->where('status', 'submitted')
            ->count();
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Finalists export completed. ' . number_format($export->successful_rows) . ' finalists exported.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' ' . number_format($failedRowsCount) . ' rows failed to export.';
        }

        return $body;
    }

    /**
     * Get query for top 2 products per rankable category.
     */
    public static function getBaseQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $session = WorkgroupSession::active()->first();
        
        if (!$session) {
            return CandidateProduct::query()->whereRaw('1 = 0');
        }

        return CandidateProduct::query()
            ->where('workgroup_session_id', $session->id)
            ->whereHas('category', fn($q) => 
                $q->where('is_rankable', true)
            )
            ->with(['category', 'session']);
    }
}
