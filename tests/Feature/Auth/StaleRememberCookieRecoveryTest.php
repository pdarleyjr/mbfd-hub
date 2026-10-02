<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\AuthenticationSession;
use App\Models\User;
use Illuminate\Auth\SessionGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class StaleRememberCookieRecoveryTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('staleRememberCookies')]
    public function test_stale_remember_cookie_allows_login_and_keeps_protected_pages_guest_only(bool $missingUser): void
    {
        $this->withoutVite();
        $user = User::factory()->create([
            'account_status' => 'active',
            'remember_token' => 'current-remember-token',
        ]);
        $before = $user->fresh()->getRawOriginal();
        $identifier = $missingUser ? $user->id + 1 : $user->id;
        $recallerName = Auth::guard('web')->getRecallerName();
        $recaller = $identifier.'|revoked-remember-token|'.$user->getAuthPassword();

        foreach (['/login', '/', '/admin'] as $path) {
            // Each HTTP probe must attempt cookie authentication independently.
            Auth::forgetGuards();
            $response = $this->withCookie($recallerName, $recaller)->get($path);

            if ($path === '/login') {
                $response->assertOk();
            } else {
                $response->assertRedirect('/login');
            }

            $this->assertGuest('web');
        }

        self::assertSame($before, $user->fresh()->getRawOriginal());
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('authentication_sessions', 0);
    }

    #[DataProvider('validRememberCookieHashes')]
    public function test_stock_guard_accepts_valid_remember_cookie_hashes(bool $useHmac): void
    {
        $user = User::factory()->create(['remember_token' => 'current-remember-token']);
        $before = $user->fresh()->getRawOriginal();
        $guard = Auth::guard('web');
        self::assertInstanceOf(SessionGuard::class, $guard);
        $passwordHash = $user->getAuthPassword();
        $cookieHash = $useHmac ? $guard->hashPasswordForCookie($passwordHash) : $passwordHash;
        $recaller = $user->id.'|'.$user->getRememberToken().'|'.$cookieHash;
        $guard->setRequest(Request::create('/', 'GET', [], [$guard->getRecallerName() => $recaller]));

        self::assertSame($user->id, $guard->user()?->getAuthIdentifier());
        self::assertTrue($guard->viaRemember());
        self::assertSame($before, $user->fresh()->getRawOriginal());
        $this->assertDatabaseCount('authentication_sessions', 0);
    }

    public function test_stock_guard_rejects_a_valid_remember_token_with_the_wrong_password_hash(): void
    {
        $user = User::factory()->create(['remember_token' => 'current-remember-token']);
        $before = $user->fresh()->getRawOriginal();
        $guard = Auth::guard('web');
        self::assertInstanceOf(SessionGuard::class, $guard);
        $recaller = $user->id.'|'.$user->getRememberToken().'|wrong-password-hash';
        $guard->setRequest(Request::create('/', 'GET', [], [$guard->getRecallerName() => $recaller]));

        self::assertNull($guard->user());
        self::assertSame($before, $user->fresh()->getRawOriginal());
        $this->assertDatabaseCount('authentication_sessions', 0);
    }

    public function test_active_canonical_session_keeps_its_identity_and_registry_with_a_stale_remember_cookie(): void
    {
        $user = User::factory()->create(['remember_token' => 'current-remember-token']);
        $this->actingAsCanonicalUser($user);
        $guard = Auth::guard('web');
        self::assertInstanceOf(SessionGuard::class, $guard);
        $session = $this->app['session.store'];
        $session->put($guard->getName(), $user->getAuthIdentifier());
        $session->save();
        $sessionId = $session->getId();
        $registered = AuthenticationSession::query()->sole();
        $before = $user->fresh()->getRawOriginal();
        $recaller = $user->id.'|revoked-remember-token|'.$user->getAuthPassword();
        Auth::forgetGuards();

        $this->withCookie($guard->getRecallerName(), $recaller)
            ->getJson('/api/me/context')
            ->assertOk()
            ->assertJsonPath('identity.user_id', $user->id)
            ->assertJsonPath('session.authenticated', true);

        $this->assertAuthenticatedAs($user, 'web');
        self::assertSame($sessionId, $session->getId());
        self::assertSame($registered->id, session('auth.canonical_session_id'));
        self::assertSame($user->id, $registered->fresh()->user_id);
        self::assertNull($registered->fresh()->revoked_at);
        self::assertSame($before, $user->fresh()->getRawOriginal());
        $this->assertDatabaseCount('authentication_sessions', 1);
    }

    /** @return array<string, array{bool}> */
    public static function validRememberCookieHashes(): array
    {
        return [
            'current HMAC' => [true],
            'legacy password hash' => [false],
        ];
    }

    /** @return array<string, array{bool}> */
    public static function staleRememberCookies(): array
    {
        return [
            'missing user' => [true],
            'mismatched remember token' => [false],
        ];
    }
}
