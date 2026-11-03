<?php

declare(strict_types=1);

namespace Tests\Feature\UnitStatus;

use Tests\Support\UnitStatusBoardFixture;
use Tests\TestCase;

/**
 * Renders the Unit Status views from a hand-built view model (contract sections 8 and 10.5). No database, feed or roster.
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

    /** The visible board without the per-unit pop-up templates. */
    private function rows(?array $board = null): string
    {
        return preg_replace('/<template\b.*?<\/template>/s', '', $this->board($board)) ?? '';
    }

    /** One unit's pop-up template. */
    private function popup(string $domId, ?array $board = null): string
    {
        preg_match('/<template id="profile-'.preg_quote($domId, '/').'".*?<\/template>/s', $this->board($board), $m);

        return $m[0] ?? '';
    }

    public function test_it_lists_five_stations_and_sixteen_compact_rows_in_contract_order(): void
    {
        $html = $this->rows();

        $this->assertSame(5, preg_match_all('/<h2 class="us-station"/', $html));
        $this->assertSame(16, preg_match_all('/<button\s+type="button"\s+class="us-row"/', $html));
        preg_match_all('/data-unit-code="([^"]+)"/', $html, $codes);
        $this->assertSame(self::ORDER, $codes[1]);
        $this->assertSame(self::ORDER, UnitStatusBoardFixture::unitCodes());
        foreach (['Station 1', 'Station 2', 'Station 3', 'Station 4', 'Station 6'] as $label) {
            $this->assertStringContainsString('>'.$label.'</h2>', $html);
        }
        $this->assertStringNotContainsString('Station 5', $html);
    }

    public function test_there_is_no_inline_expansion_table_or_separate_profile_link(): void
    {
        $html = $this->rows();

        foreach (['<table', '<td', 'us-panel-row', 'aria-expanded', 'aria-controls', 'us-toggle', 'us-view', 'Current Assignment', 'Personnel on Truck', '24h Runs', 'View profile'] as $gone) {
            $this->assertStringNotContainsString($gone, $html, $gone);
        }
        $this->assertSame(16, preg_match_all('/aria-haspopup="dialog"/', $html));
    }

    public function test_a_row_shows_only_unit_chip_and_incident(): void
    {
        $html = $this->rows();

        $this->assertMatchesRegularExpression('/<strong class="us-row__code">R2<\/strong>\s*<span class="us-row__name">Rescue 2<\/span>/', $html);
        $this->assertStringContainsString('#2601241652', $html);
        $this->assertStringContainsString('ID pending', $html);
        // Only the on-call units show an incident; quiet units show none.
        $this->assertSame(7, preg_match_all('/class="us-row__incident"/', $html));
        $this->assertStringNotContainsString('Lt. Benson', $html);
        $this->assertStringNotContainsString('>Lift Assist', $html);
    }

    public function test_the_incident_is_hidden_unless_the_unit_is_on_a_call(): void
    {
        $board = UnitStatusBoardFixture::make([], ['R4' => ['incidentLabel' => '#2601999999']]);

        $this->assertStringNotContainsString('#2601999999', $this->rows($board));
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
            $this->assertStringContainsString('PulsePoint is advisory. Verify with dispatch.', $this->rows($board));
        }
    }

    public function test_the_totals_cards_and_note_are_present(): void
    {
        $html = $this->rows();

        $this->assertSame(4, preg_match_all('/<section class="us-total"/', $html));
        $this->assertMatchesRegularExpression('/Runs this shift<\/h2>\s*<p class="us-total__figure">9</', $html);
        $this->assertMatchesRegularExpression('/Rescue<\/h2>\s*<p class="us-total__figure">5</', $html);
        $this->assertMatchesRegularExpression('/Combat<\/h2>\s*<p class="us-total__figure">4</', $html);
        $this->assertMatchesRegularExpression('/Busiest unit<\/h2>\s*<p class="us-total__figure us-total__figure--text">R2<\/p>\s*<p class="us-total__sub">4 runs</', $html);
        $this->assertStringContainsString('Counts began Nov 2, 2026 at 8:00 AM and keep adding up from there.', $html);
    }

    public function test_tied_busiest_units_are_joined_and_none_says_so(): void
    {
        $tied = UnitStatusBoardFixture::make(['totals' => ['total' => 4, 'rescue' => 2, 'combat' => 2, 'busiest' => ['codes' => ['E1', 'R2'], 'runs' => 2]]]);
        $this->assertStringContainsString('E1 · R2', $this->rows($tied));

        $none = UnitStatusBoardFixture::make(['totals' => ['total' => 0, 'rescue' => 0, 'combat' => 0, 'busiest' => null]]);
        $this->assertStringContainsString('No runs yet this shift', $this->rows($none));
    }

    public function test_hostile_text_is_escaped_everywhere(): void
    {
        $html = $this->board();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<b>x</b>', $html);
        $this->assertStringContainsString('Medical &quot;Emergency&quot; &lt;b&gt;x&lt;/b&gt;', $html);
    }

    public function test_the_popup_has_every_section(): void
    {
        $html = $this->popup('unit-l1');

        foreach (['Runs this shift', 'Current call', 'Calls this shift', 'Busiest day', 'All-time runs', 'Crew today', 'Last verified', 'Station 1'] as $section) {
            $this->assertStringContainsString($section, $html, $section);
        }
        $this->assertStringContainsString('5 runs</strong> · Nov 2, 2026', $html);
        $this->assertStringContainsString('since Nov 2, 2026', $html);
        $this->assertStringContainsString('4:58 PM', $html);
        $this->assertStringContainsString('1:10 PM', $html);
        $this->assertStringContainsString('#2601241652 · On Scene', $html);
        $this->assertStringContainsString('PulsePoint is advisory. Verify with dispatch.', $html);
        $this->assertStringContainsString('A run is a PulsePoint call', $html);
    }

    public function test_the_popup_shows_the_current_call_only_when_on_one(): void
    {
        $this->assertStringContainsString('Current call', $this->popup('unit-r2'));
        $this->assertStringContainsString('1230 Ocean Ave', $this->popup('unit-r2'));
        $this->assertStringNotContainsString('Current call', $this->popup('unit-r11'));
    }

    public function test_empty_history_and_calls_say_so(): void
    {
        $html = $this->popup('unit-r11');

        $this->assertStringContainsString('No calls yet this shift', $html);
        $this->assertStringContainsString('Not enough history yet', $html);
    }

    public function test_the_popup_renders_short_crew_strings_and_reliefs(): void
    {
        $html = $this->popup('unit-e2');

        $this->assertStringContainsString('Lt. Benson, FF Miller', $html);
        $this->assertStringContainsString('From 7:00 PM: FF Ruiz', $html);
        $this->assertStringContainsString('Roster as of', $html);
        $this->assertStringNotContainsString('Benson, Lee', $html);
        $this->assertStringContainsString('1 vacancy', $this->popup('unit-300'));
        $this->assertStringContainsString('Roster not posted — personnel not confirmed', $this->popup('unit-cpt5'));
        $this->assertStringNotContainsString('employee', strtolower($this->board()));
    }

    public function test_the_word_unconfirmed_and_question_mark_designations_never_appear(): void
    {
        $page = view('unit-status.show', ['board' => UnitStatusBoardFixture::make()])->render();
        $degraded = $this->board(UnitStatusBoardFixture::degraded());

        foreach ([$page, $this->board(), $degraded] as $html) {
            $this->assertStringNotContainsStringIgnoringCase('unconfirmed', $html);
            $this->assertDoesNotMatchRegularExpression('/(?<![A-Za-z0-9=&])\?(R|E|L|CPT|FB|300)\d*(?![A-Za-z0-9])/i', strip_tags($html));
            $this->assertDoesNotMatchRegularExpression('/>\s*\?[A-Z0-9]/', $html);
        }
    }

    public function test_status_labels_are_the_professional_wording(): void
    {
        $html = $this->rows();

        foreach (['On Scene', 'En Route', 'Dispatched', 'Transporting', 'At Hospital', 'Assigned'] as $label) {
            $this->assertStringContainsString('<span>'.$label.'</span>', $html, $label);
        }
        foreach (['Enroute', '>Transport<'] as $raw) {
            $this->assertStringNotContainsString($raw, $html);
        }
    }

    public function test_the_pulsepoint_url_is_not_mistaken_for_a_designation(): void
    {
        // The fixture's PulsePoint links contain "?incident=" and must still render.
        $this->assertStringContainsString('?incident=2', $this->popup('unit-e1'));
    }

    public function test_the_pulsepoint_link_opens_safely(): void
    {
        $html = $this->popup('unit-l1');

        $this->assertMatchesRegularExpression('/<a class="us-link" data-focus-key="call-src-1" href="https:\/\/web\.pulsepoint\.org[^"]*" target="_blank" rel="noopener noreferrer">Open in PulsePoint/', $html);
    }

    public function test_a_non_https_link_is_not_rendered(): void
    {
        $call = array_replace(UnitStatusBoardFixture::make()['stations'][0]['units'][0]['currentCalls'][0], ['sourceUrl' => 'javascript:alert(1)']);
        $board = UnitStatusBoardFixture::make([], ['R3' => ['currentCalls' => [$call]]]);

        $this->assertStringNotContainsString('javascript:', $this->board($board));
    }

    public function test_the_status_chip_uses_the_indicator_only_as_a_dot(): void
    {
        $html = $this->rows();

        $this->assertStringContainsString('style="--us-indicator: rgb(204,0,0)"', $html);
        $this->assertStringContainsString('hub-tag--success', $html);
        $this->assertStringContainsString('hub-tag--warning', $this->board(UnitStatusBoardFixture::degraded()));
        $this->assertDoesNotMatchRegularExpression('/hub-tag--success[^>]*us-indicator/', $html);
    }

    public function test_a_hostile_indicator_color_is_dropped(): void
    {
        $board = UnitStatusBoardFixture::make([], ['L3' => [
            'availability' => ['key' => 'on_call', 'label' => 'On Scene', 'family' => 'on_scene', 'tone' => 'source', 'indicatorColor' => 'red;background:url(x)', 'confirmed' => true, 'stale' => false, 'note' => null, 'observedAt' => null],
        ]]);

        $this->assertStringNotContainsString('url(x)', $this->board($board));
    }

    public function test_a_stale_on_call_unit_says_when_it_was_last_verified(): void
    {
        $this->assertStringContainsString('Last verified 4:58 PM ET', $this->popup('unit-r22'));
        $this->assertStringContainsString('PulsePoint feed delayed', $this->popup('unit-r22'));
    }

    public function test_the_board_root_carries_its_generated_time_for_the_stale_check(): void
    {
        $this->assertStringContainsString('data-generated-at="2026-11-02T21:58:30Z"', $this->board());
    }

    public function test_the_page_has_the_heading_dialog_live_region_and_focus_key(): void
    {
        $html = view('unit-status.show', ['board' => UnitStatusBoardFixture::make()])->render();

        $this->assertSame(1, preg_match_all('/<h1[^>]*>Unit Status<\/h1>/', $html));
        $this->assertSame(1, preg_match_all('/<dialog\b/', $html));
        $this->assertStringContainsString('aria-live="polite"', $html);
        $this->assertStringContainsString('data-board-url="'.url('/unit-status/board').'"', $html);
        $this->assertStringContainsString('data-us-dialog-close data-focus-key="close"', $html);
        $this->assertStringContainsString('data-etag="fixture-etag-1"', $html);
    }
}
