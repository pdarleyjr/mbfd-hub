<?php

namespace App\Services\Workgroup;

use App\Models\CandidateProduct;
use App\Models\EvaluationCategory;
use App\Models\EvaluationScore;
use App\Models\EvaluationSubmission;
use App\Models\WorkgroupMember;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EvaluationService
{
    protected int $minimumResponseThreshold = 3;

    /**
     * Base scope for submissions that should count toward results.
     * Excludes members where count_evaluations = false.
     */
    protected function countableSubmissions(): \Illuminate\Database\Eloquent\Builder
    {
        return EvaluationSubmission::whereHas('member', function ($q) {
            $q->where('count_evaluations', true);
        });
    }

    public function setMinimumResponseThreshold(int $threshold): self
    {
        $this->minimumResponseThreshold = $threshold;
        return $this;
    }

    public function getMinimumResponseThreshold(): int
    {
        return $this->minimumResponseThreshold;
    }

    public function calculateWeightedAverage(EvaluationSubmission $submission): ?float
    {
        $scores = $submission->scores()->with('criterion')->get();
        
        if ($scores->isEmpty()) {
            return null;
        }

        $totalScore = 0;
        $totalWeight = 0;

        foreach ($scores as $score) {
            if ($score->score !== null && $score->criterion) {
                $totalScore += $score->score * $score->criterion->weight;
                $totalWeight += $score->criterion->weight;
            }
        }

        return $totalWeight > 0 ? round($totalScore / $totalWeight, 2) : null;
    }

    public function calculateWeightedAverageById(int $submissionId): ?float
    {
        $submission = EvaluationSubmission::with('scores.criterion')->find($submissionId);
        return $submission ? $this->calculateWeightedAverage($submission) : null;
    }

    public function getCategoryRankings(int $categoryId, ?int $sessionId = null): Collection
    {
        $query = CandidateProduct::where('category_id', $categoryId)
            ->with(['category', 'submissions' => function ($q) use ($sessionId) {
                $q->where('status', 'submitted')
                  ->whereHas('member', fn($mq) => $mq->where('count_evaluations', true));
                if ($sessionId) {
                    $q->whereHas('candidateProduct', fn($sq) => $sq->where('workgroup_session_id', $sessionId));
                }
            }]);

        if ($sessionId) {
            $query->where('workgroup_session_id', $sessionId);
        }

        $products = $query->get();

        $rankings = $products->map(function ($product) {
            $submittedSubmissions = $product->submissions->filter(fn($s) => $s->status === 'submitted');
            $responseCount = $submittedSubmissions->count();
            
            // Use pre-calculated rubric overall_score instead of legacy EvaluationScore relationship
            $scores = $submittedSubmissions->map(fn($s) => $s->overall_score)->filter(fn($s) => $s !== null);
            
            if ($scores->isEmpty()) {
                return [
                    'product' => $product,
                    'weighted_average' => null,
                    'response_count' => 0,
                    'meets_threshold' => false,
                ];
            }

            $weightedAverage = round($scores->avg(), 2);

            return [
                'product' => $product,
                'weighted_average' => $weightedAverage,
                'response_count' => $responseCount,
                'meets_threshold' => $responseCount >= $this->minimumResponseThreshold,
            ];
        });

        return $rankings->sortByDesc(function ($item) {
            return [$item['weighted_average'] ?? -1, $item['response_count']];
        })->values();
    }

    public function getTopProducts(int $categoryId, ?int $sessionId = null, int $limit = 2): Collection
    {
        $rankings = $this->getCategoryRankings($categoryId, $sessionId);
        $eligibleProducts = $rankings->filter(fn($item) => $item['meets_threshold']);
        return $eligibleProducts->take($limit);
    }

    public function getFinalists(?int $sessionId = null): Collection
    {
        $rankableCategories = EvaluationCategory::rankable()->active()->get();
        $finalists = collect();

        foreach ($rankableCategories as $category) {
            $topProducts = $this->getTopProducts($category->id, $sessionId, 2);
            
            foreach ($topProducts as $index => $item) {
                $finalists->push([
                    'category' => $category,
                    'category_name' => $category->name,
                    'rank' => $index + 1,
                    'product' => $item['product'],
                    'weighted_average' => $item['weighted_average'],
                    'response_count' => $item['response_count'],
                ]);
            }
        }

        return $finalists;
    }

    public function getNonRankableFeedback(?int $sessionId = null): Collection
    {
        $nonRankableCategories = EvaluationCategory::where('is_rankable', false)->active()->get();
        $feedback = collect();

        foreach ($nonRankableCategories as $category) {
            $submissions = $this->countableSubmissions()
                ->whereHas('candidateProduct', function ($q) use ($category, $sessionId) {
                    $q->where('category_id', $category->id);
                    if ($sessionId) {
                        $q->where('workgroup_session_id', $sessionId);
                    }
                })->where('status', 'submitted')->with(['member.user', 'candidateProduct', 'comments'])->get();

            if ($submissions->isNotEmpty()) {
                $categoryFeedback = $submissions->map(function ($submission) use ($category) {
                    return [
                        'submission_id' => $submission->id,
                        'evaluator' => $submission->member?->user?->name,
                        'product' => $submission->candidateProduct->display_name ?? $submission->candidateProduct->name,
                        // Use pre-calculated rubric overall_score instead of legacy total_score
                        'score' => $submission->overall_score,
                        'comments' => $submission->comments->pluck('comment', 'category_id')->toArray(),
                        'submitted_at' => $submission->submitted_at,
                    ];
                });

                $feedback->push([
                    'category' => $category,
                    'category_name' => $category->name,
                    'is_rankable' => false,
                    'submissions_count' => $submissions->count(),
                    'feedback' => $categoryFeedback,
                ]);
            }
        }

        return $feedback;
    }

    public function getSessionResults(?int $sessionId = null): array
    {
        $rankableCategories = EvaluationCategory::rankable()->active()->get();
        $categoryRankings = [];

        foreach ($rankableCategories as $category) {
            $rankings = $this->getCategoryRankings($category->id, $sessionId);
            $topProducts = $rankings->filter(fn($item) => $item['meets_threshold'])->take(2);

            $categoryRankings[] = [
                'category' => $category,
                'category_name' => $category->name,
                'is_rankable' => true,
                'rankings' => $rankings,
                'top_products' => $topProducts->values(),
                'total_products' => $rankings->count(),
                'eligible_products' => $rankings->filter(fn($item) => $item['meets_threshold'])->count(),
            ];
        }

        $nonRankableFeedback = $this->getNonRankableFeedback($sessionId);

        return [
            'session_id' => $sessionId,
            'rankable_categories' => $categoryRankings,
            'non_rankable_feedback' => $nonRankableFeedback,
            'minimum_threshold' => $this->minimumResponseThreshold,
            'generated_at' => now(),
        ];
    }

    public function getSessionProgress(?int $sessionId = null): array
    {
        $query = CandidateProduct::query();
        if ($sessionId) {
            $query->where('workgroup_session_id', $sessionId);
        }
        
        $totalProducts = $query->count();

        // When a specific session is requested, count only the members who
        // attended that session (per the attendance pivot table) and also
        // have count_evaluations=true.
        // If no sessionId is provided (global view), fall back to all active
        // countable members.
        if ($sessionId) {
            $totalMembers = WorkgroupMember::where('is_active', true)
                ->where('count_evaluations', true)
                ->whereHas('sessionsAttended', fn($q) =>
                    $q->where('workgroup_sessions.id', $sessionId)
                )
                ->count();

            // If attendance table is empty for this session (e.g. admin hasn't
            // configured it yet), fall back gracefully to all countable members
            // so the display isn't broken.
            if ($totalMembers === 0) {
                $totalMembers = WorkgroupMember::where('is_active', true)
                    ->where('count_evaluations', true)
                    ->count();
            }
        } else {
            $totalMembers = WorkgroupMember::where('is_active', true)->where('count_evaluations', true)->count();
        }

        $maxSubmissions = $totalProducts * $totalMembers;

        $baseQuery = function () use ($sessionId) {
            return $this->countableSubmissions()->whereHas('candidateProduct', function ($q) use ($sessionId) {
                if ($sessionId) {
                    $q->where('workgroup_session_id', $sessionId);
                }
            });
        };

        $totalSubmissions = $baseQuery()->count();
        $draftSubmissions = $baseQuery()->where('status', 'draft')->count();
        $submittedSubmissions = $baseQuery()->where('status', 'submitted')->count();

        return [
            'total_products' => $totalProducts,
            'total_members' => $totalMembers,
            'max_possible_submissions' => $maxSubmissions,
            'total_submissions' => $totalSubmissions,
            'draft_submissions' => $draftSubmissions,
            'submitted_submissions' => $submittedSubmissions,
            'completion_percentage' => $maxSubmissions > 0 ? round(($submittedSubmissions / $maxSubmissions) * 100, 1) : 0,
        ];
    }

    public function getPendingProductsForMember(WorkgroupMember $member, ?int $sessionId = null): Collection
    {
        $evaluatedProductIds = EvaluationSubmission::where('workgroup_member_id', $member->id)
            ->pluck('candidate_product_id')
            ->toArray();

        $query = CandidateProduct::whereNotIn('id', $evaluatedProductIds)->with('category');

        if ($sessionId) {
            $query->where('workgroup_session_id', $sessionId);

            // Enforce attendance: only show products from sessions the member attended.
            // Members who already have submissions for this session retain access regardless.
            if (!$this->canMemberAccessSession($member, $sessionId)) {
                return collect(); // No access — return empty list
            }
        }

        return $query->orderBy('category_id')->orderBy('name')->get();
    }

    /**
     * Check whether a workgroup member is allowed to access (view/submit)
     * evaluations for a given session.
     *
     * Access is granted if EITHER:
     *   a) The member is in the attendance pivot table for that session, OR
     *   b) The member already has an existing evaluation submission for that
     *      session (backfill / historical data preservation).
     *
     * Admins / facilitators are never blocked — only base 'member' role.
     */
    public function canMemberAccessSession(WorkgroupMember $member, int $sessionId): bool
    {
        // Admins and facilitators always have access
        if (in_array($member->role, ['admin', 'facilitator'])) {
            return true;
        }

        // Check attendance pivot table
        $inAttendance = DB::table('session_workgroup_member_attendance')
            ->where('workgroup_session_id', $sessionId)
            ->where('workgroup_member_id', $member->id)
            ->exists();

        if ($inAttendance) {
            return true;
        }

        // Backfill safety: if the member already has any submission for this session
        // (e.g., historical data), grant read/edit access even if not in attendance table.
        $hasExistingSubmission = EvaluationSubmission::where('workgroup_member_id', $member->id)
            ->whereHas('candidateProduct', fn($q) => $q->where('workgroup_session_id', $sessionId))
            ->exists();

        return $hasExistingSubmission;
    }

    public function getOrCreateDraft(WorkgroupMember $member, int $productId): EvaluationSubmission
    {
        $submission = EvaluationSubmission::where('workgroup_member_id', $member->id)
            ->where('candidate_product_id', $productId)
            ->first(); // No status filter — find ANY existing submission (ERROR-010 fix)

        if (!$submission) {
            $submission = EvaluationSubmission::create([
                'workgroup_member_id' => $member->id,
                'candidate_product_id' => $productId,
                'status' => 'draft',
            ]);
        }

        return $submission->load(['scores.criterion', 'candidateProduct.category', 'candidateProduct.session']);
    }

    public function saveScores(EvaluationSubmission $submission, array $scores): EvaluationSubmission
    {
        foreach ($scores as $criterionId => $scoreValue) {
            EvaluationScore::updateOrCreate(
                ['submission_id' => $submission->id, 'criterion_id' => $criterionId],
                ['score' => $scoreValue !== '' ? $scoreValue : null]
            );
        }

        return $submission->fresh(['scores.criterion']);
    }

    public function submitEvaluation(EvaluationSubmission $submission): EvaluationSubmission
    {
        $template = $submission->candidateProduct->category->templates()->active()->first();
        
        if ($template) {
            $criteria = $template->criteria;
            $scores = $submission->scores;
            $scoredCriteriaIds = $scores->pluck('criterion_id')->toArray();
            $requiredCriteriaIds = $criteria->pluck('id')->toArray();
            $missingCriteria = array_diff($requiredCriteriaIds, $scoredCriteriaIds);
            
            if (!empty($missingCriteria)) {
                throw new \Exception('Please complete all criteria before submitting.');
            }
        }

        $submission->update([
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);

        return $submission->fresh();
    }

    public function getProductStats(int $productId): array
    {
        $product = CandidateProduct::with('category')->findOrFail($productId);
        
        $submissions = $this->countableSubmissions()
            ->where('candidate_product_id', $productId)
            ->where('status', 'submitted')
            ->get();

        $responseCount = $submissions->count();
        
        if ($responseCount === 0) {
            return [
                'product' => $product,
                'response_count' => 0,
                'weighted_average' => null,
                'min_score' => null,
                'max_score' => null,
                'meets_threshold' => false,
            ];
        }

        // Use pre-calculated rubric overall_score instead of legacy EvaluationScore relationship
        $scores = $submissions->map(fn($s) => $s->overall_score)->filter(fn($s) => $s !== null);
        
        if ($scores->isEmpty()) {
            return [
                'product' => $product,
                'response_count' => $responseCount,
                'weighted_average' => null,
                'min_score' => null,
                'max_score' => null,
                'meets_threshold' => false,
            ];
        }

        $weightedAverage = round($scores->avg(), 2);
        $scoreValues = $scores->toArray();

        return [
            'product' => $product,
            'response_count' => $responseCount,
            'weighted_average' => $weightedAverage,
            'min_score' => !empty($scoreValues) ? min($scoreValues) : null,
            'max_score' => !empty($scoreValues) ? max($scoreValues) : null,
            'meets_threshold' => $responseCount >= $this->minimumResponseThreshold,
        ];
    }

    /**
     * Get grouped brand purchase analysis for a given session.
     *
     * Groups candidate products by manufacturer within each rankable category
     * and computes a composite (average) score per brand across ALL their products.
     * Used to inform "best-value complete package purchase" decisions.
     *
     * Returns an array of groups. Each group has:
     *   - group_name: e.g. "Battery-Operated Extrication Tools"
     *   - category_ids: array of category IDs included in the group
     *   - brand_rankings: sorted array of brands w/ composite score + per-product breakdown
     *
     * Only includes categories where multiple manufacturers compete for the same product type
     * (i.e. categories with ≥2 manufacturers AND ≥2 products).
     */
    public function getBrandGroupedAnalysis(?int $sessionId = null): array
    {
        if (!$sessionId) {
            return [];
        }

        // Get all rankable categories with products in this session
        $categories = \App\Models\EvaluationCategory::rankable()->active()->get();

        $groups = [];

        foreach ($categories as $category) {
            $products = \App\Models\CandidateProduct::where('category_id', $category->id)
                ->where('workgroup_session_id', $sessionId)
                ->get();

            if ($products->isEmpty()) {
                continue;
            }

            // Group products by manufacturer
            $byManufacturer = $products->groupBy('manufacturer')
                ->filter(fn($p) => $p->first()?->manufacturer); // skip null manufacturer

            if ($byManufacturer->count() < 2) {
                // Only one brand — no comparison needed
                continue;
            }

            // Check there are multiple product types (e.g. cutter, spreader, ram)
            // by checking if brands share the same product names across types
            $productNames = $products->pluck('name')->unique()->values();
            if ($productNames->count() < 2) {
                continue;
            }

            // Compute composite brand scores
            $brandRankings = [];

            foreach ($byManufacturer as $brand => $brandProducts) {
                $productScores = [];
                foreach ($brandProducts as $product) {
                    $submissions = $this->countableSubmissions()
                        ->where('candidate_product_id', $product->id)
                        ->where('status', 'submitted')
                        ->get();

                    $scores = $submissions->map(fn($s) => $s->overall_score)->filter(fn($s) => $s !== null);
                    $avgScore = $scores->isNotEmpty() ? round($scores->avg(), 2) : null;
                    $responseCount = $submissions->count();

                    $productScores[] = [
                        'product' => $product,
                        'avg_score' => $avgScore,
                        'response_count' => $responseCount,
                        'capability_avg' => $responseCount > 0 ? round($submissions->avg('capability_score'), 2) : null,
                        'usability_avg' => $responseCount > 0 ? round($submissions->avg('usability_score'), 2) : null,
                        'affordability_avg' => $responseCount > 0 ? round($submissions->avg('affordability_score'), 2) : null,
                    ];
                }

                // Composite score = average of per-product averages (only include scored products)
                $scoredProducts = array_filter($productScores, fn($p) => $p['avg_score'] !== null);
                $compositeScore = count($scoredProducts) > 0
                    ? round(collect($scoredProducts)->avg('avg_score'), 2)
                    : null;

                $brandRankings[] = [
                    'brand' => $brand,
                    'composite_score' => $compositeScore,
                    'product_count' => count($productScores),
                    'scored_product_count' => count($scoredProducts),
                    'product_scores' => $productScores,
                ];
            }

            // Sort by composite score descending, nulls last
            usort($brandRankings, function ($a, $b) {
                if ($a['composite_score'] === null && $b['composite_score'] === null) return 0;
                if ($a['composite_score'] === null) return 1;
                if ($b['composite_score'] === null) return -1;
                return $b['composite_score'] <=> $a['composite_score'];
            });

            $groups[] = [
                'category_name' => $category->name,
                'category_id' => $category->id,
                'total_products' => $products->count(),
                'brand_count' => $byManufacturer->count(),
                'brand_rankings' => $brandRankings,
            ];
        }

        return $groups;
    }
}