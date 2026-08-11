<?php

namespace App\Services\VideoConferencing;

use App\Enums\VideoConferencing\ConferenceJoinRole;
use App\Models\Employee;
use Illuminate\Support\Str;

class ConferenceIdentityService
{
    public function displayName(Employee $employee): string
    {
        $name = trim((string) $employee->name);
        $rank = trim((string) $employee->rank);

        if ($rank === '' || Str::startsWith(Str::lower($name), Str::lower($rank).' ')) {
            return $name;
        }

        return trim($rank.' '.$name);
    }

    public function identity(ConferenceJoinRole $role): string
    {
        return $role->fixedIdentity() ?? 'mbfd:member:'.(string) Str::ulid();
    }
}
