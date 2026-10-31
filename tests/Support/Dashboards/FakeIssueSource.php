<?php

declare(strict_types=1);

namespace Tests\Support\Dashboards;

use App\Models\User;
use App\Support\Dashboards\ActionResult;
use App\Support\Dashboards\DashboardIssue;
use App\Support\Dashboards\DashboardQuery;
use App\Support\Dashboards\DashboardView;
use App\Support\Dashboards\IssueDetail;
use App\Support\Dashboards\IssueSource;
use Carbon\CarbonImmutable;
use Closure;

/** Test-only IssueSource with programmable rows, counts, details and outcomes. Records every call. */
final class FakeIssueSource implements IssueSource
{
    public int $rowsCalls = 0;

    public int $countsCalls = 0;

    public int $detailCalls = 0;

    /** @var list<DashboardQuery> */
    public array $rowQueries = [];

    /** @var list<array{id: string, action: string, input: array<string, mixed>, version: string}> */
    public array $performed = [];

    /**
     * @param  list<DashboardView>  $views
     * @param  list<DashboardIssue>  $rows
     * @param  array<string, int>|null  $counts  null = derived from $rows
     * @param  array<string, IssueDetail|Closure(): ?IssueDetail>  $details
     */
    public function __construct(
        public string $type = 'fake_vehicle',
        public array $views = [DashboardView::Overview, DashboardView::VehicleCheckout],
        public array $rows = [],
        public ?array $counts = null,
        public array $details = [],
        public bool $visible = true,
        public ?Closure $performUsing = null,
        public ?Closure $onRows = null,
    ) {}

    public function type(): string
    {
        return $this->type;
    }

    public function views(): array
    {
        return $this->views;
    }

    public function visibleTo(User $user, bool $displayMode): bool
    {
        return $this->visible;
    }

    public function rows(User $user, DashboardQuery $query, int $limit): array
    {
        $this->rowsCalls++;
        $this->rowQueries[] = $query;
        if ($this->onRows !== null) {
            ($this->onRows)();
        }

        $rows = array_values(array_filter($this->rows, static fn (DashboardIssue $row): bool => ($query->tags === [] || array_intersect($query->tags, $row->tags) !== [])
            && ($query->severity === null || $row->severity === $query->severity)
            && ($query->stage === null || $row->stage === $query->stage)));

        return array_slice($rows, 0, $limit);
    }

    public function counts(User $user, DashboardQuery $query): array
    {
        $this->countsCalls++;

        return $this->counts ?? self::countsFor($this->rows);
    }

    public function detail(User $user, string $id): ?IssueDetail
    {
        $this->detailCalls++;
        $detail = $this->details[$id] ?? null;

        return $detail instanceof Closure ? $detail() : $detail;
    }

    public function perform(User $user, string $id, string $action, array $input, string $expectedVersion): ActionResult
    {
        $this->performed[] = ['id' => $id, 'action' => $action, 'input' => $input, 'version' => $expectedVersion];

        return $this->performUsing !== null
            ? ($this->performUsing)($id, $action, $input, $expectedVersion)
            : ActionResult::done('Saved.');
    }

    /**
     * @param  list<DashboardIssue>  $rows
     * @return array<string, int>
     */
    public static function countsFor(array $rows): array
    {
        $counts = ['open' => 0, 'awaiting_response' => 0, 'high' => 0, 'resolved_today' => 0, 'in_progress' => 0];
        foreach ($rows as $row) {
            if ($row->stage === 'resolved') {
                $counts['resolved_today']++;

                continue;
            }
            $counts['open']++;
            if ($row->stage === 'awaiting_response') {
                $counts['awaiting_response']++;
            }
            if ($row->stage === 'in_progress') {
                $counts['in_progress']++;
            }
            if ($row->severity === 'high') {
                $counts['high']++;
            }
            foreach ($row->tags as $tag) {
                $counts[$tag] = ($counts[$tag] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /** @param  array<string, mixed>  $overrides */
    public static function issue(string $type, string $id, array $overrides = []): DashboardIssue
    {
        $values = array_merge([
            'title' => 'Issue '.$id,
            'summary' => null,
            'severity' => 'medium',
            'status' => 'open',
            'statusLabel' => 'Open',
            'stage' => 'awaiting_response',
            'tags' => [],
            'subject' => null,
            'imageKey' => null,
            'actorLabel' => null,
            'categoryLabel' => null,
            'occurredAt' => CarbonImmutable::now(),
            'version' => 'v1',
            'sourceUrl' => null,
        ], $overrides);

        return new DashboardIssue(
            type: $type,
            id: $id,
            title: $values['title'],
            summary: $values['summary'],
            severity: $values['severity'],
            status: $values['status'],
            statusLabel: $values['statusLabel'],
            stage: $values['stage'],
            tags: $values['tags'],
            subject: $values['subject'],
            imageKey: $values['imageKey'],
            actorLabel: $values['actorLabel'],
            categoryLabel: $values['categoryLabel'],
            occurredAt: $values['occurredAt'],
            version: $values['version'],
            sourceUrl: $values['sourceUrl'],
        );
    }
}
