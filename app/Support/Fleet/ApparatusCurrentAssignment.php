<?php

declare(strict_types=1);

namespace App\Support\Fleet;

use App\Models\Apparatus;
use App\Models\ApparatusChangeout;
use App\Models\ApparatusOperationalAlert;
use App\Models\ApparatusServiceTicket;

/**
 * Read-only summary behind the "Current assignment" card on the apparatus profile: the unit's active alert and this
 * truck's last changeout. A fixed handful of queries, one apparatus at a time.
 */
final readonly class ApparatusCurrentAssignment
{
    public function __construct(
        public ?ApparatusOperationalAlert $activeAlert,
        public int $otherActiveAlerts,
        public ?ApparatusServiceTicket $relatedTicket,
        public ?ApparatusChangeout $lastChangeout,
    ) {}

    public static function for(Apparatus $truck): self
    {
        $alerts = filled($truck->slug)
            ? ApparatusOperationalAlert::query()->active()->forUnit((string) $truck->slug)->with('serviceTicket')->orderByDesc('id')->limit(6)->get()
            : collect();
        $alert = $alerts->first();

        return new self(
            $alert,
            max(0, $alerts->count() - 1),
            $alert?->serviceTicket,
            ApparatusChangeout::query()->involvingTruck((int) $truck->getKey())->where('status', ApparatusChangeout::STATUS_COMPLETED)
                ->orderByDesc('effective_at')->orderByDesc('id')->first(),
        );
    }

    public function isEmpty(): bool
    {
        return $this->activeAlert === null && $this->lastChangeout === null;
    }
}
