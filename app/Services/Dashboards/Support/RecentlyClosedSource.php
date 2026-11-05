<?php

declare(strict_types=1);

namespace App\Services\Dashboards\Support;

use App\Models\ApparatusDefect;
use App\Models\User;
use App\Services\Dashboards\Sources\Station\QueryBackedSource;
use App\Support\Dashboards\DashboardQuery;
use App\Support\OperationalDisplayWindow;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * QueryBackedSource for what members submit (uniform orders, PPE requests, medical supply requests, narcotics checks,
 * station supply requests), with the board's display rules:
 *
 * - Row set: every open record, plus records closed in the last dashboards.resolved_display_hours (72 by default),
 *   the same retention the equipment issues use. They sort after the open ones (prepareRows, IssueGroups). Staff take
 *   one off early through the record's own archive action where the domain has one. "resolved_today" still counts
 *   only what closed inside the operational day (whereStage('resolved')).
 * - Extra facet counts (REQUEST_TAGS) on top of QueryBackedSource::TAGS, each "open and carrying the tag".
 * - "Member replied": whereMemberReplied()/memberReplied() is the ONE hook per source. It reads the domain's own
 *   reply signal today; the member-replies work can switch it to a shared field such as last_member_reply_at
 *   without touching anything else. A row that matches gets the "member_replied" tag, which drives the chip, the
 *   "Member replied" queue group and its count.
 *
 * @template TModel of Model
 *
 * @extends QueryBackedSource<TModel>
 */
abstract class RecentlyClosedSource extends QueryBackedSource
{
    /** Tags the request sources report: kind (uniform, ppe, medical, narcotics) and where the request stands. */
    public const REQUEST_TAGS = ['uniform', 'ppe', 'medical', 'narcotics', 'member_replied', 'waiting_member', 'awaiting_pickup'];

    /**
     * Records that closed (completed, denied, cancelled, decided, reconciled...) at or after $since.
     *
     * @param  Builder<TModel>  $query
     */
    abstract protected function whereClosedSince(Builder $query, CarbonImmutable $since): void;

    /**
     * Tag constraints for REQUEST_TAGS (plus any QueryBackedSource::TAGS this source uses). "member_replied" is added here.
     *
     * @return array<string, Closure(Builder<TModel>): mixed>
     */
    abstract protected function requestTagConstraints(): array;

    /**
     * The member answered and staff have not acted since. Default: no signal.
     *
     * @param  Builder<TModel>  $query
     */
    protected function whereMemberReplied(Builder $query): void
    {
        self::nothing($query);
    }

    /** Same rule as whereMemberReplied(), for one loaded row (its select must carry what this reads). */
    protected function memberReplied(Model $record): bool
    {
        return false;
    }

    /**
     * The ReplySubject key this source's records use in submission_reply_states, or null when the workflow has no
     * member reply thread. The shared "awaiting staff" flag (MemberReplyLedger) then counts as "member replied"
     * alongside the domain's own signal; the domain signal still covers replies sent before that flag existed.
     */
    protected function replySubject(): ?string
    {
        return null;
    }

    /**
     * Adds dashboard_awaiting_staff_reply to the row select. Sources with a replySubject() call this from prepareRows().
     *
     * @param  Builder<TModel>  $query
     */
    final protected function selectAwaitingStaffReply(Builder $query): void
    {
        $type = $this->replySubject();
        if ($type === null) {
            return;
        }
        $table = $query->getModel()->getTable();
        $query->selectRaw(
            "EXISTS (SELECT 1 FROM submission_reply_states WHERE submission_reply_states.subject_type = ? AND submission_reply_states.subject_id = {$table}.id AND submission_reply_states.awaiting_staff_reply = ?) as dashboard_awaiting_staff_reply",
            [$type, true],
        );
    }

    final protected function tagConstraints(): array
    {
        return [
            ...$this->requestTagConstraints(),
            'member_replied' => fn (Builder $q) => $q->where(function (Builder $replied): void {
                $this->whereMemberReplied($replied);
                $this->orWhereAwaitingStaffReply($replied);
            }),
        ];
    }

    /** @param  Builder<TModel>  $query */
    private function orWhereAwaitingStaffReply(Builder $query): void
    {
        $type = $this->replySubject();
        if ($type === null) {
            return;
        }
        $table = $query->getModel()->getTable();
        $query->orWhereExists(static fn ($state) => $state->selectRaw('1')->from('submission_reply_states')
            ->where('submission_reply_states.subject_type', $type)
            ->whereColumn('submission_reply_states.subject_id', $table.'.id')
            ->where('submission_reply_states.awaiting_staff_reply', true));
    }

    public function counts(User $user, DashboardQuery $query): array
    {
        $counts = parent::counts($user, $query);
        $visible = $this->visibleTo($user, $query->displayMode);
        $tags = $this->tagConstraints();
        $faceted = $visible ? $this->filtered($query, false) : null;
        foreach (self::REQUEST_TAGS as $tag) {
            $counts[$tag] = $faceted !== null && isset($tags[$tag])
                ? (clone $faceted)->where(fn (Builder $q) => $this->whereOpen($q))->where($tags[$tag])->count()
                : 0;
        }

        return $counts;
    }

    /** Oldest closing time still shown: now minus dashboards.resolved_display_hours, in UTC like the stored columns. */
    public static function closedSince(OperationalDisplayWindow $window): CarbonImmutable
    {
        return $window->now->utc()->subHours(ApparatusDefect::resolvedDisplayHours());
    }

    protected function filtered(DashboardQuery $query, bool $withTagFilter): Builder
    {
        $window = $query->window;
        $since = self::closedSince($window);
        $builder = $this->baseQuery()->where(function (Builder $scope) use ($since): void {
            $scope->where(fn (Builder $q) => $this->whereOpen($q))
                ->orWhere(fn (Builder $q) => $this->whereClosedSince($q, $since));
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

    /**
     * Tags for one row: its kind, plus the stage tags its status earns, plus "member_replied" when the hook says so.
     *
     * @param  list<string>  $tags
     * @return list<string>
     */
    protected function withReplyTag(Model $record, array $tags, bool $open): array
    {
        if ($open && ($this->memberReplied($record) || (bool) $record->getAttribute('dashboard_awaiting_staff_reply'))) {
            $tags[] = 'member_replied';
        }

        return array_values(array_unique($tags));
    }

    /**
     * "member:<user id>" so a member's requests and their other items (Hub reports, messages) share one row and one
     * page; "employee:<employee id>" for a member with no Hub sign-in, so their requests still share one row.
     */
    protected static function memberGroup(mixed $userId, mixed $employeeId = null): ?string
    {
        $user = is_numeric($userId) ? (int) $userId : 0;
        $employee = is_numeric($employeeId) ? (int) $employeeId : 0;

        return match (true) {
            $user > 0 => 'member:'.$user,
            $employee > 0 => 'employee:'.$employee,
            default => null,
        };
    }

    /** A stored timestamp as a dashboard time, never null (falls back to $fallback). */
    protected static function at(mixed $value, mixed $fallback = null): CarbonImmutable
    {
        foreach ([$value, $fallback] as $candidate) {
            if ($candidate instanceof \DateTimeInterface) {
                return CarbonImmutable::instance($candidate);
            }
            if (is_string($candidate) && $candidate !== '') {
                return CarbonImmutable::parse($candidate, 'UTC');
            }
        }

        return CarbonImmutable::now();
    }
}
