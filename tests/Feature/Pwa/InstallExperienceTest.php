<?php

declare(strict_types=1);

namespace Tests\Feature\Pwa;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * One install experience: the /install guide, the Home card, a permanent menu entry, and the
 * shared head capturing the browser's one-time install offer. Installed apps keep their identity.
 */
final class InstallExperienceTest extends TestCase
{
    use RefreshDatabase;

    private const GUIDES = ['ios-safari', 'ios-other', 'android-chrome', 'android-samsung', 'android-firefox', 'desktop-chromium', 'mac-safari', 'mac-chromium', 'other'];

    public function test_install_page_offers_every_platform_guide_and_no_fake_install_button(): void
    {
        $this->withoutVite();

        $response = $this->get('/install')->assertOk()
            ->assertSee('data-install-page', false)
            ->assertSee('Install MBFD Hub')
            // Captured inline by the shared head before any bundle loads.
            ->assertSee('window.__mbfdDeferredInstall = event', false)
            ->assertDontSee('Turn on notifications')
            ->assertDontSee('requestPermission', false);

        $page = $this->xpath($response->getContent());
        foreach (self::GUIDES as $guide) {
            self::assertSame(1, $page->query("//*[@data-install-guide='{$guide}']")->length, $guide);
            self::assertSame(1, $page->query("//button[@role='tab' and @data-guide-tab='{$guide}']")->length, $guide);
        }
        // The native install button only appears once the browser offers installation.
        $primary = $page->query('//button[@data-install-primary]');
        self::assertSame(1, $primary->length);
        self::assertTrue($primary->item(0)->hasAttribute('hidden'));
    }

    public function test_a_member_is_offered_the_existing_notification_settings_after_installing(): void
    {
        $this->withoutVite();
        $this->actingAsCanonicalFixture();

        $this->get('/install')->assertOk()
            ->assertSee('Turn on notifications')
            ->assertSee('href="'.url('/employee/notification-settings').'"', false);
    }

    public function test_home_has_a_hidden_install_card_and_the_menus_link_to_the_guide(): void
    {
        $this->withoutVite();
        $this->actingAsCanonicalFixture();

        $response = $this->get('/')->assertOk();
        $page = $this->xpath($response->getContent());

        $card = $page->query('//section[@data-mbfd-install-card]');
        self::assertSame(1, $card->length);
        self::assertTrue($card->item(0)->hasAttribute('hidden'), 'The card is revealed by script only for eligible browsers.');
        self::assertSame(1, $page->query('//section[@data-mbfd-install-card]//button[@data-install-action="dismiss"]')->length);

        $installLinks = $page->query('//*[@role="menuitem" and @href="'.route('install').'"]');
        self::assertSame(2, $installLinks->length, 'Account menu and phone More sheet both reach /install.');
    }

    public function test_every_panel_user_menu_and_the_admin_dashboard_reach_the_shared_install_experience(): void
    {
        foreach (['Admin', 'Employee', 'Training', 'Workgroup'] as $panel) {
            $provider = (string) file_get_contents(app_path("Providers/Filament/{$panel}PanelProvider.php"));
            self::assertStringContainsString("->label('Install MBFD Hub')->url(fn (): string => route('install'))", $provider, $panel);
        }

        $admin = (string) file_get_contents(app_path('Providers/Filament/AdminPanelProvider.php'));
        self::assertMatchesRegularExpression("/PanelsRenderHook::PAGE_START,\s*fn \(\): string => self::safeRender\('filament\.admin\.partials\.install-prompt'\),\s*scopes: Dashboard::class/", $admin);
        self::assertStringContainsString('<x-hub.install-card />', (string) file_get_contents(resource_path('views/filament/admin/partials/install-prompt.blade.php')));

        // The old admin-only banner and the Daily Checkout iOS banner are gone; one controller owns the prompt.
        self::assertStringNotContainsString("addEventListener('beforeinstallprompt'", (string) file_get_contents(resource_path('js/admin-pwa/main.ts')));
        self::assertFileDoesNotExist(resource_path('js/daily-checkout/src/components/IOSInstallPrompt.tsx'));
    }

    public function test_installed_apps_keep_their_identity(): void
    {
        foreach (['manifest.json', 'admin-pwa/manifest.webmanifest'] as $path) {
            $manifest = json_decode((string) file_get_contents(public_path($path)), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('/', $manifest['id'], $path);
            self::assertSame('/', $manifest['start_url'], $path);
            self::assertSame('/', $manifest['scope'], $path);
            self::assertSame('standalone', $manifest['display'], $path);
            self::assertSame('MBFD Hub', $manifest['name'], $path);
        }

        $head = (string) file_get_contents(resource_path('views/components/hub/head.blade.php'));
        self::assertStringContainsString('<link rel="manifest" href="/manifest.json">', $head);
        self::assertStringNotContainsString('unregister', $head);
    }

    private function xpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument;
        @$document->loadHTML($html);

        return new \DOMXPath($document);
    }
}
