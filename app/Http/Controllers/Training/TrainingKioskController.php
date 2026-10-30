<?php

declare(strict_types=1);

namespace App\Http\Controllers\Training;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Training\TrainingCourse;
use App\Models\Training\TrainingSession;
use App\Services\Training\TrainingAssignmentProvider;
use App\Services\Training\TrainingKioskService;
use App\Services\Training\TrainingRosterService;
use App\Services\Training\TrainingRosterSubmissionAuthenticator;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class TrainingKioskController extends Controller
{
    public function index(): View
    {
        return view('training-roster.kiosk');
    }

    public function csrf(): JsonResponse
    {
        return response()->json(['csrf_token' => csrf_token()]);
    }

    public function catalog(): JsonResponse
    {
        $today = now(\App\Support\HubTimezone::NAME)->toDateString();
        $courses = TrainingCourse::query()->with(['modules:id,training_course_id,name', 'sessions' => fn ($query) => $query->where('status', 'open')->where('attendance_source', 'digital')->with('module:id,name')->orderByDesc('held_on')->orderBy('starts_at')->orderBy('id')])
            ->withCount(['sessions as today_session_count' => fn ($query) => $query->whereDate('held_on', $today)->where('status', 'open')->where('attendance_source', 'digital')])
            ->withExists(['sessions as staff_session_today' => fn ($query) => $query->whereDate('held_on', $today)->where(fn ($staff) => $staff->whereNotNull('created_by')->orWhereNotNull('training_module_id')->orWhere('attendance_source', 'paper'))])
            ->orderBy('name')->get()->map(fn (TrainingCourse $course): array => [
                'id' => $course->id, 'name' => $course->name, 'default_location' => $course->default_location,
                'classifications' => $course->classifications,
                'requires_session_selection' => $course->modules->isNotEmpty()
                    || $course->getAttribute('staff_session_today')
                    || $course->getAttribute('today_session_count') > 1
                    || ((int) $course->getAttribute('today_session_count') === 1 && ! $course->sessions->contains(fn (TrainingSession $session): bool => $session->held_on->toDateString() === $today && $session->created_by === null && $session->training_module_id === null)),
                'modules' => $course->modules->map(fn ($module): array => ['id' => $module->id, 'name' => $module->name])->all(),
                'sessions' => $course->sessions->map(fn (TrainingSession $session): array => [
                    'public_id' => $session->public_id, 'held_on' => $session->held_on->toDateString(), 'location' => $session->location,
                    'starts_at' => $session->starts_at?->toIso8601String(), 'module_id' => $session->training_module_id,
                    'module_name' => $session->module?->name, 'staff_managed' => $session->created_by !== null,
                ])->all(),
            ]);

        return response()->json(['courses' => $courses, 'today' => now(\App\Support\HubTimezone::NAME)->toDateString(), 'csrf_token' => csrf_token()]);
    }

    public function startCourse(Request $request, TrainingCourse $course, TrainingRosterService $rosters, TrainingKioskService $kiosk, TrainingRosterSubmissionAuthenticator $authenticator): JsonResponse
    {
        $data = $request->validate([
            'session_public_id' => ['sometimes', 'required', 'string', 'size:26', 'alpha_num:ascii', Rule::prohibitedIf($request->filled('new_module_name'))],
            'training_module_id' => ['sometimes', 'nullable', 'integer', 'min:1', Rule::prohibitedIf($request->filled('new_module_name'))],
            'new_module_name' => ['sometimes', 'required', 'string', 'max:200', 'regex:/\S/u'],
            'email' => ['required_with:new_module_name', 'string', 'max:254'],
            'password' => ['required_with:new_module_name', 'string', 'max:1024'],
        ]);
        if (isset($data['new_module_name'])) {
            $actor = $authenticator->authenticate($data['email'], $data['password'], (string) $request->ip());
            unset($data['password']);
            $session = $rosters->startPublicModuleSession($course, $data['new_module_name'], $actor);
        } else {
            $session = $rosters->startPublicSession($course, $data['session_public_id'] ?? null, isset($data['training_module_id']) ? (int) $data['training_module_id'] : null);
        }
        $kiosk->openPublic($request, $session);

        return response()->json(['url' => '/r/'.$session->public_id, 'csrf_token' => csrf_token()]);
    }

    public function openPublic(Request $request, TrainingSession $session, TrainingKioskService $kiosk): JsonResponse
    {
        $kiosk->openPublic($request, $session);

        return response()->json(['url' => '/r/'.$session->public_id, 'csrf_token' => csrf_token()]);
    }

    public function submitRoster(Request $request, TrainingSession $session, TrainingKioskService $kiosk, TrainingRosterService $rosters, TrainingRosterSubmissionAuthenticator $authenticator): JsonResponse
    {
        $grantId = $kiosk->requireDevice($request, $session);
        $data = $request->validate([
            'email' => ['required', 'string', 'max:254'],
            'password' => ['required', 'string', 'max:1024'],
            'expected_revision' => ['required', 'integer', 'min:1'],
            'client_submission_id' => ['required', 'uuid'],
            'recovery_only' => ['sometimes', 'boolean'],
            'lead_employee_profile_id' => ['sometimes', 'integer', 'min:1'],
            'lead_signature' => ['sometimes', 'string', 'max:1400000'],
            'lead_confirmed' => ['sometimes', 'boolean'],
        ]);
        $actor = $authenticator->authenticate($data['email'], $data['password'], (string) $request->ip());
        unset($data['password']);
        $signoffData = array_intersect_key($data, array_flip(['lead_employee_profile_id', 'lead_signature', 'lead_confirmed']));
        $session = $rosters->submit($session, $actor, (int) $data['expected_revision'], $data['client_submission_id'], $grantId, $signoffData, (bool) ($data['recovery_only'] ?? false));
        $signoff = $session->versions()->where('revision', $session->revision)->firstOrFail()->snapshot['instructor_signoff'];

        return response()->json([
            'submitted' => true, 'course_name' => $session->course_name,
            'held_on' => $session->held_on->toDateString(), 'submitted_at' => $session->submitted_at->toIso8601String(),
            'lead' => ['id' => $signoff['lead_snapshot']['employee_profile_id'], 'name' => $signoff['lead_snapshot']['display_name']],
            'lead_signed_at' => $signoff['signed_at'],
        ]);
    }

    public function leads(Request $request, TrainingSession $session, TrainingKioskService $kiosk): JsonResponse
    {
        $kiosk->requireDevice($request, $session);
        abort_unless($session->status === 'open', 403, 'This roster is no longer open. Return to classes.');
        $identityCounts = Employee::query()->pluck('employee_id')->countBy();
        $leads = Employee::query()->where('roster_status', 'active')->orderBy('name')->orderBy('id')->get(['id', 'employee_id', 'name'])
            ->filter(fn (Employee $employee): bool => filled($employee->employee_id) && filled($employee->name) && ($identityCounts[$employee->employee_id] ?? 0) === 1)
            ->map(fn (Employee $employee): array => ['id' => $employee->id, 'name' => $employee->name, 'identifier_hint' => substr((string) $employee->employee_id, -4)])->values();

        return response()->json(['leads' => $leads, 'selected_lead_id' => $leads->contains('id', $session->oic_employee_profile_id) ? $session->oic_employee_profile_id : null]);
    }

    public function authorizeDevice(Request $request, TrainingSession $session, TrainingKioskService $kiosk): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/']]);
        $kiosk->authorizeDevice($request, $session, $data['token']);

        return response()->json(['authorized' => true, 'csrf_token' => csrf_token()]);
    }

    public function state(Request $request, TrainingSession $session, TrainingKioskService $kiosk): JsonResponse
    {
        $kiosk->requireDevice($request, $session);
        abort_unless($session->status === 'open', 403, 'This roster is no longer open. Return to classes.');
        $attendance = $session->attendances()->whereNull('voided_at')->orderBy('signed_at')->orderBy('id')
            ->get()->map(fn ($row): array => [
                'name' => $row->snapshot['display_name'],
                'rank' => $row->snapshot['rank'] ?? null,
                'shift' => $row->snapshot['shift'] ?? null,
                'signed_at' => ($row->signature_signed_at ?? $row->signed_at)->toIso8601String(),
            ]);

        return response()->json([
            'course_name' => $session->course_name,
            'held_on' => $session->held_on->format('Y-m-d'),
            'location' => $session->location,
            'module_name' => $session->module?->name,
            'starts_at' => $session->starts_at?->toIso8601String(),
            'status' => $session->status,
            'revision' => $session->revision,
            'attendance' => $attendance,
            'csrf_token' => csrf_token(),
        ]);
    }

    public function candidates(Request $request, TrainingSession $session, TrainingKioskService $kiosk, TrainingAssignmentProvider $provider): JsonResponse
    {
        $kiosk->requireDevice($request, $session);
        abort_unless($session->status === 'open', 409, 'Sign-in is closed.');
        $candidates = collect($provider->candidates(now(\App\Support\HubTimezone::NAME)->toDateString()));
        // Personnel whose positions do not bid carry no term. The screen reads one shared term for its
        // heading, so they take the roster's term here; their stored attendance snapshot stays unlabeled.
        $rosterTerm = $candidates->pluck('term_label')->filter()->countBy()->sortDesc()->keys()->first() ?? 'Current assignment';
        $members = $candidates->map(fn (array $member): array => [
            'id' => $member['employee_profile_id'],
            'first_name' => $member['first_name'],
            'last_name' => $member['last_name'],
            'name' => $member['display_name'],
            'rank' => $member['rank'],
            'shift' => $member['shift'],
            'term_label' => $member['term_label'] ?? $rosterTerm,
            // Last four digits disambiguate names without exposing full City IDs.
            'identifier_hint' => substr((string) $member['employee_id'], -4),
        ])->sortBy([['last_name', 'asc'], ['first_name', 'asc']])->values();

        return response()->json(['members' => $members]);
    }

    public function sign(Request $request, TrainingSession $session, TrainingKioskService $kiosk, TrainingRosterService $rosters): JsonResponse
    {
        $grantId = $kiosk->requireDevice($request, $session);
        $data = $request->validate([
            'employee_profile_id' => ['required', 'integer', 'min:1'],
            'signature' => ['required', 'string', 'max:1400000'],
            'client_submission_id' => ['required', 'uuid'],
        ]);
        $attendance = $rosters->sign($session, (int) $data['employee_profile_id'], $data['signature'], $data['client_submission_id'], $grantId);
        abort_if($attendance->voided_at !== null, 409, 'This attendance has been voided. Ask the instructor for assistance.');

        return response()->json(['recorded' => true, 'receipt' => $attendance->client_submission_id]);
    }

    public function receipt(Request $request, TrainingSession $session, TrainingKioskService $kiosk): JsonResponse
    {
        $kiosk->requireDevice($request, $session);
        $data = $request->validate(['client_submission_id' => ['required', 'uuid']]);
        $row = $session->attendances()->where('client_submission_id', $data['client_submission_id'])->first();
        $corrected = $row !== null && $session->audits()->where('training_attendance_id', $row->id)
            ->whereIn('action', ['attendance.corrected', 'attendance.resigned', 'attendance.exception'])->exists();

        return response()->json([
            'recorded' => $row !== null && $row->voided_at === null && ! $corrected,
            'status' => $row === null ? 'unknown' : ($row->voided_at !== null ? 'voided' : ($corrected ? 'corrected' : 'recorded')),
        ]);
    }
}
