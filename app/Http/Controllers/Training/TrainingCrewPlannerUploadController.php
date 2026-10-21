<?php

declare(strict_types=1);

namespace App\Http\Controllers\Training;

use App\Filament\Training\Pages\TrainingCrewPlanner;
use App\Http\Controllers\Controller;
use App\Models\Training\TrainingCourse;
use App\Models\User;
use App\Policies\TrainingRosterPolicy;
use App\Services\Training\CrewPlanner\TeleStaffDutyResolver;
use App\Services\Training\CrewPlanner\TeleStaffPdfSandbox;
use App\Services\Training\CrewPlanner\TrainingCrewPlannerDraftStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
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
            $course = TrainingCourse::query()->findOrFail($draft['course_id']);
            abort_unless(app(TrainingRosterPolicy::class)->view($actor, $course), 403);
            abort_unless($file instanceof UploadedFile, 422);
            try {
                $snapshot = $parser->parse($file->getPathname());
                $staff = $resolver->resolve($snapshot, []);
            } catch (\Throwable) {
                throw ValidationException::withMessages(['roster_pdf' => 'The report could not be safely read. Your saved proposal is safe. Choose a complete daily TeleStaff PDF and try again.']);
            }
            $metadata = array_intersect_key($snapshot, array_flip(['schedule_date', 'shift', 'printed_at', 'page_count', 'source_sha256', 'warnings']));
            $drafts->update($actor, $draft['id'], (int) $input['version'], static function (array $current) use ($metadata, $staff): array {
                $current['metadata'] = $metadata;
                $current['staff'] = $staff;

                return $current;
            });
            $request->session()->put('training_crew_planner_draft.'.$actor->id, $draft['id']);

            return redirect(TrainingCrewPlanner::getUrl(panel: 'training'))
                ->with('crew_planner_notice', 'Daily snapshot processed. Confirm the date and shift, then review classroom times and your proposal.');
        } finally {
            if ($file instanceof UploadedFile && is_file($file->getPathname())) {
                unlink($file->getPathname());
            }
        }
    }
}
