<?php

declare(strict_types=1);

namespace App\Support\Fleet;

use App\Models\ApparatusDefect;
use App\Models\ApparatusServiceTicket;
use Illuminate\Database\Eloquent\Builder;

/**
 * Derives an equipment issue's follow-up stage from its real data (resolved
 * flag, status, reviewer classification and linked service ticket). Nothing is
 * stored: the PHP resolver and the SQL filter below must always agree.
 */
final class DefectFollowUpState
{
    public const NEW = 'new';

    public const AWAITING_REVIEW = 'awaiting_review';

    public const ACKNOWLEDGED = 'acknowledged';

    public const SERVICE_FOLLOW_UP = 'service_follow_up';

    public const TICKET_CLOSED = 'ticket_closed';

    public const RESOLVED = 'resolved';

    /** Reviewer classifications that mean an administrator has looked at the issue. */
    private const REVIEWED_IMPACTS = ['non_blocking', 'needs_repair', 'out_of_service'];

    private const REVIEW_PENDING_IMPACT = 'needs_admin_review';

    /** @return array<string, string> */
    public static function options(): array
    {
        return [
            self::NEW => 'New / reported',
            self::AWAITING_REVIEW => 'Awaiting review',
            self::ACKNOWLEDGED => 'Acknowledged',
            self::SERVICE_FOLLOW_UP => 'Under service follow-up',
            self::TICKET_CLOSED => 'Service ticket closed',
            self::RESOLVED => 'Resolved',
        ];
    }

    public static function label(string $state): string
    {
        return self::options()[$state] ?? 'New / reported';
    }

    public static function color(string $state): string
    {
        return match ($state) {
            self::NEW => 'danger',
            self::AWAITING_REVIEW => 'warning',
            self::ACKNOWLEDGED => 'info',
            self::SERVICE_FOLLOW_UP => 'primary',
            self::TICKET_CLOSED => 'gray',
            self::RESOLVED => 'success',
            default => 'gray',
        };
    }

    /** Requires the serviceTicket relation to be loaded for a linked ticket. */
    public static function for(ApparatusDefect $defect): string
    {
        if ($defect->resolved || $defect->status === 'resolved') {
            return self::RESOLVED;
        }

        if ($defect->service_ticket_id !== null) {
            $ticket = $defect->getRelationValue('serviceTicket');

            return $ticket instanceof ApparatusServiceTicket && ! $ticket->is_open
                ? self::TICKET_CLOSED
                : self::SERVICE_FOLLOW_UP;
        }

        $impact = $defect->operational_impact;
        if ($impact === self::REVIEW_PENDING_IMPACT) {
            return self::AWAITING_REVIEW;
        }

        if ($defect->status === 'in_progress' || in_array($impact, self::REVIEWED_IMPACTS, true)) {
            return self::ACKNOWLEDGED;
        }

        return self::NEW;
    }

    /**
     * @param  Builder<ApparatusDefect>  $query
     * @return Builder<ApparatusDefect>
     */
    public static function applyFilter(Builder $query, ?string $state): Builder
    {
        return match ($state) {
            self::RESOLVED => $query->where('resolved', true),
            self::SERVICE_FOLLOW_UP => $query->where('resolved', false)
                ->whereHas('serviceTicket', fn (Builder $ticket) => $ticket->scopes('open')),
            self::TICKET_CLOSED => $query->where('resolved', false)
                ->whereNotNull('service_ticket_id')
                ->whereDoesntHave('serviceTicket', fn (Builder $ticket) => $ticket->scopes('open')),
            self::AWAITING_REVIEW => $query->where('resolved', false)
                ->whereNull('service_ticket_id')
                ->where('operational_impact', self::REVIEW_PENDING_IMPACT),
            self::ACKNOWLEDGED => $query->where('resolved', false)
                ->whereNull('service_ticket_id')
                ->where(fn (Builder $inner) => $inner
                    ->whereIn('operational_impact', self::REVIEWED_IMPACTS)
                    ->orWhere(fn (Builder $progress) => $progress
                        ->where('status', 'in_progress')
                        ->where(fn (Builder $impact) => $impact->whereNull('operational_impact')
                            ->orWhere('operational_impact', '!=', self::REVIEW_PENDING_IMPACT)))),
            self::NEW => $query->where('resolved', false)
                ->whereNull('service_ticket_id')
                ->where('status', '!=', 'in_progress')
                ->where(fn (Builder $impact) => $impact->whereNull('operational_impact')
                    ->orWhereNotIn('operational_impact', [
                        self::REVIEW_PENDING_IMPACT,
                        ...self::REVIEWED_IMPACTS,
                    ])),
            default => $query,
        };
    }
}
