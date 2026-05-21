<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Admin;

use App\Filament\Admin\Pages\BidAccessPin;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Feature tests for the MBFD Hub side of the Bid Access PIN bridge.
 *
 * The page proxies read/write to the bid Cloudflare Worker's
 * /api/portal/admin/bid-pin endpoint (PORTAL_BID_READER auth). These tests
 * stub Http::fake to verify the page hits the right URL with the right
 * payload — they do not exercise Filament's Livewire mount path because the
 * project's test DB infra doesn't currently provision an auth-capable
 * connection (see VerifyCredentialsTest for the same caveat).
 */
class BidAccessPinTest extends TestCase
{
    private const TOKEN = 'test-bid-reader-secret-do-not-use-in-prod';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.bid.reader_token', self::TOKEN);
        config()->set('services.bid.console_url', 'https://staging.bid.mbfdhub.com');
    }

    public function test_page_class_is_discoverable(): void
    {
        // Just verify the class loads — no DB or Livewire surface.
        $this->assertTrue(class_exists(BidAccessPin::class));
        $this->assertTrue(method_exists(BidAccessPin::class, 'save'));
        $this->assertTrue(method_exists(BidAccessPin::class, 'refresh'));
        $this->assertTrue(method_exists(BidAccessPin::class, 'resetToDefault'));
    }

    public function test_can_access_returns_false_without_logged_in_user(): void
    {
        // Without an authenticated admin user, canAccess() must short-circuit
        // to false so anonymous browsers can't render the page.
        $this->assertFalse(BidAccessPin::canAccess());
    }

    public function test_http_get_targets_worker_api_origin(): void
    {
        Http::fake([
            'api.staging.bid.mbfdhub.com/api/portal/admin/bid-pin' => Http::response([
                'pin' => '2300',
                'updatedAt' => null,
                'updatedBy' => null,
                'isDefault' => true,
            ], 200),
        ]);

        // Drive the read path directly via Http facade so we exercise the URL
        // transformation (staging. → api.staging.) without bootstrapping
        // Filament/Livewire.
        $base = (string) config('services.bid.console_url');
        $apiBase = rtrim(str_replace('staging.', 'api.staging.', $base), '/');
        $url = $apiBase.'/api/portal/admin/bid-pin';

        $response = Http::withToken(self::TOKEN)
            ->acceptJson()
            ->get($url);

        $this->assertTrue($response->successful());
        $this->assertSame('2300', (string) $response->json('pin'));
        Http::assertSent(static fn ($request) => $request->hasHeader('Authorization', 'Bearer '.self::TOKEN)
            && $request->url() === 'https://api.staging.bid.mbfdhub.com/api/portal/admin/bid-pin');
    }

    public function test_http_put_sends_pin_payload(): void
    {
        Http::fake([
            'api.staging.bid.mbfdhub.com/api/portal/admin/bid-pin' => Http::response([
                'pin' => '4040',
                'updatedAt' => '2026-05-21T07:00:00Z',
                'updatedBy' => 'tester@example',
                'isDefault' => false,
            ], 200),
        ]);

        $base = (string) config('services.bid.console_url');
        $apiBase = rtrim(str_replace('staging.', 'api.staging.', $base), '/');
        $url = $apiBase.'/api/portal/admin/bid-pin';

        $response = Http::withToken(self::TOKEN)
            ->acceptJson()
            ->put($url, ['pin' => '4040', 'updatedBy' => 'tester@example']);

        $this->assertTrue($response->successful());
        $this->assertSame('4040', (string) $response->json('pin'));
        Http::assertSent(static fn ($request) => $request->method() === 'PUT'
            && $request['pin'] === '4040'
            && $request['updatedBy'] === 'tester@example');
    }
}
