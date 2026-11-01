<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ems;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Ems\Concerns\RespondsWithEnvelope;
use App\Models\User;
use App\Services\Ems\CountFormService;
use App\Services\Ems\EmsException;
use App\Services\Ems\EmsValidator;
use App\Services\Ems\FormReconciliationService;
use App\Services\Ems\MasterInventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Captain 5's daily screens: reconcile selected forms, the master inventory, and the Monday count form. */
final class MasterInventoryController extends Controller
{
    use RespondsWithEnvelope;

    public function __construct(
        private readonly MasterInventoryService $master,
        private readonly CountFormService $countForm,
        private readonly FormReconciliationService $reconciliation,
    ) {}

    // -------------------------------------------------------------- reconcile

    public function reconcilePreview(Request $request): JsonResponse
    {
        return $this->guarded(function () use ($request): JsonResponse {
            $f = EmsValidator::validate($request->query(), ['ids' => ['required', 'string', 'max:2000']], ['ids.required' => 'Select at least one form to reconcile.']);

            return $this->ok($this->reconciliation->preview(array_values(array_filter(explode(',', $f['ids'])))));
        });
    }

    public function reconcile(Request $request): JsonResponse
    {
        return $this->guarded(fn (): JsonResponse => $this->written($this->reconciliation->reconcile($this->user($request), $this->idempotencyKey($request), $request->all())));
    }

    // ----------------------------------------------------------------- master

    public function index(Request $request): JsonResponse
    {
        return $this->guarded(fn (): JsonResponse => $this->ok($this->master->read($this->user($request))));
    }

    public function store(Request $request): JsonResponse
    {
        return $this->guarded(fn (): JsonResponse => $this->written($this->master->create($this->user($request), $this->idempotencyKey($request), $request->all()), creates: true));
    }

    public function update(Request $request, int $item): JsonResponse
    {
        return $this->guarded(fn (): JsonResponse => $this->written($this->master->update($item, $this->user($request), $this->idempotencyKey($request), $request->all())));
    }

    public function count(Request $request, int $item): JsonResponse
    {
        return $this->guarded(fn (): JsonResponse => $this->written($this->master->count($item, $this->user($request), $this->idempotencyKey($request), $request->all())));
    }

    // ------------------------------------------------------------- count form

    public function startCount(Request $request): JsonResponse
    {
        return $this->guarded(function () use ($request): JsonResponse {
            $user = $this->user($request);
            $outcome = $this->countForm->start($user, $this->idempotencyKey($request));
            $snapshot = $this->countForm->draftByUuid((string) $outcome['result']['id']);
            $master = $this->master->read($user);

            return $this->ok([
                'count' => [
                    'id' => $snapshot->uuid, 'started_at' => $snapshot->started_at->toIso8601String(),
                    'started_by' => $master['draft']['started_by'] ?? null, 'entries' => (object) $this->countForm->entries($snapshot),
                ],
                'resumed' => (bool) $outcome['result']['resumed'],
                'sections' => $master['sections'],
                'items' => $master['items'],
            ], ['replayed' => $outcome['replayed']]);
        });
    }

    public function saveCount(Request $request, string $uuid): JsonResponse
    {
        return $this->guarded(fn (): JsonResponse => $this->written($this->countForm->save($uuid, $this->user($request), $this->idempotencyKey($request), $request->all())));
    }

    public function reviewCount(string $uuid): JsonResponse
    {
        return $this->guarded(fn (): JsonResponse => $this->ok($this->countForm->review($uuid)));
    }

    public function submitCount(Request $request, string $uuid): JsonResponse
    {
        return $this->guarded(fn (): JsonResponse => $this->written($this->countForm->submit($uuid, $this->user($request), $this->idempotencyKey($request), $request->all())));
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        if (! $user instanceof User) {
            throw EmsException::forbidden();
        }

        return $user;
    }
}
