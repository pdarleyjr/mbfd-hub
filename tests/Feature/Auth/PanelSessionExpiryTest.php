<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\AuthenticationSession;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PanelSessionExpiryTest extends TestCase
{
    use RefreshDatabase;

    public function test_expired_livewire_request_returns_unauthenticated_json_without_following_login_html(): void
    {
        $user = User::factory()->create(['account_status' => 'active']);
        $this->actingAsCanonicalUser($user);
        $registered = AuthenticationSession::query()->sole();
        $registered->forceFill(['idle_expires_at' => now()->subSecond()])->save();
        $csrf = session()->token();

        $this->withHeader('X-Livewire', '')->post('/livewire/update', ['_token' => $csrf, 'components' => []])
            ->assertUnauthorized()->assertExactJson([
                'message' => 'Your session has ended. Please sign in again.',
                'code' => 'auth_session_expired',
            ]);
        $this->assertGuest('web');
        self::assertNull(session('auth.canonical_session_id'));
        self::assertNotSame($csrf, session()->token());
        self::assertNotNull($registered->fresh()->revoked_at);
    }

    public function test_guest_livewire_panel_requests_have_the_same_json_contract(): void
    {
        foreach (['/admin', '/employee', '/training', '/workgroups'] as $path) {
            $this->withHeader('X-Livewire', '')->get($path)->assertUnauthorized()
                ->assertJsonPath('code', 'auth_session_expired');
        }
    }

    public function test_all_panels_receive_the_shared_handler_exactly_once(): void
    {
        foreach (Filament::getPanels() as $panel) {
            Filament::setCurrentPanel($panel);
            $html = (string) FilamentView::renderHook(PanelsRenderHook::HEAD_END);
            self::assertSame(1, substr_count($html, 'data-mbfd-session-expiry'));
        }
    }

    public function test_livewire_csrf_is_still_rejected_and_no_form_values_are_flashed(): void
    {
        $this->app->bind(ValidateCsrfToken::class,
            fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken
            {
                protected function runningUnitTests(): bool
                {
                    return false;
                }
            });
        $this->withSession(['_token' => 'current-token']);
        $this->withHeader('X-Livewire', '')->post('/livewire/update', [
            '_token' => 'stale-token', 'password' => 'never-preserve-this', 'components' => [],
        ])->assertStatus(419);
        self::assertNull(session('_old_input'));
    }

    public function test_expired_notice_is_fixed_text_and_login_is_not_cacheable(): void
    {
        $this->get('/login?session_expired=1&password=never-echo-this')->assertOk()
            ->assertSee('Your session has ended. Please sign in again.')
            ->assertSee('Unsaved changes were not submitted. Review the record after signing in.')
            ->assertDontSee('never-echo-this');
        self::assertStringContainsString('no-store', (string) $this->get('/login')->headers->get('Cache-Control'));
    }
}
