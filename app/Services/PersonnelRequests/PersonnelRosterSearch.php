<?php

declare(strict_types=1);

namespace App\Services\PersonnelRequests;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class PersonnelRosterSearch
{
    public const MAX_RESULTS = 500;

    private const MIN_SEARCH_LENGTH = 2;

    /**
     * @return Collection<int, Employee>
     */
    public function employees(string $search): Collection
    {
        $term = mb_strtolower(trim($search));

        if (mb_strlen($term) < self::MIN_SEARCH_LENGTH) {
            return collect();
        }

        $escapedTerm = $this->escapeLike($term);
        $contains = "%{$escapedTerm}%";
        $startsWith = "{$escapedTerm}%";

        return Employee::query()
            ->select(['id', 'employee_id', 'name', 'rank'])
            ->where(static function (Builder $query) use ($contains): void {
                $query->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$contains])
                    ->orWhereRaw("LOWER(employee_id) LIKE ? ESCAPE '!'", [$contains])
                    ->orWhereRaw("LOWER(rank) LIKE ? ESCAPE '!'", [$contains]);
            })
            ->orderByRaw(
                "CASE
                    WHEN LOWER(employee_id) = ? THEN 0
                    WHEN LOWER(name) = ? THEN 1
                    WHEN LOWER(name) LIKE ? ESCAPE '!' THEN 2
                    ELSE 3
                END",
                [$term, $term, $startsWith],
            )
            ->orderBy('name')
            ->orderBy('employee_id')
            ->limit(self::MAX_RESULTS)
            ->get();
    }

    /** @return array<int, string> */
    public function options(string $search): array
    {
        return $this->employees($search)
            ->mapWithKeys(fn (Employee $employee): array => [
                (int) $employee->id => $this->label($employee),
            ])
            ->all();
    }

    /** @return list<array{id: int, label: string}> */
    public function payload(string $search): array
    {
        return $this->employees($search)
            ->map(fn (Employee $employee): array => [
                'id' => (int) $employee->id,
                'label' => $this->label($employee),
            ])
            ->values()
            ->all();
    }

    public function label(Employee $employee): string
    {
        return "{$employee->rank} — {$employee->name} — {$employee->employee_id}";
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }
}
