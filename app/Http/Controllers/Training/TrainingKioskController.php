<?php

declare(strict_types=1);

namespace App\Http\Controllers\Training;

use App\Http\Controllers\Controller;
use App\Models\Training\TrainingSession;
use App\Services\Training\TrainingAssignmentProvider;
use App\Services\Training\TrainingKioskService;
use App\Services\Training\TrainingRosterService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

    public function authorizeDevice(Request $request, TrainingSession $session, TrainingKioskService $kiosk): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/']]);
        $kiosk->authorizeDevice($request, $session, $data['token']);

        return response()->json(['authorized' => true, 'csrf_token' => csrf_token()]);
    }

    public function state(Request $request, TrainingSession $session, TrainingKioskService $kiosk): JsonResponse
    {
        $kiosk->requireDevice($request, $session);
        $attendance = $session->attendances()->whereNull('voided_at')->orderBy('signed_at')->orderBy('id')
            ->get()->map(fn ($row): array => [
                'name' => $row->snapshot['display_name'],
                'rank' => $row->snapshot['rank'] ?? null,
                'shift' => $row->snapshot['shift'] ?? null,
                'signed_at' => $row->signed_at->toIso8601String(),
            ]);

        return response()->json([
            'course_name' => $session->course_name,
            'held_on' => $session->held_on->format('Y-m-d'),
            'location' => $session->location,
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
        $members = collect($provider->candidates(now('America/New_York')->toDateString()))->map(fn (array $member): array => [
            'id' => $member['employee_profile_id'],
            'first_name' => $member['first_name'],
            'last_name' => $member['last_name'],
            'name' => $member['display_name'],
            'rank' => $member['rank'],
            'shift' => $member['shift'],
            'term_label' => $member['term_label'],
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
