<?php

namespace App\Services\Workgroup;

use App\Models\CandidateProduct;
use App\Models\EvaluationSubmission;
use App\Models\WorkgroupSession;
use App\Models\WorkgroupSharedUpload;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * WorkgroupAIService
 *
 * Integrates with the mbfd-workgroup-ai Cloudflare Worker for:
 * - Vectorizing uploaded product spec files (PDFs, DOCX, etc.)
 * - Generating AI analysis for individual products
 * - Generating category-level summaries
 * - Generating full executive reports for the Health & Safety Committee
 *
 * This service is COMPLETELY SEPARATE from the landing page chatbot (mbfd-support-ai).
 * It uses its own Cloudflare Worker (mbfd-workgroup-ai) with the separate
 * Vectorize index (workgroup-specs).
 */
class WorkgroupAIService
{
    protected string $workerUrl;
    protected int $timeout;

    public function __construct()
    {
        $this->workerUrl = rtrim(
            config('workgroup.ai_worker_url', env('WORKGROUP_AI_WORKER_URL', 'https://mbfd-workgroup-ai.pdarleyjr.workers.dev')),
            '/'
        );
        $this->timeout = 60; // AI requests can take up to 60s
    }

    public function isEnabled(): bool
    {
        return !empty($this->workerUrl);
    }

    // =========================================================================
    // VECTORIZATION — File Ingestion
    // =========================================================================

