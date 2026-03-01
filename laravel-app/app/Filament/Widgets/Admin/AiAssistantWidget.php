<?php

namespace App\Filament\Widgets\Admin;

use App\Services\CloudflareAIService;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\On;

class AiAssistantWidget extends Widget
{
    protected static string $view = 'filament.widgets.admin.ai-assistant-widget';

    protected int | string | array $columnSpan = [
        'default' => 'full',
        'lg' => 3,
    ];

    protected static ?int $sort = 5;

    public string $chatInput = '';
    public array $chatMessages = [];
    public bool $chatLoading = false;
    public bool $aiEnabled = false;

    public function mount(): void
    {
        try {
            $aiService = app(CloudflareAIService::class);
            $this->aiEnabled = $aiService->isEnabled();
        } catch (\Exception $e) {
            $this->aiEnabled = false;
            Log::debug('AI service unavailable: ' . $e->getMessage());
        }
    }

    public function sendChat(): void
    {
        if (empty(trim($this->chatInput)) || !$this->aiEnabled) {
            return;
        }

        $userMessage = trim($this->chatInput);
        $this->chatInput = '';
        $this->chatLoading = true;

        $this->chatMessages[] = [
            'role' => 'user',
            'content' => $userMessage,
            'time' => now()->format('g:i A'),
        ];

        try {
            $aiService = app(CloudflareAIService::class);
            $response = $aiService->chat($userMessage);
            
            $this->chatMessages[] = [
                'role' => 'assistant',
                'content' => $response['message'],
                'time' => now()->format('g:i A'),
            ];
        } catch (\Exception $e) {
            $this->chatMessages[] = [
                'role' => 'assistant',
                'content' => 'Error: ' . $e->getMessage(),
                'time' => now()->format('g:i A'),
            ];
        }

        $this->chatLoading = false;
    }

    public function quickPrompt(string $prompt): void
    {
        $this->chatInput = $prompt;
        $this->sendChat();
    }

    public function clearChat(): void
    {
        $this->chatMessages = [];
    }

    public static function getPresets(): array
    {
        return [
            [
                'label' => 'OOS Summary',
                'prompt' => 'Summarize all out of service apparatus with reasons and estimated return dates if known.',
            ],
            [
                'label' => 'Low Stock Analysis',
                'prompt' => 'Analyze the current low stock items and suggest priority reorders based on criticality.',
            ],
            [
                'label' => 'Defect Triage',
                'prompt' => 'List all open defects by severity and recommend which should be prioritized for repair.',
            ],
            [
                'label' => 'Fleet Status',
                'prompt' => 'Provide a complete fleet status breakdown by station and apparatus type.',
            ],
        ];
    }
}
