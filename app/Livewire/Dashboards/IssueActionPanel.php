<?php

declare(strict_types=1);

namespace App\Livewire\Dashboards;

use App\Models\User;
use App\Services\Dashboards\DashboardAccess;
use App\Services\Dashboards\DashboardReadModel;
use App\Services\Dashboards\IssuePresenter;
use App\Support\Dashboards\ActionResult;
use App\Support\Dashboards\DashboardImages;
use App\Support\Dashboards\IssueAction;
use App\Support\Dashboards\IssueDetail;
use App\Support\Dashboards\IssueSource;
use App\Support\Dashboards\IssueSourceRegistry;
use App\Support\HubNavigation;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Throwable;

/**
 * The one action panel for every dashboard row. Opening it loads IssueSource::detail() fresh (never
 * from a list cache) for a validated "<type>:<id>" ref, renders only the actions the source offers
 * the current admin, and submits through IssueSource::perform() with the version it loaded.
 *
 * - done: success notice, panel reloaded, every dashboard list on the page refreshes.
 * - conflict: message, detail reloaded, the admin's typed input kept.
 * - forbidden / invalid / gone: plain message, input kept.
 * - A per-load submit token makes a repeated or queued click a no-op (double-submit safe).
 */
final class IssueActionPanel extends Component
{
    public const OPEN_EVENT = 'dashboard-issue-open';

    private const KEY_PATTERN = '/^[a-z][a-z0-9_]{0,39}$/';

    private const FIELD_TYPES = ['text', 'textarea', 'select', 'date'];

    #[Locked]
    public bool $open = false;

    #[Locked]
    public ?string $ref = null;

    /** Incremented on every open, so the drawer shows content only once this open has loaded. */
    #[Locked]
    public int $loads = 0;

    #[Locked]
    public ?string $version = null;

    #[Locked]
    public string $token = '';

    /** Plain-text view model of the last fresh detail() load (escaped on output). */
    #[Locked]
    public array $detail = [];

    /** @var array{tone: string, text: string}|null */
    #[Locked]
    public ?array $notice = null;

    /** @var array<string, string> "<action>.<field>" => message */
    #[Locked]
    public array $fieldErrors = [];

    /** @var array<string, array<string, string>> action key => field name => typed value */
    public array $input = [];

    #[On(self::OPEN_EVENT)]
    public function openIssue(mixed $ref = null): void
    {
        $this->authorizePanel();
        $this->reset('ref', 'version', 'detail', 'notice', 'fieldErrors', 'input', 'token');
        $this->open = true;
        $this->loads++;

        try {
            [$type] = IssueSourceRegistry::parseRef(is_string($ref) ? $ref : '');
        } catch (InvalidArgumentException) {
            $this->notice = ['tone' => 'danger', 'text' => 'That item could not be opened.'];

            return;
        }

        if (! $this->registry()->has($type)) {
            $this->notice = ['tone' => 'danger', 'text' => 'That item could not be opened.'];

            return;
        }

        $this->ref = $ref;
        $this->load();
    }

    public function close(): void
    {
        $loads = $this->loads;
        $this->reset();
        $this->loads = $loads;
    }

    public function reload(): void
    {
        $this->authorizePanel();
        if ($this->ref !== null) {
            $this->load();
        }
    }

    public function submit(mixed $action = null, mixed $token = null): void
    {
        $this->authorizePanel();
        // A queued second click (or a replayed request) carries a token from an earlier load.
        if (! is_string($token) || $this->token === '' || ! hash_equals($this->token, $token) || ! is_string($action) || $this->ref === null) {
            return;
        }

        $spec = collect($this->detail['actions'] ?? [])->firstWhere('key', $action);
        if (! is_array($spec)) {
            $this->notice = ['tone' => 'warning', 'text' => 'That action is no longer available for this item.'];
            $this->load(keepNotice: true);

            return;
        }

        [$values, $errors] = $this->validated($spec);
        $this->fieldErrors = $errors;
        if ($errors !== []) {
            $this->notice = ['tone' => 'danger', 'text' => 'Check the highlighted fields.'];

            return;
        }

        [$type, $id] = IssueSourceRegistry::parseRef($this->ref);
        $this->token = Str::random(32);

        try {
            $result = $this->registry()->get($type)->perform($this->user(), $id, $action, $values, (string) $this->version);
        } catch (Throwable $exception) {
            report($exception);
            $this->notice = ['tone' => 'danger', 'text' => 'The update could not be completed. Try again, or open the full record.'];

            return;
        }

        $this->applyResult($result, $action);
    }

