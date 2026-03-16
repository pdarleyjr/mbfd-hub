<?php

namespace App\Filament\Exports;

use App\Models\EvaluationSubmission;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;

class EvaluationSubmissionExporter extends Exporter
{
    protected static ?string $model = EvaluationSubmission::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('id')
                ->label('Submission ID'),
            ExportColumn::make('member.user.name')
                ->label('Evaluator Name'),
            ExportColumn::make('candidateProduct.name')
                ->label('Product Name'),
            ExportColumn::make('candidateProduct.manufacturer')
                ->label('Manufacturer'),
            ExportColumn::make('candidateProduct.model')
                ->label('Model'),
            ExportColumn::make('candidateProduct.category.name')
                ->label('Category'),
            ExportColumn::make('candidateProduct.session.name')
                ->label('Session'),
            ExportColumn::make('status')
                ->label('Status'),
            ExportColumn::make('overall_score')
                ->label('Overall Score (%)'),
            ExportColumn::make('capability_score')
                ->label('Capability (S)'),
            ExportColumn::make('usability_score')
                ->label('Usability (A)'),
            ExportColumn::make('affordability_score')
                ->label('Affordability (V)'),
            ExportColumn::make('maintainability_score')
                ->label('Maintainability (E)'),
            ExportColumn::make('deployability_score')
                ->label('Deployability (R)'),
            ExportColumn::make('advance_recommendation')
                ->label('Advance Recommendation'),
            ExportColumn::make('confidence_level')
                ->label('Confidence Level'),
            ExportColumn::make('has_deal_breaker')
                ->label('Has Deal Breaker')
                ->state(fn (EvaluationSubmission $record): string => $record->has_deal_breaker ? 'Yes' : 'No'),
            ExportColumn::make('deal_breaker_note')
                ->label('Deal Breaker Note'),
            ExportColumn::make('rubric_version')
                ->label('Rubric Version'),
            ExportColumn::make('narrative_payload')
                ->label('Narrative / Comments')
                ->state(function (EvaluationSubmission $record): string {
                    $narrative = $record->narrative_payload;
                    if (empty($narrative)) {
                        return '';
                    }
                    // Flatten narrative payload into readable text
                    $parts = [];
                    foreach ($narrative as $key => $value) {
                        if (is_string($value) && trim($value) !== '') {
                            $parts[] = ucfirst(str_replace('_', ' ', $key)) . ': ' . $value;
                        }
                    }
                    return implode(' | ', $parts);
                }),
            ExportColumn::make('submitted_at')
                ->label('Submitted At'),
            ExportColumn::make('created_at')
                ->label('Created At'),
        ];
    }

    public static function modifyQuery(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('status', 'submitted');
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Your evaluation submissions export has completed and ' . number_format($export->successful_rows) . ' ' . str('row')->plural($export->successful_rows) . ' exported.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' ' . number_format($failedRowsCount) . ' ' . str('row')->plural($failedRowsCount) . ' failed to export.';
        }

        return $body;
    }
}
