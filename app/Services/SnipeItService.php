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
     * Create an asset (or accessory/consumable/component) in Snipe-IT.
     * Routes to the correct endpoint based on $data['scan_type'].
     *
     * Supported scan_type values:
     *   'hardware'    → POST /hardware   (requires resolved model_id)
     *   'accessory'   → POST /accessories (requires category_id, qty)
     *   'consumable'  → POST /consumables (requires category_id, qty)
     *   'component'   → POST /components  (requires category_id, qty)
     */
    public function createAsset(array $data): array
    {
        $type = $data['scan_type'] ?? 'hardware';

        return match ($type) {
            'accessory'  => $this->createAccessory($data),
            'consumable' => $this->createConsumable($data),
            'component'  => $this->createComponent($data),
            default      => $this->createHardwareAsset($data),
        };
    }

    // -------------------------------------------------------------------------
    // Hardware Asset  →  POST /hardware
    // -------------------------------------------------------------------------

    protected function createHardwareAsset(array $data): array
    {
        $name = trim(($data['brand'] ?? '') . ' ' . ($data['model'] ?? ''));
        if ($name === '' || $name === ' ') $name = 'Unknown Equipment';

        // Resolve or create manufacturer + category + model chain
        $manufacturerId = null;
        if (!empty($data['brand'])) {
            $manufacturerId = $this->resolveOrCreateManufacturer($data['brand']);
        }

        $categoryId = $this->resolveOrCreateCategory($data['category'] ?? 'General', 'asset');
        $modelId    = $this->resolveOrCreateModel($name, $manufacturerId, $categoryId);

        $payload = [
            'name'           => $name,
            'serial'         => $data['serial'] ?? null,
            'status_id'      => $data['status_id'] ?? 2,  // Ready to Deploy
            'rtd_location_id'=> $data['location_id'] ?? null,
            'asset_tag'      => $data['asset_tag'] ?? $this->generateAssetTag(),
            'notes'          => $data['notes'] ?? null,
        ];

        if ($modelId) {
            $payload['model_id'] = $modelId;
        }

        return $this->post('/hardware', $payload);
    }

    // -------------------------------------------------------------------------
    // Accessory  →  POST /accessories
    // -------------------------------------------------------------------------

    protected function createAccessory(array $data): array
    {
        $name       = $data['name'] ?? (trim(($data['brand'] ?? '') . ' ' . ($data['model'] ?? '')) ?: 'Unknown Accessory');
        $categoryId = $this->resolveOrCreateCategory($data['category'] ?? 'General', 'accessory');

        $payload = [
            'name'        => $name,
            'category_id' => $categoryId,
            'qty'         => max(1, (int) ($data['qty'] ?? 1)),
            'location_id' => $data['location_id'] ?? null,
            'notes'       => $data['notes'] ?? null,
        ];

        return $this->post('/accessories', $payload);
    }

    // -------------------------------------------------------------------------
    // Consumable  →  POST /consumables
    // -------------------------------------------------------------------------

    protected function createConsumable(array $data): array
    {
        $name       = $data['name'] ?? (trim(($data['brand'] ?? '') . ' ' . ($data['model'] ?? '')) ?: 'Unknown Consumable');
        $categoryId = $this->resolveOrCreateCategory($data['category'] ?? 'General', 'consumable');

        $payload = [
            'name'        => $name,
            'category_id' => $categoryId,
            'qty'         => max(1, (int) ($data['qty'] ?? 1)),
            'location_id' => $data['location_id'] ?? null,
            'notes'       => $data['notes'] ?? null,
        ];

        return $this->post('/consumables', $payload);
    }

    // -------------------------------------------------------------------------
    // Component  →  POST /components
    // -------------------------------------------------------------------------

    protected function createComponent(array $data): array
    {
        $name       = $data['name'] ?? (trim(($data['brand'] ?? '') . ' ' . ($data['model'] ?? '')) ?: 'Unknown Component');
        $categoryId = $this->resolveOrCreateCategory($data['category'] ?? 'General', 'component');

        $payload = [
            'name'        => $name,
            'category_id' => $categoryId,
            'qty'         => max(1, (int) ($data['qty'] ?? 1)),
            'location_id' => $data['location_id'] ?? null,
            'serial'      => $data['serial'] ?? null,
            'notes'       => $data['notes'] ?? null,
        ];

        return $this->post('/components', $payload);
    }

    // -------------------------------------------------------------------------
    // Relational Helpers
    // -------------------------------------------------------------------------

    /**
     * Resolve or create a manufacturer by name. Returns the ID or null.
     */
    protected function resolveOrCreateManufacturer(string $name): ?int
    {
        try {
            $response = Http::withToken($this->token)
                ->timeout($this->timeout)
                ->accept('application/json')
                ->get("{$this->baseUrl}/manufacturers", ['search' => $name, 'limit' => 5]);

            if ($response->successful()) {
                $rows = $response->json('rows', []);
                foreach ($rows as $row) {
                    if (strtolower($row['name']) === strtolower($name)) {
                        return (int) $row['id'];
                    }
                }
            }

            // Not found — create it
            $create = Http::withToken($this->token)
                ->timeout($this->timeout)
                ->accept('application/json')
                ->post("{$this->baseUrl}/manufacturers", ['name' => $name]);

            if ($create->successful()) {
                return (int) ($create->json('payload.id') ?? $create->json('id'));
            }
        } catch (\Exception $e) {
            Log::warning('SnipeIt resolveOrCreateManufacturer failed', ['error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Resolve or create a category by name and type. Returns the ID or null.
     * $type is the Snipe-IT category_type: 'asset', 'accessory', 'consumable', 'component'
     */
    protected function resolveOrCreateCategory(string $name, string $type = 'asset'): ?int
    {
        try {
            $response = Http::withToken($this->token)
                ->timeout($this->timeout)
                ->accept('application/json')
                ->get("{$this->baseUrl}/categories", ['search' => $name, 'limit' => 10]);

            if ($response->successful()) {
                $rows = $response->json('rows', []);
                foreach ($rows as $row) {
                    if (strtolower($row['name']) === strtolower($name) &&
                        ($row['category_type'] ?? '') === $type) {
                        return (int) $row['id'];
                    }
                }
            }

            // Not found — create it
            $create = Http::withToken($this->token)
                ->timeout($this->timeout)
                ->accept('application/json')
                ->post("{$this->baseUrl}/categories", [
                    'name'          => $name,
                    'category_type' => $type,
                ]);

            if ($create->successful()) {
                return (int) ($create->json('payload.id') ?? $create->json('id'));
            }
        } catch (\Exception $e) {
            Log::warning('SnipeIt resolveOrCreateCategory failed', ['error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Resolve or create a model by name + manufacturer + category. Returns the ID or null.
     */
    protected function resolveOrCreateModel(string $name, ?int $manufacturerId, ?int $categoryId): ?int
    {
        try {
            $response = Http::withToken($this->token)
                ->timeout($this->timeout)
                ->accept('application/json')
                ->get("{$this->baseUrl}/models", ['search' => $name, 'limit' => 10]);

            if ($response->successful()) {
                $rows = $response->json('rows', []);
                foreach ($rows as $row) {
                    if (strtolower($row['name']) === strtolower($name)) {
                        return (int) $row['id'];
                    }
                }
            }

            // Not found — create it
            $payload = ['name' => $name];
            if ($manufacturerId) $payload['manufacturer_id'] = $manufacturerId;
            if ($categoryId)     $payload['category_id']     = $categoryId;

            $create = Http::withToken($this->token)
                ->timeout($this->timeout)
                ->accept('application/json')
                ->post("{$this->baseUrl}/models", $payload);

            if ($create->successful()) {
                return (int) ($create->json('payload.id') ?? $create->json('id'));
            }
        } catch (\Exception $e) {
            Log::warning('SnipeIt resolveOrCreateModel failed', ['error' => $e->getMessage()]);
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Generic HTTP helper
    // -------------------------------------------------------------------------

    protected function post(string $endpoint, array $payload): array
    {
        // Strip null values to avoid Snipe-IT validation errors
        $payload = array_filter($payload, fn($v) => $v !== null && $v !== '');

        try {
            $response = Http::withToken($this->token)
                ->timeout($this->timeout)
                ->accept('application/json')
                ->post("{$this->baseUrl}{$endpoint}", $payload);

            if ($response->successful()) {
                Log::info("Snipe-IT {$endpoint} created", ['response' => $response->json()]);
                return ['success' => true, 'data' => $response->json()];
            }

            Log::warning("Snipe-IT {$endpoint} creation failed", [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);

            return [
                'success' => false,
                'error'   => $response->json('messages') ?? $response->body(),
                'status'  => $response->status(),
            ];
        } catch (\Exception $e) {
            Log::error("Snipe-IT API error on {$endpoint}", ['message' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // -------------------------------------------------------------------------
    // Bulk
    // -------------------------------------------------------------------------

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

    // -------------------------------------------------------------------------
    // Locations
    // -------------------------------------------------------------------------

    /**
     * Create a location in Snipe-IT.
     */
    public function createLocation(string $name): array
    {
        return $this->post('/locations', ['name' => $name]);
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
            5 => 'Supply Room',
            6 => 'Station 6',
            7 => 'Shop',
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
