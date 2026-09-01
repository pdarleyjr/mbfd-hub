<?php

namespace Tests;

use App\Enums\AccountStatus;
use App\Enums\SessionContextClass;
use App\Models\Employee;
use App\Models\User;
use App\Services\Identity\SessionRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Livewire\Livewire;

abstract class TestCase extends BaseTestCase
{
    /**
     * Legacy feature fixtures still describe operational actors as Employee models.
     * D03 translates those fixtures into explicitly linked canonical users; it
     * never exercises the retired employee guard.
     */
    public function actingAs(Authenticatable $user, $guard = null): static
    {
        if ($user instanceof Employee && $guard === 'employee') {
            return $this->actingAsCanonicalEmployee($user);
        }

        if ($user instanceof User && $guard === 'web') {
            $this->flushSession();
            Auth::forgetGuards();
            $this->defaultCookies = [];
        }

        return parent::actingAs($user, $guard);
    }

    public function createApplication(): Application
    {
        $app = require __DIR__.'/../bootstrap/app.php';

        $this->traitsUsedByTest = array_fill_keys(array_keys(class_uses_recursive(static::class)), 1);
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Process::preventStrayProcesses();
    }

    private function actingAsCanonicalEmployee(Employee $employee): static
    {
        $user = $employee->user()->first();

        if (! $user instanceof User) {
            $user = User::factory()->create([
                'account_status' => AccountStatus::Active,
                'employee_profile_id' => $employee->id,
            ]);
        }

        return $this->actingAsCanonicalUser($user);
    }

    protected function actingAsCanonicalUser(User $user): static
    {
        $user->forceFill(['account_status' => AccountStatus::Active])->save();
        Auth::forgetGuards();

        $sessionId = Str::random(40);
        $sessionStore = $this->app['session']->driver();
        $sessionStore->setId($sessionId);
        $this->withSession([]);
        $this->app['request']->setLaravelSession($sessionStore);
        parent::actingAs($user, 'web');
        $sessionCookie = (string) config('session.cookie');
        $this->withCookie($sessionCookie, $sessionId);
        $this->withCredentials();
        $this->withHeader('Origin', (string) config('app.url'));
        Livewire::withCookie($sessionCookie, $sessionId)->actingAs($user, 'web');

        $now = CarbonImmutable::now();
        $registered = app(SessionRegistry::class)->register(
            $user,
            $sessionId,
            SessionContextClass::UnmanagedBrowser,
            $now,
            $now->addHour(),
            $now->addDay(),
        );
        $this->app['session']->put('auth.canonical_session_id', $registered->id);

        return $this;
    }

    protected function actingAsCanonicalFixture(
        string $employeeNumber = 'E01-TEST-ACTOR',
        string $name = 'Canonical Test Actor',
        string $rank = 'Firefighter',
    ): User {
        $employee = Employee::query()->create([
            'employee_id' => $employeeNumber,
            'name' => $name,
            'rank' => $rank,
            'password' => 'not-used-by-tests',
            'must_change_password' => false,
        ]);
        $user = User::factory()->create([
            'account_status' => AccountStatus::Active,
            'employee_profile_id' => $employee->id,
        ]);
        $this->actingAsCanonicalUser($user);

        return $user;
    }

    protected function logoutCanonicalSession(): void
    {
        $this->app['auth']->guard('web')->logout();
        Auth::forgetGuards();
        $this->flushSession();
        $this->defaultCookies = [];
    }

    /**
     * Livewire's component harness deliberately disables HTTP middleware.
     * Attach the already-registered canonical session to its synthetic request
     * so component tests exercise the same resolver contract as the panel.
     */
    protected function bindCanonicalSessionToLivewireTestRequests(): void
    {
        $session = $this->app['session']->driver();

        $this->app->rebinding('request', static function ($app, Request $request) use ($session): void {
            $request->setLaravelSession($session);
            $request->setUserResolver(static fn (): mixed => auth('web')->user());
        });
    }
}
