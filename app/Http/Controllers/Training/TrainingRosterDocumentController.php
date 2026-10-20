<?php

declare(strict_types=1);

namespace App\Http\Controllers\Training;

use App\Http\Controllers\Controller;
use App\Models\Training\TrainingAttendance;
use App\Models\Training\TrainingRosterVersion;
use App\Models\Training\TrainingSession;
use App\Models\User;
use App\Services\Training\TrainingKioskService;
use App\Services\Training\TrainingRosterExportService;
use App\Services\Training\TrainingRosterService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

final class TrainingRosterDocumentController extends Controller
{
    public function signature(TrainingSession $session, TrainingAttendance $attendance): Response
    {
        Gate::authorize('view', $session);
        abort_if($session->attendance_source === 'paper', 409, 'Use the protected original paper roster.');
        abort_unless($attendance->training_session_id === $session->getKey(), 404);
        $artifact = $attendance->signature;
        $bytes = Storage::disk($artifact['disk'])->get($artifact['path']);
        abort_unless(is_string($bytes) && hash_equals($artifact['sha256'], hash('sha256', $bytes)), 409, 'The signature could not be verified.');

        return response($bytes, 200, ['Content-Type' => 'image/png', 'Cache-Control' => 'no-store, private']);
    }

    public function export(TrainingSession $session, string $format, TrainingRosterService $rosters, TrainingRosterExportService $exports): Response
    {
        Gate::authorize('export', $session);
        abort_if($session->attendance_source === 'paper', 409, 'Use the protected original paper roster.');
        abort_unless(in_array($format, ['pdf', 'xlsx'], true), 404);

        return $exports->download($rosters->snapshot($session), $format);
    }

    public function version(TrainingSession $session, TrainingRosterVersion $version, string $format, TrainingRosterExportService $exports): Response
    {
        Gate::authorize('export', $session);
        abort_if($session->attendance_source === 'paper', 409, 'Use the protected original paper roster.');
        abort_unless($version->training_session_id === $session->getKey() && in_array($format, ['pdf', 'xlsx'], true), 404);

        return $exports->download($version, $format);
    }

    public function launch(Request $request, TrainingSession $session, TrainingKioskService $kiosk): View
    {
        Gate::authorize('manageKiosk', $session);
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $url = $request->isMethod('post') ? $kiosk->issue($session, $actor) : null;

        return view('training-roster.launch', ['rosterSession' => $session, 'kioskUrl' => $url]);
    }

    public function revoke(Request $request, TrainingSession $session, TrainingKioskService $kiosk): Response
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $kiosk->revoke($session, $actor);

        return redirect()->route('training.rosters.launch', $session);
    }
}
