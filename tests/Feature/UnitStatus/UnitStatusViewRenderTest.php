<?php

declare(strict_types=1);

namespace Tests\Feature\UnitStatus;

use Tests\Support\UnitStatusBoardFixture;
use Tests\TestCase;

/**
 * Renders the Unit Status views from a hand-built view model (contract section 8). No database, feed or roster.
 */
final class UnitStatusViewRenderTest extends TestCase
{
    private const ORDER = ['L1', 'E1', 'R1', 'R11', 'E2', 'R2', 'R22', 'CPT5', '300', 'L3', 'E3', 'R3', 'E4', 'R4', 'R44', 'FB6'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function board(?array $board = null): string
    {
        return view('unit-status.partials.board', ['board' => $board ?? UnitStatusBoardFixture::make()])->render();
    }

    public function test_it_lists_five_stations_and_sixteen_units_in_contract_order(): void
    {
        $html = $this->board();

        $this->assertSame(5, preg_match_all('/<tr class="us-station">/', $html));
        $this->assertSame(16, preg_match_all('/<tr\s+class="us-unit"/', $html));
        preg_match_all('/data-unit-code="([^"]+)"/', $html, $codes);
        $this->assertSame(self::ORDER, $codes[1]);
        $this->assertSame(self::ORDER, UnitStatusBoardFixture::unitCodes());
        foreach (['Station 1', 'Station 2', 'Station 3', 'Station 4', 'Station 6'] as $label) {
            $this->assertStringContainsString('colspan="6">'.$label.'</th>', preg_replace('/\s+/', ' ', $html) ?? '');
        }
        $this->assertStringNotContainsString('Station 5', $html);
    }

    public function test_the_table_is_semantic(): void
    {
        $html = $this->board();

        $this->assertStringContainsString('<caption class="sr-only">', $html);
        $this->assertStringContainsString('<thead>', $html);
        $this->assertSame(5, preg_match_all('/<th scope="rowgroup" colspan="6">/', $html));
        $this->assertSame(5, preg_match_all('/<tbody class="us-station-group"/', $html));
        foreach (['Unit', 'Availability / Status', 'Current Assignment / Call Status', 'Personnel on Truck (This Shift)', 'Profile / Details'] as $heading) {
            $this->assertStringContainsString($heading, $html);
        }
        $this->assertStringContainsString('this duty shift since 8:00 AM', $html);
    }

    public function test_subtitle_only_shows_when_live(): void
    {
        $this->assertStringContainsString('24-Hour Live Availability', $this->board(UnitStatusBoardFixture::make(['live' => true])));

        $degraded = $this->board(UnitStatusBoardFixture::degraded());
        $this->assertStringNotContainsString('24-Hour Live Availability', $degraded);
        $this->assertStringContainsString('Feed delayed — last update 4:58 PM ET', $degraded);
        $this->assertStringContainsString('Roster not posted yet', $degraded);
    }

    public function test_the_advisory_line_is_always_there(): void
    {
        foreach ([UnitStatusBoardFixture::make(), UnitStatusBoardFixture::degraded()] as $board) {
            $this->assertStringContainsString('PulsePoint is advisory. Verify with dispatch.', $this->board($board));
        }
    }

    public function test_hostile_text_is_escaped_everywhere(): void
    {
        $html = $this->board();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<b>x</b>', $html);
        $this->assertStringContainsString('Medical &quot;Emergency&quot; &lt;b&gt;x&lt;/b&gt;', $html);
    }

    public function test_disclosure_buttons_name_the_panel_they_control(): void
    {
        $html = $this->board();

        $this->assertSame(16, preg_match_all('/aria-expanded="false"/', $html));
        $this->assertSame(1, preg_match('/data-us-toggle="unit-r2"\s+aria-expanded="false"\s+aria-controls="unit-r2-panel"/', $html));
        $this->assertStringContainsString('id="unit-r2-panel"', $html);
        $this->assertSame(16, preg_match_all('/<tr class="us-panel-row"[^>]*\shidden>/', $html));
        $this->assertSame(16, preg_match_all('/<template id="profile-unit-[a-z0-9]+"/', $html));
    }

    public function test_the_pulsepoint_link_opens_safely(): void
    {
        $html = $this->board();

        $this->assertMatchesRegularExpression('/<a class="us-link" href="https:\/\/web\.pulsepoint\.org[^"]*" target="_blank" rel="noopener noreferrer">Open in PulsePoint/', $html);
        $this->assertStringNotContainsString('target="_blank">', $html);
    }

    public function test_a_non_https_link_is_not_rendered(): void
    {
        $board = UnitStatusBoardFixture::make([], [
            'R3' => ['currentCalls' => [array_replace(UnitStatusBoardFixture::make()['stations'][0]['units'][0]['currentCalls'][0], ['sourceUrl' => 'javascript:alert(1)'])]],
        ]);

        $this->assertStringNotContainsString('javascript:', $this->board($board));
    }

    public function test_a_call_without_a_number_says_id_pending(): void
    {
        $html = $this->board();

        $this->assertStringContainsString('ID pending', $html);
        $this->assertStringContainsString('#2601241652', $html);
    }

    public function test_unconfirmed_observations_are_marked(): void
    {
        $html = $this->board();

        $this->assertStringContainsString('Dispatched (unconfirmed)', $html);
        $this->assertSame(1, substr_count($html, 'data-status="Dispatched (unconfirmed)"'));
    }

    public function test_crew_names_are_shown_and_no_employee_id_exists(): void
    {
        $board = UnitStatusBoardFixture::make();
        $html = $this->board($board);

        $this->assertStringContainsString('Lt. Pat Tester L1, Sam Sample', $html);
        $this->assertStringNotContainsString('employee', strtolower(json_encode($board, JSON_THROW_ON_ERROR)));
        $this->assertStringNotContainsString('employee', strtolower($html));
        // Unposted roster: no names, the label stands in.
        $this->assertStringContainsString('Roster not posted — personnel not confirmed', $html);
    }

    public function test_two_current_calls_show_the_newest_and_a_count(): void
    {
        $html = $this->board();

        $this->assertStringContainsString('+1 more call', $html);
        $this->assertStringContainsString('Alarm – 100 Lincoln Rd', $html);
    }

    public function test_the_status_chip_uses_the_indicator_only_as_a_dot(): void
    {
        $html = $this->board();

        $this->assertStringContainsString('style="--us-indicator: rgb(204,0,0)"', $html);
        $this->assertStringContainsString('hub-tag--success', $html);
        $this->assertStringContainsString('hub-tag--warning', $this->board(UnitStatusBoardFixture::degraded()));
        // Available never carries an indicator color.
        $this->assertDoesNotMatchRegularExpression('/hub-tag--success[^>]*us-indicator/', $html);
    }

    public function test_a_hostile_indicator_color_is_dropped(): void
    {
        $board = UnitStatusBoardFixture::make([], ['L3' => [
            'availability' => ['key' => 'on_call', 'label' => 'On Scene', 'family' => 'on_scene', 'tone' => 'source', 'indicatorColor' => 'red;background:url(x)', 'confirmed' => true, 'stale' => false, 'note' => null, 'observedAt' => null],
        ]]);

        $this->assertStringNotContainsString('url(x)', $this->board($board));
    }

    public function test_a_stale_on_call_status_notes_when_it_was_last_seen(): void
    {
        $this->assertStringContainsString('Last seen 4:58 PM ET', $this->board());
    }

    public function test_the_runs_column_shows_the_shift_count(): void
    {
        $html = $this->board();

        $this->assertMatchesRegularExpression('/<span class="us-runs">4<span class="sr-only"> runs this duty shift<\/span>/', $html);
        $this->assertStringContainsString('Since System Go-Live', $html);
    }

    public function test_the_full_profile_loads_runs_lazily_and_the_inline_one_does_not(): void
    {
        $html = $this->board();

        $this->assertSame(16, preg_match_all('/data-us-runs data-url="[^"]*\/unit-status\/units\/[^"\/]+\/runs"/', $html));
        $this->assertSame(16, preg_match_all('/us-profile--inline/', $html));
        $this->assertSame(16, preg_match_all('/us-profile--full/', $html));
        $this->assertStringContainsString('Clickable unit profile', $html);
    }

    public function test_the_page_has_the_heading_dialog_and_live_region(): void
    {
        $html = view('unit-status.show', ['board' => UnitStatusBoardFixture::make()])->render();

        $this->assertSame(1, preg_match_all('/<h1[^>]*>Unit Status<\/h1>/', $html));
        $this->assertSame(1, preg_match_all('/<dialog\b/', $html));
        $this->assertStringContainsString('aria-live="polite"', $html);
        $this->assertStringContainsString('data-board-url="'.url('/unit-status/board').'"', $html);
        $this->assertStringContainsString('data-us-board', $html);
        $this->assertStringContainsString('data-etag="fixture-etag-1"', $html);
    }
}
