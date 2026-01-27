<?php

namespace App\Models;

use App\Enums\ProjectPriority;
use App\Enums\ProjectStatus;
use App\Services\CloudflareAIService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CapitalProject extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_number',
        'name',
        'description',
        'budget_amount',
        'status',
        'priority',
        'start_date',
        'target_completion_date',
        'actual_completion_date',
        'ai_priority_rank',
        'ai_priority_score',
        'ai_reasoning',
        'last_ai_analysis',
        'notes',
        'percent_complete',
        'attachments',
    ];

    protected $casts = [
        'budget_amount' => 'decimal:2',
        'start_date' => 'date',
        'target_completion_date' => 'date',
        'actual_completion_date' => 'date',
        'last_ai_analysis' => 'datetime',
        'ai_priority_score' => 'integer',
        'ai_priority_rank' => 'integer',
        'status' => ProjectStatus::class,
        'priority' => ProjectPriority::class,
        'percent_complete' => 'integer',
        'attachments' => 'array',
    ];

    // Relationships
    public function milestones()
    {
        return $this->hasMany(ProjectMilestone::class);
    }

    public function updates()
    {
        return $this->hasMany(ProjectUpdate::class);
    }

    public function notifications()
    {
        return $this->hasMany(NotificationTracking::class, 'project_id');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', '!=', ProjectStatus::Completed);
    }

    public function scopeHighPriority($query)
    {
        return $query->whereIn('priority', [ProjectPriority::High, ProjectPriority::Critical]);
    }

    public function scopeOverdue($query)
    {
        return $query->where('target_completion_date', '<', now())
            ->whereNull('actual_completion_date');
    }

    // Accessors
    public function getIsOverdueAttribute(): bool
    {
        return $this->target_completion_date 
            && $this->target_completion_date->isPast() 
            && !$this->actual_completion_date;
    }

    public function getCompletionPercentageAttribute(): float
    {
        $totalMilestones = $this->milestones()->count();
        
        if ($totalMilestones === 0) {
            return 0;
        }

        $completedMilestones = $this->milestones()->where('completed', true)->count();
        
        return round(($completedMilestones / $totalMilestones) * 100, 2);
    }

    /**
     * Analyze this project using Cloudflare AI and update AI fields
     * 
     * @return void
     * @throws \Exception
     */
    public function analyzeWithAI(): void
    {
        $service = app(CloudflareAIService::class);
        
        if (!$service->isEnabled()) {
            throw new \Exception('Cloudflare AI service is not enabled. Please configure CLOUDFLARE_ACCOUNT_ID and CLOUDFLARE_API_TOKEN.');
        }
        
        $result = $service->analyzeProject($this);
        
        $this->update([
            'ai_priority_rank' => $result['rank'] ?? null,
            'ai_priority_score' => $result['score'] ?? null,
            'ai_reasoning' => $result['reasoning'] ?? json_encode($result),
            'last_ai_analysis' => now(),
        ]);
    }

    /**
     * Check if project needs AI analysis (hasn't been analyzed or is outdated)
     * 
     * @param int $daysThreshold Number of days before analysis is considered outdated
     * @return bool
     */
    public function needsAIAnalysis(int $daysThreshold = 7): bool
    {
        if (!$this->last_ai_analysis) {
            return true;
        }
        
        return $this->last_ai_analysis->addDays($daysThreshold)->isPast();
    }
}
