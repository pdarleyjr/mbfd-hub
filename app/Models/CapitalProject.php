<?php

namespace App\Models;

use App\Enums\ProjectPriority;
use App\Enums\ProjectStatus;
use App\Services\CloudflareAIService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        'actual_completion',
        'ai_priority_rank',
        'ai_priority_score',
        'ai_reasoning',
        'last_ai_analysis',
        'notes',
        'percent_complete',
        'attachments',
        'station_id',
    ];

    protected $casts = [
        'budget_amount' => 'decimal:2',
        'start_date' => 'date',
        'target_completion_date' => 'date',
        'actual_completion' => 'date',
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

    /**
     * Get the station that owns this project
     */
    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
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
            ->whereNull('actual_completion');
    }

    // Accessors
    public function getIsOverdueAttribute(): bool
    {
        return $this->target_completion_date
            && $this->target_completion_date->isPast()
            && ! $this->actual_completion;
    }

    /** Completed-milestone ratio (0..1) as a correlated subquery for sorting. */
    public const COMPLETION_RATIO_SQL = '(select case when count(*) = 0 then 0 else sum(case when project_milestones.completed then 1 else 0 end) * 1.0 / count(*) end from project_milestones where project_milestones.capital_project_id = capital_projects.id)';

    /** Load milestone totals with the list query so progress needs no per-row queries. */
    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public static function withMilestoneProgress(Builder $query): Builder
    {
        return $query->withCount([
            'milestones',
            'milestones as completed_milestones_count' => fn ($milestones) => $milestones->where('completed', true),
        ]);
    }

    public function getCompletionPercentageAttribute(): float
    {
        $totalMilestones = (int) ($this->attributes['milestones_count'] ?? $this->milestones()->count());

        if ($totalMilestones === 0) {
            return 0;
        }

        $completedMilestones = (int) ($this->attributes['completed_milestones_count']
            ?? $this->milestones()->where('completed', true)->count());

        return round(($completedMilestones / $totalMilestones) * 100, 2);
    }

    /**
     * Analyze this project using Cloudflare AI and update AI fields
     *
     * @throws \Exception
     */
    public function analyzeWithAI(): void
    {
        $service = app(CloudflareAIService::class);

        if (! $service->isEnabled()) {
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
     * @param  int  $daysThreshold  Number of days before analysis is considered outdated
     */
    public function needsAIAnalysis(int $daysThreshold = 7): bool
    {
        if (! $this->last_ai_analysis) {
            return true;
        }

        return $this->last_ai_analysis->addDays($daysThreshold)->isPast();
    }
}
