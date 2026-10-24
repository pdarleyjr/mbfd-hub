<?php

declare(strict_types=1);

namespace Tests\Unit\Migrations;

use PHPUnit\Framework\TestCase;

/**
 * The production deploy gate (deploy.yml, "Prove established-account integrity after deployment") refuses
 * any role or permission change on established accounts. A migration that grants access therefore fails
 * the deploy after the new release is already active and leaves members on the maintenance page.
 * Migrations may create permissions; access is granted deliberately in the Admin capability editor.
 */
final class MigrationsDoNotGrantAccessTest extends TestCase
{
    /** Migrations before this point ran under earlier release rules and are left as they are. */
    private const FIRST_GUARDED_MIGRATION = '2026_10_23_210000';

    public function test_new_migrations_never_assign_roles_or_permissions(): void
    {
        $patterns = [
            '/->\s*(givePermissionTo|assignRole|syncPermissions|syncRoles)\s*\(/',
            "/table\\(\\s*['\"](model_has_permissions|model_has_roles|role_has_permissions)['\"]\\s*\\)\\s*->\\s*(insert|insertOrIgnore|insertUsing|upsert|updateOrInsert)\\b/",
        ];
        $checked = 0;
        $offenders = [];
        foreach (glob(dirname(__DIR__, 3).'/database/migrations/*.php') ?: [] as $path) {
            if (strcmp(basename($path), self::FIRST_GUARDED_MIGRATION) < 0) {
                continue;
            }
            $checked++;
            $source = (string) file_get_contents($path);
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $source) === 1) {
                    $offenders[] = basename($path);
                }
            }
        }

        self::assertGreaterThan(0, $checked, 'The guarded migration range must not be empty.');
        self::assertSame([], array_values(array_unique($offenders)), 'Grant access in the Admin capability editor, not in a migration.');
    }
}
