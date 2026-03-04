<?php

namespace App\Filament\Workgroup\Widgets;

use App\Models\CandidateProduct;
use App\Models\EvaluationSubmission;
use App\Models\WorkgroupSession;
use Filament\Widgets\ChartWidget;

/**
 * Bar chart comparing top products by average score.
 * Only aggregates scores from official evaluators.
 */
class ProductScoreChartWidget extends ChartWidget
{
    protected static ?string $heading = 'Product Score Comparison';
    protected static ?string $pollingInterval = '30s';
    protected int | string | array $columnSpan = 'full';

    protected function getData(): array
    {
        $session = WorkgroupSession::active()->first();
        if (!$session) {
            return ['datasets' => [], 'labels' => []];
        }

        $products = CandidateProduct::where('workgroup_session_id', $session->id)
            ->whereHas('category', fn($q) => $q->where('is_rankable', true))
            ->get();

        $labels = [];
        $scores = [];
        $colors = [];

        foreach ($products as $product) {
            $avgScore = EvaluationSubmission::where('candidate_product_id', $product->id)
                ->where('status', 'submitted')
                ->whereNotNull('overall_score')
                ->whereHas('user', function($q) use ($session) {
                    $q->whereExists(function($sub) use ($session) {
                        $sub->select(\Illuminate\Support\Facades\DB::raw(1))
                            ->from('session_user')
                            ->whereColumn('session_user.user_id', 'users.id')
                            ->where('session_user.workgroup_session_id', $session->id)
                            ->where('session_user.is_official_evaluator', true);
                    });
                })
                ->avg('overall_score');

            if ($avgScore !== null) {
                $labels[] = $product->name;
                $scores[] = round($avgScore, 2);
                $colors[] = $avgScore >= 80 ? 'rgba(34, 197, 94, 0.8)' : ($avgScore >= 60 ? 'rgba(234, 179, 8, 0.8)' : 'rgba(239, 68, 68, 0.8)');
            }
        }

        // Sort by score descending
        array_multisort($scores, SORT_DESC, $labels, $colors);

        return [
            'datasets' => [
                [
                    'label' => 'Average Score (Official Evaluators)',
                    'data' => $scores,
                    'backgroundColor' => $colors,
                    'borderColor' => $colors,
                    'borderWidth' => 1,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getOptions(): array
    {
        return [
            'scales' => [
                'y' => [
                    'beginAtZero' => true,
                    'max' => 100,
                    'title' => ['display' => true, 'text' => 'Score'],
                ],
                'x' => [
                    'title' => ['display' => true, 'text' => 'Product'],
                ],
            ],
            'plugins' => [
                'legend' => ['display' => true],
            ],
        ];
    }
}
