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

    /**
     * Check if the service is properly configured and enabled
     */
    public function isEnabled(): bool
    {
        return $this->enabled && !empty($this->accountId) && !empty($this->apiToken);
    }

    /**
     * Run a Cloudflare AI model with retry logic and error handling
     */
    public function runModel(string $model, array $messages, array $options = []): array
    {
        if (!$this->isEnabled()) {
            throw new \Exception('Cloudflare AI service is not enabled or not properly configured. Please set CLOUDFLARE_ACCOUNT_ID and CLOUDFLARE_API_TOKEN in your .env file.');
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
                    $data = $response->json();
                    
                    Log::info('Cloudflare AI API call successful', [
                        'model' => $model,
                        'attempt' => $attempts,
                        'response_size' => strlen(json_encode($data)),
                    ]);

                    return $data;
                }

                // Handle rate limiting (429)
                if ($response->status() === 429) {
                    $retryAfter = $response->header('Retry-After', $retryDelay / 1000);
                    $waitTime = is_numeric($retryAfter) ? (int)$retryAfter * 1000 : $retryDelay;
                    
                    Log::warning('Rate limited by Cloudflare API', [
                        'retry_after' => $retryAfter,
                        'attempt' => $attempts,
                    ]);

                    if ($attempts < $maxAttempts) {
                        usleep($waitTime * 1000); // Convert ms to microseconds
                        continue;
                    }
                }

                throw new \Exception("API request failed: {$response->status()} - {$response->body()}");

            } catch (\Exception $e) {
                $lastException = $e;
                
                Log::error('Cloudflare AI API call failed', [
                    'model' => $model,
                    'attempt' => $attempts,
                    'error' => $e->getMessage(),
                ]);

                if ($attempts < $maxAttempts) {
                    // Exponential backoff
                    $waitTime = $retryDelay * pow(2, $attempts - 1);
                    usleep($waitTime * 1000);
                    continue;
                }
            }
        }

        throw new \Exception("Failed after {$maxAttempts} attempts: " . $lastException->getMessage());
    }

    /**
     * Check and update rate limit counter
     */
    public function checkRateLimit(): bool
    {
        $cacheKey = $this->config['rate_limit']['cache_key'] ?? 'cloudflare_ai_requests';
        $dailyLimit = $this->config['rate_limit']['daily_neurons'] ?? 9900;
        
        // Get current count
        $currentCount = Cache::get($cacheKey, 0);
        
        if ($currentCount >= $dailyLimit) {
            Log::warning('Daily rate limit exceeded', [
                'current_count' => $currentCount,
                'limit' => $dailyLimit,
            ]);
            return false;
        }

        // Increment counter
        $newCount = $currentCount + 1;
        
        // Calculate seconds until midnight UTC
        $now = Carbon::now('UTC');
        $midnight = $now->copy()->endOfDay();
        $secondsUntilMidnight = $midnight->diffInSeconds($now);
        
        // Store with expiration at midnight UTC
        Cache::put($cacheKey, $newCount, $secondsUntilMidnight);
        
        return true;
    }

    /**
     * Get current rate limit usage
     */
    public function getRateLimitUsage(): array
    {
        $cacheKey = $this->config['rate_limit']['cache_key'] ?? 'cloudflare_ai_requests';
        $dailyLimit = $this->config['rate_limit']['daily_neurons'] ?? 9900;
        $currentCount = Cache::get($cacheKey, 0);
        
        return [
            'used' => $currentCount,
            'limit' => $dailyLimit,
            'remaining' => max(0, $dailyLimit - $currentCount),
            'percentage' => round(($currentCount / $dailyLimit) * 100, 2),
        ];
    }

    /**
     * Prioritize multiple projects using AI analysis
     */
    public function prioritizeProjects(Collection $projects): array
    {
        $startTime = now();
        
        try {
            // Build project data for AI analysis
            $projectData = $projects->map(function ($project) {
                return [
                    'id' => $project->id,
                    'number' => $project->project_number,
                    'name' => $project->name,
                    'budget' => $project->budget_amount,
                    'status' => $project->status->value ?? $project->status,
                    'priority' => $project->priority->value ?? $project->priority,
                    'start_date' => $project->start_date?->format('Y-m-d'),
                    'target_completion' => $project->target_completion_date?->format('Y-m-d'),
                    'days_until_target' => $project->target_completion_date ? 
                        now()->diffInDays($project->target_completion_date, false) : null,
                    'is_overdue' => $project->is_overdue ?? false,
                    'completion_percentage' => $project->completion_percentage ?? 0,
                ];
            })->toArray();

            $systemPrompt = "You are an AI assistant specialized in capital project management and prioritization. Analyze projects considering budget urgency, timeline constraints, current status, priority levels, and project type. Provide strategic recommendations.";

            $userPrompt = "Analyze these capital projects and provide prioritization recommendations:\n\n" .
                json_encode($projectData, JSON_PRETTY_PRINT) . "\n\n" .
                "Provide your response as a JSON array with the following structure:\n" .
                "{\n" .
                "  \"priorities\": [\n" .
                "    {\n" .
                "      \"id\": project_id,\n" .
                "      \"rank\": 1-X,\n" .
                "      \"score\": 1-100,\n" .
                "      \"reasoning\": \"brief explanation\",\n" .
                "      \"risk_level\": \"low|medium|high\",\n" .
                "      \"recommended_action\": \"immediate|soon|monitor\"\n" .
                "    }\n" .
                "  ],\n" .
                "  \"summary\": \"overall portfolio analysis\"\n" .
                "}";

            $messages = [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ];

            $model = $this->config['models']['default'] ?? '@cf/meta/llama-3-8b-instruct';
            $response = $this->runModel($model, $messages);

            // Parse the AI response
            $result = $this->parseAIResponse($response);
            
            // Log the analysis
            AIAnalysisLog::create([
                'type' => 'project_prioritization',
                'projects_analyzed' => $projects->count(),
                'result' => $result,
                'executed_at' => $startTime,
            ]);

            return $result;

        } catch (\Exception $e) {
            Log::error('Project prioritization failed', [
                'error' => $e->getMessage(),
                'project_count' => $projects->count(),
            ]);

            // Log the failed attempt
            AIAnalysisLog::create([
                'type' => 'project_prioritization',
                'projects_analyzed' => $projects->count(),
                'result' => ['error' => $e->getMessage()],
                'executed_at' => $startTime,
            ]);

            throw $e;
        }
    }

    /**
     * Analyze a single project
     */
    public function analyzeProject(CapitalProject $project): array
    {
        $startTime = now();
        
        try {
            $projectData = [
                'id' => $project->id,
                'number' => $project->project_number,
                'name' => $project->name,
                'description' => $project->description,
                'budget' => $project->budget_amount,
                'status' => $project->status->value ?? $project->status,
                'priority' => $project->priority->value ?? $project->priority,
                'start_date' => $project->start_date?->format('Y-m-d'),
                'target_completion' => $project->target_completion_date?->format('Y-m-d'),
                'is_overdue' => $project->is_overdue ?? false,
                'completion_percentage' => $project->completion_percentage ?? 0,
            ];

            $systemPrompt = "You are an AI assistant specialized in capital project analysis. Provide detailed risk assessment, timeline analysis, and actionable recommendations.";

            $userPrompt = "Analyze this capital project in detail:\n\n" .
                json_encode($projectData, JSON_PRETTY_PRINT) . "\n\n" .
                "Provide your response as JSON:\n" .
                "{\n" .
                "  \"rank\": recommended_priority_rank,\n" .
                "  \"score\": 1-100,\n" .
                "  \"reasoning\": \"detailed analysis\",\n" .
                "  \"risk_level\": \"low|medium|high\",\n" .
                "  \"timeline_assessment\": \"on_track|at_risk|delayed\",\n" .
                "  \"budget_assessment\": \"analysis of budget adequacy\",\n" .
                "  \"recommendations\": [\"action1\", \"action2\"],\n" .
                "  \"key_concerns\": [\"concern1\", \"concern2\"]\n" .
                "}";

            $messages = [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ];

            $model = $this->config['models']['default'] ?? '@cf/meta/llama-3-8b-instruct';
            $response = $this->runModel($model, $messages);

            $result = $this->parseAIResponse($response);
            
            // Log the analysis
            AIAnalysisLog::create([
                'type' => 'single_project_analysis',
                'projects_analyzed' => 1,
                'result' => array_merge(['project_id' => $project->id], $result),
                'executed_at' => $startTime,
            ]);

            return $result;

        } catch (\Exception $e) {
            Log::error('Single project analysis failed', [
                'project_id' => $project->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Generate weekly summary of project portfolio
     */
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
                    'completion_percentage' => $project->completion_percentage ?? 0,
                ];
            })->toArray();

            $systemPrompt = "You are an AI assistant that creates executive summaries for capital project portfolios. Be concise and focus on critical information that requires attention.";

            $userPrompt = "Create a weekly executive summary for these capital projects:\n\n" .
                json_encode($projectData, JSON_PRETTY_PRINT) . "\n\n" .
                "Include:\n" .
                "1. Overall portfolio status\n" .
                "2. Critical items requiring immediate attention\n" .
                "3. Projects at risk\n" .
                "4. Key achievements this week\n" .
                "5. Upcoming milestones\n\n" .
                "Format as a brief professional email summary (3-5 paragraphs).";

            $messages = [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ];

            $model = $this->config['models']['default'] ?? '@cf/meta/llama-3-8b-instruct';
            $response = $this->runModel($model, $messages);

            $summary = $this->extractTextFromResponse($response);
            
            // Log the analysis
            AIAnalysisLog::create([
                'type' => 'weekly_summary',
                'projects_analyzed' => $projects->count(),
                'result' => ['summary' => $summary],
                'executed_at' => $startTime,
            ]);

            return $summary;

        } catch (\Exception $e) {
            Log::error('Weekly summary generation failed', [
                'error' => $e->getMessage(),
                'project_count' => $projects->count(),
            ]);

            throw $e;
        }
    }

    /**
     * Parse AI response and extract JSON data
     */
    protected function parseAIResponse(array $response): array
    {
        // Cloudflare AI response structure: {success: true, result: {response: "..."}}
        $text = $response['result']['response'] ?? '';
        
        // Try to extract JSON from the response
        if (preg_match('/\{[\s\S]*\}/', $text, $matches)) {
            $json = json_decode($matches[0], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $json;
            }
        }
        
        // Fallback: return raw text in a structured format
        return ['raw_response' => $text];
    }

    /**
     * Extract plain text from AI response
     */
    protected function extractTextFromResponse(array $response): string
    {
        return $response['result']['response'] ?? '';
    }

    /**
     * Check if we can make an API request (within rate limits)
     */
    public function canMakeRequest(): bool
    {
        return $this->checkRateLimit();
    }
}