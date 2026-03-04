<?php

namespace App\Filament\Workgroup\Widgets;

use App\Models\EvaluationSubmission;
use App\Models\WorkgroupSession;
use Filament\Widgets\Widget;

/**
 * AI-generated summary action card for the Admin Data Hub.
 * Displays a live summary of evaluation state.
 * Streams from the Workgroup AI Worker when available,
 * falls back to a local statistical summary.
 */
class AiSummaryWidget extends Widget
{
    protected static string $view = 'filament-workgroup.widgets.ai-summary-widget';
    protected int | string | array $columnSpan = 'full';
    protected static ?int $sort = -1;

    public function getAiSummary(): array
    {
        $session = WorkgroupSession::active()->first();
        if (!$session) {
            return [
                'status' => 'no_session',
                'summary' => 'No active evaluation session. Create and activate a session to see AI-generated insights.',
                'stats' => [],
            ];
        }

        $totalSubmissions = EvaluationSubmission::where('session_id', $session->id)->count();
        $submittedCount = EvaluationSubmission::where('session_id', $session->id)->where('status', 'submitted')->count();
        $draftCount = EvaluationSubmission::where('session_id', $session->id)->where('status', 'draft')->count();
        $avgScore = EvaluationSubmission::where('session_id', $session->id)
            ->where('status', 'submitted')
            ->whereNotNull('overall_score')
            ->avg('overall_score');
        $topProduct = EvaluationSubmission::where('session_id', $session->id)
            ->where('status', 'submitted')
            ->whereNotNull('overall_score')
            ->selectRaw('candidate_product_id, AVG(overall_score) as avg_score')
            ->groupBy('candidate_product_id')
            ->orderByDesc('avg_score')
            ->with('candidateProduct')
            ->first();

        $lockedCount = EvaluationSubmission::where('session_id', $session->id)->where('is_locked', true)->count();

        $summary = "Session: {$session->name}. ";
        $summary .= "{$submittedCount} submitted, {$draftCount} drafts, {$lockedCount} locked. ";
        if ($avgScore) {
            $summary .= "Average score: " . number_format($avgScore, 1) . "/100. ";
        }
        if ($topProduct && $topProduct->candidateProduct) {
            $summary .= "Leading product: {$topProduct->candidateProduct->name} (" . number_format($topProduct->avg_score, 1) . "). ";
        }

        // Try to fetch AI summary from Worker (graceful fallback)
        $aiText = null;
        try {
            $workerUrl = config('services.workgroup_ai.url');
            if ($workerUrl) {
                $response = \Illuminate\Support\Facades\Http::timeout(5)
                    ->get($workerUrl . '/summary', ['session_id' => $session->id]);
                if ($response->successful()) {
                    $aiText = $response->json('summary');
                }
            }
        } catch (\Throwable $e) {
            // Silently fall back to local summary
        }

        return [
            'status' => 'active',
            'session_name' => $session->name,
            'summary' => $aiText ?? $summary,
            'is_ai' => $aiText !== null,
            'stats' => [
                'submitted' => $submittedCount,
                'drafts' => $draftCount,
                'locked' => $lockedCount,
                'avg_score' => $avgScore ? number_format($avgScore, 1) : 'N/A',
                'top_product' => $topProduct?->candidateProduct?->name ?? 'N/A',
            ],
        ];
    }
}
