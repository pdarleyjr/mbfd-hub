<?php

declare(strict_types=1);

namespace App\Services\StationActors;

use App\Data\StationActors\VerifiedHumanStationActor;
use App\Models\Employee;
use Illuminate\Contracts\Auth\Factory as AuthFactory;

/**
 * Resolves station-actor provenance without accepting request-supplied identity.
 *
 * Device provenance remains unavailable until an explicitly authorized device
 * credential, rotation, and revocation model exists.
 */
final readonly class StationActorResolver
{
    public function __construct(
        private AuthFactory $auth,
    ) {}

    public function resolveVerifiedHuman(): ?VerifiedHumanStationActor
    {
        $employee = $this->auth->guard('employee')->user();

        if (! $employee instanceof Employee) {
            return null;
        }

        return VerifiedHumanStationActor::fromAuthenticatedEmployee($employee);
    }

    public function resolveVerifiedDevice(): null
    {
        return null;
    }
}
