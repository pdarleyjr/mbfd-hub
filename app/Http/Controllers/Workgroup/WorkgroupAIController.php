<?php

namespace App\Http\Controllers\Workgroup;

use App\Http\Controllers\Controller;
use App\Models\CandidateProduct;
use App\Models\WorkgroupSession;
use App\Services\Workgroup\WorkgroupAIService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * WorkgroupAIController
 *
 * Provides API endpoints for on-demand AI analysis in the workgroup UI.
 * These are called by Alpine.js / Livewire components in the blade views.
 *
 * Completely separate from the landing page chatbot.
 */
class WorkgroupAIController extends Controller
{
    public function __construct(private WorkgroupAIService $aiService)
    {
    }

    /**
     * POST /api/workgroup/ai/analyze-product/{productId}
     * Generate or retrieve cached AI analysis for a single product.
     */
    public function analyzeProduct(int $productId): JsonResponse
    {
        $product = CandidateProduct::with(['category', 'session'])->find($productId);

        if (!$product) {
            return response()->json(['error' => 'Product not found'], 404);
        }

        $result = $this->aiService->analyzeProduct($product);

        return response()->json($result);
    }

    /**
     * POST /api/workgroup/ai/category-summary
     * Generate a category-level summary for products in a category.
     */
    public function categorySummary(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'category'    => 'required|string',
            'session_id'  => 'nullable|integer|exists:workgroup_sessions,id',
        ]);

        $session = $validated['session_id']
            ? WorkgroupSession::find($validated['session_id'])
            : WorkgroupSession::active()->first();

        if (!$session) {
            return response()->json(['error' => 'No active session'], 404);
        }

        // Build products array for the category
        $products = CandidateProduct::where('workgroup_session_id', $session->id)
            ->whereHas('category', fn($q) => $q->where('name', $validated['category']))
            ->with(['category', 'submissions' => fn($q) => $q->where('status', 'submitted')])
            ->get()
            ->map(fn($p) => [
                'name'                 => $p->name,
                'manufacturer'         => $p->manufacturer,
                'model'                => $p->model,
                'averageScore'         => $p->submissions->avg('overall_score'),
                'capabilityScore'      => $p->submissions->avg('capability_score'),
                'usabilityScore'       => $p->submissions->avg('usability_score'),
                'affordabilityScore'   => $p->submissions->avg('affordability_score'),
                'maintainabilityScore' => $p->submissions->avg('maintainability_score'),
                'deployabilityScore'   => $p->submissions->avg('deployability_score'),
                'submissionCount'      => $p->submissions->count(),
                'finalistVotes'        => $p->submissions->where('advance_recommendation', 'yes')->count(),
                'dealBreakerCount'     => $p->submissions->where('has_deal_breaker', true)->count(),
            ])
            ->sortByDesc('averageScore')
            ->values()
            ->toArray();

        $result = $this->aiService->generateCategorySummary(
            $validated['category'],
            $products,
            $session->name
        );

        return response()->json($result);
    }

    /**
     * POST /api/workgroup/ai/executive-report
     * Generate the full executive report for the active session.
     * Admin/facilitator only.
     */
    public function executiveReport(Request $request): JsonResponse
    {
        $session = WorkgroupSession::active()->first();

        if (!$session) {
            return response()->json(['error' => 'No active session'], 404);
        }

        // Check cached version first
        $cached = $this->aiService->getCachedExecutiveReport($session->id);
        if ($cached && !$request->boolean('force')) {
            return response()->json(array_merge($cached, ['fromCache' => true]));
        }

        $result = $this->aiService->generateExecutiveReport($session);

        return response()->json($result);
    }

    /**
     * POST /api/workgroup/ai/vectorize-upload/{uploadId}
     * Manually trigger vectorization of an uploaded file.
     * Admin/facilitator only.
     */
    public function vectorizeUpload(int $uploadId): JsonResponse
    {
        $upload = \App\Models\WorkgroupSharedUpload::find($uploadId);

        if (!$upload) {
            return response()->json(['error' => 'Upload not found'], 404);
        }

        $result = $this->aiService->vectorizeUpload($upload);

        return response()->json($result);
    }
}
