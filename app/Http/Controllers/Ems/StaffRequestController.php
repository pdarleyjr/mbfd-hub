<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ems;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Ems\Concerns\RespondsWithEnvelope;
use App\Models\Ems\InventoryRequest;
use App\Models\User;
use App\Services\Ems\EmsAccess;
use App\Services\Ems\EmsException;
use App\Services\Ems\EmsUnitDirectory;
use App\Services\Ems\EmsValidator;
use App\Services\Ems\RequestMessageService;
use App\Services\Ems\RequestPresenter;
use App\Services\Ems\RequestWorkflowService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/** Captain 5 / EMS Admin side: Rescue Inventory (requests). Reads need `view`, every write needs `act`. */
final class StaffRequestController extends Controller
{
    use RespondsWithEnvelope;

    public function __construct(
        private readonly RequestPresenter $presenter,
        private readonly RequestWorkflowService $workflow,
        private readonly RequestMessageService $messages,
        private readonly EmsAccess $access,
        private readonly EmsUnitDirectory $units,
    ) {}

    public function meta(Request $request): JsonResponse
    {
        return $this->guarded(fn (): JsonResponse => $this->ok([
            'permissions' => $this->access->summary($this->user($request)),
            'statuses' => InventoryRequest::STATUSES,
            'target_kinds' => InventoryRequest::TARGET_KINDS,
            'units' => $this->units->options(),
            'close_reasons' => RequestWorkflowService::CLOSE_REASONS,
        ]));
    }

