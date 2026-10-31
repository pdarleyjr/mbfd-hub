<?php

declare(strict_types=1);

namespace Tests\Support\Dashboards;

use App\Models\User;
use App\Support\Dashboards\ActionResult;
use App\Support\Dashboards\DashboardIssue;
use App\Support\Dashboards\DashboardQuery;
use App\Support\Dashboards\IssueAction;
use App\Support\Dashboards\IssueDetail;
use App\Support\Dashboards\IssueSource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * TEST-ONLY synthetic issue source for browser acceptance and screenshots (never registered outside
 * tests: the e2e setup adds it to a disposable config cache). Items are invented fixtures relative to
 * "now"; their workflow state lives in the cache so actions visibly change them.
 */
abstract class FixtureIssueSource implements IssueSource
{
    /**
     * @return list<array{id: string, title: string, summary: ?string, severity: string, stage: string, tags: list<string>,
     *     subject: ?string, imageKey: ?string, actor: ?string, category: ?string, minutesAgo: int}>
     */
    abstract protected function items(): array;

    public function visibleTo(User $user, bool $displayMode): bool
    {
        return ! $displayMode;
    }

    public function rows(User $user, DashboardQuery $query, int $limit): array
    {
        $rows = array_values(array_filter($this->all(), static fn (DashboardIssue $row): bool => ($query->tags === [] || array_intersect($query->tags, $row->tags) !== [])
            && ($query->severity === null || ($row->severity === $query->severity && $row->stage !== 'resolved'))
            && ($query->stage === null || $row->stage === $query->stage)));

        return array_slice($rows, 0, $limit);
    }

    public function counts(User $user, DashboardQuery $query): array
    {
        return FakeIssueSource::countsFor($this->all());
    }

    public function detail(User $user, string $id): ?IssueDetail
    {
        $issue = collect($this->all())->first(fn (DashboardIssue $row): bool => $row->id === $id);
        if (! $issue instanceof DashboardIssue) {
            return null;
        }

        $state = $this->state($id);
        $timeline = [['at' => $issue->occurredAt, 'actor' => $issue->actorLabel, 'text' => 'Reported: '.$issue->title]];
        foreach ($state['notes'] as $note) {
            $timeline[] = ['at' => CarbonImmutable::parse($note['at']), 'actor' => $note['actor'], 'text' => $note['text']];
        }

        $actions = match ($issue->stage) {
            'awaiting_response' => [
                new IssueAction('acknowledge', 'Acknowledge', 'primary'),
                new IssueAction('reply', 'Send reply', 'secondary', [
                    ['name' => 'message', 'label' => 'Reply', 'type' => 'textarea', 'required' => true, 'max' => 500],
                ]),
            ],
            'in_progress' => [
                new IssueAction('resolve', 'Mark completed', 'primary', [
                    ['name' => 'note', 'label' => 'What was done', 'type' => 'textarea', 'required' => true, 'max' => 500],
                    ['name' => 'completed_on', 'label' => 'Completed on', 'type' => 'date', 'required' => false],
                ]),
            ],
            default => [],
        };

        return new IssueDetail(
            issue: $issue,
            body: $issue->summary,
            facts: array_values(array_filter([
                $issue->subject !== null ? ['label' => 'Unit', 'value' => $issue->subject] : null,
                ['label' => 'Category', 'value' => (string) $issue->categoryLabel],
                ['label' => 'Fixture', 'value' => 'Synthetic test data'],
            ])),
            timeline: $timeline,
            actions: $actions,
            readOnlyReason: $actions === [] ? 'Completed. No further action is needed.' : null,
        );
    }

    public function perform(User $user, string $id, string $action, array $input, string $expectedVersion): ActionResult
    {
        $state = $this->state($id);
        if ($expectedVersion !== 'v'.$state['revision']) {
            return ActionResult::conflict();
        }

        $stage = $this->currentStage($id);
        $note = null;
        switch ($action) {
            case 'acknowledge':
                if ($stage !== 'awaiting_response') {
                    return ActionResult::done('Already acknowledged.');
                }
                $state['stage'] = 'in_progress';
                $note = 'Acknowledged';
                break;
            case 'reply':
                $message = trim((string) ($input['message'] ?? ''));
                if ($message === '') {
                    return ActionResult::invalid(['message' => 'Write a reply first.']);
                }
                $note = 'Reply: '.$message;
                break;
            case 'resolve':
                $state['stage'] = 'resolved';
                $note = 'Completed: '.trim((string) ($input['note'] ?? ''));
                break;
            default:
                return ActionResult::forbidden();
        }

        $state['revision']++;
        $state['notes'][] = ['at' => CarbonImmutable::now()->toIso8601String(), 'actor' => $user->name, 'text' => $note];
        Cache::forever($this->stateKey($id), $state);

        return ActionResult::done(match ($action) {
            'acknowledge' => 'Acknowledged. The reporter can see it is being handled.',
            'reply' => 'Reply sent.',
            default => 'Marked completed.',
        });
    }

    /** @return list<DashboardIssue> */
    protected function all(): array
    {
        $now = CarbonImmutable::now();
        $rows = [];
        foreach ($this->items() as $item) {
            $state = $this->state($item['id']);
            $stage = $state['stage'] ?? $item['stage'];
            $rows[] = new DashboardIssue(
                type: $this->type(),
                id: $item['id'],
                title: $item['title'],
                summary: $item['summary'],
                severity: $item['severity'],
                status: $stage,
                statusLabel: match ($stage) {
                    'awaiting_response' => 'New',
                    'in_progress' => 'In progress',
                    'resolved' => 'Completed',
                    default => 'Open',
                },
                stage: $stage,
                tags: $item['tags'],
                subject: $item['subject'],
                imageKey: $item['imageKey'],
                actorLabel: $item['actor'],
                categoryLabel: $item['category'],
                occurredAt: $now->subMinutes($item['minutesAgo']),
                version: 'v'.$state['revision'],
            );
        }

        return $rows;
    }

    private function currentStage(string $id): ?string
    {
        foreach ($this->all() as $row) {
            if ($row->id === $id) {
                return $row->stage;
            }
        }

        return null;
    }

    /** @return array{revision: int, stage?: string, notes: list<array{at: string, actor: ?string, text: string}>} */
    private function state(string $id): array
    {
        $state = Cache::get($this->stateKey($id));

        return is_array($state) ? $state : ['revision' => 1, 'notes' => []];
    }

    private function stateKey(string $id): string
    {
        return 'dashboard-fixture:'.$this->type().':'.$id;
    }
}