    public function render(): View
    {
        return view('livewire.dashboards.issue-action-panel');
    }

    private function applyResult(ActionResult $result, string $action): void
    {
        switch ($result->outcome) {
            case 'done':
                unset($this->input[$action]);
                $this->fieldErrors = [];
                $this->notice = ['tone' => 'success', 'text' => $result->message];
                app(DashboardReadModel::class)->forget($this->user());
                $this->dispatch(DashboardBoard::CHANGED_EVENT);
                $this->load(keepNotice: true);
                break;
            case 'conflict':
                $this->notice = ['tone' => 'warning', 'text' => $result->message];
                $this->load(keepNotice: true);
                break;
            case 'invalid':
                $errors = [];
                foreach ($result->errors as $field => $message) {
                    $errors[$action.'.'.$field] = (string) $message;
                }
                $this->fieldErrors = $errors;
                $this->notice = ['tone' => 'danger', 'text' => $result->message];
                break;
            case 'gone':
                $this->notice = ['tone' => 'danger', 'text' => $result->message];
                $this->detail = [...$this->detail, 'actions' => [], 'readOnlyReason' => null, 'gone' => true];
                $this->token = '';
                break;
            default: // forbidden
                $this->notice = ['tone' => 'danger', 'text' => $result->message];
                $this->load(keepNotice: true);
        }
    }

    private function load(bool $keepNotice = false): void
    {
        if (! $keepNotice) {
            $this->notice = null;
        }

        [$type, $id] = IssueSourceRegistry::parseRef((string) $this->ref);

        try {
            $source = $this->registry()->get($type);
            $detail = $source->detail($this->user(), $id);
        } catch (Throwable $exception) {
            report($exception);
            $this->detail = [];
            $this->token = '';
            $this->notice = ['tone' => 'danger', 'text' => 'This item could not be loaded right now. Try again.'];

            return;
        }

        if (! $detail instanceof IssueDetail) {
            $this->detail = [];
            $this->version = null;
            $this->token = '';
            if ($this->notice === null) {
                $this->notice = ['tone' => 'danger', 'text' => 'This item no longer exists or is no longer available to you.'];
            }

            return;
        }

        $this->detail = $this->present($source, $detail);
        $this->version = $detail->issue->version;
        $this->token = $this->detail['actions'] === [] ? '' : Str::random(32);
    }

    /** @return array<string, mixed> */
    private function present(IssueSource $source, IssueDetail $detail): array
    {
        $issue = $detail->issue;
        $image = DashboardImages::url($issue->imageKey, 96);

        return [
            'ref' => $issue->ref(),
            'typeLabel' => Str::of($source->type())->replace('_', ' ')->ucfirst()->toString(),
            'subject' => $issue->subject,
            'title' => $issue->title,
            'summary' => $issue->summary,
            'category' => $issue->categoryLabel,
            'severity' => $issue->severity,
            'severityLabel' => IssuePresenter::severityLabel($issue->severity),
            'statusLabel' => $issue->statusLabel,
            'stage' => $issue->stage,
            'reporter' => $issue->actorLabel,
            'occurred' => IssuePresenter::time($issue->occurredAt),
            'occurredIso' => $issue->occurredAt->toIso8601String(),
            'image' => $image,
            'image2x' => $image === null ? null : DashboardImages::url($issue->imageKey, 192),
            'imageKind' => $image === null ? null : DashboardImages::kind($issue->imageKey),
            'icon' => str_starts_with((string) $issue->imageKey, 'station:') ? 'heroicon-o-building-office' : (str_starts_with((string) $issue->imageKey, 'apparatus:') ? 'heroicon-o-truck' : 'heroicon-o-document-text'),
            'body' => $detail->body,
            'facts' => array_values(array_map(static fn (array $fact): array => [
                'label' => (string) ($fact['label'] ?? ''),
                'value' => (string) ($fact['value'] ?? ''),
            ], $detail->facts)),
            'timeline' => array_values(array_map(static function (array $entry): array {
                $at = $entry['at'] ?? null;

                return [
                    'at' => $at instanceof CarbonImmutable ? IssuePresenter::time($at) : '',
                    'atIso' => $at instanceof CarbonImmutable ? $at->toIso8601String() : '',
                    'actor' => isset($entry['actor']) ? (string) $entry['actor'] : null,
                    'text' => (string) ($entry['text'] ?? ''),
                ];
            }, $detail->timeline)),
            'actions' => $this->presentActions($detail->actions),
            'readOnlyReason' => $detail->readOnlyReason,
            'sourceUrl' => HubNavigation::safePath($issue->sourceUrl),
            'gone' => false,
        ];
    }

