<?php

declare(strict_types=1);

namespace Tests\Feature\Pwa;

use App\Enums\AccountStatus;
use App\Enums\HubSupportTicketStatus;
use App\Models\HubSupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Regression for the Playwright failure "Thanks for confirming. Your report is closed." not appearing on CI.
 *
 * Saving a session ages its flash data by one step, so a flash survives exactly one saved request after the
 * one that set it. The browser fetches service-worker scripts and manifests on its own, with the member's
 * cookies, at moments of its choosing: when one of those landed between "POST reply" and the "GET report"
 * it redirects to, the background fetch saved the session first, aged the flash away, and the page rendered
 * without it. Timing decides how often that happens (a slower machine makes it likely), never whether it can,
 * so this test places the fetch deterministically between the two requests.
 */
final class BackgroundFetchKeepsFlashTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{string}> */
    public static function backgroundFetches(): array
    {
        return [
            'admin service worker script' => ['/admin/service-worker.js'],
            'admin service worker script at its static path' => ['/admin-pwa/service-worker.js'],
            'hub manifest' => ['/manifest.json'],
            'admin manifest' => ['/admin-pwa/manifest.webmanifest'],
            'legacy manifest redirect' => ['/site.webmanifest'],
            'release probe' => ['/app-release'],
        ];
    }

    #[DataProvider('backgroundFetches')]
    public function test_a_background_fetch_between_a_redirecting_post_and_its_get_does_not_consume_the_flash(string $backgroundUrl): void
    {
        $reporter = User::factory()->create(['account_status' => AccountStatus::Active]);
        $ticket = HubSupportTicket::factory()->for($reporter, 'reporter')->create([
            'status' => HubSupportTicketStatus::Resolved,
            'resolution_summary' => 'We restored the upload service.',
        ]);
        $this->actingAsCanonicalUser($reporter);

        $this->from(route('hub-support.show', $ticket))
            ->post(route('hub-support.reply', $ticket), [
                'action' => 'confirm_fixed',
                'client_action_id' => (string) Str::uuid(),
            ])
            ->assertRedirect(route('hub-support.show', $ticket));

        $background = $this->get($backgroundUrl);
        $this->assertLessThan(400, $background->getStatusCode(), 'the background fetch itself must still be answered');
        $this->assertNull(
            collect($background->headers->getCookies())->first(fn ($cookie): bool => $cookie->getName() === config('session.cookie')),
            'a background fetch must not open or refresh the member session',
        );

        $this->get(route('hub-support.show', $ticket))
            ->assertOk()
            ->assertSee('Thanks for confirming. Your report is closed.');
    }

    public function test_a_background_fetch_does_not_redirect_a_member_whose_password_change_is_pending(): void
    {
        $member = User::factory()->create(['account_status' => AccountStatus::Active, 'must_change_password' => true]);
        $this->actingAsCanonicalUser($member);

        foreach (['/admin/service-worker.js', '/manifest.json', '/admin-pwa/manifest.webmanifest'] as $url) {
            $this->get($url)->assertOk();
        }
    }
}
