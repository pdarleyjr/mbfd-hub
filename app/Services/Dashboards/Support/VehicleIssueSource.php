<?php

declare(strict_types=1);

namespace App\Services\Dashboards\Support;

use App\Models\User;
use App\Services\Defects\StaleDefectStateException;
use App\Services\Inventory\InventoryCorrectionConflict;
use App\Services\Inventory\InventoryOperationException;
use App\Support\Dashboards\ActionResult;
use App\Support\Dashboards\DashboardIssue;
use App\Support\Dashboards\DashboardQuery;
use App\Support\Dashboards\DashboardView;
use App\Support\Dashboards\IssueSource;
use App\Support\OperationalDisplayWindow;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Shared read pipeline for the vehicle-domain dashboard sources.
 *
 * Every source defines, in SQL over its own table, three expressions: severity
 * ('high'|'medium'|'low'), stage ('awaiting_response'|'in_progress'|'resolved'|'other') and the
 * moment the row is dated by. rows() SELECTs those expressions and the DashboardIssue is built from
 * the selected values, and counts() GROUPs BY the very same expressions, so a count can never
 * disagree with the rows it summarises. The filter tag of a row is its apparatus class
 * (ApparatusClass) plus any source tag ("inventory", "repair") defined as an SQL condition.
 *
 * Row set ("rows" below): open records plus records closed inside the active operational day
 * (08:00 America/New_York, OperationalDisplayWindow), narrowed by the DashboardQuery filters.
 * Order: open rows before same-day closures (a closure is never an alert), then severity
 * (high, medium, low), then most recent first, then id; the Overview takes the top N.
 *
 * counts() keys, each over that same row set:
 *  - open:              rows whose stage is awaiting_response or in_progress;
 *  - awaiting_response: rows whose stage is awaiting_response;
 *  - in_progress:       rows whose stage is in_progress;
 *  - high:              open rows whose severity is high;
 *  - resolved_today:    rows whose stage is resolved (only same-day closures are in the set);
 *  - <tag>:             open rows carrying that tag (every apparatus class tag and source tag).
 *
 * @template TModel of Model
 */
abstract class VehicleIssueSource implements IssueSource
{
    /** No dashboard request ever loads more rows than this from one source. */
    public const HARD_CAP = 200;

    /** @var list<string> */
    protected const OPEN_STAGES = ['awaiting_response', 'in_progress'];

    public function __construct(protected readonly ApparatusClass $classes) {}

    /** @return list<DashboardView> */
    public function views(): array
    {
        return [DashboardView::Overview, DashboardView::VehicleCheckout];
    }

    /** @return Builder<TModel> */
    abstract protected function newQuery(): Builder;

    /** Open records, plus records closed inside the window. @param Builder<TModel> $query */
    abstract protected function scopeToBoard(Builder $query, OperationalDisplayWindow $window): void;

    abstract protected function severitySql(): string;

    abstract protected function stageSql(): string;

    abstract protected function occurredSql(): string;

    /** @return array<string, string> source tag => SQL condition (no user input) */
    protected function sourceTagSql(): array
    {
        return [];
    }

    /** @return array<int|string, mixed> eager loads; must include the apparatus relation with ApparatusClass::COLUMNS */
    abstract protected function eagerLoads(): array;

    /** The column naming the truck this row is about. */
    protected function apparatusColumn(): string
    {
        return 'apparatus_id';
    }

    /** Extra row selects (concurrency token parts). @return array<string, mixed> */
    protected function rowSelects(): array
    {
        return [];
    }

    /**
     * @param  TModel  $record
     * @param  array{severity: string, stage: string, occurredAt: CarbonImmutable, tags: list<string>, subject: string|null, imageKey: string|null}  $projection
     */
    abstract protected function toIssue(Model $record, bool $displayMode, array $projection): DashboardIssue;

    // ------------------------------------------------------------------ reads

    public function rows(User $user, DashboardQuery $query, int $limit): array
    {
        if ($limit < 1 || ! $this->visibleTo($user, $query->displayMode)) {
            return [];
        }
        $builder = $this->filtered($query);
        if ($builder === null) {
            return [];
        }

        return $builder
            ->orderByRaw("CASE WHEN ({$this->stageSql()}) IN ('awaiting_response', 'in_progress') THEN 0 ELSE 1 END")
            ->orderByRaw($this->severityRankSql())
            ->orderByRaw('('.$this->occurredSql().') DESC')
            ->orderByDesc($builder->getModel()->qualifyColumn('id'))
            ->limit(min($limit, self::HARD_CAP))
            ->get()
            ->map(fn (Model $record): DashboardIssue => $this->project($record, $query->displayMode))
            ->values()
            ->all();
    }

