<?php

namespace App\Filament\Widgets;

use Filament\Widgets\Widget;
use App\Services\CloudflareAIService;
use App\Models\CapitalProject;
use Illuminate\Support\Facades\Log;

class SmartUpdatesWidget extends Widget
{
    protected static string $view = 'filament.widgets.smart-updates-widget';

    protected int | string | array $columnSpan = 'full';

    public ?array $smartUpdateData = null;
    public bool $isLoading = true;

    public function mount(): void
    {
        $this->loadSmartUpdates();
    }

    public function loadSmartUpdates(): void
    {
        $this->isLoading = true;
        
        try {
            $aiService = app(CloudflareAIService::class);
            
            if (!$aiService->isEnabled()) {
                $this->smartUpdateData = [
                    'error' => 'AI service not configured',
                    'summary_markdown' => "**AI Smart Updates is not configured.**\n\nTo enable this feature, configure your Cloudflare AI credentials in the .env file:\n- CLOUDFLARE_ACCOUNT_ID\n- CLOUDFLARE_API_TOKEN\n- CLOUDFLARE_AI_ENABLED=true",
                    'action_items' => [],
                    'risks' => [],
                    'generated_at' => now()->toIso8601String(),
                ];
                $this->isLoading = false;
                return;
            }

            $projects = CapitalProject::with(['milestones', 'updates'])
                ->orderBy('priority')
                ->limit(20)
                ->get();

            $summary = $aiService->generateWeeklySummary($projects);

            $this->smartUpdateData = [
                'summary_markdown' => $summary,
                'action_items' => [],
                'risks' => [],
                'generated_at' => now()->toIso8601String(),
            ];
        } catch (\Exception $e) {
            Log::error('SmartUpdatesWidget error', ['message' => $e->getMessage()]);
            $this->smartUpdateData = [
                'error' => $e->getMessage(),
                'summary_markdown' => 'Error loading smart updates: ' . $e->getMessage(),
                'action_items' => [],
                'risks' => [],
                'generated_at' => now()->toIso8601String(),
            ];
        }
        
        $this->isLoading = false;
    }

    public function refresh(): void
    {
        $this->loadSmartUpdates();
    }
}
