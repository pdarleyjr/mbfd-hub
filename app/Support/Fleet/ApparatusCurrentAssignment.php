<?php

declare(strict_types=1);

namespace App\Support\Fleet;

use App\Models\Apparatus;
use App\Models\ApparatusChangeout;
use App\Models\ApparatusChangeoutItem;
use App\Models\ApparatusOperationalAlert;
use App\Models\ApparatusServiceTicket;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read-only summary behind the "Current assignment" card on the apparatus profile: the unit's active alert and pending
 * changeout, this truck's last changeout, and its open handoff lines. A fixed handful of queries, one apparatus at a time.
 */
final readonly class ApparatusCurrentAssignment
{
    public function __construct(
        public ?ApparatusOperationalAlert $activeAlert,
        public int $otherActiveAlerts,
        public ?ApparatusServiceTicket $relatedTicket,
        public ?ApparatusChangeout $pendingChangeout,
        public ?ApparatusChangeout $lastChangeout,
        public int $openHandoffLines,
    ) {}

    public static function for(Apparatus $truck): self
    {
        $alerts = filled($truck->slug)
            ? ApparatusOperationalAlert::query()->active()->forUnit((string) $truck->slug)->with('serviceTicket')->orderByDesc('id')->limit(6)->get()
            : collect();
        $alert = $alerts->first();
        $onThisTruck = static fn (Builder $query): Builder => $query
            ->where('from_apparatus_id', $truck->getKey())
            ->orWhere('to_apparatus_id', $truck->getKey());

        return new self(
            $alert,
            max(0, $alerts->count() - 1),
            $alert?->serviceTicket,
            filled($truck->slug)
                ? ApparatusChangeout::query()->where('unit_slug', $truck->slug)->where('status', ApparatusChangeout::STATUS_PENDING_FLEET)->first()
                : null,
            ApparatusChangeout::query()->where($onThisTruck)->where('status', ApparatusChangeout::STATUS_COMPLETED)
                ->orderByDesc('effective_at')->orderByDesc('id')->first(),
            ApparatusChangeoutItem::query()->whereIn('custody_status', ApparatusChangeoutItem::OPEN_CUSTODY)
                ->whereHas('changeout', fn (Builder $changeout): Builder => $changeout->where($onThisTruck)->where('status', ApparatusChangeout::STATUS_COMPLETED))
                ->count(),
        );
    }

    public function isEmpty(): bool
    {
        return $this->activeAlert === null && $this->pendingChangeout === null && $this->lastChangeout === null && $this->openHandoffLines === 0;
    }
}
