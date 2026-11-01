<?php

declare(strict_types=1);

namespace App\Services\Narcotics;

use App\Models\Narcotics\NarcoticsCheck;
use App\Models\User;
use App\Services\Security\Captain5Access;
use App\Services\Security\Captain5AccessDecision;

/**
 * The one narcotics check authorization decision (docs/narcotics-check.md section 2). Routes, image
 * delivery, the Captain 5 inbox, notifications and the member card all ask this class; never a Gate
 * ability, because Gate::before returns true for any super_admin.
 *
 *  - submit : an active member with a personnel profile (every authorized member).
 *  - review : Captain 5 only. NOT every person who may open the Captain 5 page: officers by rank, Fleet
 *             reviewers and Admin Panel administrators are deliberately excluded, because a controlled
 *             substance photo is not their business. Review is limited to
 *               - a Captain 5 bid seat holder (any shift) or the EMS Captain seat holder,
 *               - the Shift Captain 5 right now: whoever the roster has working the seat at this moment,
 *                 not the rest of that duty day,
 *               - a person an administrator allowed on the Captain 5 page (Captain 5 Operations ->
 *                 access override "allow"),
 *               - super_admin (protected recovery access).
 *             A Captain 5 "deny" override removes every source except super_admin. The seat/override
 *             data is the existing Captain5Access decision, so there is no second list to maintain and
 *             no person is named in code.
 *  - view   : the submitting member reads their own checks and photos; a reviewer reads all.
 */
final class NarcoticsCheckAccess
{
    public function __construct(private readonly Captain5Access $captain5) {}

    public function enabled(): bool
    {
        return (bool) config('narcotics_checks.enabled', true);
    }

    public function canSubmit(?User $user): bool
    {
        return $this->enabled()
            && $this->usable($user)
            && $user->employee_profile_id !== null;
    }

    public function canReview(?User $user): bool
    {
        if (! $this->enabled() || ! $this->usable($user)) {
            return false;
        }

        $decision = $this->captain5->decide($user);

        return $decision->has(Captain5AccessDecision::SOURCE_SUPER_ADMIN)
            || $decision->has(Captain5AccessDecision::SOURCE_CAPTAIN5_SEAT)
            || $decision->has(Captain5AccessDecision::SOURCE_EMS_CAPTAIN_SEAT)
            || $decision->has(Captain5AccessDecision::SOURCE_EXPLICIT_ALLOW)
            || $decision->isShownCaptain5;
    }

    /** A reviewer reads every check; a member reads only their own. */
    public function canView(?User $user, NarcoticsCheck $check): bool
    {
        if (! $this->enabled() || ! $this->usable($user)) {
            return false;
        }
        // The owner check is cheap; the reviewer check walks the Captain 5 roster, so it goes second.
        if ($check->submitted_by_user_id !== null
            && (int) $check->submitted_by_user_id === (int) $user->getKey()) {
            return true;
        }

        return $this->canReview($user);
    }

    /** @return array{submit: bool, review: bool} */
    public function summary(?User $user): array
    {
        return ['submit' => $this->canSubmit($user), 'review' => $this->canReview($user)];
    }

    /** @phpstan-assert-if-true User $user */
    private function usable(?User $user): bool
    {
        return $user instanceof User && $user->isAuthenticationAllowed() && ! $user->must_change_password;
    }
}
