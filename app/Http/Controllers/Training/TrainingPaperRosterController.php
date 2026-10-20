<?php

declare(strict_types=1);

namespace App\Http\Controllers\Training;

use App\Http\Controllers\Controller;
use App\Models\Training\TrainingPaperAttendance;
use App\Models\Training\TrainingPaperRosterImport;
use App\Models\User;
use App\Services\Training\TrainingPaperRosterService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class TrainingPaperRosterController extends Controller
{
    public function source(Request $request, TrainingPaperRosterImport $import, TrainingPaperRosterService $papers): Response
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return response($papers->sourceBytes($import, $actor), 200, [
            'Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="paper-original-'.$import->id.'.pdf"',
            'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function review(Request $request, TrainingPaperAttendance $entry, TrainingPaperRosterService $papers): Response
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $papers->authorize($actor);
        $data = $request->validate([
            'decision' => ['required', 'in:approved,excluded,voided'], 'employee_profile_id' => ['nullable', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:4000'], 'identity_confirmed' => ['required_if:decision,approved', 'accepted_if:decision,approved'],
        ]);
        $saved = $papers->review($entry, $actor, $data['decision'], isset($data['employee_profile_id']) ? (int) $data['employee_profile_id'] : null, $data['reason']);

        return response()->json(['id' => $saved->id, 'review_status' => $saved->review_status]);
    }
}
