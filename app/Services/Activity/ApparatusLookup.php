<?php

declare(strict_types=1);

namespace App\Services\Activity;

use App\Models\Apparatus;

/**
 * The apparatus rows an activity page names, read in one query however many rows mention them, so the adapters
 * never need a query per row (and never rely on the untyped apparatus() relation of the defect and inspection models).
 */
final class ApparatusLookup
{
    /** @param  array<int, Apparatus>  $byId */
    private function __construct(private readonly array $byId) {}

    /** @param  iterable<mixed>  $ids  apparatus ids as stored on the rows; null and non-numeric values are skipped */
    public static function load(iterable $ids): self
    {
        $wanted = [];
        foreach ($ids as $id) {
            if (is_numeric($id)) {
                $wanted[(int) $id] = true;
            }
        }
        if ($wanted === []) {
            return new self([]);
        }

        $byId = [];
        foreach (Apparatus::query()->select(['id', 'unit_id', 'name', 'designation', 'slug'])->whereIn('id', array_keys($wanted))->get() as $apparatus) {
            $byId[(int) $apparatus->getKey()] = $apparatus;
        }

        return new self($byId);
    }

    public function find(mixed $id): ?Apparatus
    {
        return is_numeric($id) ? ($this->byId[(int) $id] ?? null) : null;
    }
}
