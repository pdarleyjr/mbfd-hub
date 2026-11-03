<?php

declare(strict_types=1);

namespace Tests\Unit\UnitStatus;

use App\Services\UnitStatus\SourceCall;
use PHPUnit\Framework\TestCase;

final class SourceCallTest extends TestCase
{
    /** @return array<string, mixed> */
    private function row(array $overrides = []): array
    {
        return array_merge([
            'id' => 'src_'.str_repeat('a', 32),
            'externalId' => '2601241652',
            'duplicateOf' => null,
            'sourceUrl' => 'https://web.pulsepoint.org/DeepLink.aspx?incidentid=1',
            'callType' => 'Medical Emergency',
            'address' => '1 MAIN ST',
            'lat' => 25.78,
            'lng' => -80.13,
            'locationQuality' => 'approximate',
            'receivedAt' => '2026-11-02T14:00:00+00:00',
            'section' => 'active',
            'sourceStatus' => 'Active',
            'closedAt' => null,
            'disappearedAt' => null,
            'firstSeenAt' => '2026-11-02T14:01:00+00:00',
            'lastSeenAt' => '2026-11-02T14:05:00+00:00',
            'updatedAt' => '2026-11-02T14:05:00+00:00',
            'units' => [['unitId' => 'E2', 'status' => 'Dispatched']],
            'futureField' => 'ignored',
        ], $overrides);
    }

    public function test_a_valid_row_is_parsed_and_unknown_fields_are_ignored(): void
    {
        $call = SourceCall::fromArray($this->row());

        self::assertNotNull($call);
        self::assertSame('2026-11-02 14:00:00', $call->receivedAt?->format('Y-m-d H:i:s'));
        self::assertSame([['unitId' => 'E2', 'status' => 'Dispatched']], $call->units);
        self::assertSame(25.78, $call->lat);
    }

    public function test_malformed_rows_are_rejected(): void
    {
        $bad = [
            'no id' => ['id' => null],
            'bad id' => ['id' => 'abc'],
            'unknown section' => ['section' => 'archived'],
            'units not a list' => ['units' => 'E2'],
            'unit without id' => ['units' => [['status' => 'Enroute']]],
            'unit not an object' => ['units' => ['E2']],
            'bad first seen' => ['firstSeenAt' => 'not a time'],
            'missing updated' => ['updatedAt' => null],
        ];
        foreach ($bad as $name => $override) {
            self::assertNull(SourceCall::fromArray($this->row($override)), $name);
        }
    }

    public function test_only_https_links_survive(): void
    {
        foreach (['javascript:alert(1)', 'http://web.pulsepoint.org/x', 'data:text/html,<b>', 'https://x.org/a b', ''] as $url) {
            self::assertNull(SourceCall::fromArray($this->row(['sourceUrl' => $url]))?->sourceUrl, $url);
        }
        self::assertNotNull(SourceCall::fromArray($this->row())?->sourceUrl);
    }

    public function test_location_is_kept_only_when_approximate_and_in_range(): void
    {
        self::assertNull(SourceCall::fromArray($this->row(['locationQuality' => 'none']))?->lat);
        self::assertNull(SourceCall::fromArray($this->row(['lat' => 123.0]))?->lat);
        self::assertNull(SourceCall::fromArray($this->row(['lat' => '25.7']))?->lat);
        self::assertSame('approximate', SourceCall::fromArray($this->row())?->locationQuality);
    }

    public function test_text_is_trimmed_stripped_of_control_characters_and_bounded(): void
    {
        $call = SourceCall::fromArray($this->row(['address' => "  1 MAIN\x00 ST\x07 ".str_repeat('x', 400), 'units' => [['unitId' => " ?r 1\x00", 'status' => '']]]));

        self::assertNotNull($call);
        self::assertSame(300, mb_strlen($call->address));
        self::assertStringStartsWith('1 MAIN ST', $call->address);
        self::assertSame('?r 1', $call->units[0]['unitId']);
    }
}
