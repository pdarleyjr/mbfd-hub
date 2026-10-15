<?php

declare(strict_types=1);

namespace App\Services\PersonnelRequests;

use App\Enums\PersonnelRequestStatus;
use App\Enums\PersonnelRequestType;
use App\Models\Employee;
use App\Models\PersonnelRequestItem;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Year-to-date annual allowance usage (soft enforcement).
 *
 * Usage is the requested quantity on the member's uniform requests created in
 * the current allowance year, excluding denied and cancelled requests. Going
 * over the remaining allowance is allowed with a written justification; the
 * request is then flagged for Support Services.
 */
final class UniformAllowanceService
{
    /** Statuses that never count toward the annual allowance. */
    public const EXCLUDED_STATUSES = [PersonnelRequestStatus::Denied, PersonnelRequestStatus::Cancelled];

    public function __construct(
        private readonly UniformOrderCatalog $catalog,
        private readonly UniformEntitlementService $entitlements,
    ) {}

    /** @return array{start: CarbonImmutable, end: CarbonImmutable, label: string} */
    public function period(?CarbonImmutable $at = null): array
    {
        $at ??= CarbonImmutable::now();
        $month = max(1, min(12, (int) config('uniform_orders.allowance_year.start_month', 1)));
        $day = max(1, min(28, (int) config('uniform_orders.allowance_year.start_day', 1)));
        $start = $at->setDate($at->year, $month, $day)->startOfDay();
        if ($at->lt($start)) {
            $start = $start->subYearNoOverflow();
        }
        $end = $start->addYearNoOverflow();
        $label = $month === 1 && $day === 1
            ? (string) $start->year
            : $start->format('M j, Y').' – '.$end->subDay()->format('M j, Y');

        return ['start' => $start, 'end' => $end, 'label' => $label];
    }

    /**
     * Quantity already requested this allowance year, by allowance group.
     *
     * @return array<string, int>
     */
    public function usedByGroup(Employee $employee, ?CarbonImmutable $at = null): array
    {
        $period = $this->period($at);
        $products = $this->catalog->products();
        $used = [];
        $items = PersonnelRequestItem::query()
            ->whereHas('request', fn (Builder $requests) => $requests
                ->where('beneficiary_employee_id', $employee->id)
                ->where('type', PersonnelRequestType::Uniform)
                ->whereNotIn('status', self::EXCLUDED_STATUSES)
                ->where('created_at', '>=', $period['start'])
                ->where('created_at', '<', $period['end']))
            ->get(['item_code', 'quantity']);
        foreach ($items as $item) {
            $group = $products[(string) $item->item_code]['group'] ?? null;
            if ($group !== null) {
                $used[$group] = ($used[$group] ?? 0) + max(0, (int) $item->quantity);
            }
        }

        return $used;
    }

    /**
     * Compare a selection with what remains of the annual allowance.
     *
     * @param  array<string, mixed>  $context  from UniformEntitlementService::forEmployee()
     * @param  array<string, int>  $used  from usedByGroup()
     * @param  iterable<mixed>  $selectedItems  items with item_code and quantity
     * @return array{period: array{start: CarbonImmutable, end: CarbonImmutable, label: string}, used: array<string, int>,
     *     allowed: array<string, int>, remaining: array<string, int>, over: array<string, array{label: string, allowed: int, used: int, selected: int}>, is_over: bool, swaps: int}
     */
    public function assess(array $context, array $used, iterable $selectedItems, ?CarbonImmutable $at = null): array
    {
        $labels = $this->catalog->groupLabels();
        $products = $this->catalog->products();
        $selected = [];
        foreach ($selectedItems as $item) {
            $group = is_array($item) ? ($products[(string) ($item['item_code'] ?? '')]['group'] ?? null) : null;
            if ($group !== null) {
                $selected[$group] = ($selected[$group] ?? 0) + max(0, (int) ($item['quantity'] ?? 0));
            }
        }
        $tracked = array_intersect_key($context['allowances'] ?? [], $labels);
        $combined = [];
        foreach (array_keys($tracked) as $group) {
            $combined[$group] = ($used[$group] ?? 0) + ($selected[$group] ?? 0);
        }
        $effective = $this->entitlements->effectiveAllowances($tracked, $combined);
        $allowed = array_intersect_key($effective['allowed'], $tracked);
        $remaining = [];
        $over = [];
        foreach ($allowed as $group => $limit) {
            $remaining[$group] = max(0, $limit - ($used[$group] ?? 0));
            if (($selected[$group] ?? 0) > 0 && $combined[$group] > $limit) {
                $over[$group] = ['label' => $labels[$group], 'allowed' => $limit, 'used' => $used[$group] ?? 0, 'selected' => $selected[$group]];
            }
        }

        return [
            'period' => $this->period($at),
            'used' => array_intersect_key($used, $allowed),
            'allowed' => $allowed,
            'remaining' => $remaining,
            'over' => $over,
            'is_over' => $over !== [],
            'swaps' => $effective['swaps'],
        ];
    }

    /**
     * Friendly, non-technical reasons the selection is over the allowance.
     *
     * @param  array<string, array{label: string, allowed: int, used: int, selected: int}>  $over
     * @return list<string>
     */
    public function overageMessages(array $over): array
    {
        return array_values(array_map(
            fn (array $row): string => "{$row['label']}: {$row['selected']} selected, ".max(0, $row['allowed'] - $row['used'])." of {$row['allowed']} remaining this year.",
            $over,
        ));
    }
}
