<?php

declare(strict_types=1);

namespace App\Http\Controllers\UnitStatus;

use App\Http\Controllers\Controller;
use App\Services\Security\DailyRosterAccess;
use App\Services\UnitStatus\RunHistory;
use App\Services\UnitStatus\UnitStatusBoard;
use App\Support\UnitStatus\UnitRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/** Read-only Unit Status. Crew names are roster data, so access follows the Daily Roster and nothing may be cached. */
final class UnitStatusController extends Controller
{
    private const NO_STORE = ['Cache-Control' => 'no-store, private'];

    /** Hostile text from PulsePoint stays inert even if a client ever sniffed the body as HTML. */
    private const JSON_FLAGS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE;

    public function __construct(
        private readonly DailyRosterAccess $access,
        private readonly UnitStatusBoard $board,
        private readonly RunHistory $history,
        private readonly UnitRegistry $registry,
    ) {}

    public function show(Request $request): Response
    {
        $this->authorizeViewer($request);

        return $this->html('unit-status.show', $this->board->build());
    }

    /** The swappable board, fetched on a timer by the open page. */
    public function board(Request $request): SymfonyResponse
    {
        $this->authorizeViewer($request);
        $board = $this->board->build();
        if ($this->matchesEtag($request, $board['etag'])) {
            return response('', 304, self::NO_STORE + ['ETag' => '"'.$board['etag'].'"']);
        }

        return $this->html('unit-status.partials.board', $board, true);
    }

    public function data(Request $request): SymfonyResponse
    {
        $this->authorizeViewer($request);
        $board = $this->board->build();
        if ($this->matchesEtag($request, $board['etag'])) {
            return response('', 304, self::NO_STORE + ['ETag' => '"'.$board['etag'].'"']);
        }

        return $this->json($board, ['ETag' => '"'.$board['etag'].'"']);
    }

    public function runs(Request $request, string $code): JsonResponse
    {
        $this->authorizeViewer($request);
        $unit = $this->registry->find($code);
        abort_if($unit === null, 404, 'Unknown unit.', self::NO_STORE);
        $page = $request->query('page');

        return $this->json($this->history->page($unit, is_string($page) && ctype_digit($page) ? (int) $page : 1));
    }

    private function authorizeViewer(Request $request): void
    {
        abort_unless((bool) config('unit_status.enabled'), 404, 'Not found.', self::NO_STORE);
        if (! $this->access->canView($request->user())) {
            abort(403, 'You do not have access to Unit Status.', self::NO_STORE);
        }
    }

    /** @param  array<string, mixed>  $board */
    private function html(string $view, array $board, bool $withEtag = false): Response
    {
        return response()->view($view, ['board' => $board], 200, self::NO_STORE + ($withEtag ? ['ETag' => '"'.$board['etag'].'"'] : []));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    private function json(array $payload, array $headers = []): JsonResponse
    {
        return response()->json($payload, 200, self::NO_STORE + $headers + ['X-Content-Type-Options' => 'nosniff'], self::JSON_FLAGS);
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
}