    /**
     * @param  list<IssueAction>  $actions
     * @return list<array<string, mixed>>
     */
    private function presentActions(array $actions): array
    {
        $presented = [];
        foreach ($actions as $action) {
            if (! $action instanceof IssueAction || preg_match(self::KEY_PATTERN, $action->key) !== 1) {
                continue;
            }

            $fields = [];
            foreach ($action->fields as $field) {
                $name = (string) ($field['name'] ?? '');
                $type = (string) ($field['type'] ?? 'text');
                if (preg_match(self::KEY_PATTERN, $name) !== 1 || ! in_array($type, self::FIELD_TYPES, true)) {
                    continue;
                }
                $options = [];
                foreach ((array) ($field['options'] ?? []) as $value => $label) {
                    $options[] = ['value' => (string) $value, 'label' => (string) $label];
                }
                $fields[] = [
                    'name' => $name,
                    'label' => (string) ($field['label'] ?? Str::headline($name)),
                    'type' => $type,
                    'required' => (bool) ($field['required'] ?? false),
                    'max' => isset($field['max']) ? max(1, (int) $field['max']) : ($type === 'textarea' ? 2000 : ($type === 'text' ? 255 : null)),
                    'options' => $options,
                ];
            }

            $presented[] = [
                'key' => $action->key,
                'label' => $action->label,
                'tone' => in_array($action->tone, ['primary', 'secondary', 'danger'], true) ? $action->tone : 'secondary',
                'confirm' => $action->confirm,
                'fields' => $fields,
            ];
        }

        return $presented;
    }

    /**
     * Collect only the declared fields for $spec and check them against their declared rules.
     *
     * @param  array<string, mixed>  $spec
     * @return array{0: array<string, string>, 1: array<string, string>}
     */
    private function validated(array $spec): array
    {
        $values = [];
        $errors = [];
        $typed = is_array($this->input[$spec['key']] ?? null) ? $this->input[$spec['key']] : [];

        foreach ($spec['fields'] as $field) {
            $raw = $typed[$field['name']] ?? '';
            $value = is_scalar($raw) ? trim((string) $raw) : '';
            $errorKey = $spec['key'].'.'.$field['name'];

            if ($value === '') {
                if ($field['required']) {
                    $errors[$errorKey] = $field['label'].' is required.';
                }
                $values[$field['name']] = '';

                continue;
            }

            if ($field['max'] !== null && mb_strlen($value) > $field['max']) {
                $errors[$errorKey] = $field['label'].' must be '.$field['max'].' characters or fewer.';
            } elseif ($field['type'] === 'select' && ! in_array($value, array_column($field['options'], 'value'), true)) {
                $errors[$errorKey] = 'Choose one of the listed options for '.$field['label'].'.';
            } elseif ($field['type'] === 'date' && ! $this->isDate($value)) {
                $errors[$errorKey] = $field['label'].' must be a valid date.';
            }

            $values[$field['name']] = $value;
        }

        return [$values, $errors];
    }

    private function isDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }

    private function authorizePanel(): void
    {
        abort_unless(DashboardAccess::canEnterPanel(auth()->user()), 403);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    private function registry(): IssueSourceRegistry
    {
        return app(IssueSourceRegistry::class);
    }
}
