<?php

namespace App\Console\Commands;

use App\Models\CapitalProject;
use App\Models\AIAnalysisLog;
use App\Models\NotificationTracking;
use App\Models\User;
use App\Services\CloudflareAIService;
use Filament\Notifications\Notification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class AnalyzeProjectPriorities extends Command
{
    protected $signature = 'projects:analyze-priorities {--force : Force analysis even if recently run}';
    
    protected $description = 'Analyze active projects using AI to update priority rankings and send notifications';

    public function handle()
    {
        // Use cache lock to prevent concurrent runs (5 min lock)
        $lock = Cache::lock('projects:analyze-priorities', 300);
        
        if (!$lock->get()) {
            $this->warn('Another analysis is already running. Skipping...');
            return Command::FAILURE;
        }

        try {
            $this->info('Starting project priority analysis...');
            
            // Fetch all active projects (status != Completed/Cancelled)
            $activeProjects = CapitalProject::whereNotIn('status', ['Completed', 'Cancelled'])
                ->orderBy('id')
                ->get();
            
            if ($activeProjects->isEmpty()) {
                $this->info('No active projects found to analyze.');
                return Command::SUCCESS;
            }
            
            $this->info("Found {$activeProjects->count()} active projects");
            
            // Check if we have required AI service configuration
            $aiService = app(CloudflareAIService::class);
            
            if (!$aiService->canMakeRequest()) {
                $this->warn('Cloudflare AI service not configured. Skipping AI analysis.');
                Log::warning('Scheduled task projects:analyze-priorities skipped - AI service not configured');
                return Command::SUCCESS;
            }
            
            // Call AI service to prioritize projects
            $this->info('Calling AI service for priority analysis...');
            $bar = $this->output->createProgressBar($activeProjects->count());
            $bar->start();
            
            $result = $aiService->prioritizeProjects($activeProjects->toArray());
            
            if (!$result['success']) {
                $this->error('AI analysis failed: ' . $result['error']);
                Log::error('Project priority analysis failed', ['error' => $result['error']]);
                return Command::FAILURE;
            }
            
            // Update projects with AI analysis results
            $prioritizedProjects = $result['data']['projects'] ?? [];
            $updatedCount = 0;
            $notificationsSent = 0;
            
            foreach ($prioritizedProjects as $projectData) {
                $project = $activeProjects->firstWhere('id', $projectData['id']);
                
                if ($project) {
                    $project->update([
                        'ai_priority_rank' => $projectData['priority_rank'] ?? null,
                        'ai_priority_score' => $projectData['priority_score'] ?? null,
                        'ai_reasoning' => $projectData['reasoning'] ?? null,
                        'last_ai_analysis' => now(),
                    ]);
                    
                    $updatedCount++;
                    
                    // Send notifications for high-priority projects
                    if ($this->shouldNotifyForProject($project)) {
                        $this->sendProjectNotification($project);
                        $notificationsSent++;
                    }
                    
                    $bar->advance();
                }
            }
            
            $bar->finish();
            $this->newLine();
            
            // Log results to ai_analysis_logs table
            AIAnalysisLog::create([
                'analysis_type' => 'priority_ranking',
                'projects_analyzed' => $activeProjects->count(),
                'model_used' => $result['data']['model'] ?? 'unknown',
                'tokens_used' => $result['data']['tokens_used'] ?? 0,
                'analysis_summary' => json_encode([
                    'projects_updated' => $updatedCount,
                    'notifications_sent' => $notificationsSent,
                    'execution_time' => $result['data']['execution_time'] ?? null,
                ]),
                'status' => 'completed',
            ]);
            
            $this->info("✓ Analysis complete: {$updatedCount} projects updated");
            $this->info("✓ Notifications sent: {$notificationsSent}");
            
            Log::info('Project priority analysis completed', [
                'projects_analyzed' => $activeProjects->count(),
                'projects_updated' => $updatedCount,
                'notifications_sent' => $notificationsSent,
            ]);
            
            return Command::SUCCESS;
            
        } catch (\Exception $e) {
            $this->error('Error during project analysis: ' . $e->getMessage());
            Log::error('Project priority analysis error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            // Log failed analysis
            AIAnalysisLog::create([
                'analysis_type' => 'priority_ranking',
                'projects_analyzed' => 0,
                'model_used' => 'N/A',
                'tokens_used' => 0,
                'analysis_summary' => json_encode(['error' => $e->getMessage()]),
                'status' => 'failed',
            ]);
            
            return Command::FAILURE;
        } finally {
            $lock->release();
        }
    }
    
    private function shouldNotifyForProject(CapitalProject $project): bool
    {
        // Notify for top 3 ranked projects or critical/high priority
        if ($project->ai_priority_rank <= 3) {
            return true;
        }
        
        if (in_array($project->priority, ['critical', 'high'])) {
            return true;
        }
        
        return false;
    }
    
    private function sendProjectNotification(CapitalProject $project): void
    {
        // Get recipients (users with admin or project-manager role)
        $recipients = User::whereHas('roles', function ($query) {
            $query->whereIn('name', ['admin', 'project-manager']);
        })->get();
        
        foreach ($recipients as $user) {
            // Check if we've recently notified this user about this project (24-hour cooldown)
            $recentNotification = NotificationTracking::where('user_id', $user->id)
                ->where('notifiable_type', CapitalProject::class)
                ->where('notifiable_id', $project->id)
                ->where('notification_type', 'priority_alert')
                ->where('created_at', '>=', now()->subHours(24))
                ->exists();
            
            if ($recentNotification) {
                continue; // Skip if already notified recently
            }
            
            // Create Filament notification
            Notification::make()
                ->title('High Priority Project Alert')
                ->body("**{$project->name}** requires attention. Priority Rank: #{$project->ai_priority_rank}")
                ->icon('heroicon-o-exclamation-triangle')
                ->iconColor('warning')
                ->actions([
                    \Filament\Notifications\Actions\Action::make('view')
                        ->label('View Project')
                        ->url(route('filament.admin.resources.capital-projects.edit', $project)),
                ])
                ->sendToDatabase($user);
            
            // Track notification
            NotificationTracking::create([
                'user_id' => $user->id,
                'notifiable_type' => CapitalProject::class,
                'notifiable_id' => $project->id,
                'notification_type' => 'priority_alert',
                'metadata' => [
                    'priority_rank' => $project->ai_priority_rank,
                    'priority_score' => $project->ai_priority_score,
                    'reasoning' => $project->ai_reasoning,
                ],
            ]);
        }
    }
}
