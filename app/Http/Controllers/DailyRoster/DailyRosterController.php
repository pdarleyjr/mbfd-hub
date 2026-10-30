<?php

declare(strict_types=1);

namespace App\Http\Controllers\DailyRoster;

use App\Http\Controllers\Controller;
use App\Services\DailyRoster\DailyRosterPagePresenter;
use App\Services\DailyRoster\RosterChangeBuilder;
use App\Services\DailyRoster\RosterFreshnessResolver;
use App\Services\Security\DailyRosterAccess;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/** The member-facing roster. Read only, personnel data, so nothing here may be stored by a cache. */
final class DailyRosterController extends Controller
{
    private const NO_STORE = ['Cache-Control' => 'no-store, private'];

    public function __construct(
        private readonly DailyRosterAccess $access,
        private readonly DailyRosterPagePresenter $page,
        private readonly RosterFreshnessResolver $freshness,
        private readonly RosterChangeBuilder $changeBuilder,
    ) {}

    public function show(Request $request): Response
    {
        $this->authorizeViewer($request);

        return response()->view('daily-roster.show', [
            'roster' => $this->page->build($this->requestedDate($request)),
        ], 200, self::NO_STORE);
    }

    /** The swappable part of the page, fetched when the version changes. */
    public function sections(Request $request): SymfonyResponse
    {
        $this->authorizeViewer($request);

        $date = $this->requestedDate($request);
        $etag = sha1($this->freshness->status()->etag.'|'.$date);
        if ($this->matchesEtag($request, $etag)) {
            return $this->notModified($etag);
        }

        return response()->view('daily-roster._body', [
            'roster' => $this->page->build($date),
        ], 200, self::NO_STORE + ['ETag' => '"'.$etag.'"']);
    }

    /** Only the seats that changed since the snapshot a page is showing, or `full` when it must take the whole roster. */
    public function changes(Request $request): JsonResponse
    {
        $this->authorizeViewer($request);

        $from = $request->query('from');
        $state = $request->query('state');
        $changes = is_string($from) && preg_match('/\A[1-9]\d{0,18}\z/', $from) === 1
            ? $this->changeBuilder->since((int) $from, is_string($state) ? $state : null)
            : null;

        return response()->json($changes ?? ['mode' => 'full'], 200, self::NO_STORE);
    }

    /** The entire roster for the date on screen, whatever the member has filtered or collapsed there. */
    public function pdf(Request $request): SymfonyResponse
    {
        $this->authorizeViewer($request);

        $data = $this->page->pdfData($this->requestedDate($request));
        abort_if($data['roster']['isEmpty'], 404, 'No roster has been published yet.', self::NO_STORE);

        $response = Pdf::loadView('daily-roster.pdf', $data)
            ->setPaper('letter', 'landscape')
            // Embeds only the glyphs used: about a tenth of the size, for a member on a phone connection.
            ->setOption('isFontSubsettingEnabled', true)
            ->download('MBFD-Daily-Roster-'.$data['roster']['displayedDate'].'.pdf');
        $response->headers->set('Cache-Control', self::NO_STORE['Cache-Control']);
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    public function version(Request $request): SymfonyResponse|JsonResponse
    {
        $this->authorizeViewer($request);

        $status = $this->freshness->status();
        if ($this->matchesEtag($request, $status->etag)) {
            return $this->notModified($status->etag);
        }

        return response()->json([
            'version' => $status->version,
            'etag' => $status->etag,
            'state' => $status->state,
            'duty_date' => $status->dutyDate,
            'as_of' => $status->asOf?->toIso8601String(),
            'facts' => $this->page->liveFacts($status),
        ], 200, self::NO_STORE + ['ETag' => '"'.$status->etag.'"']);
    }

    private function authorizeViewer(Request $request): void
    {
        if (! $this->access->canView($request->user())) {
            abort(403, 'You do not have access to the Daily Roster.', self::NO_STORE);
        }
    }

    private function requestedDate(Request $request): ?string
    {
        $date = $request->query('date');

        return is_string($date) && preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $date) === 1 ? $date : null;
    }

    private function matchesEtag(Request $request, string $etag): bool
    {
        foreach (explode(',', (string) $request->header('If-None-Match')) as $candidate) {
            if (trim(preg_replace('/\AW\//', '', trim($candidate)) ?? '', '"') === $etag) {
                return true;
            }
        }

        return false;
    }

    private function notModified(string $etag): SymfonyResponse
    {
        return response('', 304, self::NO_STORE + ['ETag' => '"'.$etag.'"']);
    }
}
