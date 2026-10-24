<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ems;

use App\Concerns\ResolvesCanonicalEmployee;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Ems\Concerns\RespondsWithEnvelope;
use App\Jobs\Ems\CreateMedicalInventoryRequestCopy;
use App\Models\Employee;
use App\Models\Ems\InventoryRequest;
use App\Models\User;
use App\Services\Ems\EmsException;
use App\Services\Ems\EmsValidator;
use App\Services\Ems\MemberCopyService;
use App\Services\Ems\RequestFormDefinition;
use App\Services\Ems\RequestMessageService;
use App\Services\Ems\RequestPresenter;
use App\Services\Ems\RequestSubmissionService;
use App\Services\Ems\RequestWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Employee Portal side of the Medical Inventory Request: any signed-in member, their own requests only. */
final class MemberRequestController extends Controller
{
    use ResolvesCanonicalEmployee;
    use RespondsWithEnvelope;

    public function __construct(
        private readonly RequestFormDefinition $form,
        private readonly RequestSubmissionService $submissions,
        private readonly RequestPresenter $presenter,
        private readonly RequestMessageService $messages,
        private readonly RequestWorkflowService $workflow,
        private readonly MemberCopyService $copies,
    ) {}

    public function form(): JsonResponse
    {
        return $this->guarded(fn (): JsonResponse => $this->ok($this->form->definition()));
    }

    public function submit(Request $request): JsonResponse
    {
        return $this->guarded(function () use ($request): JsonResponse {
            $employee = $this->authenticatedEmployee();
            $outcome = $this->submissions->submit($this->user($request), $employee, $request->all());
            if ($outcome['replayed']) {
                $this->repairCopy($outcome['request']);
            }

            return $this->ok(
                $this->presenter->detail($outcome['request']->fresh(), RequestPresenter::AUDIENCE_MEMBER),
                ['replayed' => $outcome['replayed']],
                $outcome['replayed'] ? 200 : 201,
            );
        });
    }

    public function index(Request $request): JsonResponse
    {
        return $this->guarded(function () use ($request): JsonResponse {
            $f = EmsValidator::validate($request->query(), ['page' => ['nullable', 'integer', 'min:1', 'max:100000'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:50']], ['page.integer' => 'The page must be a number.', 'per_page.integer' => 'The page size must be a number.']);
            $perPage = (int) ($f['per_page'] ?? 20);
            $page = InventoryRequest::query()->where('submitted_by_user_id', $this->user($request)->getKey())
                ->withCount(['lines', 'lines as issued_lines_count' => fn ($lines) => $lines->where('issued_qty', '>', 0)])
                ->orderByDesc('submitted_at')->orderByDesc('id')
                ->paginate($perPage, ['*'], 'page', (int) ($f['page'] ?? 1));

            return $this->ok(
                $page->getCollection()->map(fn (InventoryRequest $r): array => $this->presenter->summary($r))->values()->all(),
                $this->pageMeta($page->currentPage(), $page->perPage(), $page->total()),
            );
        });
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        return $this->guarded(function () use ($request, $uuid): JsonResponse {
            $record = $this->own($request, $uuid);
            $this->repairCopy($record);

            return $this->ok($this->presenter->detail($record, RequestPresenter::AUDIENCE_MEMBER));
        });
    }

    public function message(Request $request, string $uuid): JsonResponse
    {
        return $this->guarded(function () use ($request, $uuid): JsonResponse {
            $record = $this->own($request, $uuid);

            return $this->written($this->messages->postAsMember(
                (int) $record->getKey(), $this->user($request), $this->idempotencyKey($request), $request->input('body'),
            ), creates: true);
        });
    }

    public function cancel(Request $request, string $uuid): JsonResponse
    {
        return $this->guarded(function () use ($request, $uuid): JsonResponse {
            $record = $this->own($request, $uuid);

            return $this->written($this->workflow->cancelByMember(
                (int) $record->getKey(), $this->user($request), $this->idempotencyKey($request), $request->input('reason'),
            ));
        });
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        if (! $user instanceof User) {
            throw EmsException::forbidden();
        }

        return $user;
    }

    /** A member only ever sees their own requests; anyone else's looks like it does not exist. */
    private function own(Request $request, string $uuid): InventoryRequest
    {
        $record = InventoryRequest::query()->where('uuid', $uuid)->where('submitted_by_user_id', $this->user($request)->getKey())->first();
        if ($record === null) {
            throw EmsException::notFound('request');
        }

        return $record;
    }

    /** On-demand regeneration: a missing record or file is rebuilt by the queued job. */
    private function repairCopy(InventoryRequest $record): void
    {
        try {
            // Nothing to file against once the member has no personnel profile.
            $hasProfile = $record->submitted_by_employee_id !== null && Employee::query()->whereKey($record->submitted_by_employee_id)->exists();
            if ($hasProfile && ! $this->copies->isComplete($record)) {
                CreateMedicalInventoryRequestCopy::dispatch((int) $record->getKey());
            }
        } catch (Throwable $exception) {
            Log::error('EMS Files copy could not be queued', ['request_id' => $record->getKey(), 'exception' => $exception->getMessage()]);
        }
    }
}
