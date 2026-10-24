<?php

declare(strict_types=1);

namespace App\Services\Ems;

use App\Models\Apparatus;
use App\Models\Ems\InventoryRequest;
use App\Services\Captain5\Captain5IssueScope;
use App\Services\DailyCheckoutChecklistResolver;

/**
 * The unit choices on a Medical Inventory Request. Apparatus is the authoritative unit
 * directory: Rescue units (the Daily Checkout "rescue" checklist) come first, then every
 * other unit that is not Out of Service, then the "Detail / Special Event" choice. Submitted
 * apparatus ids are validated against this list on the server.
 */
final class EmsUnitDirectory
{
    public const DETAIL_LABEL = 'Detail / Special Event';

    /** @var list<array<string, mixed>>|null */
    private ?array $memo = null;

    public function __construct(private readonly DailyCheckoutChecklistResolver $resolver) {}

    /** @return list<array{id: int|null, kind: string, label: string, designation: string|null, status: string|null, group: string}> */
    public function options(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        $rescue = [];
        $other = [];
        foreach (Apparatus::query()->orderBy('designation')->orderBy('name')->get() as $apparatus) {
            $isRescue = in_array($this->resolver->checklistTypeFor($apparatus), Captain5IssueScope::RESCUE_TYPES, true);
            if (! $isRescue && $apparatus->status === 'Out of Service') {
                continue;
            }
            $label = trim((string) ($apparatus->name ?: $apparatus->designation ?: $apparatus->unit_id));
            $row = [
                'id' => (int) $apparatus->getKey(),
                'kind' => $isRescue ? InventoryRequest::TARGET_RESCUE : InventoryRequest::TARGET_OTHER,
                'label' => $label !== '' ? $label : 'Unit '.$apparatus->getKey(),
                'designation' => $apparatus->designation !== null ? (string) $apparatus->designation : null,
                'status' => $apparatus->status !== null ? (string) $apparatus->status : null,
                'group' => $isRescue ? 'Rescue units' : 'Other units',
            ];
            if ($isRescue) {
                $rescue[] = $row;
            } else {
                $other[] = $row;
            }
        }

        $detail = [[
            'id' => null, 'kind' => InventoryRequest::TARGET_DETAIL, 'label' => self::DETAIL_LABEL,
            'designation' => null, 'status' => null, 'group' => 'Details',
        ]];

        return $this->memo = [...$rescue, ...$other, ...$detail];
    }

    /** @return array{id: int|null, kind: string, label: string, designation: string|null, status: string|null, group: string}|null */
    public function find(?int $apparatusId): ?array
    {
        foreach ($this->options() as $option) {
            if ($option['id'] !== null && $option['id'] === $apparatusId) {
                return $option;
            }
        }

        return null;
    }
}
