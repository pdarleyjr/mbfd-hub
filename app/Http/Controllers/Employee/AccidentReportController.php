<?php

declare(strict_types=1);

namespace App\Http\Controllers\Employee;

use App\Http\Controllers\Controller;
use App\Models\AccidentReport;
use App\Models\Employee;
use App\Models\UnitMasterVehicle;
use App\Models\User;
use App\Services\Accidents\AccidentReportPdf;
use App\Services\Accidents\AccidentReportPresenter;
use App\Services\Accidents\AccidentReportSchema;
use App\Services\Accidents\AccidentReportService;
use App\Support\Personnel\PersonnelAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Employee Portal API for the two accident forms (routes/personnel-forms.php). */
final class AccidentReportController extends Controller
{
    public function __construct(
        private readonly AccidentReportService $service,
        private readonly AccidentReportPresenter $presenter,
    ) {}

    /** Everything the form needs to start: who the member is, the vehicle list, supervisors and fixed choices. */
    public function form(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $employee = $actor->employeeProfile;

        return response()->json(['data' => [
            'me' => ['id' => $employee?->getKey(), 'name' => $employee?->name, 'rank' => $employee?->rank, 'employee_id' => $employee?->employee_id],
            'can_supervise' => PersonnelAccess::canSupervise($actor),
            'vehicles' => UnitMasterVehicle::query()->with('apparatus:id,unit_master_vehicle_id,designation')
                ->orderBy('veh_number')->get(['id', 'veh_number', 'make', 'model', 'year', 'tag_number', 'asset_category'])
                ->map(fn (UnitMasterVehicle $vehicle): array => [
                    'id' => $vehicle->getKey(),
                    'label' => trim($vehicle->veh_number.($vehicle->apparatus?->designation ? ' · '.$vehicle->apparatus->designation : '')),
                    'detail' => trim(implode(' ', array_filter([$vehicle->year, $vehicle->make, $vehicle->model]))),
                ])->values(),
            'supervisors' => $this->service->supervisorOptions(),
            'causes' => AccidentReportSchema::CAUSES,
            'actions' => AccidentReportSchema::ACTIONS,
            'notices' => [
                'vehicle' => 'Both sides of this report must be completed before the driver completes his tour of duty. Two (2) copies must be submitted to Risk Management immediately. Any questions call 305-673-7014, or fax: 305-673-7023.',
                'vehicle_distribution' => 'Distribution: Dept/Div, Risk Management, Fleet Management.',
                'supervisor' => 'Complete this form for any accident involving an employee, and return to Human Resources, Risk Management Division. In case of an emergency, contact Risk Management at 305-673-7524 or Fax: 305-673-7023.',
                'supervisor_distribution' => 'Distribution: 1-Risk Management, 2-Employee\'s Department File, 3-Department\'s Accident File.',
                'hub' => 'Submitting here alerts MBFD administration and keeps the record in the Hub. It does not send the report to Risk Management: download the finished PDF and deliver it as the form instructs.',
            ],
        ]]);
    }

    public function index(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $reports = $this->service->forMember($actor)->limit(100)->get();

        return response()->json(['data' => $reports->map(fn (AccidentReport $report): array => $this->presenter->summary($report, $actor))->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $data = $request->validate([
            'report_type' => ['required', Rule::in(array_keys(AccidentReport::TYPES))],
            'client_key' => ['required', 'uuid'],
            'subject_employee_id' => ['nullable', 'integer'],
        ]);
        $subject = isset($data['subject_employee_id']) ? Employee::query()->findOrFail((int) $data['subject_employee_id']) : null;
        $report = $this->service->start($actor, $data['report_type'], $data['client_key'], $subject);

        return response()->json(['data' => $this->presenter->detail($report, $actor)], $report->wasRecentlyCreated ? 201 : 200);
    }

    public function show(Request $request, AccidentReport $report): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeView($actor, $report);

        return response()->json(['data' => $this->presenter->detail($report, $actor)]);
    }

    public function update(Request $request, AccidentReport $report): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeView($actor, $report);
        $payload = $request->validate([
            'revision' => ['required', 'integer', 'min:1'],
            'data' => ['sometimes', 'array'],
            'unit_master_vehicle_id' => ['sometimes', 'nullable', 'integer'],
            'subject_employee_id' => ['sometimes', 'nullable', 'integer'],
            'supervisor_employee_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        return $this->guardConflict($report, $actor, fn () => $this->service->save($actor, $report, (int) $payload['revision'], $payload));
    }

    public function submit(Request $request, AccidentReport $report): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeView($actor, $report);
        $payload = $this->signedPayload($request);

        return $this->guardConflict($report, $actor, fn () => $this->service->submit($actor, $report, $payload['revision'], $payload['signature'], $payload['attest']));
    }

    public function completeSupervisor(Request $request, AccidentReport $report): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeView($actor, $report);
        $payload = $this->signedPayload($request);

        return $this->guardConflict($report, $actor, fn () => $this->service->completeSupervisorPart($actor, $report, $payload['revision'], $payload['signature'], $payload['attest']));
    }

    /** A preview of the current entries on the official form. Generated on request and never stored. */
    public function preview(Request $request, AccidentReport $report, AccidentReportPdf $pdf): Response
    {
        $actor = $this->actor($request);
        $this->authorizeView($actor, $report);
        // The supervisor's assessment stays with the supervisor and administrators, as on the form screen.
        $seesSupervisorPart = $this->service->canCompleteSupervisorPart($actor, $report)
            || ($actor->employee_profile_id !== null && (int) $actor->employee_profile_id === (int) $report->supervisor_employee_id)
            || PersonnelAccess::canViewAccidents($actor);
        $bytes = $pdf->render($report, preview: true, includeSupervisorPart: $seesSupervisorPart);

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$report->reference.'-preview.pdf"',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ]);
    }

    public function destroy(Request $request, AccidentReport $report): Response
    {
        $actor = $this->actor($request);
        abort_unless($report->status === AccidentReport::STATUS_DRAFT && $report->created_by_user_id === $actor->getKey(), 403);
        $report->delete();

        return response()->noContent();
    }

    /** @return array{revision:int, signature:string, attest:bool} */
    private function signedPayload(Request $request): array
    {
        $data = $request->validate([
            'revision' => ['required', 'integer', 'min:1'],
            'signature' => ['required', 'string', 'max:1400000'],
            'attest' => ['required', 'accepted'],
        ], ['signature.required' => 'Sign in the box before submitting.', 'attest.accepted' => 'Confirm that the report is accurate.']);

        return ['revision' => (int) $data['revision'], 'signature' => $data['signature'], 'attest' => true];
    }

    private function guardConflict(AccidentReport $report, User $actor, callable $operation): JsonResponse
    {
        try {
            $updated = $operation();
        } catch (HttpException $exception) {
            if ($exception->getStatusCode() !== 409) {
                throw $exception;
            }

            return response()->json([
                'error' => 'revision_conflict',
                'message' => 'This report changed on another screen. Your latest entries were not saved; review the current version.',
                'data' => $this->presenter->detail($report->fresh(), $actor),
            ], 409);
        }

        return response()->json(['data' => $this->presenter->detail($updated->fresh(), $actor)]);
    }

    private function authorizeView(User $actor, AccidentReport $report): void
    {
        // Not found, rather than forbidden, so a guessed reference reveals nothing.
        abort_unless(PersonnelAccess::canViewReport($actor, $report), 404);
    }

    private function actor(Request $request): User
    {
        $user = $request->user('web');
        abort_unless($user instanceof User && $user->employeeProfile !== null, 403);

        return $user;
    }
}
