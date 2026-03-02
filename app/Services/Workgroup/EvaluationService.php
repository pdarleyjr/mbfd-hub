<?php

namespace App\Services\Workgroup;

use App\Models\CandidateProduct;
use App\Models\EvaluationCategory;
use App\Models\EvaluationScore;
use App\Models\EvaluationSubmission;
use App\Models\WorkgroupMember;
use Illuminate\Support\Collection;

class EvaluationService
{
    protected int $minimumResponseThreshold = 3;

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
            ->with(['category', 'submissions' => function ($q) {
                $q->where('status', 'submitted')->with('scores.criterion');
            }]);

        if ($sessionId) {
            $query->where('workgroup_session_id', $sessionId);
        }

        $products = $query->get();

        $rankings = $products->map(function ($product) {
            $submittedSubmissions = $product->submissions->filter(fn($s) => $s->status === 'submitted');
            $responseCount = $submittedSubmissions->count();
            $scores = $submittedSubmissions->flatMap(fn($s) => $s->scores);
            
            if ($scores->isEmpty()) {
                return [
                    'product' => $product,
                    'weighted_average' => null,
                    'response_count' => 0,
                    'meets_threshold' => false,
                ];
            }

            $totalScore = 0;
            $totalWeight = 0;

            foreach ($scores as $score) {
                if ($score->score !== null && $score->criterion) {
                    $totalScore += $score->score * $score->criterion->weight;
                    $totalWeight += $score->criterion->weight;
                }
            }

            $weightedAverage = $totalWeight > 0 ? round($totalScore / $totalWeight, 2) : null;

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
            $submissions = EvaluationSubmission::whereHas('candidateProduct', function ($q) use ($category, $sessionId) {
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
                        'score' => $submission->total_score,
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
        $totalMembers = WorkgroupMember::where('is_active', true)->count();
        $maxSubmissions = $totalProducts * $totalMembers;

        $submissionQuery = EvaluationSubmission::whereHas('candidateProduct', function ($q) use ($sessionId) {
            if ($sessionId) {
                $q->where('workgroup_session_id', $sessionId);
            }
        });

        $totalSubmissions = $submissionQuery->count();
        $draftSubmissions = $submissionQuery->where('status', 'draft')->count();
        $submittedSubmissions = $submissionQuery->where('status', 'submitted')->count();

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
        }

        return $query->orderBy('category_id')->orderBy('name')->get();
    }

    public function getOrCreateDraft(WorkgroupMember $member, int $productId): EvaluationSubmission
    {
        $submission = EvaluationSubmission::where('workgroup_member_id', $member->id)
            ->where('candidate_product_id', $productId)
            ->where('status', 'draft')
            ->first();

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
        
        $submissions = EvaluationSubmission::where('candidate_product_id', $productId)
            ->where('status', 'submitted')
            ->with('scores.criterion')
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

        $allScores = $submissions->flatMap(fn($s) => $s->scores);
        
        $totalScore = 0;
        $totalWeight = 0;
        $scoreValues = [];

        foreach ($allScores as $score) {
            if ($score->score !== null && $score->criterion) {
                $totalScore += $score->score * $score->criterion->weight;
                $totalWeight += $score->criterion->weight;
                $scoreValues[] = $score->score;
            }
        }

        $weightedAverage = $totalWeight > 0 ? round($totalScore / $totalWeight, 2) : null;

        return [
            'product' => $product,
            'response_count' => $responseCount,
            'weighted_average' => $weightedAverage,
            'min_score' => !empty($scoreValues) ? min($scoreValues) : null,
            'max_score' => !empty($scoreValues) ? max($scoreValues) : null,
            'meets_threshold' => $responseCount >= $this->minimumResponseThreshold,
        ];
    }
}