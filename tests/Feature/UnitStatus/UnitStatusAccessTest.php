<?php

declare(strict_types=1);

namespace Tests\Feature\UnitStatus;

use App\Models\UnitStatus\UnitStatusRun;
use App\Models\User;
use App\Support\HubShellNavigation;
use Illuminate\Testing\TestResponse;
use Tests\Support\DailyRosterSnapshotFactory;

final class UnitStatusAccessTest extends UnitStatusTestCase
{
    private const DATA = '/unit-status/data';

    private const BOARD = '/unit-status/board';

    private const PAGE = '/unit-status';

    private const RUNS = '/unit-status/units/E2/runs';

    private function assertNoStore(TestResponse $response): void
    {
        $header = (string) $response->headers->get('Cache-Control');
        self::assertStringContainsString('no-store', $header);
        self::assertStringContainsString('private', $header);
    }

    public function test_a_guest_is_sent_to_login_everywhere(): void
    {
        foreach ([self::PAGE, self::BOARD, self::DATA, self::RUNS] as $url) {
            $this->get($url)->assertRedirect('/login');
        }
    }

    public function test_an_account_without_a_personnel_profile_is_refused_with_no_store_headers(): void
    {
        DailyRosterSnapshotFactory::publish(self::TODAY, ['Station 2 / Rescue 2' => [['employee_id' => 'EMP-SECRET-1', 'name' => 'ALPHA, ANN']]]);
        $this->actingAsCanonicalUser(User::factory()->create());

        foreach ([self::PAGE, self::BOARD, self::DATA, self::RUNS] as $url) {
            $response = $this->get($url)->assertForbidden();
            $response->assertDontSee('ALPHA');
            $this->assertNoStore($response);
        }
    }

    public function test_with_the_flag_off_every_route_answers_404_even_for_a_member(): void
    {
        config(['unit_status.enabled' => false]);
        $this->actingAsCanonicalFixture();

        foreach ([self::PAGE, self::BOARD, self::DATA, self::RUNS] as $url) {
            $response = $this->get($url)->assertNotFound();
            $this->assertNoStore($response);
        }
    }

    public function test_the_json_is_private_uncacheable_and_carries_an_etag(): void
    {
        $this->markFeedFresh();
        DailyRosterSnapshotFactory::publish(self::TODAY, ['Station 2 / Rescue 2' => [['employee_id' => 'EMP-SECRET-1', 'name' => 'ALPHA, ANN', 'rank' => 'Captain']]]);
        $this->actingAsCanonicalFixture();

        $response = $this->get(self::DATA)->assertOk();

        $this->assertNoStore($response);
        self::assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $body = $response->json();
        self::assertSame('"'.$body['etag'].'"', $response->headers->get('ETag'));
        self::assertCount(5, $body['stations']);
        self::assertTrue($body['live']);
        self::assertStringNotContainsString('EMP-SECRET-1', (string) $response->getContent());
        self::assertStringNotContainsString('employee_id', (string) $response->getContent());
    }

    public function test_a_matching_etag_gets_a_304_with_no_body(): void
    {
        $this->markFeedFresh();
        $this->actingAsCanonicalFixture();
        $etag = $this->get(self::DATA)->assertOk()->json('etag');

        $response = $this->get(self::DATA, ['If-None-Match' => '"'.$etag.'"'])->assertStatus(304);
        self::assertSame('', (string) $response->getContent());
        $this->assertNoStore($response);
        $this->get(self::DATA, ['If-None-Match' => 'W/"'.$etag.'"'])->assertStatus(304);
        $this->get(self::DATA, ['If-None-Match' => '"stale"'])->assertOk();

        $this->storeCall(1, ['E2' => ['status' => 'Enroute']]);
        $this->get(self::DATA, ['If-None-Match' => '"'.$etag.'"'])->assertOk();
    }

    public function test_a_304_is_still_refused_to_someone_without_access(): void
    {
        $this->actingAsCanonicalUser(User::factory()->create());

        $this->get(self::DATA, ['If-None-Match' => '"anything"'])->assertForbidden();
    }

