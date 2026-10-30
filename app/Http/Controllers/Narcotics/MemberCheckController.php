<?php

declare(strict_types=1);

namespace App\Http\Controllers\Narcotics;

use App\Concerns\ResolvesCanonicalEmployee;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Ems\Concerns\RespondsWithEnvelope;
use App\Models\Narcotics\NarcoticsCheck;
use App\Models\User;
use App\Services\Ems\EmsException;
use App\Services\Ems\EmsValidator;
use App\Services\Narcotics\NarcoticsCheckAccess;
use App\Services\Narcotics\NarcoticsCheckPresenter;
use App\Services\Narcotics\NarcoticsCheckService;
use App\Services\Narcotics\NarcoticsImageProcessor;
use App\Support\Narcotics\RescueUnits;
use App\Support\Narcotics\SealNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/** Employee Portal side of the narcotics check: any signed-in member, their own checks only. */
final class MemberCheckController extends Controller
{
    use ResolvesCanonicalEmployee;
    use RespondsWithEnvelope;

    public function __construct(
        private readonly NarcoticsCheckService $checks,
        private readonly NarcoticsCheckPresenter $presenter,
        private readonly NarcoticsCheckAccess $access,
    ) {}

    /** What the form needs to know: limits, the rescue units to offer, the seal number length and the picture types this server accepts. */
    public function form(Request $request): JsonResponse
    {
        return $this->guarded(function () use ($request): JsonResponse {
            $this->authorizeSubmit($request);
            $image = (array) config('narcotics_checks.image');

            return $this->ok([
                'note_max_length' => (int) config('narcotics_checks.note_max_length'),
                // Every option in display order, "Other" last; choosing "Other" asks for text of at most the length below.
                'rescue_units' => RescueUnits::options(),
                'rescue_unit_other_option' => RescueUnits::other(),
                'rescue_unit_other_max_length' => RescueUnits::maxOtherLength(),
                'seal_number_max_length' => SealNumber::maxLength(),
                'accepted_mime_types' => NarcoticsImageProcessor::acceptedMimeTypes(),
                'max_upload_bytes' => (int) $image['max_upload_kb'] * 1024,
                'max_edge' => (int) $image['full_edge'],
                'min_edge' => (int) $image['min_edge'],
            ]);
        });
    }

    public function store(Request $request): JsonResponse
    {
        return $this->guarded(function () use ($request): JsonResponse {
            $user = $this->authorizeSubmit($request);
            $employee = $this->authenticatedEmployee();
            $photo = $request->file('photo');
            if (! $photo instanceof UploadedFile) {
                throw new EmsException('Choose or take a photo first.', 'photo_missing', 422, ['photo' => ['Choose or take a photo first.']]);
            }
            if (! $photo->isValid()) {
                throw new EmsException('The photo did not finish uploading. Please try again.', 'photo_upload_failed', 422, ['photo' => ['The photo did not finish uploading. Please try again.']]);
            }

            $outcome = $this->checks->submit($user, $employee, $photo, $request->only(['client_submission_id', 'rescue_unit', 'rescue_unit_other', 'seal_number', 'note', 'resubmission_of']));

            return $this->ok(
                $this->presenter->detail($outcome['check']->fresh(['resubmission', 'resubmissionOf']), NarcoticsCheckPresenter::AUDIENCE_MEMBER),
                ['replayed' => $outcome['replayed']],
                $outcome['replayed'] ? 200 : 201,
            );
        });
    }

    public function index(Request $request): JsonResponse
    {
        return $this->guarded(function () use ($request): JsonResponse {
            $user = $this->authorizeSubmit($request);
            $f = EmsValidator::validate($request->query(), [
                'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
                'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            ], ['page.integer' => 'The page must be a number.', 'per_page.integer' => 'The page size must be a number.']);
            $perPage = (int) ($f['per_page'] ?? 20);

            $page = NarcoticsCheck::query()
                ->where('submitted_by_user_id', $user->getKey())
                ->with(['resubmission:id,uuid,resubmission_of_id', 'resubmissionOf:id,uuid'])
                ->orderByDesc('submitted_at')->orderByDesc('id')
                ->paginate($perPage, ['*'], 'page', (int) ($f['page'] ?? 1));

            return $this->ok(
                $page->getCollection()->map(fn (NarcoticsCheck $check): array => $this->presenter->summary($check, NarcoticsCheckPresenter::AUDIENCE_MEMBER))->values()->all(),
                $this->pageMeta($page->currentPage(), $page->perPage(), $page->total()),
            );
        });
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        return $this->guarded(function () use ($request, $uuid): JsonResponse {
            $user = $this->authorizeSubmit($request);
            // Another member's check looks exactly like one that does not exist.
            $check = NarcoticsCheck::query()
                ->where('uuid', Str::lower($uuid))
                ->where('submitted_by_user_id', $user->getKey())
                ->with(['resubmission:id,uuid,resubmission_of_id', 'resubmissionOf:id,uuid'])
                ->first();
            if ($check === null) {
                throw EmsException::notFound('narcotics check');
            }

            return $this->ok($this->presenter->detail($check, NarcoticsCheckPresenter::AUDIENCE_MEMBER));
        });
    }

    private function authorizeSubmit(Request $request): User
    {
        $user = $request->user();
        if (! $user instanceof User || ! $this->access->canSubmit($user)) {
            throw EmsException::forbidden('The narcotics check is not available to your account.');
        }

        return $user;
    }
}
