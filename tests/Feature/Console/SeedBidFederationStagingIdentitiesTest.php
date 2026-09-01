<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Console\Commands\SeedBidFederationStagingIdentities;
use App\Models\User;
use App\Services\Identity\CanonicalUserResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Console\OutputStyle;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

final class SeedBidFederationStagingIdentitiesTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeded_admin_is_linked_to_its_canonical_employee_profile(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'staging');
        putenv('HUB_STAGING_BID_ADMIN_PASSWORD=staging-admin-password-for-test-only');
        putenv('HUB_STAGING_BID_MEMBER_PASSWORD=staging-member-password-for-test-only');
        putenv('HUB_STAGING_BID_WORKGROUP_PASSWORD=staging-workgroup-password-for-test-only');

        try {
            $command = app(SeedBidFederationStagingIdentities::class);
            $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput()));
            $this->assertSame(0, $command->handle());

            $user = User::query()->where('employee_id', 'STG-BID-ADMIN')->firstOrFail();

            $this->assertNotNull($user->employee_profile_id);
            $this->assertSame('STG-BID-ADMIN', $user->employeeProfile?->employee_id);
            $this->assertSame($user->id, app(CanonicalUserResolver::class)->byEmployeeId('STG-BID-ADMIN')?->id);
            $this->assertTrue($user->isAuthenticationAllowed());
        } finally {
            putenv('HUB_STAGING_BID_ADMIN_PASSWORD');
            putenv('HUB_STAGING_BID_MEMBER_PASSWORD');
            putenv('HUB_STAGING_BID_WORKGROUP_PASSWORD');
        }
    }
}
