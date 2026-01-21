<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SmartUpdatesController extends Controller
{
    /**
     * Get AI-generated smart updates from Cloudflare Worker
     */
    public function index(Request $request)
    {
        try {
            $workerUrl = config('services.cloudflare.worker_url');
            
            if (!$workerUrl) {
                return response()->json([
                    'error' => 'Cloudflare Worker URL not configured',
                    'summary_markdown' => 'Smart updates service is not configured.',
                    'action_items' => [],
                    'risks' => [],
                    'generated_at' => now()->toIso8601String(),
                ], 500);
            }

            // Call the Cloudflare Worker
            $response = Http::timeout(30)->get($workerUrl);

            if ($response->successful()) {
                $data = $response->json();
                
                // Ensure required fields exist
                return response()->json([
                    'summary_markdown' => $data['summary_markdown'] ?? 'No summary available',
                    'action_items' => $data['action_items'] ?? [],
                    'risks' => $data['risks'] ?? [],
                    'generated_at' => $data['generated_at'] ?? now()->toIso8601String(),
                ]);
            }

            // Worker returned an error
            Log::warning('Cloudflare Worker returned error', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return response()->json([
                'error' => 'Failed to fetch smart updates from worker',
                'summary_markdown' => 'Unable to generate smart updates at this time. Please try again later.',
                'action_items' => [],
                'risks' => [],
                'generated_at' => now()->toIso8601String(),
            ], 502);

        } catch (\Exception $e) {
            Log::error('Error fetching smart updates', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'An error occurred while fetching smart updates',
                'summary_markdown' => 'System error occurred. Please contact administrator.',
                'action_items' => [],
                'risks' => [],
                'generated_at' => now()->toIso8601String(),
            ], 500);
        }
    }
}
