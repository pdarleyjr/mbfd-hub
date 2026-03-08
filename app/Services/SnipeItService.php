<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SnipeItService
{
    protected string $baseUrl;
    protected string $token;
    protected int $timeout;

    // In-memory cache for resolved IDs within a single request cycle
    protected array $manufacturerCache = [];
    protected array $categoryCache = [];
    protected array $modelCache = [];

    // Category names that map to Snipe-IT consumable type
    protected const CONSUMABLE_CATEGORIES = ['Consumable', 'Medical', 'Medical Supply'];

    public function __construct()
    {
        $this->baseUrl = rtrim(config('snipeit.url'), '/');
        $this->token = config('snipeit.token');
        $this->timeout = config('snipeit.timeout', 15);
    }

    // -------------------------------------------------------------------------
    // RELATIONAL RESOLUTION METHODS
    // -------------------------------------------------------------------------

    /**
     * Resolve or create a manufacturer by name. Returns the manufacturer ID.
     */
    public function resolveManufacturer(string $name): ?int
    {
        $name = trim($name);
        if (empty($name)) {
            return null;
        }

        if (isset($this->manufacturerCache[$name])) {
            return $this->manufacturerCache[$name];
        }

        try {
            $response = Http::withToken($this->token)
                ->timeout($this->timeout)
                ->accept('application/json')
                ->get("{$this->baseUrl}/manufacturers", ['search' => $name, 'limit' => 10]);

            if ($response->successful()) {
                $rows = $response->json('rows', []);
                foreach ($rows as $row) {
                    if (strcasecmp($row['name'], $name) === 0) {
                        $id = (int) $row['id'];
                        $this->manufacturerCache[$name] = $id;
                        Log::info('Snipe-IT manufacturer resolved (existing)', ['name' => $name, 'id' => $id]);
                        return $id;
                    }
                }
            }

            // Not found — create it
            $createResponse = Http::withToken($this->token)
                ->timeout($this->timeout)
                ->accept('application/json')
                ->post("{$this->baseUrl}/manufacturers", ['name' => $name]);

            if ($createResponse->successful()) {
                $id = (int) ($createResponse->json('payload.id') ?? $createResponse->json('id'));
                if ($id > 0) {
                    $this->manufacturerCache[$name] = $id;
                    Log::info('Snipe-IT manufacturer created', ['name' => $name, 'id' => $id]);
                    return $id;
                }
            }

            Log::warning('Snipe-IT manufacturer create failed', [
                'name' => $name,
                'body' => $createResponse->body(),
            ]);
        } catch (\Exception $e) {
            Log::error('Snipe-IT resolveManufacturer error', ['message' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Resolve or create a category by name and type ('asset' or 'consumable').
     * Returns ['id' => int, 'type' => string].
     */
    public function resolveCategory(string $name, string $type = 'asset'): ?array
    {
        $name = trim($name);
        if (empty($name)) {
            $name = 'General';
        }

        $cacheKey = $name . '|' . $type;
        if (isset($this->categoryCache[$cacheKey])) {
            return $this->categoryCache[$cacheKey];
        }

        try {
            $response = Http::withToken($this->token)
                ->timeout($this->timeout)
                ->accept('application/json')
                ->get("{$this->baseUrl}/categories", ['search' => $name, 'limit' => 20]);

            if ($response->successful()) {
                $rows = $response->json('rows', []);
                foreach ($rows as $row) {
                    if (strcasecmp($row['name'], $name) === 0) {
                        $result = ['id' => (int) $row['id'], 'type' => $row['category_type'] ?? $type];
                        $this->categoryCache[$cacheKey] = $result;
                        Log::info('Snipe-IT category resolved (existing)', ['name' => $name, 'id' => $result['id']]);
                        return $result;
                    }
                }
            }

            // Not found — create it
            $createResponse = Http::withToken($this->token)
                ->timeout($this->timeout)
                ->accept('application/json')
                ->post("{$this->baseUrl}/categories", [
                    'name' => $name,
                    'category_type' => $type,
                ]);

            if ($createResponse->successful()) {
                $id = (int) ($createResponse->json('payload.id') ?? $createResponse->json('id'));
                if ($id > 0) {
                    $result = ['id' => $id, 'type' => $type];
                    $this->categoryCache[$cacheKey] = $result;
                    Log::info('Snipe-IT category created', ['name' => $name, 'id' => $id, 'type' => $type]);
                    return $result;
                }
            }

            Log::warning('Snipe-IT category create failed', [
                'name' => $name,
                'body' => $createResponse->body(),
            ]);
        } catch (\Exception $e) {
            Log::error('Snipe-IT resolveCategory error', ['message' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Resolve or create a model by name, manufacturer ID, and category ID.
     * Returns the model ID.
     */
    public function resolveModel(string $name, int $manufacturerId, int $categoryId): ?int
    {
        $name = trim($name);
        if (empty($name)) {
            return null;
        }

        $cacheKey = $name . '|' . $manufacturerId . '|' . $categoryId;
        if (isset($this->modelCache[$cacheKey])) {
            return $this->modelCache[$cacheKey];
        }

        try {
            $response = Http::withToken($this->token)
                ->timeout($this->timeout)
                ->accept('application/json')
                ->get("{$this->baseUrl}/models", ['search' => $name, 'limit' => 20]);

            if ($response->successful()) {
                $rows = $response->json('rows', []);
                foreach ($rows as $row) {
                    if (strcasecmp($row['name'], $name) === 0) {
                        $id = (int) $row['id'];
                        $this->modelCache[$cacheKey] = $id;
                        Log::info('Snipe-IT model resolved (existing)', ['name' => $name, 'id' => $id]);
                        return $id;
                    }
                }
            }

            // Not found — create it
            $createResponse = Http::withToken($this->token)
                ->timeout($this->timeout)
                ->accept('application/json')
                ->post("{$this->baseUrl}/models", [
                    'name' => $name,
                    'manufacturer_id' => $manufacturerId,
                    'category_id' => $categoryId,
                ]);

            if ($createResponse->successful()) {
                $id = (int) ($createResponse->json('payload.id') ?? $createResponse->json('id'));
                if ($id > 0) {
                    $this->modelCache[$cacheKey] = $id;
                    Log::info('Snipe-IT model created', ['name' => $name, 'id' => $id]);
                    return $id;
                }
            }

            Log::warning('Snipe-IT model create failed', [
                'name' => $name,
                'body' => $createResponse->body(),
            ]);
        } catch (\Exception $e) {
            Log::error('Snipe-IT resolveModel error', ['message' => $e->getMessage()]);
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // ASSET / CONSUMABLE CREATION
    // -------------------------------------------------------------------------

    /**
     * Create a hardware asset or consumable in Snipe-IT.
     * Resolves or creates Manufacturer, Category, and Model IDs automatically.
     *
     * $data keys (all optional except noted):
     *   brand       - manufacturer name string
     *   model       - model name string (required for hardware)
     *   category    - category name string (determines consumable vs hardware routing)
     *   serial      - serial number
     *   location_id - Snipe-IT location ID
     *   status_id   - defaults to 2 (Ready to Deploy)
     *   notes       - free-text notes
     *   qty         - quantity (consumables only, defaults to 1)
     */
    public function createAsset(array $data): array
    {
        $brandName    = trim($data['brand'] ?? '');
        $modelName    = trim($data['model'] ?? '');
        $categoryName = trim($data['category'] ?? 'General');
        $notes        = trim($data['notes'] ?? $data['scan_notes'] ?? '');

        // Determine if this should route to consumables
        $isConsumable = in_array($categoryName, self::CONSUMABLE_CATEGORIES, true);
        $categoryType = $isConsumable ? 'consumable' : 'asset';

        // Resolve category
        $categoryResult = $this->resolveCategory($categoryName, $categoryType);
        $categoryId = $categoryResult['id'] ?? null;

        if ($isConsumable) {
            return $this->createConsumable($data, $categoryId);
        }

        // Hardware path — resolve manufacturer and model
        $manufacturerId = null;
        if (!empty($brandName)) {
            $manufacturerId = $this->resolveManufacturer($brandName);
        }

        // Fall back to a generic manufacturer if brand is empty
        if (!$manufacturerId) {
            $manufacturerId = $this->resolveManufacturer('Unknown');
        }

        // Fall back to a generic category if resolution failed
        if (!$categoryId) {
            $fallback = $this->resolveCategory('General', 'asset');
            $categoryId = $fallback['id'] ?? null;
        }

        $modelId = null;
        if (!empty($modelName) && $manufacturerId && $categoryId) {
            $modelId = $this->resolveModel($modelName, $manufacturerId, $categoryId);
        }

        if (!$modelId) {
            Log::warning('Snipe-IT createAsset: could not resolve model_id', [
                'brand' => $brandName,
                'model' => $modelName,
                'category' => $categoryName,
            ]);
            return ['success' => false, 'error' => 'Could not resolve or create Snipe-IT model. Check manufacturer/category names.'];
        }

        $assetName = trim(implode(' ', array_filter([$brandName, $modelName])));
        if (empty($assetName)) {
            $assetName = 'Unknown Asset';
        }

        $payload = [
            'name'             => $assetName,
            'serial'           => $data['serial'] ?? null,
            'status_id'        => $data['status_id'] ?? 2,
            'model_id'         => $modelId,
            'rtd_location_id'  => $data['location_id'] ?? null,
            'asset_tag'        => $data['asset_tag'] ?? $this->generateAssetTag(),
        ];

        if (!empty($notes)) {
            $payload['notes'] = $notes;
        }

        try {
            $response = Http::withToken($this->token)
                ->timeout($this->timeout)
                ->accept('application/json')
                ->post("{$this->baseUrl}/hardware", $payload);

            if ($response->successful()) {
                Log::info('Snipe-IT hardware asset created', ['response' => $response->json()]);
                return ['success' => true, 'data' => $response->json()];
            }

            Log::warning('Snipe-IT hardware asset creation failed', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);

            return [
                'success' => false,
                'error'   => $response->json('messages') ?? $response->body(),
                'status'  => $response->status(),
            ];
        } catch (\Exception $e) {
            Log::error('Snipe-IT API error (hardware)', ['message' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Create a consumable in Snipe-IT.
     * Requires: name, category_id. qty defaults to 1.
     */
    protected function createConsumable(array $data, ?int $categoryId): array
    {
        $name  = trim($data['name'] ?? $data['model'] ?? 'Unknown Consumable');
        $notes = trim($data['notes'] ?? '');
        $qty   = max(1, (int) ($data['qty'] ?? $data['quantity'] ?? 1));

        if (!$categoryId) {
            Log::warning('Snipe-IT createConsumable: no category_id', ['data' => $data]);
            return ['success' => false, 'error' => 'Could not resolve Snipe-IT category for consumable.'];
        }

        $payload = [
            'name'        => $name,
            'category_id' => $categoryId,
            'qty'         => $qty,
        ];

        if (!empty($notes)) {
            $payload['notes'] = $notes;
        }

        if (!empty($data['location_id'])) {
            $payload['location_id'] = $data['location_id'];
        }

        try {
            $response = Http::withToken($this->token)
                ->timeout($this->timeout)
                ->accept('application/json')
                ->post("{$this->baseUrl}/consumables", $payload);

            if ($response->successful()) {
                Log::info('Snipe-IT consumable created', ['response' => $response->json()]);
                return ['success' => true, 'data' => $response->json()];
            }

            Log::warning('Snipe-IT consumable creation failed', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);

            return [
                'success' => false,
                'error'   => $response->json('messages') ?? $response->body(),
                'status'  => $response->status(),
            ];
        } catch (\Exception $e) {
            Log::error('Snipe-IT API error (consumable)', ['message' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Bulk create assets/consumables in Snipe-IT.
     * Each item is processed sequentially to reuse the relational resolution cache.
     */
    public function bulkCreateAssets(array $items): array
    {
        $results = [];
        foreach ($items as $index => $data) {
            $results[$index] = $this->createAsset($data);
        }
        return $results;
    }

    // -------------------------------------------------------------------------
    // LOCATIONS & MODELS
    // -------------------------------------------------------------------------

    /**
     * Create a location in Snipe-IT.
     */
    public function createLocation(string $name): array
    {
        try {
            $response = Http::withToken($this->token)
                ->timeout($this->timeout)
                ->accept('application/json')
                ->post("{$this->baseUrl}/locations", [
                    'name' => $name,
                ]);

            if ($response->successful()) {
                Log::info('Snipe-IT location created', ['response' => $response->json()]);
                return ['success' => true, 'data' => $response->json()];
            }

            return [
                'success' => false,
                'error' => $response->json('messages') ?? $response->body(),
            ];
        } catch (\Exception $e) {
            Log::error('Snipe-IT create location error', ['message' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
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