    public function test_hostile_text_stays_inert_in_the_json(): void
    {
        $this->markFeedFresh();
        $hostile = '<script>alert("x")</script> & \'quote\'';
        $this->storeCall(1, ['E2' => ['status' => 'On Scene']], ['address' => $hostile, 'call_type' => '</script><img src=x onerror=1>']);
        $this->actingAsCanonicalFixture();

        $response = $this->get(self::DATA)->assertOk();
        $raw = (string) $response->getContent();

        self::assertStringNotContainsString('<script>', $raw);
        self::assertStringNotContainsString('<img', $raw);
        self::assertStringNotContainsString('</script>', $raw);
        $e2 = collect($response->json('stations.1.units'))->firstWhere('code', 'E2');
        self::assertSame($hostile, $e2['currentCalls'][0]['address']);
        self::assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));
    }

    public function test_run_history_pages_ten_at_a_time_newest_first_without_voided_credits(): void
    {
        $this->actingAsCanonicalFixture();
        foreach (range(1, 13) as $n) {
            $this->storeCall($n, ['E2' => ['status' => 'Cleared From Incident']], ['section' => 'recent', 'call_type' => 'Call '.$n]);
            UnitStatusRun::query()->create([
                'unit_code' => 'E2', 'source_call_id' => self::callId($n), 'external_id' => '26011'.str_pad((string) $n, 5, '0', STR_PAD_LEFT),
                'attributed_at' => self::et('2026-11-02 09:00:00')->addMinutes($n), 'attribution_basis' => 'received',
                'duty_date' => '2026-11-02', 'confirmed' => $n !== 3, 'provenance' => $n === 1 ? 'backfill' : 'live',
                'voided_at' => $n === 12 ? now() : null, 'void_reason' => $n === 12 ? 'duplicate' : null,
            ]);
        }

        $one = $this->get(self::RUNS)->assertOk();
        $two = $this->get(self::RUNS.'?page=2')->assertOk();

        $this->assertNoStore($one);
        self::assertSame(['code' => 'E2', 'name' => 'Engine 2'], $one->json('unit'));
        self::assertCount(10, $one->json('runs'));
        self::assertTrue($one->json('hasMore'));
        self::assertSame(1, $one->json('page'));
        self::assertSame('Call 13', $one->json('runs.0.callType'));
        self::assertSame('#2601100013', $one->json('runs.0.idLabel'));
        self::assertSame('Cleared', $one->json('runs.0.unitStatus'));
        self::assertSame('live', $one->json('runs.0.provenance'));
        self::assertNotContains('Call 3', array_column($one->json('runs'), 'callType'));
        self::assertNotContains('Call 12', array_column($one->json('runs'), 'callType'));
        self::assertStringNotContainsString('confirmed', (string) $one->getContent());
        self::assertSame(['attributedAt', 'attributedLabel', 'callType', 'idLabel', 'externalId', 'unitStatus', 'provenance'], array_keys($one->json('runs.0')));
        self::assertCount(1, $two->json('runs'));
        self::assertFalse($two->json('hasMore'));
        self::assertSame('backfill', $two->json('runs.0.provenance'));
        self::assertSame(2, $two->json('page'));
        $this->get(self::RUNS.'?page=abc')->assertOk()->assertJsonPath('page', 1);
        $this->get(self::RUNS.'?page=-4')->assertOk()->assertJsonPath('page', 1);
        $this->get(self::RUNS.'?page=99')->assertOk()->assertJsonPath('runs', []);
    }

    public function test_an_unknown_unit_code_is_a_404(): void
    {
        $this->actingAsCanonicalFixture();

        foreach (['R5', 'R', 'XYZ', 'R111', 'r2x'] as $code) {
            $this->get('/unit-status/units/'.$code.'/runs')->assertNotFound();
        }
        $this->get('/unit-status/units/r44/runs')->assertOk()->assertJsonPath('unit.code', 'R44');
        $this->get('/unit-status/units/300/runs')->assertOk()->assertJsonPath('unit.name', '300');
    }

    public function test_the_page_and_fragment_render_when_the_views_exist(): void
    {
        if (! view()->exists('unit-status.show') || ! view()->exists('unit-status.partials.board')) {
            $this->markTestSkipped('The Unit Status views belong to the UI stream; the lead integrates them.');
        }
        $this->markFeedFresh();
        $this->actingAsCanonicalFixture();

        $page = $this->get(self::PAGE)->assertOk();
        $this->assertNoStore($page);
        $fragment = $this->get(self::BOARD)->assertOk();
        $this->assertNoStore($fragment);
        $etag = trim((string) $fragment->headers->get('ETag'), '"');
        self::assertNotSame('', $etag);
        $this->get(self::BOARD, ['If-None-Match' => '"'.$etag.'"'])->assertStatus(304);
    }

    public function test_the_menu_offers_unit_status_only_when_enabled_and_allowed(): void
    {
        $member = $this->actingAsCanonicalFixture();
        $keys = static fn (User $user): array => array_column(HubShellNavigation::forUser($user, [])['moreNavigation'], 'key');

        self::assertContains('unit-status', $keys($member));
        $entry = collect(HubShellNavigation::forUser($member, [])['moreNavigation'])->firstWhere('key', 'unit-status');
        self::assertSame('Unit Status', $entry['label']);
        self::assertSame('/unit-status', $entry['href']);

        config(['unit_status.enabled' => false]);
        self::assertNotContains('unit-status', $keys($member));

        config(['unit_status.enabled' => true]);
        self::assertNotContains('unit-status', $keys(User::factory()->create()));
    }

    public function test_home_offers_unit_status_next_to_the_daily_roster_only_when_enabled_and_allowed(): void
    {
        config(['daily_roster.mode' => 'publish']);
        $this->actingAsCanonicalFixture();
        $actions = static function (string $html): array {
            $dom = new \DOMDocument;
            @$dom->loadHTML($html);
            $links = (new \DOMXPath($dom))->query('//section[contains(@class,"home-today")]//div[contains(@class,"hub-page-header__actions")]/a');

            return array_map(static fn (\DOMElement $link): array => [
                'name' => trim(preg_replace('/\s+/', ' ', $link->textContent) ?? ''),
                'class' => $link->getAttribute('class'),
                'href' => $link->getAttribute('href'),
            ], iterator_to_array($links));
        };

        $buttons = $actions($this->get('/')->assertOk()->getContent());
        $names = array_column($buttons, 'name');
        $roster = array_search('Daily Roster', $names, true);
        self::assertIsInt($roster);
        self::assertSame('Unit Status', $names[$roster + 1], 'Unit Status sits right after the Daily Roster.');
        self::assertSame(route('unit-status.show'), $buttons[$roster + 1]['href']);
        foreach ($buttons as $button) {
            // Vehicle Checkout keeps its solid blue; every other top action carries the branded tint, none stays plain white.
            $expected = $button['name'] === 'Vehicle Checkout' ? 'hub-btn--primary' : 'hub-btn--brand';
            self::assertStringContainsString($expected, $button['class'], $button['name']);
            self::assertStringNotContainsString('hub-btn--secondary', $button['class'], $button['name']);
        }

        config(['unit_status.enabled' => false]);
        self::assertNotContains('Unit Status', array_column($actions($this->get('/')->assertOk()->getContent()), 'name'));

        config(['unit_status.enabled' => true]);
        $this->logoutCanonicalSession();
        $this->actingAsCanonicalUser(User::factory()->create());
        self::assertNotContains('Unit Status', array_column($actions($this->get('/')->assertOk()->getContent()), 'name'));
    }

    public function test_the_menu_entry_follows_daily_roster_in_the_more_menu(): void
    {
        config(['daily_roster.mode' => 'publish']);
        $member = $this->actingAsCanonicalFixture();

        $keys = array_column(HubShellNavigation::forUser($member, [])['moreNavigation'], 'key');

        self::assertSame($keys[array_search('daily-roster', $keys, true) + 1], 'unit-status');
    }
}