    public function counts(User $user, DashboardQuery $query): array
    {
        $counts = ['open' => 0, 'awaiting_response' => 0, 'in_progress' => 0, 'high' => 0, 'resolved_today' => 0];
        foreach ([...ApparatusClass::TAGS, ...array_keys($this->sourceTagSql())] as $tag) {
            $counts[$tag] = 0;
        }
        if (! $this->visibleTo($user, $query->displayMode)) {
            return $counts;
        }
        $builder = $this->filtered($query, forCount: true);
        if ($builder === null) {
            return $counts;
        }

        $inner = $builder->toBase()->selectRaw(implode(', ', [
            $builder->getModel()->qualifyColumn($this->apparatusColumn()).' AS apparatus_id',
            '('.$this->severitySql().') AS dashboard_severity',
            '('.$this->stageSql().') AS dashboard_stage',
            ...$this->sourceTagColumns(),
        ]));
        $groupColumns = ['apparatus_id', 'dashboard_severity', 'dashboard_stage', ...array_map(static fn (string $tag): string => 'dashboard_tag_'.$tag, array_keys($this->sourceTagSql()))];
        $groups = DB::query()->fromSub($inner, 'board')
            ->select($groupColumns)
            ->selectRaw('COUNT(*) AS aggregate')
            ->groupBy($groupColumns)
            ->get();

        $classes = $groups->isEmpty() ? [] : $this->classes->all();
        foreach ($groups as $group) {
            $n = (int) $group->aggregate;
            $open = in_array($group->dashboard_stage, self::OPEN_STAGES, true);
            $counts['open'] += $open ? $n : 0;
            $counts['awaiting_response'] += $group->dashboard_stage === 'awaiting_response' ? $n : 0;
            $counts['in_progress'] += $group->dashboard_stage === 'in_progress' ? $n : 0;
            $counts['high'] += $open && $group->dashboard_severity === 'high' ? $n : 0;
            $counts['resolved_today'] += $group->dashboard_stage === 'resolved' ? $n : 0;
            if (! $open) {
                continue;
            }
            $counts[$classes[(int) $group->apparatus_id]['tag'] ?? ApparatusClass::SPECIAL] += $n;
            foreach (array_keys($this->sourceTagSql()) as $tag) {
                $counts[$tag] += (int) $group->{'dashboard_tag_'.$tag} === 1 ? $n : 0;
            }
        }

        return $counts;
    }

    /** One record by id with the same projection rows() uses (no board window: any record the viewer may open). */
    protected function freshIssue(string $id, bool $displayMode = false): ?array
    {
        if (preg_match('/^[1-9][0-9]{0,18}$/', $id) !== 1) {
            return null;
        }
        $builder = $this->newQuery();
        $record = $this->withRowColumns($builder)->whereKey((int) $id)->first();

        return $record === null ? null : ['record' => $record, 'issue' => $this->project($record, $displayMode)];
    }

    /** @return Builder<TModel>|null null when the filters can match nothing */
    private function filtered(DashboardQuery $query, bool $forCount = false): ?Builder
    {
        $builder = $this->newQuery();
        $this->scopeToBoard($builder, $query->window);

        if ($query->tags !== []) {
            $wanted = array_values(array_unique($query->tags));
            $classTags = array_values(array_intersect($wanted, ApparatusClass::TAGS));
            $sourceTags = array_intersect_key($this->sourceTagSql(), array_flip($wanted));
            $apparatusIds = $classTags === [] ? [] : array_keys(array_filter(
                $this->classes->all(),
                static fn (array $class): bool => in_array($class['tag'], $classTags, true),
            ));
            if ($apparatusIds === [] && $sourceTags === []) {
                return null;
            }
            $column = $builder->getModel()->qualifyColumn($this->apparatusColumn());
            $builder->where(function (Builder $tagged) use ($apparatusIds, $sourceTags, $column): void {
                if ($apparatusIds !== []) {
                    $tagged->orWhereIn($column, $apparatusIds);
                }
                foreach ($sourceTags as $condition) {
                    $tagged->orWhereRaw('('.$condition.')');
                }
            });
        }
        if ($query->severity !== null) {
            $builder->whereRaw('('.$this->severitySql().') = ?', [$query->severity]);
        }
        if ($query->stage !== null) {
            $builder->whereRaw('('.$this->stageSql().') = ?', [$query->stage]);
        }

        return $forCount ? $builder : $this->withRowColumns($builder);
    }

    /** @param Builder<TModel> $builder @return Builder<TModel> */
    private function withRowColumns(Builder $builder): Builder
    {
        return $builder
            ->select($builder->getModel()->qualifyColumn('*'))
            ->selectRaw('('.$this->severitySql().') AS dashboard_severity')
            ->selectRaw('('.$this->stageSql().') AS dashboard_stage')
            ->selectRaw('('.$this->occurredSql().') AS dashboard_occurred_at')
            ->selectRaw(implode(', ', $this->sourceTagColumns()) ?: '0 AS dashboard_no_tags')
            ->addSelect($this->rowSelects())
            ->with($this->eagerLoads());
    }

