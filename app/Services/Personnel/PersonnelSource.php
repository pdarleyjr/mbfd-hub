<?php

declare(strict_types=1);

namespace App\Services\Personnel;

use App\Models\Employee;
use App\Models\EmployeeBidAssignment;
use App\Models\PromotionalList;
use App\Services\Personnel\Promotions\PromotionalDirectory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class PersonnelSource
{
    private const PROFILE = ['name', 'employee_id', 'city_email', 'rank', 'station', 'roster_status', 'phone', 'display_name'];

    private const BID = [
        'shift' => 'shift_label', 'a_day' => 'a_day_label', 'division' => 'division_label',
        'unit' => 'unit_label', 'bid_position' => 'position_label', 'bid_station' => 'station_label',
        'bid_term' => 'term_label', 'assignment_source' => 'assignment_source',
    ];

    /** Filter criteria for promotional lists; list types come from config/promotional_lists.php. */
    public const PROMOTIONAL_CRITERIA = ['promotional_list_type', 'promotional_on_list'];

    private ?PromotionalDirectory $promotions = null;

    /** @return array<string, string> */
    public function fields(): array
    {
        return [
            'name' => 'Name', 'employee_id' => 'Employee ID', 'city_email' => 'Email address',
            'rank' => 'Rank / position', 'shift' => 'Shift (published bid)', 'a_day' => 'A-Day (published bid)',
            'station' => 'Station on personnel profile', 'division' => 'Division (published bid)',
            'unit' => 'Seat / unit (published bid)', 'bid_position' => 'Bid position',
            'email_match_status' => 'Email match status', 'bid_station' => 'Station (published bid)',
            'bid_term' => 'Published bid term', 'assignment_source' => 'Assignment source',
            'roster_status' => 'Employment status', 'phone' => 'Phone', 'display_name' => 'Display name',
            ...PromotionalDirectory::FIELDS,
        ];
    }

    /** @return Builder<Employee> */
    public function query(): Builder
    {
        $query = Employee::query()->select('employees.*');
        foreach (self::BID as $key => $field) {
            $query->selectSub($this->currentBid()->select($field)
                ->whereColumn('employee_profile_id', 'employees.id')->limit(1), 'source_'.$key);
        }
        // Sort keys for the promotional fields; display values come from PromotionalDirectory::lines().
        foreach (array_keys(PromotionalDirectory::FIELDS) as $key) {
            $query->selectSub(PromotionalDirectory::sortSubquery($key), 'source_'.$key);
        }

        return $query;
    }

    /**
     * Order a directory query by one source field. Published-bid and promotional fields sort on
     * their sub-select; unranked members always sort last.
     *
     * @param  Builder<Employee>  $query
     * @return Builder<Employee>
     */
    public function order(Builder $query, string $key, string $direction): Builder
    {
        $direction = $direction === 'desc' ? 'desc' : 'asc';
        if (array_key_exists($key, PromotionalDirectory::FIELDS)) {
            return $query->orderByRaw('source_'.$key.' '.$direction.' nulls last');
        }

        return $query->orderBy(isset(self::BID[$key]) ? 'source_'.$key : 'employees.'.$key, $direction);
    }

    /**
     * @param  Builder<Employee>  $query
     * @param  array<string, mixed>  $criteria
     * @return Builder<Employee>
     */
    public function filter(Builder $query, array $criteria): Builder
    {
        $criteria = $this->validateCriteria($criteria);
        if ($criteria['active_only'] ?? false) {
            $query->where('employees.roster_status', 'active');
        }
        $search = trim((string) ($criteria['search'] ?? ''));
        if ($search !== '') {
            $query->where(function (Builder $match) use ($search): void {
                foreach (['name', 'employee_id', 'city_email', 'rank', 'station'] as $field) {
                    $match->orWhereLike('employees.'.$field, '%'.$search.'%');
                }
                // Published-bid placement (shift, unit, bid station) uses the same single current term as the columns.
                $match->orWhereIn('employees.id', $this->currentBid()->select('employee_profile_id')
                    ->where(function (Builder $bid) use ($search): void {
                        foreach (['shift_label', 'unit_label', 'station_label'] as $field) {
                            $bid->orWhereLike($field, '%'.$search.'%');
                        }
                    }));
            });
        }
        if ($criteria['promotional_on_list'] ?? false) {
            $query->whereIn('employees.id', PromotionalDirectory::employeeIds(eligibleOnly: true));
        }
        if (! empty($criteria['promotional_list_type'])) {
            $query->whereIn('employees.id', PromotionalDirectory::employeeIds(array_values((array) $criteria['promotional_list_type'])));
        }
        foreach (array_diff(array_keys($criteria), ['active_only', 'search'], self::PROMOTIONAL_CRITERIA) as $key) {
            $values = is_array($criteria[$key]) ? $criteria[$key] : [$criteria[$key]];
            if ($values === [] || $values === [''] || $values === [null]) {
                continue;
            }
            if (in_array($key, self::PROFILE, true)) {
                $query->whereIn('employees.'.$key, $values);
            } else {
                $query->whereIn('employees.id', $this->currentBid()->select('employee_profile_id')->whereIn(self::BID[$key], $values));
            }
        }

        return $query;
    }

    public function validateCriteria(array $criteria): array
    {
        $allowed = [...self::PROFILE, ...array_keys(self::BID), ...self::PROMOTIONAL_CRITERIA, 'search', 'active_only'];
        if (array_diff(array_keys($criteria), $allowed) !== []) {
            throw ValidationException::withMessages(['criteria' => 'Choose a supported personnel filter.']);
        }
        Validator::make($criteria, [
            'search' => ['sometimes', 'nullable', 'string', 'max:150'], 'active_only' => ['sometimes', 'boolean'],
            'promotional_on_list' => ['sometimes', 'boolean'],
            'promotional_list_type' => ['sometimes', 'array', 'max:30'],
            'promotional_list_type.*' => ['string', Rule::in(array_keys(PromotionalList::types()))],
        ])->validate();
        foreach (array_diff(array_keys($criteria), ['active_only', 'search'], self::PROMOTIONAL_CRITERIA) as $key) {
            Validator::make(['values' => is_array($criteria[$key]) ? $criteria[$key] : [$criteria[$key]]], [
                'values' => ['array', 'max:30'], 'values.*' => ['nullable', 'string', 'max:255'],
            ])->validate();
        }

        return $criteria;
    }

    public function value(Employee $employee, string $key): mixed
    {
        if (! array_key_exists($key, $this->fields())) {
            throw ValidationException::withMessages(['source_columns' => 'Choose a supported personnel field.']);
        }
        if ($key === 'email_match_status') {
            return null; // Private reconciliation metadata is resolved by its authorized reader.
        }
        if (array_key_exists($key, PromotionalDirectory::FIELDS)) {
            return implode('; ', $this->promotionalLines($employee, $key)) ?: null;
        }

        return $employee->getAttribute(isset(self::BID[$key]) ? 'source_'.$key : $key);
    }

    /**
     * One display line per promotional list in effect (newest first); empty when the member is on none.
     *
     * @return list<string>
     */
    public function promotionalLines(Employee $employee, string $key): array
    {
        return ($this->promotions ??= new PromotionalDirectory)->lines($employee, $key);
    }

    /** @return Builder<EmployeeBidAssignment> */
    private function currentBid(): Builder
    {
        return $this->constrainCurrentBid(EmployeeBidAssignment::query());
    }

    /**
     * @param  Builder<EmployeeBidAssignment>  $query
     * @return Builder<EmployeeBidAssignment>
     */
    private function constrainCurrentBid(Builder $query): Builder
    {
        // The source publishes bid terms, not effective operational placements.
        // Never promote a future term or use an older term for a missing member.
        $year = now(\App\Support\HubTimezone::NAME)->year;

        return $query->whereNull('superseded_at')->where('bid_year', '=', EmployeeBidAssignment::query()
            ->selectRaw('MAX(bid_year)')->whereNull('superseded_at')->where('bid_year', '<=', $year));
    }
}
