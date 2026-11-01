<?php

namespace App\Services\VideoConferencing;

use App\Models\Employee;
use App\Models\User;
use App\Services\Security\Command300Access;
use Illuminate\Http\Request;

/**
 * 300 Command video controls follow the central Command300Access decision.
 * It is re-evaluated live on every call: there is no PIN and no session
 * marker, so a demotion or revoked grant takes effect on the next request.
 */
class ConferenceCommandAuthorizationService
{
    public const DENIED_MESSAGE = '300 Command video controls are available to Captains, Chiefs, the 300 command officer, whoever TeleStaff has working the 300 seat today, administrators, and members granted 300 Command Access.';

    public function __construct(private readonly Command300Access $access) {}

    public function allows(?User $user): bool
    {
        return $this->access->allows($user);
    }

    public function assertUserAllowed(?User $user): void
    {
        abort_unless($this->access->allows($user), 403, self::DENIED_MESSAGE);
    }

    /** The employee argument is kept for caller compatibility; the account is what is authorized. */
    public function assertAuthorized(Request $request, Employee $employee): void
    {
        $this->assertUserAllowed($request->user());
    }
}
