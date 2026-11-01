<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusDefectObservation;
use App\Models\ApparatusInspectionConcern;
use App\Models\User;
use App\Services\Defects\MemberIssueHistory;
use App\Services\Identity\AuthenticatedMemberContextResolver;
use ErrorException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use TypeError;
use UnexpectedValueException;
use ValueError;

/**
 * Issue history and photos for members (docs/apparatus-issue-history.md section 2).
 * Fleet managers and active members with an employee profile may read it, the rule used for vehicle changeouts.
 * The shapes are built by MemberIssueHistory; this class only authorizes and streams.
 */
final class ApparatusIssueHistoryController extends Controller
{
    public function __construct(private readonly MemberIssueHistory $history) {}

    public function index(Request $request, Apparatus $apparatus): JsonResponse
    {
        $this->authorizeMember($request);

        return response()->json($this->history->list($apparatus));
    }

    public function closed(Request $request, Apparatus $apparatus): JsonResponse
    {
        $this->authorizeMember($request);
        $cursor = $request->query('cursor');
        abort_unless($cursor === null || (is_string($cursor) && strlen($cursor) <= 512), 422, 'That page marker is not valid.');

        try {
            return response()->json($this->history->closed($apparatus, $cursor));
        } catch (ErrorException|QueryException|TypeError|UnexpectedValueException|ValueError) {
            // A hand-made cursor that decodes but does not name a position.
            abort(422, 'That page marker is not valid.');
        }
    }

    public function timeline(Request $request, ApparatusDefect $defect): JsonResponse
    {
        $this->authorizeMember($request);

        return response()->json($this->history->timeline($defect));
    }

    public function defectPhoto(Request $request, ApparatusDefect $defect): StreamedResponse
    {
        $this->authorizeMember($request);

        return $this->stream($defect->getRawOriginal('photo_path'));
    }

    public function observationPhoto(Request $request, ApparatusDefect $defect, ApparatusDefectObservation $observation): StreamedResponse
    {
        $this->authorizeMember($request);
        // A photo is served only under the issue it belongs to.
        abort_unless((int) $observation->apparatus_defect_id === (int) $defect->getKey(), 404);

        return $this->stream($observation->photo_path);
    }

    public function concernPhoto(Request $request, ApparatusInspectionConcern $concern): StreamedResponse
    {
        $this->authorizeMember($request);

        return $this->stream($concern->photo_path);
    }

    /** Same rule as ApparatusChangeoutController::authorizeMember. */
    private function authorizeMember(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        if (! $user->canManageFleetSwaps()) {
            app(AuthenticatedMemberContextResolver::class)->resolve($request)->actor()->requireEmployee();
        }

        return $user;
    }

    /** Streams from the disk photos are stored on. A path is never accepted from the request. */
    private function stream(mixed $path): StreamedResponse
    {
        $disk = Storage::disk('public');
        abort_unless(is_string($path) && trim($path) !== '' && ! str_contains($path, '..') && $disk->exists($path), 404);

        return $disk->response($path, null, [
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
