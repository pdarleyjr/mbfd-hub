<?php

declare(strict_types=1);

namespace Tests\Feature\Panels;

use App\Enums\AccountStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Gate;
use Illuminate\Testing\TestResponse;
use Livewire\Features\SupportRedirects\SupportRedirects;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Every parameterless GET page of every Filament panel must render for a
 * super administrator without a server error.
 */
#[RunTestsInSeparateProcesses]
final class PanelGetRouteSweepTest extends TestCase
{
    use RefreshDatabase;

    private const PANEL_PREFIXES = ['admin', 'employee', 'training', 'workgroups'];

    /** Identity, export and download endpoints are covered by dedicated tests. */
    private const EXCLUDED = '#/(login|logout|set-password|password-reset|email-verification|oauth|download|export|livewire|filament/(exports|imports))(/|$)#';

    public function test_every_parameterless_panel_page_renders_for_super_admin(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $role = Role::create(['name' => 'super_admin', 'guard_name' => 'web']);
        $admin = User::factory()->create(['account_status' => AccountStatus::Active, 'is_admin' => true]);
        $admin->assignRole($role);
        Gate::before(fn (User $user): ?bool => $user->hasRole('super_admin') ? true : null);
        $this->actingAs($admin);
        $this->withoutVite();

        $uris = $this->panelPageUris();
        $this->assertNotEmpty($uris);

        $failures = [];
        $laravelRedirector = $this->app->make('redirect');
        foreach ($uris as $uri) {
            // Production serves each request in a fresh PHP request scope. In
            // one test process a component that aborts after booting leaves
            // Livewire's request-scoped redirector bound for the next request.
            SupportRedirects::$redirectorCacheStack = [];
            $this->app->instance('redirect', $laravelRedirector);
            $response = $this->get('/'.$uri);
            $status = $response->baseResponse->getStatusCode();
            if ($status >= 500) {
                $failures[] = sprintf('%s -> HTTP %d %s', $uri, $status, $this->exceptionSummary($response));
            }
        }

        $this->assertSame([], $failures, 'Panel GET pages must render without server errors.');
    }

    /** @return list<string> */
    private function panelPageUris(): array
    {
        return collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn (Route $route): bool => in_array('GET', $route->methods(), true))
            ->map(fn (Route $route): string => $route->uri())
            ->filter(fn (string $uri): bool => in_array(explode('/', $uri)[0], self::PANEL_PREFIXES, true))
            ->reject(fn (string $uri): bool => str_contains($uri, '{') || preg_match(self::EXCLUDED, '/'.$uri) === 1)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    private function exceptionSummary(TestResponse $response): string
    {
        $exception = $response->exception;

        return $exception === null ? '' : class_basename($exception).': '.mb_substr($exception->getMessage(), 0, 200).' @ '.$exception->getFile().':'.$exception->getLine();
    }
}
