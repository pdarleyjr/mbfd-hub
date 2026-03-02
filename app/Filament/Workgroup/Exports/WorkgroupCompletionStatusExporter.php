<?php

namespace App\Filament\Workgroup\Exports;

use App\Models\CandidateProduct;
use App\Models\EvaluationSubmission;
use App\Models\WorkgroupMember;
use App\Models\WorkgroupSession;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;

/**
 * Exporter for per-user completion status - who has completed what evaluations.
 */
class WorkgroupCompletionStatusExporter extends Exporter
{
    protected static ?string $model = WorkgroupMember::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('user.name')
                ->label('Evaluator Name'),

            ExportColumn::make('user.email')
                ->label('Email'),

            ExportColumn::make('role')
                ->label('Role'),

            ExportColumn::make('workgroup.name')
                ->label('Workgroup'),

            ExportColumn::make('completed_count')
                ->label('Completed')
                ->state(fn (WorkgroupMember $record): int => $this->getCompletedCount($record)),

            ExportColumn::make('pending_count')
                ->label('Pending')
                ->state(fn (WorkgroupMember $record): int => $this->getPendingCount($record)),

            ExportColumn::make('total_products')
                ->label('Total Products')
                ->state(fn (WorkgroupMember $record): int => $this->getTotalProducts($record)),

            ExportColumn::make('completion_percentage')
                ->label('Completion %')
                ->state(fn (WorkgroupMember $record): string => $this->getCompletionPercentage($record)),

            ExportColumn::make('status')
                ->label('Status')
                ->state(fn (WorkgroupMember $record): string => $this->getStatus($record)),

            ExportColumn::make('evaluated_products')
                ->label('Evaluated Products')
                ->state(fn (WorkgroupMember $record): string => $this->getEvaluatedProducts($record)),
        ];
    }

    protected function getCompletedCount(WorkgroupMember $record): int
    {
        $session = WorkgroupSession::active()->first();
        
        if (!$session) {
            return 0;
        }

        return EvaluationSubmission::where('workgroup_member_id', $record->id)
            ->whereHas('candidateProduct', fn($q) => 
                $q->where('workgroup_session_id', $session->id)
            )
            ->where('status', 'submitted')
            ->count();
    }

    protected function getPendingCount(WorkgroupMember $record): int
    {
        $session = WorkgroupSession::active()->first();
        
        if (!$session) {
            return 0;
        }

        $totalProducts = $session->candidateProducts()->count();
        $completedCount = $this->getCompletedCount($record);

        return max(0, $totalProducts - $completedCount);
    }

    protected function getTotalProducts(WorkgroupMember $record): int
    {
        $session = WorkgroupSession::active()->first();
        
        if (!$session) {
            return 0;
        }

        return $session->candidateProducts()->count();
    }

    protected function getCompletionPercentage(WorkgroupMember $record): string
    {
        $total = $this->getTotalProducts($record);
        
        if ($total === 0) {
            return '0%';
        }

        $completed = $this->getCompletedCount($record);
        $percentage = round(($completed / $total) * 100, 1);

        return "{$percentage}%";
    }

    protected function getStatus(WorkgroupMember $record): string
    {
        $total = $this->getTotalProducts($record);
        
        if ($total === 0) {
            return 'No Products';
        }

        $completed = $this->getCompletedCount($record);

        if ($completed === 0) {
            return 'Not Started';
        } elseif ($completed < $total) {
            return 'In Progress';
        } else {
            return 'Complete';
        }
    }

    protected function getEvaluatedProducts(WorkgroupMember $record): string
    {
        $session = WorkgroupSession::active()->first();
        
        if (!$session) {
            return '';
        }

        $products = EvaluationSubmission::where('workgroup_member_id', $record->id)
            ->whereHas('candidateProduct', fn($q) => 
                $q->where('workgroup_session_id', $session->id)
            )
            ->where('status', 'submitted')
            ->with('candidateProduct')
            ->get()
            ->pluck('candidateProduct.name')
            ->implode(', ');

        return $products ?: 'None';
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Completion status export completed. ' . number_format($export->successful_rows) . ' member records exported.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' ' . number_format($failedRowsCount) . ' rows failed to export.';
        }

        return $body;
    }

    /**
     * Get query for all active members.
     */
    public static function getBaseQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return WorkgroupMember::query()
            ->where('is_active', true)
            ->with(['user', 'workgroup']);
    }
}