    /** @return list<string> */
    private function sourceTagColumns(): array
    {
        $columns = [];
        foreach ($this->sourceTagSql() as $tag => $condition) {
            $columns[] = 'CASE WHEN ('.$condition.') THEN 1 ELSE 0 END AS dashboard_tag_'.$tag;
        }

        return $columns;
    }

    private function severityRankSql(): string
    {
        return "CASE ({$this->severitySql()}) WHEN 'high' THEN 0 WHEN 'medium' THEN 1 ELSE 2 END";
    }

    private function project(Model $record, bool $displayMode): DashboardIssue
    {
        $apparatus = $record->getRelationValue('apparatus');
        $class = $this->classes->of($apparatus);
        $tags = [$class['tag']];
        foreach (array_keys($this->sourceTagSql()) as $tag) {
            if ((int) $record->getAttribute('dashboard_tag_'.$tag) === 1) {
                $tags[] = $tag;
            }
        }
        $occurred = $record->getAttribute('dashboard_occurred_at');

        $issue = $this->toIssue($record, $displayMode, [
            'severity' => (string) $record->getAttribute('dashboard_severity'),
            'stage' => (string) $record->getAttribute('dashboard_stage'),
            'occurredAt' => $occurred === null ? CarbonImmutable::now('UTC') : CarbonImmutable::parse((string) $occurred, 'UTC'),
            'tags' => $tags,
            'subject' => ApparatusClass::label($apparatus),
            'imageKey' => $class['imageKey'],
        ]);
        $apparatusId = $record->getAttribute($this->apparatusColumn());

        return $apparatusId === null ? $issue : $issue->inGroup('apparatus:'.(int) $apparatusId);
    }

    // ---------------------------------------------------------------- actions

    /**
     * Runs one dashboard action and turns the domain's refusals into ActionResults. Anything thrown
     * inside $work rolls the caller's transaction back, so a refused action writes nothing.
     *
     * @param  Closure(): ActionResult  $work
     */
    protected function attempt(Closure $work): ActionResult
    {
        try {
            return $work();
        } catch (ValidationException $exception) {
            $errors = array_map(static fn (array $messages): string => (string) ($messages[0] ?? 'This value is not valid.'), $exception->errors());

            return ActionResult::invalid($errors, (string) (reset($errors) ?: 'Check the highlighted fields.'));
        } catch (StaleVersion|StaleDefectStateException) {
            return ActionResult::conflict();
        } catch (InventoryCorrectionConflict $conflict) {
            return $conflict->reason === InventoryCorrectionConflict::DEPENDENT_CHANGE
                ? ActionResult::invalid(['action' => $conflict->getMessage()], $conflict->getMessage())
                : ActionResult::conflict($conflict->getMessage());
        } catch (InventoryOperationException $exception) {
            return ActionResult::invalid(['action' => $exception->getMessage()], $exception->getMessage());
        } catch (AuthorizationException) {
            return ActionResult::forbidden();
        } catch (ModelNotFoundException) {
            return ActionResult::gone();
        } catch (HttpExceptionInterface $exception) {
            return $exception->getStatusCode() === 404 ? ActionResult::gone() : ActionResult::forbidden();
        }
    }

    /** A plain id the dashboard routes by, or null when malformed. */
    protected static function intId(string $id): ?int
    {
        return preg_match('/^[1-9][0-9]{0,18}$/', $id) === 1 ? (int) $id : null;
    }

    protected static function personName(?User $user): ?string
    {
        if (! $user instanceof User) {
            return null;
        }
        $name = trim((string) ($user->display_name ?: $user->name));

        return $name === '' ? null : $name;
    }

    /** Optional free text from the panel, trimmed; null when blank. */
    protected static function text(array $input, string $key): ?string
    {
        $value = $input[$key] ?? null;
        if (! is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /** @return array<string, mixed> */
    protected static function textarea(string $name, string $label, bool $required = false, int $max = 2000): array
    {
        return ['name' => $name, 'label' => $label, 'type' => 'textarea', 'required' => $required, 'max' => $max];
    }

    /** @param array<string|int, string> $options @return array<string, mixed> */
    protected static function select(string $name, string $label, array $options, bool $required = true): array
    {
        return ['name' => $name, 'label' => $label, 'type' => 'select', 'options' => $options, 'required' => $required];
    }

    protected static function local(?\DateTimeInterface $at): ?string
    {
        return $at === null ? null : CarbonImmutable::instance($at)->setTimezone(OperationalDisplayWindow::TIMEZONE)->format('M j, Y g:i A');
    }
}
