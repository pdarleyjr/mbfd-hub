<?php

namespace App\Services\Baserow;

use App\Models\ExternalSource;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BaserowClient
{
    protected string $baseUrl;
    protected string $token;

    public function __construct(string $baseUrl, string $token)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->token = $token;
    }

    public static function fromSource(ExternalSource $source): static
    {
        return new static($source->base_url, $source->token);
    }

    public static function fromConfig(): static
    {
        return new static(
            config('services.baserow.base_url'),
            config('services.baserow.token'),
        );
    }

    protected function request(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl . '/api')
            ->withHeaders([
                'Authorization' => 'Token ' . $this->token,
            ])
            ->timeout(15)
            ->connectTimeout(5)
            ->retry(2, 500);
    }

    public function getFields(int $tableId): array
    {
        $response = $this->request()->get("/database/fields/table/{$tableId}/");

        if ($response->failed()) {
            Log::error('Baserow getFields failed', [
                'table_id' => $tableId,
                'status' => $response->status(),
            ]);
            return [];
        }

        return $response->json() ?? [];
    }

    public function listRows(int $tableId, array $query = []): array
    {
        $response = $this->request()->get("/database/rows/table/{$tableId}/", $query);

        if ($response->failed()) {
            Log::error('Baserow listRows failed', [
                'table_id' => $tableId,
                'status' => $response->status(),
            ]);
            return ['count' => 0, 'results' => []];
        }

        return $response->json() ?? ['count' => 0, 'results' => []];
    }

    public function listRowsForView(int $tableId, int $viewId, array $query = []): array
    {
        $query['view_id'] = $viewId;
        return $this->listRows($tableId, $query);
    }
}
