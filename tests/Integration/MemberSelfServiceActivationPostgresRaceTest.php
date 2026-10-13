<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Enums\AccountStatus;
use App\Models\Employee;
use App\Models\EmployeeBidAssignment;
use App\Models\MemberOnboardingInvitation;
use App\Models\MemberOnboardingRosterBinding;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process as SymfonyProcess;
use Tests\TestCase;

#[Group('postgres')]
final class MemberSelfServiceActivationPostgresRaceTest extends TestCase
{
    private const CLAIM = <<<'PHP'
$user = app(App\Services\Identity\MemberSelfServiceActivation::class)->claim(
    getenv('RACE_EMPLOYEE_NUMBER'),
    getenv('RACE_EMAIL'),
    'A Shift',
    'Engine 1',
    Illuminate\Support\Facades\Hash::make('claim-'.getenv('RACE_WORKER')),
    Carbon\CarbonImmutable::now(),
);
echo $user === null ? 'rejected' : 'activated';
PHP;

    private const EMAIL_COMPLETION = <<<'PHP'
try {
    app(App\Services\Identity\AccountSecurityService::class)->completeMemberOnboarding(
        (int) getenv('RACE_INVITATION_ID'),
        (int) getenv('RACE_USER_ID'),
        (int) getenv('RACE_EMPLOYEE_ID'),
        1,
        getenv('RACE_BINDING'),
        Illuminate\Support\Facades\Hash::make('email-completion'),
        now(),
    );
    echo 'activated';
} catch (App\Exceptions\MemberBootstrapStateChanged) {
    echo 'rejected';
}
PHP;

    public function test_two_simultaneous_claims_activate_the_account_exactly_once(): void
    {
        $this->requireDisposablePostgres();
        [$user, $employee] = $this->pendingMember();

        try {
            $results = $this->race([
                $this->worker(self::CLAIM, $user, $employee, ['RACE_WORKER' => 'first']),
                $this->worker(self::CLAIM, $user, $employee, ['RACE_WORKER' => 'second']),
            ]);

            self::assertSame(['activated', 'rejected'], $results);
            $this->assertActivatedOnce($user);
        } finally {
            $this->cleanup($user, $employee);
        }
    }

    public function test_simultaneous_email_and_self_service_activation_set_only_one_password(): void
    {
        $this->requireDisposablePostgres();
        [$user, $employee] = $this->pendingMember();
        $binding = 'race-binding-'.bin2hex(random_bytes(6));
        $invitation = MemberOnboardingInvitation::query()->create([
            'user_id' => $user->id,
            'employee_profile_id' => $employee->id,
            'email' => $employee->city_email,
            'security_version' => 1,
            'token_hash' => hash('sha256', 'race-token-'.$binding),
            'expires_at' => now()->addMinutes(30),
            'redeemed_at' => now(),
            'redeemed_binding_hash' => hash('sha256', $binding),
            'delivery_status' => 'redeemed',
        ]);

        try {
            $results = $this->race([
                $this->worker(self::CLAIM, $user, $employee, ['RACE_WORKER' => 'self-service']),
                $this->worker(self::EMAIL_COMPLETION, $user, $employee, [
                    'RACE_INVITATION_ID' => (string) $invitation->id,
                    'RACE_BINDING' => $binding,
                ]),
            ]);

            self::assertSame(['activated', 'rejected'], $results);
            $this->assertActivatedOnce($user);
        } finally {
            $this->cleanup($user, $employee);
        }
    }

    private function assertActivatedOnce(User $user): void
    {
        $user->refresh();
        self::assertSame(AccountStatus::Active, $user->account_status);
        self::assertSame(2, $user->security_version);
        self::assertNotNull($user->bootstrap_onboarding_completed_at);
        self::assertSame(1, DB::table('security_action_events')->where('target_user_id', $user->id)
            ->whereIn('action', ['complete_member_self_service_activation', 'complete_member_onboarding'])->count());
    }

    /**
     * @param  list<SymfonyProcess>  $workers
     * @return list<string>
     */
    private function race(array $workers): array
    {
        try {
            foreach ($workers as $worker) {
                $worker->start();
            }
            $results = [];
            foreach ($workers as $worker) {
                $worker->wait();
                self::assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
                $results[] = $worker->getOutput();
            }
            sort($results);

            return $results;
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
        }
    }

