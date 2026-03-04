<?php

namespace App\Listeners;

use App\Events\EvaluationSubmitted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sends anonymous evaluation text to the Workgroup AI Worker
 * for continuous RAG analysis when an evaluation is submitted.
 */
class SendEvaluationToAiWorker implements ShouldQueue
{
    public $tries = 2;
    public $backoff = 30;

    public function handle(EvaluationSubmitted $event): void
    {
        $submission = $event->submission;
        $workerUrl = config('services.workgroup_ai.url');

        if (!$workerUrl) {
            Log::info('SendEvaluationToAiWorker: Worker URL not configured, skipping');
            return;
        }

        // Build anonymous evaluation text
        $narratives = $submission->narrative_payload ?? [];
        $evaluationText = collect($narratives)
            ->filter(fn($v) => is_string($v) && !empty($v))
            ->map(fn($v, $k) => ucfirst(str_replace('_', ' ', $k)) . ": {$v}")
            ->implode("\n");

        if (empty($evaluationText)) {
            $evaluationText = "Score: {$submission->overall_score}/100. Recommendation: {$submission->advance_recommendation}.";
        }

        try {
            $response = Http::timeout(15)->post($workerUrl . '/analyze', [
                'evaluation_text' => $evaluationText,
                'session_id' => $submission->session_id,
                'product_name' => $submission->candidateProduct?->name,
            ]);

            if ($response->successful()) {
                Log::info('SendEvaluationToAiWorker: Analysis sent', [
                    'submission_id' => $submission->id,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('SendEvaluationToAiWorker: Failed to reach worker', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
