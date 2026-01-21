<?php

namespace App\Console\Commands;

use App\Services\CloudflareAIService;
use App\Services\RateLimitService;
use App\Models\CapitalProject;
use Illuminate\Console\Command;

class TestAIServicesCommand extends Command
{
    protected $signature = 'test:ai-services';
    protected $description = 'Test Cloudflare AI Services configuration and functionality';

    public function handle(): int
    {
        $this->info('=== Testing Cloudflare AI Services ===');
        $this->newLine();

        // Test 1: Check if service is enabled
        $this->info('1. Checking if Cloudflare AI service is enabled...');
        $aiService = app(CloudflareAIService::class);
        $isEnabled = $aiService->isEnabled();
        $this->line('   Service enabled: ' . ($isEnabled ? '<fg=green>YES</>' : '<fg=red>NO</>'));
        if (!$isEnabled) {
            $this->warn('   NOTE: To enable, set CLOUDFLARE_ACCOUNT_ID in .env');
            $this->warn('   Get Account ID from: https://dash.cloudflare.com > Workers & Pages');
        }
        $this->newLine();

        // Test 2: Check rate limit service
        $this->info('2. Testing Rate Limit Service...');
        $rateLimitService = app(RateLimitService::class);
        $stats = $rateLimitService->getUsageStats();
        $this->line("   Current usage: {$stats['used']} / {$stats['limit']} ({$stats['percentage']}%)");
        $this->line("   Remaining requests: {$stats['remaining']}");
        $this->line("   Status: {$stats['status']}");
        $this->line("   Reset in: {$stats['hours_until_reset']}h {$stats['minutes_until_reset']}m");
        $warning = $rateLimitService->getWarningMessage();
        if ($warning) {
            $this->warn("   WARNING: {$warning}");
        }
        $this->newLine();

        // Test 3: Get rate limit usage from CloudflareAIService
        $this->info('3. Getting rate limit usage from AI service...');
        $usage = $aiService->getRateLimitUsage();
        $this->line("   Used: {$usage['used']}");
        $this->line("   Limit: {$usage['limit']}");
        $this->line("   Remaining: {$usage['remaining']}");
        $this->line("   Percentage: {$usage['percentage']}%");
        $this->newLine();

        // Test 4: Check if we can make requests
        $this->info('4. Checking if we can make API requests...');
        $canMakeRequest = $rateLimitService->canMakeRequest();
        $this->line('   Can make request: ' . ($canMakeRequest ? '<fg=green>YES</>' : '<fg=red>NO</>'));
        $this->newLine();

        // Test 5: Count existing projects
        $this->info('5. Counting capital projects...');
        $totalProjects = CapitalProject::count();
        $activeProjects = CapitalProject::active()->count();
        $this->line("   Total projects: {$totalProjects}");
        $this->line("   Active projects: {$activeProjects}");
        $this->newLine();

        // Test 6: Check if any project needs AI analysis
        $this->info('6. Checking which projects need AI analysis...');
        $projects = CapitalProject::all();
        $needsAnalysis = $projects->filter(function($p) { return $p->needsAIAnalysis(); })->count();
        $this->line("   Projects needing analysis: {$needsAnalysis} / {$totalProjects}");
        $this->newLine();

        // Test 7: Configuration check
        $this->info('7. Configuration Check...');
        $config = config('cloudflare.ai');
        $this->line('   Account ID set: ' . (!empty($config['account_id']) ? '<fg=green>YES</>' : '<fg=red>NO - REQUIRED</>'));
        $this->line('   API Token set: ' . (!empty($config['api_token']) ? '<fg=green>YES</>' : '<fg=red>NO</>'));
        $this->line('   Enabled flag: ' . ($config['enabled'] ? '<fg=green>YES</>' : '<fg=red>NO</>'));
        $this->line('   Default model: ' . ($config['models']['default'] ?? 'not set'));
        $this->line('   Daily limit: ' . ($config['rate_limit']['daily_neurons'] ?? 'not set'));
        $this->newLine();

        // Summary
        $this->info('=== Test Complete ===');
        $this->newLine();

        $this->comment('NEXT STEPS:');
        $this->line('1. If Account ID is missing:');
        $this->line('   - Visit https://dash.cloudflare.com');
        $this->line('   - Go to Workers & Pages');
        $this->line('   - Copy your Account ID from the sidebar');
        $this->line('   - Add to .env: CLOUDFLARE_ACCOUNT_ID=your_account_id_here');
        $this->line('   - Run: php artisan config:clear');
        $this->newLine();

        $this->line('2. To test actual AI API call:');
        $this->line('   - php artisan tinker');
        $this->line('   - $service = app(\App\Services\CloudflareAIService::class);');
        $this->line('   - $projects = \App\Models\CapitalProject::active()->get();');
        $this->line('   - $result = $service->prioritizeProjects($projects);');
        $this->newLine();

        return self::SUCCESS;
    }
}
