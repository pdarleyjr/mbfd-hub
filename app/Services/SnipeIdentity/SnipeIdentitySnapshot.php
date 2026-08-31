<?php

declare(strict_types=1);

namespace App\Services\SnipeIdentity;

use Illuminate\Support\Facades\DB;
use stdClass;

final class SnipeIdentitySnapshot
{
    /** @return array{employees: list<array{id: int, employee_id: string, name: string, rank: ?string}>, users: list<array{id: int, employee_id: ?string, name: string, email: string}>} */
    public function read(): array
    {
        return DB::transaction(function (): array {
            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement('SET TRANSACTION READ ONLY');
            }

            /** @var list<stdClass> $employees */
            $employees = DB::table('employees')
                ->select(['id', 'employee_id', 'name', 'rank'])
                ->orderBy('id')
                ->get()
                ->all();
            /** @var list<stdClass> $users */
            $users = DB::table('users')
                ->select(['id', 'employee_id', 'name', 'email'])
                ->orderBy('id')
                ->get()
                ->all();

            return [
                'employees' => array_map(static fn (stdClass $employee): array => [
                    'id' => (int) $employee->id,
                    'employee_id' => (string) $employee->employee_id,
                    'name' => (string) $employee->name,
                    'rank' => $employee->rank === null ? null : (string) $employee->rank,
                ], $employees),
                'users' => array_map(static fn (stdClass $user): array => [
                    'id' => (int) $user->id,
                    'employee_id' => $user->employee_id === null ? null : (string) $user->employee_id,
                    'name' => (string) $user->name,
                    'email' => (string) $user->email,
                ], $users),
            ];
        });
    }
}
