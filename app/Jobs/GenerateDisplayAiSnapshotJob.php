<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\CloudflareAIService;
use App\Services\Display\DisplayAiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Generates the descriptive-only command-display AI brief in the background.
 *
 * Dispatched by {@see DisplayAiService::ensureFresh()} ONLY when the sanitised
 * snapshot fingerprint changed and no job is already pending. Running async
 * keeps the display API request path free of any LLM latency and only loads
 * the local model when there is genuinely new information to describe.
 *
 * On any failure the last-good cached brief is left untouched (the endpoint
 * serves it as "stale").
 */
class GenerateDisplayAiSnapshotJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 180;

    /**
     * @param  array<string, mixed>  $sanitizedSnapshot
     */
    public function __construct(
        public string $expectedFingerprint,
        public array $sanitizedSnapshot
    ) {}

    public function handle(CloudflareAIService $ai): void
    {
        try {
            $model = (string) config('cloudflare.ai.local.model', 'qwen3.6:35b');
            $messages = $this->buildMessages($this->sanitizedSnapshot);

            $response = $ai->runModel($model, $messages, [
                'temperature' => 0.2,
                'max_tokens' => 1500,
            ]);

            $text = (string) ($response['result']['response'] ?? '');
            $parsed = $this->parseJson($text);

            if ($parsed === null) {
                Log::warning('[Display AI] model returned unparseable JSON; keeping last-good brief');

                return;
            }

            $brief = $this->validateSchema($parsed);
            $brief = DisplayAiService::enforceDescriptive($brief);

            Cache::put(
                DisplayAiService::CACHE_KEY,
                [
                    'fp' => $this->expectedFingerprint,
                    'brief' => $brief,
                    'at' => now()->toISOString(),
                ],
                DisplayAiService::CACHE_TTL
            );
        } catch (\Throwable $e) {
            // Leave the previous (stale) brief in place; just log.
            Log::warning('[Display AI] brief generation failed', ['error' => $e->getMessage()]);
        } finally {
            Cache::forget(DisplayAiService::PENDING_KEY);
        }
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return list<array{role: string, content: string}>
     */
    private function buildMessages(array $snapshot): array
    {
        $system = 'You are a briefing analyst for the Miami Beach Fire Department '
            .'operational dashboard. Describe ONLY what the data shows. '
            .'FORBIDDEN: recommendations, instructions, the words should/must/recommend/need to, '
            .'personnel judgments, fabricated facts. Ground every sentence in the provided JSON '
            .'snapshot. If data is missing or stale, say so in data_gaps. '
            .'Output ONLY valid JSON, no markdown.';

        $schema = <<<'JSON'
{
  "mode": "descriptive",
  "briefing": "string",
  "station_summaries": [{ "station": "string", "summary": "string" }],
  "active_run_summary": "string",
  "camera_source_summary": "string",
  "data_gaps": ["string"],
  "confidence": 0.0,
  "generated_at": "ISO-8601",
  "model": "qwen3.6:35b"
}
JSON;

        $user = "Operational snapshot (JSON):\n"
            .json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            ."\n\nRespond with ONLY this exact JSON shape (no other text):\n"
            .$schema;

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseJson(string $text): ?array
    {
        $trimmed = trim($text);

        // Strip a ```json fence if the model added one despite instructions.
        if (str_starts_with($trimmed, '```')) {
            $trimmed = preg_replace('/^```[a-zA-Z]*\s*|\s*```$/', '', $trimmed) ?? $trimmed;
        }

        $decoded = json_decode($trimmed, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // Fallback: extract the first balanced-looking JSON object.
        if (preg_match('/\{[\s\S]*\}/', $text, $matches)) {
            $decoded = json_decode($matches[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * Coerce the parsed payload into the exact descriptive schema, dropping any
     * extra keys and filling missing ones with safe defaults.
     *
     * @param  array<string, mixed>  $parsed
     * @return array<string, mixed>
     */
    private function validateSchema(array $parsed): array
    {
        $stationSummaries = [];
        if (isset($parsed['station_summaries']) && is_array($parsed['station_summaries'])) {
            foreach ($parsed['station_summaries'] as $entry) {
                if (! is_array($entry)) {
                    continue;
                }
                $stationSummaries[] = [
                    'station' => (string) ($entry['station'] ?? 'Unknown'),
                    'summary' => (string) ($entry['summary'] ?? ''),
                ];
            }
        }

        $dataGaps = [];
        if (isset($parsed['data_gaps']) && is_array($parsed['data_gaps'])) {
            foreach ($parsed['data_gaps'] as $gap) {
                $dataGaps[] = (string) $gap;
            }
        }

        $confidence = isset($parsed['confidence']) && is_numeric($parsed['confidence'])
            ? (float) $parsed['confidence']
            : 0.5;
        $confidence = round(max(0.0, min(1.0, $confidence)), 2);

        return [
            'mode' => 'descriptive',
            'briefing' => (string) ($parsed['briefing'] ?? ''),
            'station_summaries' => $stationSummaries,
            'active_run_summary' => (string) ($parsed['active_run_summary'] ?? ''),
            'camera_source_summary' => (string) ($parsed['camera_source_summary'] ?? ''),
            'data_gaps' => $dataGaps,
            'confidence' => $confidence,
            'generated_at' => now()->toISOString(),
            'model' => (string) config('cloudflare.ai.local.model', 'qwen3.6:35b'),
        ];
    }
}
