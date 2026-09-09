<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\AccountStatus;
use App\Models\AuthenticationSession;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class FederationSessionExpiryTest extends TestCase
{
    use RefreshDatabase;

    /** @return iterable<string, array{string, string}> */
    public static function expiredHandoffs(): iterable
    {
        foreach (['idle', 'revoked', 'version'] as $failure) {
            foreach (['/auth/media-control/authorize', '/auth/bid/authorize', '/oauth/authorize'] as $path) {
                yield $failure.$path => [$failure, $path.'?client_id=test-client&state=opaque%2Bstate%2Fvalue&redirect_uri=https%3A%2F%2Fmedia.mbfdhub.com%2Fapi%2Fauth%2Fhub%2Fcallback'];
            }
        }
    }

    #[DataProvider('expiredHandoffs')]
    public function test_expired_canonical_session_resumes_only_the_current_federation_get_after_login(string $failure, string $handoff): void
    {
        $employee = Employee::query()->create([
            'employee_id' => 'EXPIRY-TEST',
            'name' => 'Expiry Test Member',
            'rank' => 'Firefighter',
            'password' => Hash::make('legacy-password'),
            'must_change_password' => false,
        ]);
        $user = User::factory()->create([
            'account_status' => AccountStatus::Active,
            'employee_id' => $employee->employee_id,
            'employee_profile_id' => $employee->id,
            'password' => Hash::make('correct-password'),
            'must_change_password' => false,
        ]);
        $passwordHash = $user->password;
        $this->actingAsCanonicalUser($user);
        $registered = AuthenticationSession::query()->sole();
        $oldSessionId = $this->app['session.store']->getId();
        $oldCsrf = $this->app['session.store']->token();
        $this->withSession(['url.intended' => 'https://untrusted.example/old', 'sensitive_form' => 'must-disappear']);
        match ($failure) {
            'idle' => $registered->forceFill(['idle_expires_at' => now()->subSecond()])->save(),
            'revoked' => $registered->forceFill(['revoked_at' => now()])->save(),
            'version' => $user->forceFill(['security_version' => $user->security_version + 1])->save(),
        };

        $login = $this->captureFederationLogin($this->get($handoff));

        $this->assertGuest('web');
        self::assertNotSame($oldSessionId, $this->app['session.store']->getId());
        self::assertNotSame($oldCsrf, $this->app['session.store']->token());
        self::assertNull(session('sensitive_form'));
        self::assertNull(session('auth.canonical_session_id'));
        self::assertNotNull($registered->fresh()->revoked_at);
        $this->withCookie((string) config('session.cookie'), $this->app['session.store']->getId());

        $this->post($login, ['employee_id' => $employee->employee_id, 'password' => 'correct-password'])
            ->assertRedirect($handoff);

        $this->assertAuthenticatedAs($user, 'web');
        self::assertSame($passwordHash, $user->fresh()->password);
        self::assertSame($employee->id, $user->fresh()->employee_profile_id);
        self::assertNull(session('url.intended'));
    }

    /** @return iterable<string, array{string, string, array<string, string>, int}> */
    public static function nonNavigations(): iterable
    {
        yield 'unrelated GET' => ['GET', '/expiry-test-unrelated', [], 302];
        yield 'federation POST' => ['POST', '/auth/media-control/authorize', [], 302];
        yield 'JSON GET' => ['GET', '/auth/media-control/authorize', ['Accept' => 'application/json'], 401];
        yield 'Livewire GET' => ['GET', '/auth/media-control/authorize', ['X-Livewire' => ''], 401];
    }

    /** @param array<string, string> $headers */
    #[DataProvider('nonNavigations')]
    public function test_expiry_does_not_retain_unrelated_requests_or_submitted_input(string $method, string $path, array $headers, int $status): void
    {
        Route::middleware('web')->match(['GET', 'POST'], $path, fn () => response('must not reach handler'));
        $user = User::factory()->create(['account_status' => AccountStatus::Active]);
        $this->actingAsCanonicalUser($user);
        AuthenticationSession::query()->sole()->forceFill(['idle_expires_at' => now()->subSecond()])->save();
        $this->withSession(['url.intended' => '/auth/bid/authorize?state=old', 'sensitive_form' => 'private-profile']);

        $this->withHeaders($headers);
        $response = $method === 'GET'
            ? $this->get($path)
            : $this->post($path, ['password' => 'never-preserve-this']);

        $response->assertStatus($status);
        $response->assertDontSee('must not reach handler')->assertDontSee('never-preserve-this');
        self::assertNull(session('url.intended'));
        self::assertNull(session('_old_input'));
        self::assertNull(session('sensitive_form'));
        $this->assertGuest('web');
    }
}
