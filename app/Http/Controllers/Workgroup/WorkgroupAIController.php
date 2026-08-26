<?php

namespace App\Http\Controllers\Workgroup;

use App\Http\Controllers\Controller;
use App\Models\CandidateProduct;
use App\Models\User;
use App\Models\WorkgroupSession;
use App\Models\WorkgroupSharedUpload;
use App\Services\Workgroup\WorkgroupAIService;
use App\Support\Workgroups\WorkgroupAccess;
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
    public function __construct(
        private WorkgroupAIService $aiService,
        private WorkgroupAccess $workgroupAccess,
    ) {}

    /**
     * POST /api/workgroup/ai/analyze-product/{productId}
     * Generate or retrieve cached AI analysis for a single product.
     */
    public function analyzeProduct(int $productId): JsonResponse
    {
        $user = $this->currentUser();
        $product = $this->workgroupAccess
            ->scopeCandidateProducts(CandidateProduct::with(['category', 'session']), $user)
            ->find($productId);

        if (! $product) {
            return response()->json(['error' => 'Product not found'], 404);
        }

        $this->workgroupAccess->requireManageCandidateProduct($user, $product);

        $result = $this->aiService->analyzeProduct($product);

        return response()->json($result);
    }

    /**
     * POST /api/workgroup/ai/category-summary
     * Generate a category-level summary for products in a category.
     */
    public function categorySummary(Request $request): JsonResponse
    {
        $user = $this->currentUser();
        $validated = $request->validate([
            'category' => 'required|string',
            'session_id' => 'nullable|integer',
        ]);

        $sessions = $this->workgroupAccess->scopeSessions(WorkgroupSession::query(), $user);
        $session = $validated['session_id']
            ? $sessions->find($validated['session_id'])
            : $sessions->active()->orderBy('id')->first();

        if (! $session) {
            return response()->json(['error' => 'No active session'], 404);
        }

        $this->workgroupAccess->requireManageSession($user, $session);
        $session->loadMissing('workgroup');
        abort_unless($session->workgroup !== null, 404);

        // Build products array for the category
        $products = CandidateProduct::where('workgroup_session_id', $session->id)
            ->whereHas('category', fn ($q) => $q->where('name', $validated['category']))
            ->with(['category', 'submissions' => fn ($q) => $q->where('status', 'submitted')])
            ->get()
            ->map(fn ($p) => [
                'name' => $p->name,
                'manufacturer' => $p->manufacturer,
                'model' => $p->model,
                'averageScore' => $p->submissions->avg('overall_score'),
                'capabilityScore' => $p->submissions->avg('capability_score'),
                'usabilityScore' => $p->submissions->avg('usability_score'),
                'affordabilityScore' => $p->submissions->avg('affordability_score'),
                'maintainabilityScore' => $p->submissions->avg('maintainability_score'),
                'deployabilityScore' => $p->submissions->avg('deployability_score'),
                'submissionCount' => $p->submissions->count(),
                'finalistVotes' => $p->submissions->where('advance_recommendation', 'yes')->count(),
                'dealBreakerCount' => $p->submissions->where('has_deal_breaker', true)->count(),
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
        $user = $this->currentUser();
        $validated = $request->validate([
            'session_id' => 'nullable|integer',
        ]);

        $sessions = $this->workgroupAccess->scopeSessions(WorkgroupSession::query(), $user);
        $session = $validated['session_id']
            ? $sessions->find($validated['session_id'])
            : $sessions->active()->orderBy('id')->first();

        if (! $session) {
            return response()->json(['error' => 'No active session'], 404);
        }

        $this->workgroupAccess->requireManageSession($user, $session);
        $session->loadMissing('workgroup');
        abort_unless($session->workgroup !== null, 404);

        // Check cached version first
        $cached = $this->aiService->getCachedExecutiveReport($session->id);
        if ($cached && ! $request->boolean('force')) {
            return response()->json(array_merge($cached, ['fromCache' => true]));
        }

        $result = $this->aiService->generateExecutiveReport($session->workgroup, $session);

        return response()->json($result);
    }

    /**
     * POST /api/workgroup/ai/vectorize-upload/{uploadId}
     * Manually trigger vectorization of an uploaded file.
     * Admin/facilitator only.
     */
    public function vectorizeUpload(int $uploadId): JsonResponse
    {
        $user = $this->currentUser();
        $upload = $this->workgroupAccess
            ->scopeWorkgroupRecords(WorkgroupSharedUpload::query(), $user)
            ->find($uploadId);

        if (! $upload) {
            return response()->json(['error' => 'Upload not found'], 404);
        }

        $this->workgroupAccess->requireManageUpload($user, $upload);

        $result = $this->aiService->vectorizeUpload($upload);

        return response()->json($result);
    }

    private function currentUser(): User
    {
        $user = Auth::user();

        abort_unless($user instanceof User, 404);

        return $user;
    }
}