    /**
     * Vectorize a shared upload file (PDF, DOCX, TXT) into the workgroup-specs index.
     * Called automatically when a file is uploaded via SharedUploads page.
     *
     * For binary files (PDF/DOCX), the text must be extracted before calling this.
     * This method handles plain text files directly; for PDF extraction, use
     * vectorizeUploadedFile() which handles extraction.
     */
    public function vectorizeTextChunk(
        string $text,
        string $filename,
        ?string $productName = null,
        ?string $manufacturer = null,
        ?string $category = null,
        int $chunkIndex = 0,
        ?int $fileId = null
    ): array {
        if (!$this->isEnabled() || empty(trim($text))) {
            return ['success' => false, 'error' => 'Service not enabled or empty text'];
        }

        try {
            $response = Http::timeout($this->timeout)->post("{$this->workerUrl}/vectorize", [
                'text'         => $text,
                'filename'     => $filename,
                'productName'  => $productName,
                'manufacturer' => $manufacturer,
                'category'     => $category,
                'chunkIndex'   => $chunkIndex,
                'fileId'       => $fileId ? "file-{$fileId}" : "file-unknown",
            ]);

            if ($response->successful()) {
                return $response->json();
            }

            Log::warning('[WorkgroupAI] Vectorize failed', [
                'status'   => $response->status(),
                'filename' => $filename,
                'body'     => $response->body(),
            ]);

            return ['success' => false, 'error' => "Worker returned {$response->status()}"];
        } catch (\Exception $e) {
            Log::error('[WorkgroupAI] Vectorize exception', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Vectorize an uploaded WorkgroupSharedUpload.
     * Extracts text from the file and sends chunks to the vector index.
     * Supports: plain text files. For PDF/DOCX, extracts available text.
     */
    public function vectorizeUpload(WorkgroupSharedUpload $upload): array
    {
        if (!$this->isEnabled()) {
            return ['success' => false, 'error' => 'AI service not configured'];
        }

        $filepath = $upload->filepath;
        $filename = $upload->filename;

        try {
            // Get file content from storage
            $content = Storage::disk('public')->get($filepath);
            if (!$content) {
                return ['success' => false, 'error' => 'File not found in storage'];
            }

            // For text-based files, extract content directly
            $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            $text = $this->extractTextFromContent($content, $extension);

            if (empty(trim($text))) {
                return ['success' => false, 'error' => 'No extractable text in file'];
            }

            // Split into chunks of ~1500 chars to preserve context quality
            $chunks = $this->chunkText($text, 1500, 200); // 200 char overlap
            $results = [];

            foreach ($chunks as $index => $chunk) {
                $result = $this->vectorizeTextChunk(
                    text:         $chunk,
                    filename:     $filename,
                    productName:  null, // Could be enriched by admin later
                    manufacturer: null,
                    category:     null,
                    chunkIndex:   $index,
                    fileId:       $upload->id
                );
                $results[] = $result;

                // Brief pause between chunks to avoid rate limiting
                if ($index < count($chunks) - 1) {
                    usleep(200000); // 200ms
                }
            }

            $successful = count(array_filter($results, fn($r) => $r['success'] ?? false));

            return [
                'success'     => $successful > 0,
                'chunks'      => count($chunks),
                'vectorized'  => $successful,
                'filename'    => $filename,
            ];

        } catch (\Exception $e) {
            Log::error('[WorkgroupAI] Vectorize upload failed', [
                'upload_id' => $upload->id,
                'error'     => $e->getMessage(),
            ]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // =========================================================================
    // ANALYSIS — Product & Category AI Summaries
    // =========================================================================

    /**
     * Generate AI analysis for a single candidate product.
     * Pulls evaluation submissions and calls the /analyze endpoint.
     * Results are cached for 2 hours.
     */
    public function analyzeProduct(CandidateProduct $product): array
    {
        if (!$this->isEnabled()) {
            return $this->fallbackAnalysis($product);
        }

        $cacheKey = "workgroup_ai_product_{$product->id}";

        return Cache::remember($cacheKey, 7200, function () use ($product) {
            $submissions = EvaluationSubmission::where('candidate_product_id', $product->id)
                ->where('status', 'submitted')
                ->whereHas('member', fn($q) => $q->where('count_evaluations', true))
                ->with(['member.user'])
                ->get();

            if ($submissions->isEmpty()) {
                return ['analysis' => null, 'error' => 'No submitted evaluations yet'];
            }

            $aggregateScores = $this->calculateAggregateScores($submissions);
            $submissionsFormatted = $submissions->map(fn($s) => $this->formatSubmission($s))->values()->toArray();

            try {
                $response = Http::timeout($this->timeout)->post("{$this->workerUrl}/analyze", [
                    'productName'     => $product->name,
                    'manufacturer'    => $product->manufacturer,
                    'model'           => $product->model,
                    'category'        => $product->category?->name,
                    'submissions'     => $submissionsFormatted,
                    'aggregateScores' => $aggregateScores,
                    'sessionName'     => $product->session?->name,
                ]);

                if ($response->successful()) {
                    return $response->json();
                }

                Log::warning('[WorkgroupAI] Analyze failed', [
                    'product_id' => $product->id,
                    'status'     => $response->status(),
                ]);
                return ['analysis' => null, 'error' => "Worker error: {$response->status()}"];

            } catch (\Exception $e) {
                Log::error('[WorkgroupAI] Analyze exception', ['error' => $e->getMessage()]);
                return ['analysis' => null, 'error' => $e->getMessage()];
            }
        });
    }

    /**
     * Generate a category-level summary.
     * Cached for 2 hours.
     */
    public function generateCategorySummary(string $category, array $products, ?string $sessionName = null): array
    {
        if (!$this->isEnabled()) {
            return ['summary' => null, 'error' => 'AI service not configured'];
        }

        $cacheKey = 'workgroup_ai_category_' . md5($category . serialize($products));

        return Cache::remember($cacheKey, 7200, function () use ($category, $products, $sessionName) {
            // Determine if battery hydraulics (rank by brand)
            $rankingType = $this->detectRankingType($category);

            try {
                $response = Http::timeout($this->timeout)->post("{$this->workerUrl}/summary", [
                    'category'    => $category,
                    'products'    => $products,
                    'sessionName' => $sessionName,
                    'rankingType' => $rankingType,
                ]);

                if ($response->successful()) {
                    return $response->json();
                }

                return ['summary' => null, 'error' => "Worker error: {$response->status()}"];
            } catch (\Exception $e) {
                Log::error('[WorkgroupAI] Category summary exception', ['error' => $e->getMessage()]);
                return ['summary' => null, 'error' => $e->getMessage()];
            }
        });
    }

    /**
     * Generate the full executive report for all categories.
     * This is the final document for the Health & Safety Committee.
     * NOT cached — always fresh.
     */
    public function generateExecutiveReport(WorkgroupSession $session): array
    {
        if (!$this->isEnabled()) {
            return ['report' => null, 'error' => 'AI service not configured'];
        }

        $categories = $this->buildCategoriesForReport($session);
        $overallStats = $this->buildOverallStats($session);

        try {
            $response = Http::timeout(120)->post("{$this->workerUrl}/executive-report", [
                'sessionName'  => $session->name,
                'sessionDate'  => now()->format('F j, Y'),
                'categories'   => $categories,
                'overallStats' => $overallStats,
            ]);

            if ($response->successful()) {
                $result = $response->json();
                // Cache for 30 min so back-to-back exports don't re-generate
                Cache::put("workgroup_ai_exec_report_{$session->id}", $result, 1800);
                return $result;
            }

            return ['report' => null, 'error' => "Worker error: {$response->status()}"];
        } catch (\Exception $e) {
            Log::error('[WorkgroupAI] Executive report exception', ['error' => $e->getMessage()]);
            return ['report' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get cached executive report for a session (if available).
     */
    public function getCachedExecutiveReport(int $sessionId): ?array
    {
        return Cache::get("workgroup_ai_exec_report_{$sessionId}");
    }

    /**
     * Clear AI analysis cache for a product (e.g., when new evaluations submitted).
     */
    public function clearProductCache(int $productId): void
    {
        Cache::forget("workgroup_ai_product_{$productId}");
    }

    /**
     * Clear all AI analysis caches.
     */
    public function clearAllCaches(): void
    {
        Cache::flush(); // Nuclear option — only during testing
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    protected function calculateAggregateScores(Collection $submissions): array
    {
        $count = $submissions->count();
        if ($count === 0) return [];

        $advanceCount = $submissions->where('advance_recommendation', 'yes')->count();
        $maybeCount   = $submissions->where('advance_recommendation', 'maybe')->count();
        $noCount      = $submissions->where('advance_recommendation', 'no')->count();
        $dealBreakers = $submissions->where('has_deal_breaker', true)->count();

        return [
            'evaluatorCount'   => $count,
            'averageOverall'   => $submissions->avg('overall_score'),
            'avgCapability'    => $submissions->avg('capability_score'),
            'avgUsability'     => $submissions->avg('usability_score'),
            'avgAffordability' => $submissions->avg('affordability_score'),
            'avgMaintainability' => $submissions->avg('maintainability_score'),
            'avgDeployability' => $submissions->avg('deployability_score'),
            'advanceCount'     => $advanceCount,
            'maybeCount'       => $maybeCount,
            'noCount'          => $noCount,
            'dealBreakerCount' => $dealBreakers,
        ];
    }

    protected function formatSubmission(EvaluationSubmission $submission): array
    {
        return [
            'evaluatorRole'        => $submission->member?->role ?? 'member',
            'overallScore'         => $submission->overall_score,
            'capabilityScore'      => $submission->capability_score,
            'usabilityScore'       => $submission->usability_score,
            'affordabilityScore'   => $submission->affordability_score,
            'maintainabilityScore' => $submission->maintainability_score,
            'deployabilityScore'   => $submission->deployability_score,
            'recommendationLabel'  => $submission->recommendation_label,
            'confidenceLabel'      => $submission->confidence_label,
            'hasDealBreaker'       => $submission->has_deal_breaker,
            'dealBreakerNote'      => $submission->deal_breaker_note,
            'narrative'            => $submission->narrative_payload,
        ];
    }

    protected function buildCategoriesForReport(WorkgroupSession $session): array
    {
        // Get all products grouped by category — only include countable submissions
        $products = CandidateProduct::where('workgroup_session_id', $session->id)
            ->with(['category', 'submissions' => fn($q) => $q->where('status', 'submitted')
                ->whereHas('member', fn($mq) => $mq->where('count_evaluations', true))])
            ->get();

        $grouped = $products->groupBy('category.name');

        return $grouped->map(function ($categoryProducts, $categoryName) {
            $rankingType = $this->detectRankingType($categoryName);

            $productsFormatted = $categoryProducts->map(function (CandidateProduct $product) {
                $submissions = $product->submissions;
                return [
                    'name'            => $product->name,
                    'manufacturer'    => $product->manufacturer,
                    'model'           => $product->model,
                    'averageScore'    => $submissions->avg('overall_score'),
                    'submissionCount' => $submissions->count(),
                    'isFinalist'      => $submissions->filter(fn($s) => $s->advance_recommendation === 'yes')->count() >= ceil($submissions->count() / 2),
                    'hasDealBreaker'  => $submissions->where('has_deal_breaker', true)->count() > 0,
                    'finalistVotes'   => $submissions->where('advance_recommendation', 'yes')->count(),
                    'capabilityScore'      => $submissions->avg('capability_score'),
                    'usabilityScore'       => $submissions->avg('usability_score'),
                    'affordabilityScore'   => $submissions->avg('affordability_score'),
                    'maintainabilityScore' => $submissions->avg('maintainability_score'),
                    'deployabilityScore'   => $submissions->avg('deployability_score'),
                ];
            })
            ->sortByDesc('averageScore')
            ->values()
            ->toArray();

            $evaluatorIds = $categoryProducts->flatMap(fn($p) => $p->submissions->pluck('workgroup_member_id'))->unique()->count();

            return [
                'name'           => $categoryName,
                'rankingType'    => $rankingType,
                'products'       => $productsFormatted,
                'evaluatorCount' => $evaluatorIds,
            ];
        })->values()->toArray();
    }

    protected function buildOverallStats(WorkgroupSession $session): array
    {
        $products     = CandidateProduct::where('workgroup_session_id', $session->id)->count();
        $submissions  = EvaluationSubmission::whereHas('candidateProduct', fn($q) => $q->where('workgroup_session_id', $session->id))
            ->where('status', 'submitted')
            ->whereHas('member', fn($mq) => $mq->where('count_evaluations', true))
            ->count();
        $evaluatorIds = EvaluationSubmission::whereHas('candidateProduct', fn($q) => $q->where('workgroup_session_id', $session->id))
            ->where('status', 'submitted')
            ->whereHas('member', fn($mq) => $mq->where('count_evaluations', true))
            ->distinct('workgroup_member_id')
            ->count('workgroup_member_id');

        return [
            'totalProducts'    => $products,
            'totalEvaluators'  => $evaluatorIds,
            'totalSubmissions' => $submissions,
        ];
    }

    protected function detectRankingType(string $categoryName): string
    {
        $name = strtolower($categoryName);
        if (
            str_contains($name, 'hydraulic') ||
            str_contains($name, 'battery') ||
            str_contains($name, 'cutter') ||
            str_contains($name, 'spreader') ||
            str_contains($name, 'ram')
        ) {
            return 'brand';
        }
        return 'individual';
    }

    protected function extractTextFromContent(string $content, string $extension): string
    {
        // For plain text formats, return directly
        if (in_array($extension, ['txt', 'md', 'csv', 'json'])) {
            return $content;
        }

        // For PDF/DOCX, attempt basic text extraction
        // Strip binary content, keep readable ASCII/UTF-8 text runs
        if (in_array($extension, ['pdf', 'docx', 'doc'])) {
            // Extract printable text runs from binary
            $text = '';
            preg_match_all('/[^\x00-\x08\x0B\x0C\x0E-\x1F\x7F-\x9F]{4,}/u', $content, $matches);
            $text = implode(' ', $matches[0] ?? []);
            // Clean up whitespace
            $text = preg_replace('/\s+/', ' ', $text);
            return trim($text);
        }

        // For unknown types, try to extract readable text
        $text = '';
        preg_match_all('/[a-zA-Z0-9\s\.,\-:;!?\/\\\\()\[\]\'\"@#$%&*+=<>]{10,}/u', $content, $matches);
        return implode(' ', $matches[0] ?? []);
    }

    /**
     * Split text into overlapping chunks for better vector context.
     */
    protected function chunkText(string $text, int $chunkSize = 1500, int $overlap = 200): array
    {
        $text = trim($text);
        if (strlen($text) <= $chunkSize) {
            return [$text];
        }

        $chunks = [];
        $start  = 0;
        $length = strlen($text);

        while ($start < $length) {
            $end = min($start + $chunkSize, $length);

            // Try to break at a sentence boundary
            if ($end < $length) {
                $breakAt = strrpos(substr($text, $start, $end - $start), '. ');
                if ($breakAt !== false && $breakAt > $chunkSize / 2) {
                    $end = $start + $breakAt + 1;
                }
            }

            $chunks[] = substr($text, $start, $end - $start);
            $start    = max($start + 1, $end - $overlap);
        }

        return $chunks;
    }

    protected function fallbackAnalysis(CandidateProduct $product): array
    {
        return [
            'analysis'       => null,
            'error'          => 'AI service not configured. Set WORKGROUP_AI_WORKER_URL in .env',
            'productName'    => $product->name,
            'generatedAt'    => now()->toISOString(),
        ];
    }
}
