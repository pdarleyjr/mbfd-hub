<?php

declare(strict_types=1);

namespace App\Http\Controllers\TakeHomeInspections;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTakeHomeInspectionRequest;
use App\Models\TakeHomeVehicleInspection;
use App\Models\User;
use App\Services\Fleet\TakeHomeInspectionSubmitter;
use App\Support\Fleet\TakeHomeInspectionAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class TakeHomeInspectionController extends Controller
{
    public function index(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();
        $inspections = TakeHomeVehicleInspection::query()
            ->where('inspector_user_id', $user->getKey())
            ->latest('submitted_at')
            ->paginate(20);

        $receipt = null;
        $submittedReference = $request->session()->get('take_home_submitted');
        if (is_string($submittedReference)) {
            $receipt = TakeHomeVehicleInspection::query()
                ->where('reference', $submittedReference)
                ->where('inspector_user_id', $user->getKey())
                ->first();
        }

        return view('take-home-inspections.index', [
            'inspections' => $inspections,
            'receipt' => $receipt,
            'canViewAll' => TakeHomeInspectionAccess::canViewAll($user),
        ]);
    }

    public function create(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        return view('take-home-inspections.create', [
            'config' => [
                'userKey' => hash('sha256', 'thvi:'.$user->getKey()),
                'inspectorName' => (string) ($user->display_name ?: $user->name),
                'serverNow' => now()->toIso8601String(),
                'checks' => collect(TakeHomeVehicleInspection::CHECKS)
                    ->map(fn (array $check, string $key): array => ['key' => $key, 'label' => $check[0], 'hint' => $check[1]])
                    ->values(),
                'angles' => collect(TakeHomeVehicleInspection::PHOTO_ANGLES)
                    ->map(fn (string $label, string $key): array => ['key' => $key, 'label' => $label])
                    ->values(),
                'categories' => \App\Models\UnitMasterVehicle::CATEGORY_LABELS,
                'urls' => [
                    'vehicles' => route('take-home-inspections.vehicles.search'),
                    'vehicle' => route('take-home-inspections.vehicles.show', ['vehicle' => '__ID__']),
                    'storeVehicle' => route('take-home-inspections.vehicles.store'),
                    'employees' => route('take-home-inspections.employees.search'),
                    'photos' => route('take-home-inspections.photos.store'),
                    'photo' => url('/take-home-vehicle-inspections/photos/__UUID__'),
                    'submit' => route('take-home-inspections.store'),
                    'index' => route('take-home-inspections.index'),
                ],
            ],
        ]);
    }

    public function store(StoreTakeHomeInspectionRequest $request, TakeHomeInspectionSubmitter $submitter): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $inspection = $submitter->submit($user, $request->validated());
        $request->session()->flash('take_home_submitted', $inspection->reference);

        return response()->json([
            'reference' => $inspection->reference,
            'redirect' => route('take-home-inspections.index'),
            'show' => route('take-home-inspections.show', $inspection),
        ], $inspection->wasRecentlyCreated ? 201 : 200, ['Cache-Control' => 'private, no-store']);
    }

    public function show(Request $request, TakeHomeVehicleInspection $inspection): View
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless(TakeHomeInspectionAccess::canView($user, $inspection), 404);
        $inspection->load(['photos', 'vehicleChanges']);

        return view('take-home-inspections.show', [
            'inspection' => $inspection,
            'isOwn' => (int) $inspection->inspector_user_id === (int) $user->getKey(),
            'canViewAll' => TakeHomeInspectionAccess::canViewAll($user),
        ]);
    }
}
