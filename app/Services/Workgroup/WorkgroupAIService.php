<?php

namespace App\Services\Workgroup;

use App\Models\CandidateProduct;
use App\Models\EvaluationSubmission;
use App\Models\Workgroup;
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
     *
     * When $session is null, generates an "Overall Project Evaluation" report
     * aggregating data across ALL sessions — not just Day 1.
     */
    public function generateExecutiveReport(Workgroup $workgroup, ?WorkgroupSession $session = null): array
    {
        if (!$this->isEnabled()) {
            return ['report' => null, 'error' => 'AI service not configured'];
        }

        $categories = $session
            ? $this->buildCategoriesForReport($session)
            : $this->buildCategoriesForOverallReport($workgroup);
        $overallStats = $session
            ? $this->buildOverallStats($session)
            : $this->buildOverallStatsAllSessions($workgroup);

        // Collect anonymous evaluator comments for AI context
        $anonymousComments = $this->collectAnonymousComments($workgroup, $session);

        $sessionLabel = $session ? $session->name : 'Overall Project Evaluation';

        try {
            $response = Http::timeout(120)->post("{$this->workerUrl}/executive-report", [
                'sessionName'  => $sessionLabel,
                'sessionDate'  => now()->format('F j, Y'),
                'categories'   => $categories,
                'overallStats' => $overallStats,
                'anonymousComments' => $anonymousComments,
                'systemDirective' => 'You must analyze the provided anonymous evaluator comments and cross-reference them against the vendor product specifications and tool details found in your RAG index for these specific brands. Include qualitative insights from evaluator feedback alongside the quantitative scores.',
            ]);

            if ($response->successful()) {
                $result = $response->json();
                $cacheKey = $session
                    ? "workgroup_ai_exec_report_{$session->id}"
                    : "workgroup_ai_exec_report_overall_{$workgroup->id}";
                Cache::put($cacheKey, $result, 1800);
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

    // =========================================================================
    // SAVER REPORT — DHS-style purchasing recommendation document
    // =========================================================================

    /**
     * Generate a DHS SAVER-style executive purchasing report for a workgroup.
     *
     * Uses EvaluationService::getComprehensiveResults() to gather all aggregated
     * data, builds a comprehensive prompt, and calls the AI worker to produce
     * a formatted HTML report covering all five SAVER dimensions.
     *
     * @return string HTML content of the SAVER report
     */
    public function generateSaverReport(Workgroup $workgroup, ?WorkgroupSession $session = null): string
    {
        if (!$this->isEnabled()) {
            return '<p class="text-red-600">AI service not configured. Set WORKGROUP_AI_WORKER_URL in .env</p>';
        }

        $evalService = app(EvaluationService::class);
        $results = $evalService->getComprehensiveResults($workgroup, $session);

        $prompt = $this->buildSaverPrompt($results, $workgroup, $session);

        try {
            $response = Http::timeout(120)->post("{$this->workerUrl}/saver-report", [
                'prompt' => $prompt,
                'workgroupName' => $workgroup->name,
                'sessionName' => $session?->name ?? 'All Sessions',
                'generatedAt' => now()->format('F j, Y'),
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $html = $data['report'] ?? $data['result']['response'] ?? '';

                if (!empty($html)) {
                    Cache::put("workgroup_saver_report_{$workgroup->id}_{$session?->id}", $html, 3600);
                    return $html;
                }

                return '<p class="text-yellow-600">AI returned empty report. Try regenerating.</p>';
            }

            Log::warning('[WorkgroupAI] SAVER report failed', [
                'status' => $response->status(),
                'body' => substr($response->body(), 0, 500),
            ]);

            // Fallback: try the general /analyze endpoint with the SAVER prompt
            return $this->generateSaverReportFallback($prompt, $workgroup, $session);

        } catch (\Exception $e) {
            Log::error('[WorkgroupAI] SAVER report exception', ['error' => $e->getMessage()]);
            return $this->generateSaverReportFallback($prompt, $workgroup, $session);
        }
    }

    /**
     * Get cached SAVER report if available.
     */
    public function getCachedSaverReport(int $workgroupId, ?int $sessionId = null): ?string
    {
        return Cache::get("workgroup_saver_report_{$workgroupId}_{$sessionId}");
    }

    /**
     * Fallback: use the executive-report endpoint with SAVER-formatted prompt.
     */
    protected function generateSaverReportFallback(string $prompt, Workgroup $workgroup, ?WorkgroupSession $session): string
    {
        try {
            $response = Http::timeout(120)->post("{$this->workerUrl}/executive-report", [
                'sessionName' => $session?->name ?? 'All Sessions — ' . $workgroup->name,
                'sessionDate' => now()->format('F j, Y'),
                'categories' => [],
                'overallStats' => [],
                'customPrompt' => $prompt,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $html = $data['report'] ?? $data['result']['response'] ?? '';
                if (!empty($html)) {
                    Cache::put("workgroup_saver_report_{$workgroup->id}_{$session?->id}", $html, 3600);
                    return $html;
                }
            }
        } catch (\Exception $e) {
            Log::error('[WorkgroupAI] SAVER fallback failed', ['error' => $e->getMessage()]);
        }

        return '<p class="text-red-600">Unable to generate SAVER report. The AI service may be temporarily unavailable. Please try again later.</p>';
    }

    /**
     * Build the comprehensive SAVER report prompt from evaluation data.
     */
    protected function buildSaverPrompt(array $results, Workgroup $workgroup, ?WorkgroupSession $session): string
    {
        $lines = [];
        $lines[] = "You are an expert evaluator writing a DHS SAVER (System Assessment and Validation for Emergency Responders) style report.";
        $lines[] = "You must analyze the provided anonymous evaluator comments and cross-reference them against the vendor product specifications and tool details found in your RAG index for these specific brands.";
        $lines[] = "";
        $lines[] = "WORKGROUP: {$workgroup->name}";
        $lines[] = "SESSION: " . ($session ? $session->name : 'Overall Project Evaluation');
        $lines[] = "DATE: " . now()->format('F j, Y');
        $lines[] = "";

        // Brand aggregated rankings
        if (!empty($results['brand_aggregated_rankings'])) {
            $lines[] = "=== BRAND AGGREGATED RANKINGS ===";
            foreach ($results['brand_aggregated_rankings'] as $cat) {
                $lines[] = "Category: {$cat['category_name']}";
                foreach ($cat['brand_rankings'] as $idx => $brand) {
                    $score = $brand['composite_score'] !== null ? number_format($brand['composite_score'], 1) : 'N/A';
                    $lines[] = "  #{$idx}: {$brand['brand']} — Composite: {$score} ({$brand['product_count']} products)";

                    if (!empty($brand['saver_breakdown'])) {
                        $s = $brand['saver_breakdown'];
                        $lines[] = "    Capability: " . ($s['capability'] ?? 'N/A') .
                            " | Usability: " . ($s['usability'] ?? 'N/A') .
                            " | Affordability: " . ($s['affordability'] ?? 'N/A') .
                            " | Maintainability: " . ($s['maintainability'] ?? 'N/A') .
                            " | Deployability: " . ($s['deployability'] ?? 'N/A');
                    }

                    if (!empty($brand['products'])) {
                        foreach ($brand['products'] as $p) {
                            $pScore = $p['avg_score'] !== null ? number_format($p['avg_score'], 1) : 'N/A';
                            $lines[] = "    - {$p['name']}: {$pScore} ({$p['response_count']} responses)";
                        }
                    }
                }
                $lines[] = "";
            }
        }

        // Competitor group rankings
        if (!empty($results['competitor_group_rankings'])) {
            $lines[] = "=== COMPETITOR GROUP RANKINGS ===";
            foreach ($results['competitor_group_rankings'] as $cat) {
                $lines[] = "Category: {$cat['category_name']}";
                foreach ($cat['groups'] as $group) {
                    $lines[] = "  Group: {$group['group_name']} ({$group['product_count']} products)";

                    foreach ($group['rankings'] as $idx => $r) {
                        $rScore = $r['avg_score'] !== null ? number_format($r['avg_score'], 1) : 'N/A';
                        $lines[] = "    #{$idx}: {$r['name']} ({$r['brand']}) — {$rScore} ({$r['response_count']} resp.)";
                    }
                }
                $lines[] = "";
            }
        }

        // Isolated products
        if (!empty($results['isolated_products'])) {
            $lines[] = "=== STANDALONE PRODUCTS ===";
            foreach ($results['isolated_products'] as $iso) {
                $iScore = $iso['avg_score'] !== null ? number_format($iso['avg_score'], 1) : 'N/A';
                $lines[] = "- {$iso['name']} ({$iso['brand']}) in {$iso['category_name']}: {$iScore} ({$iso['response_count']} resp.) {$iso['note']}";
            }
            $lines[] = "";
        }

        // Standard category rankings
        if (!empty($results['standard_category_rankings'])) {
            $lines[] = "=== STANDARD CATEGORY RANKINGS ===";
            foreach ($results['standard_category_rankings'] as $cat) {
                $lines[] = "Category: {$cat['category_name']} ({$cat['total_products']} products, {$cat['eligible_products']} eligible)";

                foreach ($cat['rankings'] as $idx => $item) {
                    $sScore = $item['weighted_average'] !== null ? number_format($item['weighted_average'], 1) : 'N/A';

                    $name = $item['product']->name ?? 'Unknown';
                    $lines[] = "  #{$idx}: {$name} — {$sScore} ({$item['response_count']} resp.) " .
                        ($item['meets_threshold'] ? '✓ threshold' : '✗ below threshold');
                }

                $lines[] = "";
            }
        }

        // Non-rankable feedback
        $nrFeedback = $results['non_rankable_feedback'] ?? collect();

        if ($nrFeedback->isNotEmpty()) {
            $lines[] = "=== NON-RANKABLE CATEGORY FEEDBACK ===";
            foreach ($nrFeedback as $nrCat) {
                $lines[] = "Category: {$nrCat['category_name']} ({$nrCat['submissions_count']} submissions)";

                foreach ($nrCat['feedback'] as $fb) {
                    $lines[] = "  {$fb['evaluator']}: {$fb['product']} — Score: " . ($fb['score'] ?? 'N/A');
                }

                $lines[] = "";
            }
        }

        // Collect and inject anonymous comments
        $anonymousComments = $this->collectAnonymousComments($workgroup, $session);

        if (!empty($anonymousComments)) {
            $lines[] = "=== ANONYMOUS EVALUATOR COMMENTS ===";
            $groupedByProduct = collect($anonymousComments)->groupBy('product');

            foreach ($groupedByProduct as $productName => $productComments) {
                $lines[] = "Product: {$productName}";

                foreach ($productComments as $c) {
                    $lines[] = "  [{$c['type']}]: {$c['comment']}";
                }

                $lines[] = "";
            }
        }

        $lines[] = "";
        $lines[] = "=== INSTRUCTIONS ===";
        $lines[] = "You are generating a highly detailed, professional DHS SAVER (System Assessment and Validation for Emergency Responders) purchasing recommendation document for the Miami Beach Fire Department Health & Safety Committee.";

        $lines[] = "\nTarget length: 3000-5000 words of substantive analysis";

        $lines[] = "This document will be used to justify a multi-hundred-thousand-dollar capital purchase. It must be thorough, data-driven, and technically rigorous.";

        $lines[] = "\nGenerate the report in clean HTML format with these sections:";
        $lines[] = "1. <h2>Executive Summary</h2> — Overall findings, key recommendation, evaluation scope";

        $lines[] = "\n2. <h2>Vendor Profiles</h2> — For each competing vendor/manufacturer (e.g., Holmatro, TNT Rescue, Hurst, Amkus), provide:";
        $lines[] = "   * Company background and market position in the rescue tool industry";
        $lines[] = "   * Product line overview based on the evaluated tools";
        $lines[] = "   * Cross-reference any vendor spec sheets available in your RAG index (workgroup-specs Vectorize)";
        $lines[] = "   * Key technical differentiators (battery platform, power output, weight, cutting/spreading force)";

        $lines[] = "\n3. <h2>Capability Assessment</h2> (SAVER Dimension 1) — Analyze how well each brand/product performs its intended rescue function";
        $lines[] = "   Include specific capability scores from the data above";
        $lines[] = "   Compare power output, cutting force, spreading distance across brands";
        $lines[] = "   Reference evaluator comments about real-world performance";

        $lines[] = "\n4. <h2>Usability Assessment</h2> (SAVER Dimension 2) — Ergonomic analysis: weight, grip comfort, balance, trigger design";
        $lines[] = "   Training requirements and learning curve for each brand";
        $lines[] = "   Evaluator feedback on ease of operation under stress";

        $lines[] = "\n5. <h2>Affordability Assessment</h2> (SAVER Dimension 3) — Total cost of ownership analysis (tools + batteries + chargers + cases)";
        $lines[] = "   Value proposition: cost per unit of capability";
        $lines[] = "   Battery ecosystem costs (proprietary vs. shared platform)";

        $lines[] = "\n6. <h2>Maintainability Assessment</h2> (SAVER Dimension 4) — Durability indicators and expected service life";
        $lines[] = "   Repair complexity and parts availability";
        $lines[] = "   Warranty terms and manufacturer support";

        $lines[] = "\n7. <h2>Deployability Assessment</h2> (SAVER Dimension 5) — Portability and apparatus compartment compatibility";
        $lines[] = "   Battery interchangeability across tool types";
        $lines[] = "   Setup time from compartment to operational";
        $lines[] = "   Integration with existing MBFD apparatus layout";

        $lines[] = "\n8. <h2>Evaluator Feedback Analysis</h2> — Synthesize the anonymous evaluator comments provided above";
        $lines[] = "   Identify recurring themes (positive and negative) per brand";
        $lines[] = "   Highlight any safety concerns or deal-breakers mentioned";
        $lines[] = "   Note consensus points and areas of disagreement among evaluators";

        $lines[] = "\n9. <h2>Comparative Analysis Table</h2> — Create an HTML <table> comparing all brands across ALL five SAVER dimensions";
        $lines[] = "   Include overall composite score, rank, and recommendation status";
        $lines[] = "   Use color-coded indicators (green for highest, red for lowest in each dimension)";
        $lines[] = "\n10. <h2>Final Purchasing Recommendation</h2> — Ranked recommendations (#1, #2, #3) with detailed justification";
        $lines[] = "    Recommended purchase configuration (which tools, how many, which accessories)";
        $lines[] = "    Risk assessment for each option";
        $lines[] = "    Dissenting considerations (why someone might choose #2 over #1)";

        return implode("\n", $lines);
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
        // Extract anonymous feedback notes from narrative_payload
        $narrative = $submission->narrative_payload ?? [];

        $anonymousNotes = [];

        if (!empty($narrative['strengths'])) {
            $anonymousNotes[] = "Strengths: {$narrative['strengths']}";
        }

        if (!empty($narrative['weaknesses'])) {
            $anonymousNotes[] = "Weaknesses: {$narrative['weaknesses']}";
        }

        if (!empty($narrative['overall_impression'])) {
            $anonymousNotes[] = "Overall Impression: {$narrative['overall_impression']}";
        }

        if (!empty($narrative['additional_comments'])) {
            $anonymousNotes[] = "Additional: {$narrative['additional_comments']}";
        }

        // Include legacy comments if present
        $legacyComments = $submission->comments->pluck('comment')->filter()->values()->toArray();

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
            'anonymousNotes'       => array_merge($anonymousNotes, $legacyComments),
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

    /**
     * Build categories for an "Overall" report spanning ALL sessions in a workgroup.
     * This prevents the fallback-to-Day-1 bug.
     */
    protected function buildCategoriesForOverallReport(Workgroup $workgroup): array
    {
        $sessionIds = $workgroup->sessions()->pluck('id')->toArray();

        if (empty($sessionIds)) {
            return [];
        }

        $products = CandidateProduct::whereIn('workgroup_session_id', $sessionIds)
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
                    'isFinalist'      => $submissions->filter(fn($s) => $s->advance_recommendation === 'yes')->count() >= ceil(max($submissions->count(), 1) / 2),
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

    /**
     * Build overall stats across ALL sessions in a workgroup.
     */
    protected function buildOverallStatsAllSessions(Workgroup $workgroup): array
    {
        $sessionIds = $workgroup->sessions()->pluck('id')->toArray();

        if (empty($sessionIds)) {
            return ['totalProducts' => 0, 'totalEvaluators' => 0, 'totalSubmissions' => 0];
        }

        $products = CandidateProduct::whereIn('workgroup_session_id', $sessionIds)->count();
        $submissions = EvaluationSubmission::whereHas('candidateProduct', fn($q) => $q->whereIn('workgroup_session_id', $sessionIds))
            ->where('status', 'submitted')
            ->whereHas('member', fn($mq) => $mq->where('count_evaluations', true))
            ->count();
        $evaluatorIds = EvaluationSubmission::whereHas('candidateProduct', fn($q) => $q->whereIn('workgroup_session_id', $sessionIds))
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

    /**
     * Collect anonymous evaluator comments for AI context.
     *
     * Strips member names to maintain anonymity. Pulls from:
     * - narrative_payload (strengths, weaknesses, overall_impression)
     * - deal_breaker_note
     * - legacy EvaluationComment records
     */
    protected function collectAnonymousComments(Workgroup $workgroup, ?WorkgroupSession $session): array
    {
        $sessionIds = $session
            ? [$session->id]
            : $workgroup->sessions()->pluck('id')->toArray();

        if (empty($sessionIds)) {
            return [];
        }

        $submissions = EvaluationSubmission::whereHas('candidateProduct', fn($q) => $q->whereIn('workgroup_session_id', $sessionIds))
            ->where('status', 'submitted')
            ->whereHas('member', fn($mq) => $mq->where('count_evaluations', true))
            ->with(['candidateProduct', 'comments'])
            ->get();

        $comments = [];

        foreach ($submissions as $submission) {
            $productName = $submission->candidateProduct?->name ?? 'Unknown Product';

            // Extract from narrative_payload
            $narrative = $submission->narrative_payload ?? [];

            foreach (['strengths', 'weaknesses', 'overall_impression', 'additional_comments'] as $field) {
                if (!empty($narrative[$field])) {
                    $comments[] = [
                        'product' => $productName,
                        'type' => str_replace('_', ' ', $field),
                        'comment' => $narrative[$field],
                    ];
                }
            }

            // Deal breaker notes
            if ($submission->has_deal_breaker && !empty($submission->deal_breaker_note)) {
                $comments[] = [
                    'product' => $productName,
                    'type' => 'deal breaker',
                    'comment' => $submission->deal_breaker_note,
                ];
            }

            // Legacy comments
            foreach ($submission->comments as $comment) {
                if (!empty($comment->comment)) {
                    $comments[] = [
                        'product' => $productName,
                        'type' => 'evaluator comment',
                        'comment' => $comment->comment,
                    ];
                }
            }
        }

        return $comments;
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
