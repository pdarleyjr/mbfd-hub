<?php

declare(strict_types=1);

namespace App\Http\Controllers\Narcotics;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Ems\Concerns\RespondsWithEnvelope;
use App\Models\Narcotics\NarcoticsCheck;
use App\Models\User;
use App\Services\Ems\EmsException;
use App\Services\Ems\EmsValidator;
use App\Services\Narcotics\NarcoticsCheckAccess;
use App\Services\Narcotics\NarcoticsCheckPresenter;
use App\Services\Narcotics\NarcoticsCheckService;
use App\Support\HubTimezone;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Captain 5 side of the narcotics check: the inbox, the archive, Approve & Archive and Reject. */
final class StaffCheckController extends Controller
{
    use RespondsWithEnvelope;

    public function __construct(
        private readonly NarcoticsCheckService $checks,
        private readonly NarcoticsCheckPresenter $presenter,
        private readonly NarcoticsCheckAccess $access,
    ) {}

    /** Open to any signed-in person so the Captain 5 page can ask "do I get the tab?" without a 403. */
    public function meta(Request $request): JsonResponse
    {
        return $this->guarded(function () use ($request): JsonResponse {
            $user = $request->user();
            $review = $user instanceof User && $this->access->canReview($user);

            return $this->ok([
                'permissions' => ['review' => $review],
                'pending_count' => $review ? NarcoticsCheck::query()->inbox()->count() : 0,
            ]);
        });
    }

    public function index(Request $request): JsonResponse
    {
        return $this->guarded(function () use ($request): JsonResponse {
            $f = EmsValidator::validate($request->query(), [
                'scope' => ['nullable', 'in:inbox,archive'],
                'status' => ['nullable', 'in:approved,rejected'],
                'q' => ['nullable', 'string', 'max:100'],
                'date_from' => ['nullable', 'date_format:Y-m-d'],
                'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
                'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
                'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            ], [
                'date_from.date_format' => 'Dates must look like 2026-10-30.',
                'date_to.date_format' => 'Dates must look like 2026-10-30.',
                'date_to.after_or_equal' => 'The "to" date must not be before the "from" date.',
            ]);

            $archive = ($f['scope'] ?? 'inbox') === 'archive';
            $query = NarcoticsCheck::query()->with(['resubmission:id,uuid,resubmission_of_id', 'resubmissionOf:id,uuid']);
            $archive ? $query->archived() : $query->inbox();
            if ($archive && isset($f['status'])) {
                $query->where('status', $f['status']);
            }
            $this->applySearch($query, (string) ($f['q'] ?? ''));
            $this->applyDates($query, $f['date_from'] ?? null, $f['date_to'] ?? null);

            $perPage = (int) ($f['per_page'] ?? 25);
            // Oldest first in the inbox (work the queue in order), newest first in the archive.
            $archive ? $query->orderByDesc('archived_at') : $query->orderBy('submitted_at');
            $page = $query->orderBy('id', $archive ? 'desc' : 'asc')->paginate($perPage, ['*'], 'page', (int) ($f['page'] ?? 1));

            return $this->ok(
                $page->getCollection()->map(fn (NarcoticsCheck $check): array => $this->presenter->summary($check, NarcoticsCheckPresenter::AUDIENCE_STAFF))->values()->all(),
                $this->pageMeta($page->currentPage(), $page->perPage(), $page->total()) + ['pending_count' => NarcoticsCheck::query()->inbox()->count()],
            );
        });
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        return $this->guarded(function () use ($request, $uuid): JsonResponse {
            $check = $this->find($uuid);
            $this->checks->recordView($check, $this->reviewer($request));

            return $this->ok($this->presenter->detail($check, NarcoticsCheckPresenter::AUDIENCE_STAFF));
        });
    }

    public function approve(Request $request, string $uuid): JsonResponse
    {
        return $this->guarded(function () use ($request, $uuid): JsonResponse {
            $outcome = $this->checks->approve(Str::lower($uuid), $this->reviewer($request));

            return $this->ok(
                $this->presenter->detail($this->find($uuid), NarcoticsCheckPresenter::AUDIENCE_STAFF),
                ['changed' => $outcome['changed']],
            );
        });
    }

    public function reject(Request $request, string $uuid): JsonResponse
    {
        return $this->guarded(function () use ($request, $uuid): JsonResponse {
            $outcome = $this->checks->reject(Str::lower($uuid), $this->reviewer($request), $request->input('explanation'));

            return $this->ok(
                $this->presenter->detail($this->find($uuid), NarcoticsCheckPresenter::AUDIENCE_STAFF),
                ['changed' => $outcome['changed']],
            );
        });
    }

    private function find(string $uuid): NarcoticsCheck
    {
        $check = NarcoticsCheck::query()
            ->where('uuid', Str::lower($uuid))
            ->with(['resubmission:id,uuid,resubmission_of_id', 'resubmissionOf:id,uuid'])
            ->first();
        if ($check === null) {
            throw EmsException::notFound('narcotics check');
        }

        return $check;
    }

    private function reviewer(Request $request): User
    {
        $user = $request->user();
        if (! $user instanceof User || ! $this->access->canReview($user)) {
            throw EmsException::forbidden();
        }

        return $user;
    }

    /** @param Builder<NarcoticsCheck> $query */
    private function applySearch(Builder $query, string $term): void
    {
        $term = trim($term);
        if ($term === '') {
            return;
        }
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], Str::lower($term)).'%';
        $escape = " LIKE ? ESCAPE '\\'";
        $query->where(function (Builder $inner) use ($like, $escape): void {
            foreach (['submitter_name', 'rescue_unit', 'rescue_unit_other', 'seal_number', 'member_note', 'decision_note', 'decided_by_name'] as $index => $column) {
                $inner->{$index === 0 ? 'whereRaw' : 'orWhereRaw'}("LOWER(COALESCE({$column}, ''))".$escape, [$like]);
            }
        });
    }

    /** Eastern-time calendar days, inclusive. */
    private function applyDates(Builder $query, ?string $from, ?string $to): void
    {
        if ($from !== null) {
            $query->where('submitted_at', '>=', CarbonImmutable::parse($from, HubTimezone::NAME)->startOfDay()->utc());
        }
        if ($to !== null) {
            $query->where('submitted_at', '<=', CarbonImmutable::parse($to, HubTimezone::NAME)->endOfDay()->utc());
        }
    }
}
