<?php

declare(strict_types=1);

namespace App\Services\PersonnelRequests;

final class UniformOrderCatalog
{
    /** @return array<string, array<string, mixed>> */
    public function products(): array
    {
        $products = [];
        foreach (config('uniform_orders.products', []) as $code => $product) {
            $asset = $product['asset'] ?? null;
            $products[$code] = $product + [
                'item_code' => $code,
                'frequency' => 'annual',
                'variant' => [],
                'help' => null,
                'thumbnail' => $asset ? "images/uniforms/{$asset}-thumb.webp" : null,
                'image' => $asset ? "images/uniforms/{$asset}-large.webp" : null,
            ];
        }

        return $products;
    }

    public function product(string $code): ?array
    {
        return $this->products()[$code] ?? null;
    }

    /** @return array<string, string> */
    public function categories(): array
    {
        return config('uniform_orders.categories', []);
    }

    /** @return array<string, string> */
    public function groupLabels(): array
    {
        return config('uniform_orders.groups', []);
    }

    /** Human-readable ordering fields without requiring administrators to parse JSON. */
    public function orderingDetails(string $code, array $metadata): array
    {
        $details = [];
        foreach (($this->product($code)['fields'] ?? []) as $field) {
            $value = $metadata[$field['key']] ?? null;
            if (! is_scalar($value) || trim((string) $value) === '') {
                continue;
            }
            $details[$field['label']] = (string) ($field['options'][$value] ?? $value);
        }

        return $details;
    }
}
