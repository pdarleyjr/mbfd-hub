{{-- Admin Data Hub View --}}
<x-filament-panels::page>
    <div class="space-y-6">
        {{-- AI Summary Widget --}}
        @livewire(\App\Filament\Workgroup\Widgets\AiSummaryWidget::class)

        {{-- Stats Overview --}}
        @livewire(\App\Filament\Workgroup\Widgets\WorkgroupAdminStatsWidget::class)

        {{-- Product Score Chart --}}
        @livewire(\App\Filament\Workgroup\Widgets\ProductScoreChartWidget::class)

        {{-- Evaluator Tracking --}}
        @livewire(\App\Filament\Workgroup\Widgets\EvaluatorTrackingWidget::class)

        {{-- Session Progress --}}
        @livewire(\App\Filament\Workgroup\Widgets\SessionProgressWidget::class)

        {{-- Category Rankings --}}
        @livewire(\App\Filament\Workgroup\Widgets\CategoryRankingsWidget::class)

        {{-- Finalists --}}
        @livewire(\App\Filament\Workgroup\Widgets\FinalistsWidget::class)

        {{-- Non-Rankable Feedback --}}
        @livewire(\App\Filament\Workgroup\Widgets\NonRankableFeedbackWidget::class)
    </div>
</x-filament-panels::page>
