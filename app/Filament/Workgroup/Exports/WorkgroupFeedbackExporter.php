<?php

namespace App\Filament\Workgroup\Exports;

use App\Models\EvaluationComment;
use App\Models\EvaluationSubmission;
use App\Models\WorkgroupSession;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;

/**
 * Exporter for non-rankable feedback summaries - Training, Instructor categories.
 */
class WorkgroupFeedbackExporter extends Exporter
{
    protected static ?string $model = EvaluationComment::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('session.name')
                ->label('Session'),

            ExportColumn::make('submission.candidateProduct.category.name')
                ->label('Category'),

            ExportColumn::make('submission.candidateProduct.name')
                ->label('Product'),

            ExportColumn::make('submission.candidateProduct.manufacturer')
                ->label('Manufacturer'),

            ExportColumn::make('submission.candidateProduct.model')
                ->label('Model'),

            ExportColumn::make('submission.member.user.name')
                ->label('Evaluator'),

            ExportColumn::make('comment')
                ->label('Feedback/Comment'),

            ExportColumn::make('created_at')
                ->label('Created At')
                ->formatStateUsing(fn ($state) => $state ? $state->format('Y-m-d H:i:s') : 'N/A'),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Feedback export completed. ' . number_format($export->successful_rows) . ' feedback entries exported.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' ' . number_format($failedRowsCount) . ' rows failed to export.';
        }

        return $body;
    }

    /**
     * Get query for all feedback comments in non-rankable categories.
     */
    public static function getBaseQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $session = WorkgroupSession::active()->first();
        
        if (!$session) {
            return EvaluationComment::query()->whereRaw('1 = 0');
        }

        return EvaluationComment::query()
            ->whereHas('submission', fn($query) => 
                $query->where('status', 'submitted')
                    ->whereHas('candidateProduct', fn($q) => 
                        $q->where('workgroup_session_id', $session->id)
                            ->whereHas('category', fn($cq) => 
                                $cq->where('is_rankable', false)
                            )
                    )
            )
            ->with([
                'submission.candidateProduct.category',
                'submission.candidateProduct.session',
                'submission.member.user',
            ]);
    }
}
