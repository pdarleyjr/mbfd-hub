<?php

declare(strict_types=1);

namespace App\Services\PersonnelRequests;

final class UniformOrderCatalog
{
    private const RETIRED_FROM_SELF_SERVICE = ['uniform_shirt', 'work_boots', 'class_a_coat'];

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

    /**
     * Products a member may order (or change) in the uniform order builder. Retired products
     * stay in the catalog so older requests still render; Support Services handles them.
     *
     * @return array<string, array<string, mixed>>
     */
    public function selfServiceProducts(): array
    {
        return array_diff_key($this->products(), array_flip(self::RETIRED_FROM_SELF_SERVICE));
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
