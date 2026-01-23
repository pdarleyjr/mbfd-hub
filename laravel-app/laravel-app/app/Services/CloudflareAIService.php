<?php

namespace App\Services;

use App\Models\AIAnalysisLog;
use App\Models\CapitalProject;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class CloudflareAIService
{
    protected string $accountId;
    protected string $apiToken;
    protected array $config;
    protected bool $enabled;

    public function __construct()
    {
        $this->config = config('cloudflare.ai', []);
        $this->accountId = $this->config['account_id'] ?? '';
        $this->apiToken = $this->config['api_token'] ?? '';
        $this->enabled = $this->config['enabled'] ?? false;
    }

    public function isEnabled(): bool
    {
        return $this->enabled && !empty($this->accountId) && !empty($this->apiToken);
    }

    public function runModel(string $model, array $messages, array $options = []): array
    {
        if (!$this->isEnabled()) {
            throw new \Exception('Cloudflare AI service is not enabled or not properly configured.');
        }

        if (!$this->checkRateLimit()) {
            throw new \Exception('Daily rate limit exceeded. Please try again tomorrow.');
        }

        $url = "https://api.cloudflare.com/client/v4/accounts/{$this->accountId}/ai/run/{$model}";
        
        $attempts = 0;
        $maxAttempts = $this->config['rate_limit']['retry_attempts'] ?? 3;
        $retryDelay = $this->config['rate_limit']['retry_delay'] ?? 1000;
        $lastException = null;

        while ($attempts < $maxAttempts) {
            $attempts++;

            try {
                $response = Http::withHeaders([
                    'Authorization' => "Bearer {$this->apiToken}",
                    'Content-Type' => 'application/json',
                ])
                ->timeout($this->config['timeouts']['request'] ?? 30)
                ->connectTimeout($this->config['timeouts']['connect'] ?? 10)
                ->post($url, array_merge([
                    'messages' => $messages,
                ], $options));

                if ($response->successful()) {
                    return $response->json();
                }

                if ($response->status() === 429) {
                    $retryAfter = $response->header('Retry-After', $retryDelay / 1000);
                    $waitTime = is_numeric($retryAfter) ? (int)$retryAfter * 1000 : $retryDelay;
                    if ($attempts < $maxAttempts) {
                        usleep($waitTime * 1000);
                        continue;
                    }
                }

                throw new \Exception("API request failed: {$response->status()}");

            } catch (\Exception $e) {
                $lastException = $e;
                if ($attempts < $maxAttempts) {
                    $waitTime = $retryDelay * pow(2, $attempts - 1);
                    usleep($waitTime * 1000);
                    continue;
                }
            }
        }

        throw new \Exception("Failed after {$maxAttempts} attempts: " . $lastException->getMessage());
    }

    public function checkRateLimit(): bool
    {
        $cacheKey = $this->config['rate_limit']['cache_key'] ?? 'cloudflare_ai_requests';
        $dailyLimit = $this->config['rate_limit']['daily_neurons'] ?? 9900;
        $currentCount = Cache::get($cacheKey, 0);
        
        if ($currentCount >= $dailyLimit) {
            return false;
        }

        $now = Carbon::now('UTC');
        $midnight = $now->copy()->endOfDay();
        $secondsUntilMidnight = $midnight->diffInSeconds($now);
        Cache::put($cacheKey, $currentCount + 1, $secondsUntilMidnight);
        
        return true;
    }

    public function getRateLimitUsage(): array
    {
        $cacheKey = $this->config['rate_limit']['cache_key'] ?? 'cloudflare_ai_requests';
        $dailyLimit = $this->config['rate_limit']['daily_neurons'] ?? 9900;
        $currentCount = Cache::get($cacheKey, 0);
        
        return [
            'used' => $currentCount,
            'limit' => $dailyLimit,
            'remaining' => max(0, $dailyLimit - $currentCount),
        ];
    }

    /**
     * Generate compact bullet-point admin dashboard summary
     */
    public function generateAdminBulletSummary(array $metrics): array
    {
        $startTime = now();

        try {
            $systemPrompt = "You are a fire department operations assistant. Generate ONLY valid JSON with brief bullet points (max 10 words each). No markdown, no paragraphs.";

            $userPrompt = "Based on this operational data, create a compact summary:\n\n" .
                json_encode($metrics, JSON_PRETTY_PRINT) . "\n\n" .
                "Respond with ONLY this JSON structure (no other text):\n" .
                "{\n" .
                "  \"vehicle_inventory\": [\"bullet1\", \"bullet2\"],\n" .
                "  \"out_of_service\": [\"bullet1\"],\n" .
                "  \"apparatus_issues\": [\"bullet1\"],\n" .
                "  \"equipment_alerts\": [\"bullet1\"],\n" .
                "  \"capital_projects\": [\"bullet1\"]\n" .
                "}\n" .
                "Rules: 3-10 words per bullet. Use empty arrays [] if no items.";

            $messages = [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ];

            $model = $this->config['models']['default'] ?? '@cf/meta/llama-3-8b-instruct';
            $response = $this->runModel($model, $messages);

            $result = $this->parseAIResponse($response);

            AIAnalysisLog::create([
                'type' => 'admin_bullet_summary',
                'projects_analyzed' => 0,
                'result' => $result,
                'executed_at' => $startTime,
            ]);

            return $result;

        } catch (\Exception $e) {
            Log::error('Admin bullet summary generation failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    public function prioritizeProjects(Collection $projects): array
    {
        $startTime = now();
        
        try {
            $projectData = $projects->map(function ($project) {
                return [
                    'id' => $project->id,
                    'name' => $project->name,
                    'budget' => $project->budget_amount,
                    'status' => $project->status->value ?? $project->status,
                    'priority' => $project->priority->value ?? $project->priority,
                    'target_completion' => $project->target_completion_date?->format('Y-m-d'),
                    'is_overdue' => $project->is_overdue ?? false,
                ];
            })->toArray();

            $systemPrompt = "You are an AI assistant specialized in capital project prioritization.";

            $userPrompt = "Analyze these capital projects:\n\n" .
                json_encode($projectData, JSON_PRETTY_PRINT) . "\n\n" .
                "Provide JSON response: {\"priorities\": [{\"id\": X, \"rank\": Y, \"score\": Z, \"risk_level\": \"low|medium|high\"}], \"summary\": \"brief\"}";

            $messages = [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ];

            $model = $this->config['models']['default'] ?? '@cf/meta/llama-3-8b-instruct';
            $response = $this->runModel($model, $messages);
            $result = $this->parseAIResponse($response);
            
            AIAnalysisLog::create([
                'type' => 'project_prioritization',
                'projects_analyzed' => $projects->count(),
                'result' => $result,
                'executed_at' => $startTime,
            ]);

            return $result;

        } catch (\Exception $e) {
            Log::error('Project prioritization failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    public function analyzeProject(CapitalProject $project): array
    {
        $startTime = now();
        
        try {
            $projectData = [
                'id' => $project->id,
                'name' => $project->name,
                'description' => $project->description,
                'budget' => $project->budget_amount,
                'status' => $project->status->value ?? $project->status,
                'priority' => $project->priority->value ?? $project->priority,
                'target_completion' => $project->target_completion_date?->format('Y-m-d'),
                'is_overdue' => $project->is_overdue ?? false,
            ];

            $systemPrompt = "You are an AI assistant for capital project analysis.";

            $userPrompt = "Analyze this project:\n\n" .
                json_encode($projectData, JSON_PRETTY_PRINT) . "\n\n" .
                "Respond as JSON: {\"score\": 1-100, \"risk_level\": \"low|medium|high\", \"recommendations\": [\"item1\"]}";

            $messages = [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ];

            $model = $this->config['models']['default'] ?? '@cf/meta/llama-3-8b-instruct';
            $response = $this->runModel($model, $messages);
            $result = $this->parseAIResponse($response);
            
            AIAnalysisLog::create([
                'type' => 'single_project_analysis',
                'projects_analyzed' => 1,
                'result' => array_merge(['project_id' => $project->id], $result),
                'executed_at' => $startTime,
            ]);

            return $result;

        } catch (\Exception $e) {
            Log::error('Single project analysis failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    public function generateWeeklySummary(Collection $projects): string
    {
        $startTime = now();
        
        try {
            $projectData = $projects->map(function ($project) {
                return [
                    'name' => $project->name,
                    'status' => $project->status->value ?? $project->status,
                    'priority' => $project->priority->value ?? $project->priority,
                    'budget' => $project->budget_amount,
                    'target_completion' => $project->target_completion_date?->format('Y-m-d'),
                    'is_overdue' => $project->is_overdue ?? false,
                ];
            })->toArray();

            $systemPrompt = "You are an AI assistant creating executive summaries. Be concise.";

            $userPrompt = "Create a weekly summary for these projects:\n\n" .
                json_encode($projectData, JSON_PRETTY_PRINT) . "\n\n" .
                "Format as brief professional summary (2-3 paragraphs max).";

            $messages = [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ];

            $model = $this->config['models']['default'] ?? '@cf/meta/llama-3-8b-instruct';
            $response = $this->runModel($model, $messages);
            $summary = $this->extractTextFromResponse($response);
            
            AIAnalysisLog::create([
                'type' => 'weekly_summary',
                'projects_analyzed' => $projects->count(),
                'result' => ['summary' => $summary],
                'executed_at' => $startTime,
            ]);

            return $summary;

        } catch (\Exception $e) {
            Log::error('Weekly summary generation failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    protected function parseAIResponse(array $response): array
    {
        $text = $response['result']['response'] ?? '';
        
        if (preg_match('/\{[\s\S]*\}/', $text, $matches)) {
            $json = json_decode($matches[0], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $json;
            }
        }
        
        return ['raw_response' => $text];
    }

    protected function extractTextFromResponse(array $response): string
    {
        return $response['result']['response'] ?? '';
    }

    public function canMakeRequest(): bool
    {
        return $this->checkRateLimit();
    }

    public function chat(string $message, array $metrics = []): array
    {
        $response = Http::withHeaders([
            'x-api-secret' => config('cloudflare.worker_api_secret')
        ])->timeout(10)->post(config('cloudflare.worker_url').'/ai/inventory-chat', [
            'message' => $message,
            'inventory_context' => $metrics
        ]);
        
        if ($response->failed()) {
            Log::error('Cloudflare AI chat failed', ['response' => $response->body()]);
            throw new \Exception($response->json()['message'] ?? 'AI chat failed');
        }
        
        $result = $response->json();
        return [
            'message' => $result['assistant_message'] ?? '',
            'actions' => $result['proposed_actions'] ?? []
        ];
    }
}
