<?php

declare(strict_types=1);

namespace App\Support\Fleet;

use App\Models\ApparatusChangeout;
use App\Models\ApparatusOperationalAlert;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The two Operations navigation badges. Every admin page draws the sidebar, and draws it more than once, so both
 * numbers come from one query and that query runs once per HTTP request. A badge never takes a page down.
 */
final class FleetQueueCounts
{
    private const REQUEST_KEY = 'fleet.queue-counts';

    public static function activeAlerts(): int
    {
        return self::thisRequest()['active_alerts'];
    }

    public static function pendingChangeouts(): int
    {
        return self::thisRequest()['pending_changeouts'];
    }

    /** @return array{active_alerts: int, pending_changeouts: int} */
    private static function thisRequest(): array
    {
        $attributes = request()->attributes;
        if (! $attributes->has(self::REQUEST_KEY)) {
            $attributes->set(self::REQUEST_KEY, self::load());
        }

        return $attributes->get(self::REQUEST_KEY);
    }

    /** @return array{active_alerts: int, pending_changeouts: int} */
    private static function load(): array
    {
        try {
            $row = DB::query()
                ->selectSub(ApparatusOperationalAlert::query()->active()->selectRaw('count(*)'), 'active_alerts')
                ->selectSub(ApparatusChangeout::query()->where('status', ApparatusChangeout::STATUS_PENDING_FLEET)->selectRaw('count(*)'), 'pending_changeouts')
                ->first();
        } catch (Throwable $exception) {
            report($exception);

            return ['active_alerts' => 0, 'pending_changeouts' => 0];
        }

        return [
            'active_alerts' => (int) ($row->active_alerts ?? 0),
            'pending_changeouts' => (int) ($row->pending_changeouts ?? 0),
        ];
    }
}
