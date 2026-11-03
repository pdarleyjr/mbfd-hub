<?php

declare(strict_types=1);

namespace Tests\Feature\UnitStatus;

use App\Services\UnitStatus\UnitStatusBoard;
use App\Support\UnitStatus\StatusFamily;

/** Contract 10.6: professional wording from one mapping, and no trace of PulsePoint's "?" doubt anywhere. */
final class UnitStatusLabelsTest extends UnitStatusTestCase
{
    /** @return array<string, mixed> */
    private function board(): array
    {
        return app(UnitStatusBoard::class)->build(now());
    }

    /** @return array<string, mixed> */
    private function unit(array $board, string $code): array
    {
        foreach ($board['stations'] as $station) {
            foreach ($station['units'] as $unit) {
                if ($unit['code'] === $code) {
                    return $unit;
                }
            }
        }
        self::fail('No unit '.$code);
    }

    public function test_the_label_table_is_the_only_source_of_status_text(): void
    {
        $expected = [
            'Dispatched' => 'Dispatched', 'Acknowledged' => 'Dispatched', 'Enroute' => 'En Route', 'Staged' => 'Staged',
            'On Scene' => 'On Scene', 'Available On Scene' => 'On Scene', 'Transport' => 'Transporting',
            'Transport Arrived' => 'At Hospital', 'Cleared From Incident' => 'Cleared', '' => 'Assigned',
            'something new' => 'Assigned', '  on   scene ' => 'On Scene', 'ENROUTE' => 'En Route',
        ];
        foreach ($expected as $word => $label) {
            self::assertSame($label, StatusFamily::label($word), $word);
        }
        self::assertSame('Assigned', StatusFamily::label(null));
    }

    public function test_every_status_a_unit_can_show_uses_the_board_wording_everywhere(): void
    {
        $this->markFeedFresh();
        $received = ['received_at' => now()->subMinutes(2)];
        $words = ['E1' => ['Dispatched', 'Dispatched'], 'E2' => ['Acknowledged', 'Dispatched'], 'E3' => ['Enroute', 'En Route'], 'E4' => ['Staged', 'Staged'],
            'R1' => ['On Scene', 'On Scene'], 'R2' => ['Available On Scene', 'On Scene'], 'R3' => ['Transport', 'Transporting'], 'R4' => ['Transport Arrived', 'At Hospital'], 'L1' => ['', 'Assigned']];
        foreach (array_keys($words) as $i => $code) {
            $this->storeCall($i + 1, [$code => ['status' => $words[$code][0]]], $received);
        }

        $board = $this->board();

        foreach ($words as $code => [$raw, $label]) {
            $unit = $this->unit($board, $code);
            self::assertSame($label, $unit['availability']['label'], $code);
            self::assertSame($label, $unit['currentCalls'][0]['unitStatus'], $code);
            self::assertTrue($unit['availability']['confirmed'], $code);
            self::assertTrue($unit['currentCalls'][0]['unitConfirmed'], $code);
        }
    }

    public function test_the_other_units_list_has_confirmed_units_only_in_board_wording(): void
    {
        $this->markFeedFresh();
        $this->storeCall(1, ['E1' => ['status' => 'Enroute']], ['received_at' => now()->subMinutes(2), 'units_json' => [
            ['unitId' => 'E1', 'status' => 'Enroute'], ['unitId' => 'G1', 'status' => 'On Scene'], ['unitId' => '?G2', 'status' => 'Dispatched'],
            ['unitId' => ' ?300', 'status' => 'Dispatched'], ['unitId' => 'R1', 'status' => 'Transport'],
        ]]);

        $others = $this->unit($this->board(), 'E1')['currentCalls'][0]['otherUnits'];

        self::assertSame([['designation' => 'G1', 'status' => 'On Scene'], ['designation' => 'R1', 'status' => 'Transporting']], $others);
    }

    public function test_no_surface_ever_shows_a_question_mark_unit_or_the_word_unconfirmed(): void
    {
        $this->fakeFeed($this->feedPage([$this->sourceCall(1, [
            ['unitId' => '?300', 'status' => 'Dispatched'], ['unitId' => '?CPT5', 'status' => 'Dispatched'], ['unitId' => '?R22', 'status' => 'Dispatched'],
            ['unitId' => '?G9', 'status' => 'Dispatched'], ['unitId' => 'E1', 'status' => 'Enroute'], ['unitId' => 'R1', 'status' => 'Transport'],
        ], ['receivedAt' => now()->subMinutes(1)->toIso8601String(), 'firstSeenAt' => now()->subMinutes(1)->toIso8601String()])]));
        $this->sync();
        $this->actingAsCanonicalFixture();

        $surfaces = [
            'data' => (string) $this->get('/unit-status/data')->assertOk()->getContent(),
            'runs' => (string) $this->get('/unit-status/units/R22/runs')->assertOk()->getContent(),
        ];
        self::assertSame('on_call', $this->unit($this->board(), 'E1')['availability']['key']);
        self::assertNotSame('on_call', $this->unit($this->board(), 'R22')['availability']['key']);

        if (view()->exists('unit-status.show') && view()->exists('unit-status.partials.board')) {
            $surfaces['page'] = (string) $this->get('/unit-status')->assertOk()->getContent();
            $surfaces['board'] = (string) $this->get('/unit-status/board')->assertOk()->getContent();
        }

        foreach ($surfaces as $name => $body) {
            foreach (['unconfirmed', '?300', '?cpt5', '?r22', '?g9'] as $forbidden) {
                self::assertStringNotContainsStringIgnoringCase($forbidden, $body, $name.' must not contain '.$forbidden);
            }
        }
        self::assertStringContainsString('En Route', $surfaces['data']);
        self::assertStringContainsString('Transporting', $surfaces['data']);
        self::assertStringNotContainsString('Enroute', $surfaces['data']);
    }
}
