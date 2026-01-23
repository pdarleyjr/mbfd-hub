<?php

/**
 * Test script for Cloudflare AI Services
 * Run with: php artisan tinker < test_ai_services.php
 * Or manually in tinker
 */

echo "=== Testing Cloudflare AI Services ===\n\n";

// Test 1: Check if service is enabled
echo "1. Checking if Cloudflare AI service is enabled...\n";
$aiService = app(\App\Services\CloudflareAIService::class);
$isEnabled = $aiService->isEnabled();
echo "   Service enabled: " . ($isEnabled ? "YES" : "NO") . "\n";
if (!$isEnabled) {
    echo "   NOTE: To enable, set CLOUDFLARE_ACCOUNT_ID in .env\n";
    echo "   Get Account ID from: https://dash.cloudflare.com > Workers & Pages\n";
}
echo "\n";

// Test 2: Check rate limit service
echo "2. Testing Rate Limit Service...\n";
$rateLimitService = app(\App\Services\RateLimitService::class);
$stats = $rateLimitService->getUsageStats();
echo "   Current usage: {$stats['used']} / {$stats['limit']} ({$stats['percentage']}%)\n";
echo "   Remaining requests: {$stats['remaining']}\n";
echo "   Status: {$stats['status']}\n";
echo "   Reset in: {$stats['hours_until_reset']}h {$stats['minutes_until_reset']}m\n";
$warning = $rateLimitService->getWarningMessage();
if ($warning) {
    echo "   WARNING: {$warning}\n";
}
echo "\n";

// Test 3: Get rate limit usage from CloudflareAIService
echo "3. Getting rate limit usage from AI service...\n";
$usage = $aiService->getRateLimitUsage();
echo "   Used: {$usage['used']}\n";
echo "   Limit: {$usage['limit']}\n";
echo "   Remaining: {$usage['remaining']}\n";
echo "   Percentage: {$usage['percentage']}%\n";
echo "\n";

// Test 4: Check if we can make requests
echo "4. Checking if we can make API requests...\n";
$canMakeRequest = $rateLimitService->canMakeRequest();
echo "   Can make request: " . ($canMakeRequest ? "YES" : "NO") . "\n";
echo "\n";

// Test 5: Get usage history
echo "5. Getting usage history (last 7 days)...\n";
$history = $rateLimitService->getUsageHistory(7);
if (empty($history)) {
    echo "   No usage history found\n";
} else {
    foreach ($history as $day) {
        echo "   {$day['date']}: {$day['count']} requests\n";
        foreach ($day['types'] as $type => $count) {
            echo "      - {$type}: {$count}\n";
        }
    }
}
echo "\n";

// Test 6: Count existing projects
echo "6. Counting capital projects...\n";
$totalProjects = \App\Models\CapitalProject::count();
$activeProjects = \App\Models\CapitalProject::active()->count();
echo "   Total projects: {$totalProjects}\n";
echo "   Active projects: {$activeProjects}\n";
echo "\n";

// Test 7: Check if any project needs AI analysis
echo "7. Checking which projects need AI analysis...\n";
$projects = \App\Models\CapitalProject::all();
$needsAnalysis = $projects->filter(function($p) { return $p->needsAIAnalysis(); })->count();
echo "   Projects needing analysis: {$needsAnalysis} / {$totalProjects}\n";
echo "\n";

// Test 8: Configuration check
echo "8. Configuration Check...\n";
$config = config('cloudflare.ai');
echo "   Account ID set: " . (!empty($config['account_id']) ? "YES" : "NO - REQUIRED") . "\n";
echo "   API Token set: " . (!empty($config['api_token']) ? "YES" : "NO") . "\n";
echo "   Enabled flag: " . ($config['enabled'] ? "YES" : "NO") . "\n";
echo "   Default model: " . ($config['models']['default'] ?? 'not set') . "\n";
echo "   Daily limit: " . ($config['rate_limit']['daily_neurons'] ?? 'not set') . "\n";
echo "\n";

// Test 9: Test AI analysis (only if enabled AND we have projects)
if ($isEnabled && $activeProjects > 0) {
    echo "9. Testing AI analysis on a single project...\n";
    echo "   WARNING: This will consume 1 API request from your daily quota!\n";
    echo "   Skipping actual API call in test script.\n";
    echo "   To test manually, run in tinker:\n";
    echo "   \$project = \\App\\Models\\CapitalProject::active()->first();\n";
    echo "   \$project->analyzeWithAI();\n";
} else {
    echo "9. Skipping AI analysis test (service not enabled or no projects)\n";
}
echo "\n";

echo "=== Test Complete ===\n\n";

echo "NEXT STEPS:\n";
echo "1. If Account ID is missing:\n";
echo "   - Visit https://dash.cloudflare.com\n";
echo "   - Go to Workers & Pages\n";
echo "   - Copy your Account ID from the sidebar\n";
echo "   - Add to .env: CLOUDFLARE_ACCOUNT_ID=your_account_id_here\n";
echo "   - Run: docker exec mbfd-hub-app php artisan config:clear\n\n";

echo "2. To test actual AI API call:\n";
echo "   - docker exec -it mbfd-hub-app php artisan tinker\n";
echo "   - \$service = app(\\App\\Services\\CloudflareAIService::class);\n";
echo "   - \$projects = \\App\\Models\\CapitalProject::active()->get();\n";
echo "   - \$result = \$service->prioritizeProjects(\$projects);\n";
echo "   - dd(\$result);\n\n";

echo "3. To analyze a single project:\n";
echo "   - \$project = \\App\\Models\\CapitalProject::first();\n";
echo "   - \$project->analyzeWithAI();\n\n";
