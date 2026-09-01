<?php

declare(strict_types=1);

namespace App\Services\StationActors;

use App\Data\StationActors\VerifiedHumanStationActor;
use App\Models\Employee;
use App\Services\Identity\AuthenticatedMemberContextResolver;
use Illuminate\Auth\AuthenticationException;

/**
 * Resolves station-actor provenance without accepting request-supplied identity.
 *
 * Device provenance remains unavailable until an explicitly authorized device
 * credential, rotation, and revocation model exists.
 */
final readonly class StationActorResolver
{
    public function __construct(
        private AuthenticatedMemberContextResolver $members,
    ) {}

    public function resolveVerifiedHuman(): ?VerifiedHumanStationActor
    {
        try {
            $employee = $this->members->resolve(request())->employee();
        } catch (AuthenticationException) {
            return null;
        }

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
