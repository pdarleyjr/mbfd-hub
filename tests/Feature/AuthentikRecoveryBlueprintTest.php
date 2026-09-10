<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

final class AuthentikRecoveryBlueprintTest extends TestCase
{
    public function test_restored_recovery_tokens_skip_identification_and_second_email(): void
    {
        $blueprint = file_get_contents(dirname(__DIR__, 2).'/infra/authentik/blueprints/mbfd-identity-recovery.yaml');

        self::assertIsString($blueprint);
        self::assertStringContainsString(
            'return not bool(request.context.get("is_restored"))',
            $blueprint,
        );
        self::assertMatchesRegularExpression(
            '/policy: !KeyOf skip-if-restored\s+target: !KeyOf identification-binding/s',
            $blueprint,
        );
        self::assertMatchesRegularExpression(
            '/policy: !KeyOf skip-if-restored\s+target: !KeyOf email-binding/s',
            $blueprint,
        );
        self::assertDoesNotMatchRegularExpression(
            '/target: !KeyOf email-binding\s+order: 0\s+state: absent/s',
            $blueprint,
        );
        self::assertStringContainsString('activate_user_on_success: false', $blueprint);
    }
}
