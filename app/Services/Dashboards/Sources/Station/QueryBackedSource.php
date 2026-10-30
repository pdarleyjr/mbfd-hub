<?php

declare(strict_types=1);

namespace App\Services\Dashboards\Sources\Station;

use App\Models\User;
use App\Support\Dashboards\DashboardIssue;
use App\Support\Dashboards\DashboardQuery;
use App\Support\Dashboards\IssueSource;
use App\Support\OperationalDisplayWindow;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Shared rows()/counts() plumbing for the station-side dashboard adapters, so every count is
 * computed from exactly the predicate rows() uses.
 *
 * In scope for a render: every OPEN record, plus records RESOLVED inside the operational day
 * (OperationalDisplayWindow, 08:00 America/New_York). Count keys:
 * - open: in-scope rows whose stage is not "resolved".
 * - awaiting_response: rows in stage "awaiting_response" (nobody has acted yet).
 * - high: open rows with severity "high".
 * - resolved_today: rows in stage "resolved" (resolved inside the window).
 * - facilities / supplies / access_it / other: in-scope rows carrying that tag. These facet counts
 *   apply the query's stage and severity filters but not its tag filter, so every chip keeps its number.
 */
abstract class QueryBackedSource implements IssueSource
{
    public const ROW_CAP = 200;

    /** Filter chips every station adapter reports (zero where a source has no such rows). */
    public const TAGS = ['facilities', 'supplies', 'access_it', 'other'];

    /** @return Builder<Model> records this source may show, before window and dashboard filters */
    abstract protected function baseQuery(): Builder;

    abstract protected function whereOpen(Builder $query): void;

    /** Narrow to one stage; "resolved" must be limited to resolutions inside $window. */
    abstract protected function whereStage(Builder $query, string $stage, OperationalDisplayWindow $window): void;

    abstract protected function whereSeverity(Builder $query, string $severity): void;

    /** @return array<string, Closure(Builder): void> tag => constraint */
    abstract protected function tagConstraints(): array;

    /** Eager loads and ordering for list rows (no per-row queries in toIssue()). */
    abstract protected function prepareRows(Builder $query): void;

    abstract protected function toIssue(Model $record, bool $displayMode): DashboardIssue;

    public function rows(User $user, DashboardQuery $query, int $limit): array
    {
        $limit = max(0, min($limit, self::ROW_CAP));
        if ($limit === 0 || ! $this->visibleTo($user, $query->displayMode)) {
            return [];
        }
        $builder = $this->filtered($query, true);
        $this->prepareRows($builder);

        return $builder->limit($limit)->get()
            ->map(fn (Model $record): DashboardIssue => $this->toIssue($record, $query->displayMode))
            ->values()->all();
    }

    public function counts(User $user, DashboardQuery $query): array
    {
        $keys = ['open', 'awaiting_response', 'high', 'resolved_today', ...self::TAGS];
        if (! $this->visibleTo($user, $query->displayMode)) {
            return array_fill_keys($keys, 0);
        }
        $window = $query->window;
        $filtered = $this->filtered($query, true);
        $count = static fn (Builder $base, Closure $constraint): int => (clone $base)->where($constraint)->count();

        $counts = [
            'open' => $count($filtered, fn (Builder $q) => $this->whereOpen($q)),
            'awaiting_response' => $count($filtered, fn (Builder $q) => $this->whereStage($q, 'awaiting_response', $window)),
            'high' => $count($filtered, function (Builder $q): void {
                $this->whereOpen($q);
                $this->whereSeverity($q, 'high');
            }),
            'resolved_today' => $count($filtered, fn (Builder $q) => $this->whereStage($q, 'resolved', $window)),
        ];
        $faceted = $this->filtered($query, false);
        $tags = $this->tagConstraints();
        foreach (self::TAGS as $tag) {
            $counts[$tag] = isset($tags[$tag]) ? $count($faceted, $tags[$tag]) : 0;
        }

        return $counts;
    }

    /** @return Builder<Model> */
    protected function filtered(DashboardQuery $query, bool $withTagFilter): Builder
    {
        $window = $query->window;
        $builder = $this->baseQuery()->where(function (Builder $scope) use ($window): void {
            $scope->where(fn (Builder $q) => $this->whereOpen($q))
                ->orWhere(fn (Builder $q) => $this->whereStage($q, 'resolved', $window));
        });
        if ($query->stage !== null) {
            $builder->where(fn (Builder $q) => $this->whereStage($q, $query->stage, $window));
        }
        if ($query->severity !== null) {
            $builder->where(fn (Builder $q) => $this->whereSeverity($q, $query->severity));
        }
        if ($withTagFilter && $query->tags !== []) {
            $wanted = array_intersect_key($this->tagConstraints(), array_flip($query->tags));
            $builder->where(function (Builder $q) use ($wanted): void {
                if ($wanted === []) {
                    $q->whereRaw('1 = 0');
                }
                foreach ($wanted as $constraint) {
                    $q->orWhere($constraint);
                }
            });
        }

        return $builder;
    }

    /** Column inside [window start, window end), compared in UTC like every stored timestamp. */
    protected static function whereInWindow(Builder $query, string $column, OperationalDisplayWindow $window): void
    {
        $query->where($column, '>=', $window->databaseStart())->where($column, '<', $window->databaseEnd());
    }

    protected static function nothing(Builder $query): void
    {
        $query->whereRaw('1 = 0');
    }

    /** updated_at to the microsecond, the base of every version token here. */
    protected static function stamp(?CarbonInterface $at): string
    {
        return $at?->copy()->utc()->format('Y-m-d\TH:i:s.u\Z') ?? '-';
    }

    /** @return array<string, string> field => first message */
    protected static function errorsOf(ValidationException $exception): array
    {
        return collect($exception->errors())
            ->map(fn (array $messages): string => (string) ($messages[0] ?? 'Invalid value.'))
            ->all();
    }

    protected static function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
