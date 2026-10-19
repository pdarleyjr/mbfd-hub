<?php

declare(strict_types=1);

namespace App\Support\Fleet;

use App\Models\Apparatus;
use App\Models\ApparatusInspection;

/**
 * Read-only summary behind the Fleet apparatus profile's Overview tab. Counts
 * come from a single aggregate query so they always match what the Defects and
 * Service Tickets tabs list.
 */
final readonly class ApparatusOverview
{
    public function __construct(
        public int $openDefects,
        public int $openTickets,
        public ?ApparatusInspection $lastInspection,
    ) {}

    public static function for(Apparatus $apparatus): self
    {
        $counts = Apparatus::query()
            ->whereKey($apparatus->getKey())
            ->withCount([
                'openDefects as open_defects_count',
                'serviceTickets as open_tickets_count' => fn ($tickets) => $tickets->open(),
            ])
            ->first();

        $lastInspection = $apparatus->inspections()
            ->orderByDesc('completed_at')
            ->orderByDesc('id')
            ->first();

        return new self(
            (int) ($counts?->getAttribute('open_defects_count') ?? 0),
            (int) ($counts?->getAttribute('open_tickets_count') ?? 0),
            $lastInspection,
        );
    }
}
