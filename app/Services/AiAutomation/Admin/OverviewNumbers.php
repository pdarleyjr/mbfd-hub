<?php

declare(strict_types=1);

namespace App\Services\AiAutomation\Admin;

use App\Models\AiAutomation\AiIntakeEvent;
use App\Models\ApparatusDefect;
use App\Models\StationRequest;
use App\Models\StationSupplyRequest;
use App\Models\SupportTriage\SupportTriageLevel;
use App\Services\SupportTriage\TriageProjector;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The few numbers the Overview page shows. Every figure is one small query, and all of them run only when the
 * Overview page is open (never on other admin pages).
 */
final class OverviewNumbers
{
    /**
     * Open issues at each priority: the effective level in support_triage_levels, counted only while the
     * record behind it is still open.
     *
     * @return array{low: int, medium: int, high: int}
     */
    public function openLevelCounts(): array
    {
        $rows = SupportTriageLevel::query()
            ->where(function ($query): void {
                $query
                    ->where(fn ($q) => $q->where('subject_type', TriageProjector::DEFECT)->whereIn('subject_id', ApparatusDefect::query()->where('resolved', false)->select('id')))
                    ->orWhere(fn ($q) => $q->where('subject_type', TriageProjector::STATION_REQUEST)->whereIn('subject_id', StationRequest::query()->open()->select('id')))
                    ->orWhere(fn ($q) => $q->where('subject_type', TriageProjector::SUPPLY_REQUEST)->whereIn('subject_id', StationSupplyRequest::query()->open()->select('id')));
            })
            ->select('level', DB::raw('count(*) as total'))
            ->groupBy('level')
            ->pluck('total', 'level');

        return [
            'low' => (int) ($rows['low'] ?? 0),
            'medium' => (int) ($rows['medium'] ?? 0),
            'high' => (int) ($rows['high'] ?? 0),
        ];
    }

    /**
     * @return array{sent_today: int, waiting_for_staff: int, errors: int, flagged_today: int, queued: int, stopped_today: int, skipped_today: int}
     */
    public function today(): array
    {
        $timezone = (string) config('ai_automation.timezone', 'America/New_York');
        $start = CarbonImmutable::now($timezone)->startOfDay()->utc();
        $week = CarbonImmutable::now()->subDays(7);

        $todayRows = fn () => AiIntakeEvent::query()->where('updated_at', '>=', $start);

        return [
            'sent_today' => $todayRows()->where('state', AiIntakeEvent::STATE_SENT)->count(),
            'flagged_today' => $todayRows()->where('state', AiIntakeEvent::STATE_SENT)->whereIn('risk_tier', ['critical', 'elevated'])->count(),
            'skipped_today' => $todayRows()->where('state', AiIntakeEvent::STATE_SKIPPED)->count(),
            'stopped_today' => $todayRows()->where('state', AiIntakeEvent::STATE_CANCELLED)->count(),
            'queued' => AiIntakeEvent::query()->whereIn('state', AiIntakeEvent::OPEN_STATES)->count(),
            'waiting_for_staff' => AiIntakeEvent::query()
                ->where('state', AiIntakeEvent::STATE_SENT)
                ->where('needs_human', true)
                ->whereIn('risk_tier', ['critical', 'elevated'])
                ->where('updated_at', '>=', $week)
                ->count(),
            'errors' => AiIntakeEvent::query()->where('state', AiIntakeEvent::STATE_FAILED)->where('updated_at', '>=', $week)->count(),
        ];
    }

    /**
     * The last seven days (oldest first) of responses sent, with how each was written and for which area.
     *
     * @return array{days: list<array{label: string, count: int}>, total: int, from_messages: int, from_model: int, from_guidance: int, by_area: array<string, int>}
     */
    public function lastSevenDays(): array
    {
        $timezone = (string) config('ai_automation.timezone', 'America/New_York');
        $startLocal = CarbonImmutable::now($timezone)->startOfDay()->subDays(6);

        $events = AiIntakeEvent::query()
            ->where('state', AiIntakeEvent::STATE_SENT)
            ->where('updated_at', '>=', $startLocal->utc())
            ->get(['updated_at', 'domain', 'template_key', 'model_call_id', 'knowledge_refs']);

        $perDay = [];
        for ($i = 0; $i < 7; $i++) {
            $perDay[$startLocal->addDays($i)->format('Y-m-d')] = 0;
        }
        $byArea = [];
        $fromMessages = $fromModel = $fromGuidance = 0;

        foreach ($events as $event) {
            $day = CarbonImmutable::parse($event->updated_at)->setTimezone($timezone)->format('Y-m-d');
            if (isset($perDay[$day])) {
                $perDay[$day]++;
            }
            $area = AdminLabels::area((string) $event->domain);
            $byArea[$area] = ($byArea[$area] ?? 0) + 1;
            if ($event->model_call_id !== null) {
                $fromModel++;
            } elseif (! empty($event->knowledge_refs)) {
                $fromGuidance++;
            } else {
                $fromMessages++;
            }
        }

        $days = [];
        foreach ($perDay as $date => $count) {
            $days[] = ['label' => CarbonImmutable::parse($date, $timezone)->format('D M j'), 'count' => $count];
        }
        arsort($byArea);

        return [
            'days' => $days,
            'total' => $events->count(),
            'from_messages' => $fromMessages,
            'from_model' => $fromModel,
            'from_guidance' => $fromGuidance,
            'by_area' => $byArea,
        ];
    }
}