    /** @param array<string,string> $environment */
    private function worker(string $body, User $user, Employee $employee, array $environment): SymfonyProcess
    {
        $bootstrap = <<<'PHP'
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

PHP;

        return new SymfonyProcess([PHP_BINARY, '-r', $bootstrap.$body], base_path(), $environment + [
            'APP_ENV' => 'testing',
            'RACE_USER_ID' => (string) $user->id,
            'RACE_EMPLOYEE_ID' => (string) $employee->id,
            'RACE_EMPLOYEE_NUMBER' => $employee->employee_id,
            'RACE_EMAIL' => (string) $employee->city_email,
            'SystemRoot' => (string) getenv('SystemRoot'),
            'WINDIR' => (string) getenv('WINDIR'),
        ] + $_ENV);
    }

    /** @return array{User, Employee} */
    private function pendingMember(): array
    {
        $suffix = bin2hex(random_bytes(6));
        $employee = Employee::query()->create([
            'employee_id' => 'SELF-RACE-'.$suffix,
            'name' => 'Self Service Race Member',
            'roster_status' => 'active',
            'city_email' => 'self-race-'.$suffix.'@miamibeachfl.gov',
            'password' => Hash::make('unusable-roster-password'),
        ]);
        $user = User::factory()->create([
            'employee_profile_id' => $employee->id,
            'employee_id' => $employee->employee_id,
            'email' => $employee->city_email,
            'account_status' => AccountStatus::PendingActivation,
            'security_version' => 1,
            'bootstrap_onboarding_eligible' => true,
            'bootstrap_onboarding_eligible_at' => now(),
        ]);
        MemberOnboardingRosterBinding::query()->create([
            'employee_profile_id' => $employee->id,
            'employee_id' => $employee->employee_id,
            'city_email' => $employee->city_email,
            'source_sha256' => str_repeat('a', 64),
            'approved_at' => now(),
        ]);
        // A far-future year keeps this the latest result in a shared test database.
        EmployeeBidAssignment::query()->create([
            'employee_profile_id' => $employee->id,
            'payload_version' => 2,
            'bid_year' => 2099,
            'term_label' => '2099–2100',
            'bid_session_id' => 'race-'.$suffix,
            'position_id' => 'P-'.$suffix,
            'rank_label' => 'Firefighter',
            'shift_label' => 'A Shift',
            'station_label' => 'Station 1',
            'unit_label' => 'Engine 1',
            'bid_selection_label' => 'Engine 1',
            'assignment_source' => 'bid_award',
            'a_day_label' => 'None',
            'idempotency_key' => 'race-'.$suffix,
            'payload_hash' => hash('sha256', $suffix),
            'is_forced' => false,
        ]);

        return [$user, $employee];
    }

    private function cleanup(User $user, Employee $employee): void
    {
        DB::table('security_action_events')->where('target_user_id', $user->id)->delete();
        DB::table('authentication_sessions')->where('user_id', $user->id)->delete();
        DB::table('member_onboarding_invitations')->where('user_id', $user->id)->delete();
        DB::table('member_onboarding_roster_bindings')->where('employee_profile_id', $employee->id)->delete();
        DB::table('member_activation_attempts')->where('employee_profile_id', $employee->id)->delete();
        DB::table('outbound_emails')->where('source_type', 'member_self_service_activation')->where('source_id', (string) $user->id)->delete();
        DB::table('employee_bid_assignments')->where('employee_profile_id', $employee->id)->delete();
        DB::table('users')->where('id', $user->id)->delete();
        DB::table('employees')->where('id', $employee->id)->delete();
    }

    private function requireDisposablePostgres(): void
    {
        if (app()->environment('testing')
            && getenv('MBFD_ALLOW_DISPOSABLE_POSTGRES') === '1'
            && getenv('EXPECTED_TEST_DB_CONNECTION') === 'pgsql'
            && DB::connection()->getDriverName() === 'pgsql') {
            return;
        }

        if (getenv('REQUIRE_POSTGRES_INTEGRATION') === 'true') {
            $this->fail('PostgreSQL integration tests require the explicit loopback disposable database configuration.');
        }

        $this->markTestSkipped('This regression requires the explicitly configured disposable PostgreSQL test database.');
    }
}