    public function index(Request $request): JsonResponse
    {
        return $this->guarded(function () use ($request): JsonResponse {
            $f = EmsValidator::validate($request->query(), [
                'status' => ['nullable', 'string', 'max:120'],
                'unit' => ['nullable', 'regex:/^(detail|\d{1,9})$/'],
                'target_kind' => ['nullable', 'in:'.implode(',', InventoryRequest::TARGET_KINDS)],
                'date_from' => ['nullable', 'date_format:Y-m-d'],
                'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
                'q' => ['nullable', 'string', 'max:100'],
                'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
                'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            ], [
                'unit.regex' => 'Choose a unit from the list.', 'date_from.date_format' => 'Enter the start date as year-month-day.',
                'date_to.date_format' => 'Enter the end date as year-month-day.', 'date_to.after_or_equal' => 'The end date cannot be before the start date.',
                'q.max' => 'Search text can be at most 100 characters.', 'q.string' => 'Search text must be plain text.',
            ]);

            $query = InventoryRequest::query()->withCount('lines');
            $status = array_values(array_intersect(array_filter(explode(',', (string) ($f['status'] ?? ''))), InventoryRequest::STATUSES));
            if ($status !== []) {
                $query->whereIn('status', $status);
            }
            if (! empty($f['unit'])) {
                $f['unit'] === 'detail'
                    ? $query->where('target_kind', InventoryRequest::TARGET_DETAIL)
                    : $query->where('apparatus_id', (int) $f['unit']);
            }
            if (! empty($f['target_kind'])) {
                $query->where('target_kind', $f['target_kind']);
            }
            if (! empty($f['date_from'])) {
                $query->where('submitted_at', '>=', CarbonImmutable::parse($f['date_from'], \App\Support\HubTimezone::NAME)->startOfDay()->utc());
            }
            if (! empty($f['date_to'])) {
                $query->where('submitted_at', '<=', CarbonImmutable::parse($f['date_to'], \App\Support\HubTimezone::NAME)->endOfDay()->utc());
            }
            $text = trim((string) ($f['q'] ?? ''));
            if ($text !== '') {
                $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], mb_strtolower($text)).'%';
                // A unit typed exactly ("R2") means that unit, never every unit whose name merely contains it ("R22").
                $unitIds = $this->unitIdsNamed($text);
                $query->where(fn ($w) => $w->whereRaw('lower(number) like ?', [$like])->orWhereRaw('lower(submitter_name) like ?', [$like])
                    ->when($unitIds === [], fn ($q) => $q->orWhereRaw('lower(target_label) like ?', [$like]), fn ($q) => $q->orWhereIn('apparatus_id', $unitIds))
                    ->orWhereRaw('lower(coalesce(event_assignment, \'\')) like ?', [$like]));
            }
            $page = $query->orderByDesc('submitted_at')->orderByDesc('id')
                ->paginate((int) ($f['per_page'] ?? 25), ['*'], 'page', (int) ($f['page'] ?? 1));

            return $this->ok(
                $page->getCollection()->map(fn (InventoryRequest $r): array => $this->presenter->summary($r))->values()->all(),
                $this->pageMeta($page->currentPage(), $page->perPage(), $page->total()),
            );
        });
    }

    public function show(string $uuid): JsonResponse
    {
        return $this->guarded(fn (): JsonResponse => $this->ok($this->presenter->detail($this->find($uuid), RequestPresenter::AUDIENCE_STAFF)));
    }

    public function review(Request $request, string $uuid): JsonResponse
    {
        return $this->guarded(fn (): JsonResponse => $this->written($this->workflow->review($this->id($uuid), $this->user($request), $this->idempotencyKey($request))));
    }

    public function prepare(Request $request, string $uuid): JsonResponse
    {
        return $this->guarded(fn (): JsonResponse => $this->written($this->workflow->prepare($this->id($uuid), $this->user($request), $this->idempotencyKey($request), $request->all())));
    }

    public function issue(Request $request, string $uuid): JsonResponse
    {
        return $this->guarded(fn (): JsonResponse => $this->written($this->workflow->issue($this->id($uuid), $this->user($request), $this->idempotencyKey($request), $request->all())));
    }

    public function notIssued(Request $request, string $uuid): JsonResponse
    {
        return $this->guarded(fn (): JsonResponse => $this->written($this->workflow->markNotIssued($this->id($uuid), $this->user($request), $this->idempotencyKey($request), $request->all())));
    }

    public function close(Request $request, string $uuid): JsonResponse
    {
        return $this->guarded(fn (): JsonResponse => $this->written($this->workflow->close($this->id($uuid), $this->user($request), $this->idempotencyKey($request), $request->all())));
    }

    public function reopen(Request $request, string $uuid): JsonResponse
    {
        return $this->guarded(fn (): JsonResponse => $this->written($this->workflow->reopen($this->id($uuid), $this->user($request), $this->idempotencyKey($request), $request->all())));
    }

    public function correct(Request $request, string $uuid): JsonResponse
    {
        return $this->guarded(fn (): JsonResponse => $this->written($this->workflow->correct($this->id($uuid), $this->user($request), $this->idempotencyKey($request), $request->all())));
    }

    public function message(Request $request, string $uuid): JsonResponse
    {
        return $this->guarded(fn (): JsonResponse => $this->written($this->messages->postAsStaff(
            $this->id($uuid), $this->user($request), $this->idempotencyKey($request), $request->input('body'),
        ), creates: true));
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        if (! $user instanceof User) {
            throw EmsException::forbidden();
        }

        return $user;
    }

    /**
     * Apparatus ids a search term names exactly as a unit ("R2", "r 22", "E1"); empty when the text is anything else.
     * A request is found by its unit's id, so "R2" never also returns "R22".
     *
     * @return list<int>
     */
    private function unitIdsNamed(string $text): array
    {
        $term = preg_replace('/\s+/u', '', mb_strtolower($text)) ?? '';
        if (preg_match('/^[a-z]{1,3}\d{1,3}$/', $term) !== 1) {
            return [];
        }
        $ids = [];
        foreach ($this->units->options() as $unit) {
            $designation = preg_replace('/\s+/u', '', mb_strtolower((string) ($unit['designation'] ?? ''))) ?? '';
            $lastWord = preg_replace('/\s+/u', '', mb_strtolower((string) Arr::last(preg_split('/\s+/u', trim((string) $unit['label'])) ?: ['']))) ?? '';
            if ($unit['id'] !== null && ($designation === $term || $lastWord === $term)) {
                $ids[] = (int) $unit['id'];
            }
        }

        return $ids;
    }

    private function find(string $uuid): InventoryRequest
    {
        return InventoryRequest::query()->where('uuid', $uuid)->first() ?? throw EmsException::notFound('request');
    }

    private function id(string $uuid): int
    {
        return (int) $this->find($uuid)->getKey();
    }
}
