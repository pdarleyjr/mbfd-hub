<?php

declare(strict_types=1);

namespace App\Http\Controllers\DailyRoster;

use App\Filament\Resources\EmployeeResource;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\User;
use App\Services\DailyRoster\RosterProfileLinks;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * A roster name to the member's profile, by the verified Employee ID on the roster row. Never by name: when the ID
 * matches no profile or more than one, the viewer gets a short explanation and nothing is guessed.
 */
final class RosterProfileController extends Controller
{
    private const NO_STORE = ['Cache-Control' => 'no-store, private'];

    public function __construct(private readonly RosterProfileLinks $links) {}

    public function show(Request $request, string $employeeId): RedirectResponse|Response
    {
        $actor = $request->user('web');
        // Access is checked before the ID is looked up, so the answer never reveals whether a profile exists.
        if (! $actor instanceof User || ! $this->links->enabled()) {
            abort(403, 'You do not have access to personnel profiles.', self::NO_STORE);
        }
        $employeeId = trim($employeeId);
        if (! RosterProfileLinks::isWellFormed($employeeId)) {
            return $this->explain($employeeId, 'not_found', 404);
        }

        $matches = $this->links->matches($employeeId);
        if ($matches->count() > 1) {
            return $this->explain($employeeId, 'ambiguous', 409);
        }
        $employee = $matches->first();
        if (! $employee instanceof Employee) {
            return $this->explain($employeeId, 'not_found', 404);
        }
        if (! EmployeeResource::canEdit($employee)) {
            abort(403, 'You do not have access to this profile.', self::NO_STORE);
        }

        return redirect()->to(EmployeeResource::getUrl('edit', ['record' => $employee], panel: 'admin'))
            ->withHeaders(self::NO_STORE);
    }

    private function explain(string $employeeId, string $reason, int $status): Response
    {
        return response()->view('daily-roster.profile-unresolved', [
            'employeeId' => $employeeId,
            'reason' => $reason,
        ], $status, self::NO_STORE);
    }
}
