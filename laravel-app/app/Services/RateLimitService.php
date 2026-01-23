<?php

namespace App\Services;

use App\Models\AIAnalysisLog;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class RateLimitService
{
    protected string $cacheKey;
    protected int $dailyLimit;

    public function __construct()
    {
        $config = config('cloudflare.ai.rate_limit', []);
        $this->cacheKey = $config['cache_key'] ?? 'cloudflare_ai_requests';
        $this->dailyLimit = $config['daily_neurons'] ?? 9900;
    }

    /**
     * Check if we can make another API request
     */
    public function canMakeRequest(): bool
    {
        $currentCount = $this->getCurrentCount();
        return $currentCount < $this->dailyLimit;
    }

    /**
     * Increment the request counter
     */
    public function incrementCount(): void
    {
        $currentCount = $this->getCurrentCount();
        $newCount = $currentCount + 1;
        
        // Calculate seconds until midnight UTC
        $now = Carbon::now('UTC');
        $midnight = $now->copy()->endOfDay();
        $secondsUntilMidnight = $midnight->diffInSeconds($now);
        
        // Store with expiration at midnight UTC
        Cache::put($this->cacheKey, $newCount, $secondsUntilMidnight);
    }

    /**
     * Get current request count
     */
    public function getCurrentCount(): int
    {
        return Cache::get($this->cacheKey, 0);
    }

    /**
     * Get remaining requests for today
     */
    public function getRemainingRequests(): int
    {
        $current = $this->getCurrentCount();
        return max(0, $this->dailyLimit - $current);
    }

    /**
     * Get usage percentage
     */
    public function getUsagePercentage(): float
    {
        $current = $this->getCurrentCount();
        return round(($current / $this->dailyLimit) * 100, 2);
    }

    /**
     * Get detailed usage statistics
     */
    public function getUsageStats(): array
    {
        $current = $this->getCurrentCount();
        $remaining = $this->getRemainingRequests();
        $percentage = $this->getUsagePercentage();
        
        // Calculate time until reset
        $now = Carbon::now('UTC');
        $midnight = $now->copy()->endOfDay();
        $hoursUntilReset = $midnight->diffInHours($now);
        $minutesUntilReset = $midnight->diffInMinutes($now) % 60;
        
        return [
            'used' => $current,
            'limit' => $this->dailyLimit,
            'remaining' => $remaining,
            'percentage' => $percentage,
            'reset_at' => $midnight->toIso8601String(),
            'hours_until_reset' => $hoursUntilReset,
            'minutes_until_reset' => $minutesUntilReset,
            'status' => $this->getUsageStatus($percentage),
        ];
    }

    /**
     * Get usage status based on percentage
     */
    protected function getUsageStatus(float $percentage): string
    {
        if ($percentage >= 100) {
            return 'exceeded';
        } elseif ($percentage >= 90) {
            return 'critical';
        } elseif ($percentage >= 75) {
            return 'warning';
        } elseif ($percentage >= 50) {
            return 'elevated';
        } else {
            return 'normal';
        }
    }

    /**
     * Reset the counter (for testing or manual reset)
     */
    public function resetCount(): void
    {
        Cache::forget($this->cacheKey);
    }

    /**
     * Get usage history from AIAnalysisLog
     */
    public function getUsageHistory(int $days = 7): array
    {
        $startDate = Carbon::now()->subDays($days);
        
        $logs = AIAnalysisLog::where('executed_at', '>=', $startDate)
            ->orderBy('executed_at', 'desc')
            ->get();
        
        // Group by date
        $history = [];
        foreach ($logs as $log) {
            $date = $log->executed_at->format('Y-m-d');
            if (!isset($history[$date])) {
                $history[$date] = [
                    'date' => $date,
                    'count' => 0,
                    'types' => [],
                ];
            }
            
            $history[$date]['count']++;
            $type = $log->type;
            if (!isset($history[$date]['types'][$type])) {
                $history[$date]['types'][$type] = 0;
            }
            $history[$date]['types'][$type]++;
        }
        
        return array_values($history);
    }

    /**
     * Check if we're approaching the daily limit
     */
    public function isApproachingLimit(int $threshold = 90): bool
    {
        return $this->getUsagePercentage() >= $threshold;
    }

    /**
     * Get a warning message if approaching limit
     */
    public function getWarningMessage(): ?string
    {
        $percentage = $this->getUsagePercentage();
        $remaining = $this->getRemainingRequests();
        
        if ($percentage >= 100) {
            return "Daily API limit exceeded. No more requests can be made today.";
        } elseif ($percentage >= 90) {
            return "Warning: Only {$remaining} API requests remaining today ({$percentage}% used).";
        } elseif ($percentage >= 75) {
            return "Notice: {$remaining} API requests remaining today ({$percentage}% used).";
        }
        
        return null;
    }
}
