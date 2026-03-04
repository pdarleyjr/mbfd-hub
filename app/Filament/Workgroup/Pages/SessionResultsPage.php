<?php

namespace App\Filament\Workgroup\Pages;

use App\Models\CandidateProduct;
use App\Models\EvaluationSubmission;
use App\Models\WorkgroupMember;
use App\Models\WorkgroupSession;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Anonymous Member Live View - read-only simplified dashboard for members.
 * Displays aggregate scores and anonymous feedback.
 * ALL workgroup members can access this page.
 */
class SessionResultsPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-trophy';
    protected static ?string $title = 'Session Results';
    protected static string $view = 'filament-workgroup.pages.session-results';
    protected static ?string $navigationLabel = 'Results & Analytics';
    protected static ?string $navigationGroup = 'Analytics';
    protected static ?string $slug = 'session-results';
    protected static ?int $navigationSort = 2;

    public function getHeading(): string
    {
        $session = WorkgroupSession::active()->first();
        return $session ? "Results: {$session->name}" : 'Session Results';
    }

    public function getSubheading(): ?string
    {
        return 'Aggregate scores and anonymous feedback from the evaluation session.';
    }

    public function mount(): void
    {
        parent::mount();
        abort_unless(static::canAccess(), 403);
    }

    /**
     * All workgroup members can access results (read-only).
     */
    public static function canAccess(): bool
    {
        $user = Auth::user();
        if (!$user) return false;

        return WorkgroupMember::where('user_id', $user->id)->where('is_active', true)->exists();
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
                    ->where('status', 'submitted')
                    ->whereNotNull('overall_score')
                    ->avg('overall_score');

                $responseCount = EvaluationSubmission::where('candidate_product_id', $product->id)
                    ->where('status', 'submitted')
                    ->count();

                return [
                    'name' => $product->name,
                    'manufacturer' => $product->manufacturer ?? '',
                    'category' => $product->category?->name ?? 'N/A',
                    'avg_score' => $avgScore !== null ? number_format($avgScore, 1) : 'N/A',
                    'response_count' => $responseCount,
                ];
            })
            ->sortByDesc(fn ($p) => is_numeric($p['avg_score']) ? (float)$p['avg_score'] : 0)
            ->values()
            ->toArray();
    }

    public function getAnonymousFeedback(): array
    {
        $session = WorkgroupSession::active()->first();
        if (!$session) return [];

        return EvaluationSubmission::whereHas('candidateProduct', fn($q) => 
                $q->where('workgroup_session_id', $session->id)
            )
            ->where('status', 'submitted')
            ->whereNotNull('narrative_payload')
            ->get()
            ->flatMap(function ($submission) {
                $narratives = $submission->narrative_payload ?? [];
                $feedback = [];
                foreach ($narratives as $key => $text) {
                    if (!empty($text) && is_string($text)) {
                        $feedback[] = [
                            'product' => $submission->candidateProduct?->name ?? 'Unknown',
                            'type' => ucfirst(str_replace('_', ' ', $key)),
                            'text' => $text,
                        ];
                    }
                }
                return $feedback;
            })
            ->shuffle()
            ->take(20)
            ->toArray();
    }
}
