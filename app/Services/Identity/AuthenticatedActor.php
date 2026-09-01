<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

final readonly class AuthenticatedActor
{
    public function __construct(
        private User $user,
        private ?Employee $employee,
    ) {}

    public function user(): User
    {
        return $this->user;
    }

    public function userId(): int
    {
        return (int) $this->user->getKey();
    }

    public function employee(): ?Employee
    {
        return $this->employee;
    }

    public function requireEmployee(): Employee
    {
        if (! $this->employee instanceof Employee) {
            throw new AuthorizationException('An operational Employee profile is required for this action.');
        }

        return $this->employee;
    }
}
