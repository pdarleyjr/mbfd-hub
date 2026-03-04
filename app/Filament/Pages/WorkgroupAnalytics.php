<?php

namespace App\Filament\Pages;

use App\Models\CandidateProduct;
use App\Models\EvaluationSubmission;
use App\Models\WorkgroupSession;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Http;

/**
 * Workgroup Analytics page for the Admin (Logistics) panel.
 * Shows evaluation results, product rankings, and AI summaries.
 */
class WorkgroupAnalytics extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-presentation-chart-bar';
    protected static ?string $title = 'Workgroup Analytics';
    protected static string $view = 'filament.pages.workgroup-analytics';
    protected static ?string $navigationLabel = 'Workgroup Analytics';
    protected static ?string $navigationGroup = 'Workgroup Management';
    protected static ?int $navigationSort = 1;
    protected static ?string $slug = 'workgroup-analytics';

    public static function canAccess(): bool
    {
        return auth()->user()->hasAnyRole(['super_admin', 'admin', 'logistics_admin']);
    }

    public function getProductScores(): array
    {
        $session = WorkgroupSession::active()->first();
        if (!$session) return [];

        return CandidateProduct::where('workgroup_session_id', $session->id)
            ->whereHas('category', fn($q) => $q->where('is_rankable', true))
            ->with('category')
            ->get()
            ->map(function ($product) {
                $avgScore = EvaluationSubmission::where('candidate_product_id', $product->id)
                    ->where('status', 'submitted')->whereNotNull('overall_score')->avg('overall_score');
                $responseCount = EvaluationSubmission::where('candidate_product_id', $product->id)
                    ->where('status', 'submitted')->count();
                return [
                    'name' => $product->name,
                    'manufacturer' => $product->manufacturer ?? '',
                    'category' => $product->category?->name ?? 'N/A',
                    'avg_score' => $avgScore !== null ? number_format($avgScore, 1) : 'N/A',
                    'response_count' => $responseCount,
                ];
            })
            ->sortByDesc(fn ($p) => is_numeric($p['avg_score']) ? (float)$p['avg_score'] : 0)
            ->values()->toArray();
    }

    public function getSessionInfo(): ?array
    {
        $session = WorkgroupSession::active()->with('workgroup')->first();
        if (!$session) return null;
        $totalSubmissions = EvaluationSubmission::whereHas('candidateProduct', fn($q) => $q->where('workgroup_session_id', $session->id))->count();
        $submittedCount = EvaluationSubmission::whereHas('candidateProduct', fn($q) => $q->where('workgroup_session_id', $session->id))->where('status', 'submitted')->count();
        return [
            'name' => $session->name,
            'workgroup' => $session->workgroup?->name ?? 'N/A',
            'status' => $session->status,
            'total_submissions' => $totalSubmissions,
            'submitted' => $submittedCount,
            'products' => $session->candidateProducts()->count(),
        ];
    }

    public function getAiSummary(): ?string
    {
        $workerUrl = config('services.workgroup_ai.url');
        if (!$workerUrl) return null;
        try {
            $session = WorkgroupSession::active()->first();
            $response = Http::timeout(5)->get($workerUrl . '/summary', ['session_id' => $session?->id]);
            return $response->successful() ? $response->json('summary') : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
