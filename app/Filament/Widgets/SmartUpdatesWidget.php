<?php

namespace App\Filament\Widgets;

use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Http;

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
            $response = Http::get(route('api.smart-updates'));
            
            if ($response->successful()) {
                $this->smartUpdateData = $response->json();
            } else {
                $this->smartUpdateData = [
                    'error' => 'Failed to load smart updates',
                    'summary_markdown' => 'Unable to fetch updates at this time.',
                    'action_items' => [],
                    'risks' => [],
                    'generated_at' => now()->toIso8601String(),
                ];
            }
        } catch (\Exception $e) {
            $this->smartUpdateData = [
                'error' => $e->getMessage(),
                'summary_markdown' => 'Error loading smart updates.',
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
