<?php

declare(strict_types=1);

namespace App\Http\Controllers\Training;

use App\Filament\Training\Pages\TrainingCrewPlanner;
use App\Http\Controllers\Controller;
use App\Models\Training\TrainingCourse;
use App\Models\User;
use App\Policies\TrainingRosterPolicy;
use App\Services\Training\CrewPlanner\TeleStaffDutyResolver;
use App\Services\Training\CrewPlanner\TeleStaffPdfProcessingException;
use App\Services\Training\CrewPlanner\TeleStaffPdfSandbox;
use App\Services\Training\CrewPlanner\TrainingCrewPlannerDraftStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class TrainingCrewPlannerUploadController extends Controller
{
    public function __invoke(Request $request, TrainingCrewPlannerDraftStore $drafts, TeleStaffPdfSandbox $parser, TeleStaffDutyResolver $resolver): RedirectResponse
    {
        // A normal multipart request uses PHP-owned temporary files, not a retained Livewire upload.
        $file = $request->file('roster_pdf');
        try {
            $actor = $request->user();
            abort_unless($actor instanceof User && app(TrainingRosterPolicy::class)->create($actor), 403);
            abort_if(array_diff(array_keys($request->all()), ['_token', 'draft_id', 'version', 'roster_pdf']) !== [], 422);
            $input = $request->validate([
                'draft_id' => ['required', 'uuid'], 'version' => ['required', 'integer', 'min:1'],
                'roster_pdf' => ['required', 'file', 'mimes:pdf', 'max:5120'],
            ]);
            $draft = $drafts->get($actor, $input['draft_id']);
            abort_if($draft['version'] !== (int) $input['version'], 409, 'The proposal changed in another tab. Reload it before uploading.');
            if ($draft['course_id'] !== null) {
                $course = TrainingCourse::query()->findOrFail($draft['course_id']);
                abort_unless(app(TrainingRosterPolicy::class)->view($actor, $course), 403);
            }
            abort_unless($file instanceof UploadedFile, 422);
            try {
                $snapshot = $parser->parse($file->getPathname());
                $staff = $resolver->resolve($snapshot, []);
            } catch (TeleStaffPdfProcessingException $exception) {
                $this->rejectReport($exception->reason);
            } catch (\Throwable) {
                $this->rejectReport('staffing_analysis_failed');
            }
            $metadata = array_intersect_key($snapshot, array_flip(['schedule_date', 'shift', 'printed_at', 'page_count', 'source_sha256', 'warnings']));
            $drafts->update($actor, $draft['id'], (int) $input['version'], static function (array $current) use ($metadata, $staff): array {
                $current['metadata'] = $metadata;
                $current['staff'] = $staff;

                return $current;
            });
            $request->session()->put('training_crew_planner_draft.'.$actor->id, $draft['id']);
            $notice = match (true) {
                $draft['course_id'] === null => 'Daily report read. Choose a course and use its training scope, then save classroom times to show eligible members.',
                $draft['slots'] === [] => 'Daily report read. Save classroom times to show eligible members, then build your planning list.',
                default => 'Daily report read. Review the results and your planning list.',
            };

            return redirect(TrainingCrewPlanner::getUrl(panel: 'training'))
                ->with('crew_planner_notice', $notice);
        } finally {
            if ($file instanceof UploadedFile && is_file($file->getPathname())) {
                unlink($file->getPathname());
            }
        }
    }

    private function rejectReport(string $reason): never
    {
        $reference = (string) Str::uuid();
        Log::warning('training_crew_planner_upload_failed', ['reason' => $reason, 'reference' => $reference]);
        $message = $reason === TeleStaffPdfProcessingException::INVALID_REPORT
            ? 'Hub could not recognize this as a complete daily TeleStaff report. Check the report and retry, or contact Hub support.'
            : 'A server processing problem prevented reading the report. Try again or contact Hub support.';

        throw ValidationException::withMessages(['roster_pdf' => $message.' Your saved proposal is safe. Reference: '.$reference]);
    }
}
