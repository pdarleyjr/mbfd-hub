<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Apparatus;
use App\Models\StretcherIssueReport;
use App\Models\User;
use App\Services\Defects\DefectMemberCorrection;
use App\Services\Defects\MemberCorrectionRefused;
use App\Services\Defects\StretcherIssueReporter;
use App\Services\Identity\AuthenticatedMemberContextResolver;
use App\Support\Security\Base64Image;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * What any member can do about an equipment issue without being staff (docs/unified-activity-and-ownership.md
 * sections 3 and 4): say it is fixed, and report a stretcher problem outside a Daily Checkout. Network only (never
 * queued offline). The rule is the issue-history rule: Fleet managers, or an active member with an employee profile.
 */
final class MemberIssueActionsController extends Controller
{
    private const STRETCHER_PHOTO_MAX_BYTES = 10_485_760;

    public function correction(Request $request, DefectMemberCorrection $corrections, int $defect): JsonResponse
    {
        $user = $this->authorizeMember($request);
        $valid = $request->validate([
            'client_action_id' => ['required', 'uuid'],
            'outcome' => ['required', 'string'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $result = $corrections->report($user, $defect, ['outcome' => $valid['outcome'], 'note' => $valid['note'] ?? null], (string) $valid['client_action_id']);
        } catch (MemberCorrectionRefused $refused) {
            return response()->json(['message' => $refused->getMessage(), 'code' => $refused->errorCode, 'status_label' => $refused->currentStatus], $refused->status);
        }

        return response()->json(['data' => $result->toArray()]);
    }

    public function stretcher(Request $request, StretcherIssueReporter $reporter, Apparatus $apparatus): JsonResponse
    {
        $user = $this->authorizeMember($request);
        $valid = $request->validate([
            'client_submission_id' => ['required', 'uuid'],
            'problem' => ['required', Rule::in(array_keys(StretcherIssueReport::PROBLEMS))],
            'description' => ['required', 'string', 'min:3', 'max:1000'],
            'unusable' => ['required', 'boolean'],
            'photo' => ['nullable', 'file', 'max:'.intdiv(self::STRETCHER_PHOTO_MAX_BYTES, 1024)],
        ], [
            'problem.in' => 'Choose what is wrong with the stretcher.',
            'description.required' => 'Say briefly what is wrong.',
            'description.min' => 'Say briefly what is wrong.',
        ]);
        $submissionId = strtolower((string) $valid['client_submission_id']);
        $photo = $request->file('photo');
        // A retry of a report already saved keeps its first photo; nothing new is stored.
        $isRetry = StretcherIssueReport::query()->where('client_submission_id', $submissionId)->exists();

        $photoPath = ! $isRetry && $photo instanceof UploadedFile ? $this->storePhoto($photo) : null;
        try {
            $outcome = $reporter->report($user, $apparatus, [
                'client_submission_id' => $submissionId,
                'problem' => (string) $valid['problem'],
                'description' => trim((string) $valid['description']),
                'unusable' => (bool) $valid['unusable'],
                'photo_path' => $photoPath,
            ]);
        } catch (\Throwable $exception) {
            $this->forgetPhoto($photoPath);

            throw $exception;
        }
        $report = $outcome['report'];
        if ($outcome['replayed'] && $photoPath !== null && $photoPath !== $report->photo_path) {
            $this->forgetPhoto($photoPath); // a concurrent duplicate send: the first report kept its own photo
        }

        return response()->json(['data' => [
            'issue_id' => (int) $outcome['defect']->getKey(),
            'attached' => (bool) $report->attached_to_existing,
            'message' => $report->attached_to_existing
                ? 'Sent to Captain 5. This problem was already reported on this unit, so your report was added to it.'
                : 'Sent to Captain 5.'.($report->unusable ? ' The EMS Captain was told too.' : ''),
        ]], $outcome['replayed'] ? 200 : 201);
    }

    /** Magic-byte checked and re-stored under a server-chosen name; the member's file name is never used. */
    private function storePhoto(UploadedFile $photo): string
    {
        $bytes = (string) file_get_contents((string) $photo->getRealPath());
        $mime = (string) $photo->getMimeType();
        $path = Base64Image::store('data:'.$mime.';base64,'.base64_encode($bytes), 'defects', 'stretcher', 'public', self::STRETCHER_PHOTO_MAX_BYTES);
        if ($path === null) {
            throw ValidationException::withMessages(['photo' => 'Use a JPEG, PNG or WebP photo under 10 MB.']);
        }

        return $path;
    }

    private function forgetPhoto(?string $path): void
    {
        if ($path !== null) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($path);
        }
    }

    /** Same rule as ApparatusIssueHistoryController::authorizeMember. */
    private function authorizeMember(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        if (! $user->canManageFleetSwaps()) {
            app(AuthenticatedMemberContextResolver::class)->resolve($request)->actor()->requireEmployee();
        }

        return $user;
    }
}
