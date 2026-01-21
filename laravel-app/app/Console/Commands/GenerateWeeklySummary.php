<?php

namespace App\Console\Commands;

use App\Models\CapitalProject;
use App\Models\ProjectMilestone;
use App\Models\User;
use App\Services\CloudflareAIService;
use Filament\Notifications\Notification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class GenerateWeeklySummary extends Command
{
    protected $signature = 'projects:weekly-summary';
    
    protected $description = 'Generate and send weekly project summary with AI insights';

    public function handle()
    {
        $this->info('Generating weekly project summary...');
        
        try {
            // Gather statistics
            $startOfWeek = now()->startOfWeek();
            $endOfWeek = now()->endOfWeek();
            
            $completedThisWeek = CapitalProject::whereBetween('actual_completion', [$startOfWeek, $endOfWeek])
                ->count();
            
            $overdueProjects = CapitalProject::where('target_completion_date', '<', now())
                ->whereNull('actual_completion')
                ->whereNotIn('status', ['completed', 'on-hold'])
                ->count();
            
            $highPriorityProjects = CapitalProject::whereNotIn('status', ['completed', 'on-hold'])
                ->whereIn('priority', ['critical', 'high'])
                ->count();
            
            $activeProjects = CapitalProject::whereNotIn('status', ['completed', 'on-hold'])->get();
            
            $totalBudget = $activeProjects->sum('budget_amount');
            $spentBudget = 0; // spend column was removed in migration
            $budgetPercentage = 0; // cannot calculate without spend data
            
            // Try to get AI summary
            $aiSummary = null;
            $aiService = app(CloudflareAIService::class);
            
            if ($aiService->canMakeRequest() && $activeProjects->isNotEmpty()) {
                $this->info('Requesting AI-generated summary...');
                $result = $aiService->generateWeeklySummary($activeProjects->toArray());
                
                if ($result['success']) {
                    $aiSummary = $result['data']['summary'] ?? null;
                }
            }
            
            // Build summary message
            $summaryBody = $this->buildSummaryMessage([
                'completed_this_week' => $completedThisWeek,
                'overdue_count' => $overdueProjects,
                'high_priority_count' => $highPriorityProjects,
                'budget_percentage' => $budgetPercentage,
                'total_budget' => $totalBudget,
                'spent_budget' => $spentBudget,
                'ai_summary' => $aiSummary,
            ]);
            
            // Send to all admin users
            $recipients = User::whereHas('roles', function ($query) {
                $query->where('name', 'admin');
            })->get();
            
            foreach ($recipients as $user) {
                Notification::make()
                    ->title('📊 Weekly Project Summary')
                    ->body($summaryBody)
                    ->icon('heroicon-o-chart-bar')
                    ->iconColor('info')
                    ->actions([
                        \Filament\Notifications\Actions\Action::make('view')
                            ->label('View All Projects')
                            ->url(route('filament.admin.resources.capital-projects.index')),
                    ])
                    ->sendToDatabase($user);
            }
            
            $this->info("✓ Weekly summary sent to {$recipients->count()} admin users");
            
            // Display summary in console
            $this->newLine();
            $this->info('=== Weekly Project Summary ===');
            $this->table(
                ['Metric', 'Value'],
                [
                    ['Projects Completed This Week', $completedThisWeek],
                    ['Overdue Projects', $overdueProjects],
                    ['High Priority Projects', $highPriorityProjects],
                    ['Budget Used', "{$budgetPercentage}% (\${$spentBudget} / \${$totalBudget})"],
                ]
            );
            
            if ($aiSummary) {
                $this->newLine();
                $this->info('AI Summary:');
                $this->line($aiSummary);
            }
            
            Log::info('Weekly summary generated', [
                'completed_this_week' => $completedThisWeek,
                'overdue_count' => $overdueProjects,
                'high_priority_count' => $highPriorityProjects,
                'recipients' => $recipients->count(),
            ]);
            
            return Command::SUCCESS;
            
        } catch (\Exception $e) {
            $this->error('Error generating weekly summary: ' . $e->getMessage());
            Log::error('Weekly summary generation error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return Command::FAILURE;
        }
    }
    
    private function buildSummaryMessage(array $stats): string
    {
        $message = "**Key Statistics for the Week:**\n\n";
        $message .= "✅ Projects Completed: {$stats['completed_this_week']}\n";
        $message .= "⚠️ Overdue Projects: {$stats['overdue_count']}\n";
        $message .= "🔴 High Priority Items: {$stats['high_priority_count']}\n";
        $message .= "💰 Budget Status: {$stats['budget_percentage']}% used (\$" . number_format($stats['spent_budget'], 2) . " / \$" . number_format($stats['total_budget'], 2) . ")\n";
        
        if ($stats['ai_summary']) {
            $message .= "\n**AI Insights:**\n{$stats['ai_summary']}";
        }
        
        return $message;
    }
}
