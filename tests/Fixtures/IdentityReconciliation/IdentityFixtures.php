<?php

declare(strict_types=1);

namespace Tests\Fixtures\IdentityReconciliation;

use App\Data\IdentityReconciliation\EmployeeIdentity;
use App\Data\IdentityReconciliation\UserIdentity;

final class IdentityFixtures
{
    public const BCRYPT_ONE = '$2y$04$prFlZfmC92jkvGLNU1D36uuYqWsf6uP8YDLh5.FCX.QdNfEWJ1vxS';

    public const BCRYPT_TWO = '$2y$04$yfwilIMnISyWByrEd.ImNu.uOS.dg8e6Dbzsc6tfQwCJRypVuTA0u';

    /** @param array<string, mixed> $overrides */
    public static function user(array $overrides = []): UserIdentity
    {
        $values = array_merge([
            'id' => 10,
            'legacyEmployeeId' => '10010',
            'employeeProfileId' => null,
            'accountStatus' => 'pending_activation',
            'securityVersion' => 1,
            'name' => 'Synthetic User',
            'email' => 'synthetic.user@example.test',
            'rank' => 'Firefighter',
            'passwordHash' => self::BCRYPT_ONE,
            'mustChangePassword' => false,
            'roles' => [],
            'directPermissions' => [],
            'effectivePermissions' => [],
            'workgroups' => [],
            'notificationRelationships' => [],
            'externalMappings' => [],
        ], $overrides);

        return new UserIdentity(...$values);
    }

    /** @param array<string, mixed> $overrides */
    public static function employee(array $overrides = []): EmployeeIdentity
    {
        $values = array_merge([
            'id' => 20,
            'employeeId' => '10010',
            'name' => 'Synthetic Employee',
            'rank' => 'Firefighter',
            'passwordHash' => self::BCRYPT_ONE,
            'mustChangePassword' => false,
            'externalMappings' => [],
        ], $overrides);

        return new EmployeeIdentity(...$values);
    }
}
