<?php

namespace App\Http\Controllers\Workgroup;

use App\Models\CandidateProduct;
use App\Models\EvaluationSubmission;
use App\Models\WorkgroupMember;
use App\Models\WorkgroupSession;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;

/**
 * Generates executive PDF reports for the Health and Safety Committee.
 * Uses the Workgroup AI Worker for RAG-enhanced summaries,
 * with a graceful local fallback.
 */
class ExecutiveReportController extends Controller
{
    public function generate(Request $request)
    {
        $user = Auth::user();
        $member = WorkgroupMember::where('user_id', $user->id)->where('is_active', true)->first();
        abort_unless($member && in_array($member->role, ['admin', 'facilitator']), 403);

        $session = WorkgroupSession::active()->first();
        abort_unless($session, 404, 'No active session found.');

        // Gather product data
        $products = CandidateProduct::where('workgroup_session_id', $session->id)
            ->whereHas('category', fn($q) => $q->where('is_rankable', true))
            ->get()
            ->map(function ($product) {
                $avgScore = EvaluationSubmission::where('candidate_product_id', $product->id)
                    ->where('status', 'submitted')
                    ->whereNotNull('overall_score')
                    ->avg('overall_score');
                $responseCount = EvaluationSubmission::where('candidate_product_id', $product->id)
                    ->where('status', 'submitted')->count();

                return [
                    'name' => $product->name,
                    'manufacturer' => $product->manufacturer ?? 'N/A',
                    'model' => $product->model ?? 'N/A',
                    'category' => $product->category?->name ?? 'N/A',
                    'avg_score' => $avgScore ? number_format($avgScore, 1) : 'N/A',
                    'response_count' => $responseCount,
                ];
            })
            ->sortByDesc('avg_score')
            ->values()
            ->toArray();

        // Build scores summary
        $scoresSummary = collect($products)->map(fn($p) =>
            "{$p['name']} ({$p['manufacturer']}): {$p['avg_score']}/100, {$p['response_count']} evaluations"
        )->implode("\n");

        // Try AI-generated report
        $aiReport = null;
        $workerUrl = config('services.workgroup_ai.url');
        if ($workerUrl) {
            try {
                $response = Http::timeout(30)->post($workerUrl . '/executive-report', [
                    'scores_summary' => $scoresSummary,
                    'session_name' => $session->name,
                    'products' => $products,
                ]);
                if ($response->successful()) {
                    $aiReport = $response->json('report');
                }
            } catch (\Throwable $e) {
                // Fall back to local
            }
        }

        // Local fallback report
        if (!$aiReport) {
            $aiReport = $this->generateLocalReport($session, $products, $scoresSummary);
        }

        // Generate PDF
        $pdf = Pdf::loadView('pdf.executive-report', [
            'session' => $session,
            'products' => $products,
            'aiReport' => $aiReport,
            'generatedAt' => now()->format('F j, Y \a\t g:i A'),
        ]);

        $filename = 'MBFD_Executive_Report_' . str_replace(' ', '_', $session->name) . '_' . now()->format('Y-m-d') . '.pdf';

        return $pdf->download($filename);
    }

    protected function generateLocalReport($session, array $products, string $scoresSummary): string
    {
        $report = "EXECUTIVE SUMMARY\n\n";
        $report .= "This report summarizes the evaluation results for the {$session->name} session conducted by the Miami Beach Fire Department Workgroup.\n\n";

        $report .= "PRODUCTS EVALUATED\n\n";
        foreach ($products as $i => $p) {
            $rank = $i + 1;
            $report .= "{$rank}. {$p['name']} ({$p['manufacturer']} {$p['model']})\n";
            $report .= "   Category: {$p['category']} | Average Score: {$p['avg_score']}/100 | Evaluations: {$p['response_count']}\n\n";
        }

        $report .= "METHODOLOGY\n\n";
        $report .= "Products were evaluated using the MBFD Universal Evaluation Rubric covering capability, usability, affordability, maintainability, and deployability criteria.\n\n";

        if (!empty($products)) {
            $top = $products[0];
            $report .= "RECOMMENDATION\n\n";
            $report .= "Based on the evaluation data, {$top['name']} ({$top['manufacturer']}) received the highest average score of {$top['avg_score']}/100 and is recommended for advancement to the Health and Safety Committee for final review.\n";
        }

        return $report;
    }
}
