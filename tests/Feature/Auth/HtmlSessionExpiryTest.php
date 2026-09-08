<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class HtmlSessionExpiryTest extends TestCase
{
    private int $submissions = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->bind(ValidateCsrfToken::class,
            fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken
            {
                protected function runningUnitTests(): bool
                {
                    return false;
                }
            });

        Route::middleware('web')->post('/session-expiry-test', function () {
            $this->submissions++;

            return response()->noContent();
        });
    }

    public function test_invalid_html_submissions_return_to_login_without_replaying_or_flashing_input(): void
    {
        foreach (['/login', '/session-expiry-test'] as $path) {
            foreach ([null, 'expired-token', 'another-session-token'] as $token) {
                $this->withSession(['_token' => 'current-token']);
                $response = $this->withHeaders([
                    'Accept' => 'text/html', 'Referer' => 'https://attacker.invalid/return',
                ])->post($path, [
                    '_token' => $token, 'employee_id' => 'private-id',
                    'password' => 'never-preserve-this', 'profile' => 'private-profile',
                ]);

                $response->assertStatus(303)->assertRedirect('/login?session_expired=1');
                self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
                self::assertNull(session('_old_input'));
                self::assertSame(0, $this->submissions);
                self::assertStringNotContainsString('private-id', $response->getContent());
                self::assertStringNotContainsString('never-preserve-this', $response->getContent());
                self::assertStringNotContainsString('attacker.invalid', $response->getContent());
            }
        }
    }

    public function test_valid_csrf_still_reaches_the_handler_once(): void
    {
        $this->withSession(['_token' => 'current-token'])
            ->post('/session-expiry-test', ['_token' => 'current-token'])->assertNoContent();

        self::assertSame(1, $this->submissions);
    }

    public function test_json_and_livewire_preserve_their_419_contract(): void
    {
        $this->withSession(['_token' => 'current-token'])
            ->postJson('/session-expiry-test', ['_token' => 'expired-token'])->assertStatus(419);
        $this->withHeaders(['Accept' => 'text/html', 'X-Livewire' => ''])
            ->post('/session-expiry-test', ['_token' => 'expired-token'])->assertStatus(419);

        self::assertSame(0, $this->submissions);
        self::assertNull(session('_old_input'));
    }

    public function test_other_http_errors_are_not_redirected(): void
    {
        Route::get('/session-expiry-forbidden-test', fn () => abort(403));

        $this->get('/session-expiry-forbidden-test')->assertForbidden();
        $this->get('/session-expiry-missing-test')->assertNotFound();
    }
}
