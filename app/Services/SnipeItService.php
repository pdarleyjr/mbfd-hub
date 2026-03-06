<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SnipeItService
{
    protected string $baseUrl;
    protected string $token;
    protected int $timeout;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('snipeit.url'), '/');
        $this->token = config('snipeit.token');
        $this->timeout = config('snipeit.timeout', 15);
    }

    /**
     * Create an asset in Snipe-IT.
     */
    public function createAsset(array $data): array
    {
        $payload = [
            'name' => ($data['brand'] ?? 'Unknown') . ' ' . ($data['model'] ?? 'Unknown'),
            'serial' => $data['serial'] ?? null,
            'status_id' => $data['status_id'] ?? 2, // Ready to Deploy
            'model_id' => $data['model_id'] ?? null,
            'rtd_location_id' => $data['location_id'] ?? null,
            'asset_tag' => $data['asset_tag'] ?? $this->generateAssetTag(),
        ];

        // Add custom fields if present
        if (!empty($data['brand'])) {
            $payload['_snipeit_manufacturer_1'] = $data['brand'];
        }

        try {
            $response = Http::withToken($this->token)
                ->timeout($this->timeout)
                ->accept('application/json')
                ->post("{$this->baseUrl}/hardware", $payload);

            if ($response->successful()) {
                Log::info('Snipe-IT asset created', ['response' => $response->json()]);
                return ['success' => true, 'data' => $response->json()];
            }

            Log::warning('Snipe-IT asset creation failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return [
                'success' => false,
                'error' => $response->json('messages') ?? $response->body(),
                'status' => $response->status(),
            ];
        } catch (\Exception $e) {
            Log::error('Snipe-IT API error', ['message' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Bulk create assets in Snipe-IT.
     */
    public function bulkCreateAssets(array $items): array
    {
        $results = [];
        foreach ($items as $index => $item) {
            $results[$index] = $this->createAsset($item);
        }
        return $results;
    }

    /**
     * Get locations from Snipe-IT for dropdown.
     */
    public function getLocations(): array
    {
        try {
            $response = Http::withToken($this->token)
                ->timeout($this->timeout)
                ->accept('application/json')
                ->get("{$this->baseUrl}/locations", ['limit' => 500]);

            if ($response->successful()) {
                $rows = $response->json('rows', []);
                $locations = [];
                foreach ($rows as $row) {
                    $locations[$row['id']] = $row['name'];
                }
                return $locations;
            }
        } catch (\Exception $e) {
            Log::error('Snipe-IT locations fetch error', ['message' => $e->getMessage()]);
        }

        // Fallback static locations for MBFD
        return [
            1 => 'Station 1',
            2 => 'Station 2',
            3 => 'Station 3',
            4 => 'Station 4',
            5 => 'Warehouse',
            6 => 'Shop',
        ];
    }

    /**
     * Get models from Snipe-IT.
     */
    public function getModels(): array
    {
        try {
            $response = Http::withToken($this->token)
                ->timeout($this->timeout)
                ->accept('application/json')
                ->get("{$this->baseUrl}/models", ['limit' => 500]);

            if ($response->successful()) {
                $rows = $response->json('rows', []);
                $models = [];
                foreach ($rows as $row) {
                    $models[$row['id']] = $row['name'];
                }
                return $models;
            }
        } catch (\Exception $e) {
            Log::error('Snipe-IT models fetch error', ['message' => $e->getMessage()]);
        }

        return [];
    }

    /**
     * Generate a unique asset tag.
     */
    protected function generateAssetTag(): string
    {
        return 'MBFD-' . strtoupper(substr(md5(uniqid()), 0, 8));
    }
}
