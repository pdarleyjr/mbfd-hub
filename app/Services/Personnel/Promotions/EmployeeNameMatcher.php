<?php

declare(strict_types=1);

namespace App\Services\Personnel\Promotions;

use App\Models\Employee;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Exact first-plus-last name matching against the personnel roster.
 *
 * Matching is case, accent, punctuation and spacing insensitive but never fuzzy: "Jon" does
 * not match "John" and a middle name keeps a record from matching. Callers decide what an
 * ambiguous or missing match means; this class never links anyone.
 */
final class EmployeeNameMatcher
{
    /** @var array<string, list<array{id: int, active: bool}>>|null */
    private ?array $index = null;

    /**
     * Roster members whose full name equals "first last" exactly (after normalization).
     *
     * @return Collection<int, Employee>
     */
    public function candidates(string $firstName, string $lastName, bool $activeOnly = false): Collection
    {
        $key = self::key($firstName.' '.$lastName);
        if ($key === '') {
            return new Collection;
        }
        $ids = [];
        foreach ($this->index()[$key] ?? [] as $row) {
            if (! $activeOnly || $row['active']) {
                $ids[] = $row['id'];
            }
        }

        return $ids === [] ? new Collection : Employee::query()->whereIn('id', $ids)->orderBy('name')->get();
    }

    /** The one active employee with this exact name, or null when there is none or more than one. */
    public function uniqueActive(string $firstName, string $lastName): ?Employee
    {
        $matches = $this->candidates($firstName, $lastName, true);

        return $matches->count() === 1 ? $matches->first() : null;
    }

    public static function key(string $name): string
    {
        $name = trim($name);
        if (str_contains($name, ',')) {
            [$family, $given] = array_map('trim', explode(',', $name, 2));
            $name = $given.' '.$family;
        }
        $ascii = Str::lower(Str::ascii($name));
        $ascii = (string) preg_replace('/[^a-z0-9 ]+/', '', str_replace(['-', "'", '.'], ['', '', ''], $ascii));

        return trim((string) preg_replace('/\s+/', ' ', $ascii));
    }

    /** @return array<string, list<array{id: int, active: bool}>> */
    private function index(): array
    {
        if ($this->index === null) {
            $this->index = [];
            foreach (Employee::query()->get(['id', 'name', 'roster_status']) as $employee) {
                $key = self::key((string) $employee->name);
                if ($key !== '') {
                    $this->index[$key][] = ['id' => (int) $employee->id, 'active' => $employee->roster_status === 'active'];
                }
            }
        }

        return $this->index;
    }
}
