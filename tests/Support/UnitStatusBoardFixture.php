<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * A hand-built Unit Status view model that follows docs/unit-status/unit-status-contract.md section 8 exactly,
 * so the views can be rendered without a database, a feed or a roster. Names are synthetic. There is no employee id
 * anywhere in this shape, by contract.
 *
 * @phpstan-type Unit array<string, mixed>
 */
final class UnitStatusBoardFixture
{
    public const HOSTILE_ADDRESS = '<script>alert(1)</script>';

    public const HOSTILE_CALL_TYPE = 'Medical "Emergency" <b>x</b>';

    /** The 16 units in contract order, by station. */
    private const LAYOUT = [
        1 => ['L1' => 'Ladder 1', 'E1' => 'Engine 1', 'R1' => 'Rescue 1', 'R11' => 'Rescue 11'],
        2 => ['E2' => 'Engine 2', 'R2' => 'Rescue 2', 'R22' => 'Rescue 22', 'CPT5' => 'Captain 5', '300' => '300'],
        3 => ['L3' => 'Ladder 3', 'E3' => 'Engine 3', 'R3' => 'Rescue 3'],
        4 => ['E4' => 'Engine 4', 'R4' => 'Rescue 4', 'R44' => 'Rescue 44'],
        6 => ['FB6' => 'Fireboat 6'],
    ];

    /**
     * @param  array<string, mixed>  $overrides  Top-level keys replace the defaults
     * @param  array<string, array<string, mixed>>  $unitOverrides  Unit code => keys to replace on that unit
     * @return array<string, mixed>
     */
    public static function make(array $overrides = [], array $unitOverrides = [], bool $scenario = true): array
    {
        $stations = [];
        foreach (self::LAYOUT as $number => $units) {
            $rows = [];
            foreach ($units as $code => $name) {
                $code = (string) $code; // PHP turns the key '300' into an int
                $unit = self::quietUnit($code, $name, $number);
                if ($scenario) {
                    $unit = array_replace($unit, self::scenario($code, $name, $number));
                }
                $rows[] = array_replace($unit, $unitOverrides[$code] ?? []);
            }
            $stations[] = ['number' => $number, 'label' => 'Station '.$number, 'units' => $rows];
        }

        return array_replace([
            'schemaVersion' => 2,
            'generatedAt' => '2026-11-02T21:58:30Z',
            'etag' => 'fixture-etag-1',
            'live' => true,
            'advisory' => 'PulsePoint is advisory. Verify with dispatch.',
            'source' => [
                'state' => 'live', 'label' => 'PulsePoint live', 'message' => 'Feed is current.',
                'lastSourceReadAt' => '2026-11-02T21:58:10Z', 'lastSyncAt' => '2026-11-02T21:58:20Z', 'fresh' => true,
            ],
            'roster' => [
                'state' => 'current', 'label' => 'Roster current', 'message' => 'Roster published for today.',
                'dutyDate' => '2026-11-02', 'shift' => 'A', 'asOf' => '2026-11-02T12:30:00Z',
            ],
            'dutyWindow' => [
                'dutyDate' => '2026-11-02', 'startsAt' => '2026-11-02T13:00:00Z', 'endsAt' => '2026-11-03T13:00:00Z',
                'label' => 'Nov 2, 8:00 AM – Nov 3, 8:00 AM ET', 'shift' => 'A',
            ],
            'tracking' => [
                'startsAt' => '2026-11-02T13:00:00Z', 'startsLabel' => 'Nov 2, 2026', 'firstSyncedAt' => '2026-11-02T13:01:00Z',
                'definition' => 'A run is a PulsePoint call that listed this unit (an observed assignment). It is not proof the unit responded.',
                'note' => 'Counts began Nov 2, 2026 at 8:00 AM and keep adding up from there.',
                'openGap' => false,
                'gaps' => [['startedAt' => '2026-11-02T15:00:00Z', 'endedAt' => '2026-11-02T15:10:00Z', 'reason' => 'feed_delayed', 'label' => 'Feed delayed 11:00 AM to 11:10 AM ET']],
            ],
            'totals' => ['total' => 9, 'rescue' => 5, 'combat' => 4, 'busiest' => ['codes' => ['R2'], 'runs' => 4]],
            'stations' => $stations,
        ], $overrides);
    }

    /** Same shape with the feed delayed and the roster not posted: no subtitle, no names. */
    public static function degraded(): array
    {
        return self::make([
            'live' => false,
            'source' => [
                'state' => 'delayed', 'label' => 'Feed delayed', 'message' => 'PulsePoint feed delayed',
                'lastSourceReadAt' => '2026-11-02T21:58:10Z', 'lastSyncAt' => '2026-11-02T21:58:20Z', 'fresh' => false,
            ],
            'roster' => [
                'state' => 'none', 'label' => 'Roster not posted', 'message' => 'Roster not posted yet — personnel not confirmed',
                'dutyDate' => '2026-11-02', 'shift' => null, 'asOf' => null,
            ],
        ], [
            'E3' => [
                'availability' => self::availability('unknown', 'Not confirmed', 'warning', 'unknown', null, true, false, 'PulsePoint feed delayed'),
                'assignmentSummary' => 'Not confirmed',
                'crew' => self::crew('not_posted', 'Roster not posted — personnel not confirmed', []),
            ],
        ]);
    }

    /** @return list<string> */
    public static function unitCodes(): array
    {
        return array_map('strval', array_merge(...array_map('array_keys', array_values(self::LAYOUT))));
    }

    private static function quietUnit(string $code, string $name, int $station): array
    {
        return [
            'code' => $code,
            'name' => $name,
            'station' => $station,
            'domId' => 'unit-'.strtolower($code),
            'availability' => self::availability('available', 'Available', 'success', 'available', null, true, false, null),
            'assignmentSummary' => 'No active call',
            'conflict' => false,
            'currentCalls' => [],
            'incidentLabel' => null,
            'profile' => self::profile(0, 0, null, []),
            'crew' => self::crew('confirmed', 'Crew confirmed', [['rank' => 'Lt.', 'name' => 'Benson, Lee'], ['rank' => 'FF', 'name' => 'Miller, Sam']]),
        ];
    }

    /** The interesting units; everything else stays quiet and available. */
    private static function scenario(string $code, string $name, int $station): array
    {
        return match ($code) {
            // On a call at a hostile address, with a hostile call type.
            'L1' => [
                'availability' => self::availability('on_call', 'On Scene', 'source', 'on_scene', 'rgb(204,0,0)', true, false, null),
                'assignmentSummary' => self::HOSTILE_CALL_TYPE.' – '.self::HOSTILE_ADDRESS,
                'currentCalls' => [self::call('src-1', '2601241652', self::HOSTILE_CALL_TYPE, self::HOSTILE_ADDRESS, 'On Scene', true, 'https://web.pulsepoint.org/?agencyid=1&incident=1')],
                'incidentLabel' => '#2601241652',
                'profile' => self::profile(3, 12, ['runs' => 5, 'date' => '2026-11-02', 'label' => 'Nov 2, 2026'], [self::run('4:58 PM', self::HOSTILE_CALL_TYPE, '#2601241652', 'On Scene', true), self::run('1:10 PM', 'Alarm', '#2601240900', 'Cleared From Incident', false)]),
            ],
            // Two calls at once.
            'E1' => [
                'availability' => self::availability('on_call', 'En Route', 'source', 'enroute', 'rgb(0,204,0)', true, false, null),
                'assignmentSummary' => 'Alarm – 100 Lincoln Rd',
                'conflict' => true,
                'currentCalls' => [
                    self::call('src-2', '2601241700', 'Alarm', '100 Lincoln Rd', 'En Route', true, 'https://web.pulsepoint.org/?incident=2'),
                    self::call('src-3', '2601241650', 'Medical Emergency', '200 Collins Ave', 'Dispatched', true, 'https://web.pulsepoint.org/?incident=3'),
                ],
                'incidentLabel' => '#2601241700',
                'profile' => self::profile(2, 5, null, []),
            ],
            // Dispatched, confirmed.
            'R1' => [
                'availability' => self::availability('on_call', 'Dispatched', 'source', 'dispatched', 'rgb(245,124,0)', true, false, null),
                'assignmentSummary' => 'Fire Alarm – 55 Meridian Ave',
                'incidentLabel' => '#2601241710',
                'currentCalls' => [self::call('src-4', '2601241710', 'Fire Alarm', '55 Meridian Ave', 'Dispatched', true, 'https://web.pulsepoint.org/?incident=4')],
            ],
            // A provisional call that PulsePoint has not numbered yet.
            'R2' => [
                'availability' => self::availability('on_call', 'Transporting', 'source', 'transport', 'rgb(255,193,7)', true, false, null),
                'assignmentSummary' => 'Medical Emergency – 1230 Ocean Ave',
                'currentCalls' => [self::call('src-5', null, 'Medical Emergency', '1230 Ocean Ave', 'Transporting', true, null)],
                'incidentLabel' => 'ID pending',
                'profile' => self::profile(4, 9, ['runs' => 6, 'date' => '2026-11-02', 'label' => 'Nov 2, 2026'], [self::run('4:58 PM', 'Medical Emergency', 'ID pending', 'Transporting', true)]),
            ],
            // On a call, feed went stale.
            'R22' => [
                'availability' => self::availability('on_call', 'On Scene', 'source', 'on_scene', 'rgb(204,0,0)', true, true, 'PulsePoint feed delayed', '2026-11-02T21:58:00Z'),
                'assignmentSummary' => 'Lift Assist – 9 Bay Rd',
                'incidentLabel' => '#2601241720',
                'currentCalls' => [self::call('src-6', '2601241720', 'Lift Assist', '9 Bay Rd', 'On Scene', true, 'https://web.pulsepoint.org/?incident=6')],
            ],
            'CPT5' => [
                'availability' => self::availability('no_active_call', 'No active call', 'neutral', 'neutral', null, null, false, 'Crew not confirmed'),
                'assignmentSummary' => 'No active call',
                'crew' => self::crew('not_posted', 'Roster not posted — personnel not confirmed', []),
            ],
            '300' => [
                'availability' => self::availability('not_staffed', 'Not staffed', 'neutral', 'neutral', null, true, false, null),
                'assignmentSummary' => 'No active call',
                'crew' => self::crew('none_working', 'No one working now', [], 1),
            ],
            // At the hospital.
            'L3' => [
                'availability' => self::availability('on_call', 'At Hospital', 'source', 'arrived', 'rgb(25,118,210)', true, false, null),
                'assignmentSummary' => 'Medical Emergency – 400 Alton Rd',
                'incidentLabel' => '#2601241730',
                'currentCalls' => [self::call('src-7', '2601241730', 'Medical Emergency', '400 Alton Rd', 'At Hospital', true, 'https://web.pulsepoint.org/?incident=7')],
            ],
            // A confirmed unit PulsePoint has not given a phase yet.
            'R3' => [
                'availability' => self::availability('on_call', 'Assigned', 'source', 'unknown', null, true, false, null),
                'assignmentSummary' => 'Fire Alarm – 7 Lenox Ave',
                'incidentLabel' => '#2601241735',
                'currentCalls' => [self::call('src-8', '2601241735', 'Fire Alarm', '7 Lenox Ave', 'Assigned', true, 'https://web.pulsepoint.org/?incident=8')],
            ],
            default => [],
        };
    }

    /** @return array<string, mixed> */
    private static function availability(string $key, string $label, string $tone, string $family, ?string $indicator, ?bool $confirmed, bool $stale, ?string $note, ?string $observedAt = null): array
    {
        return [
            'key' => $key, 'label' => $label, 'family' => $family, 'tone' => $tone,
            'indicatorColor' => $indicator, 'confirmed' => $confirmed, 'stale' => $stale, 'note' => $note, 'observedAt' => $observedAt,
        ];
    }

    /** @return array<string, mixed> */
    private static function call(string $sourceCallId, ?string $externalId, string $type, string $address, string $status, bool $confirmed, ?string $url): array
    {
        return [
            'sourceCallId' => $sourceCallId,
            'externalId' => $externalId,
            'idLabel' => $externalId === null ? 'ID pending' : '#'.$externalId,
            'callType' => $type,
            'address' => $address,
            'receivedAt' => '2026-11-02T21:58:00Z',
            'receivedLabel' => '4:58 PM ET',
            'unitStatus' => $status,
            'unitFamily' => 'on_scene',
            'indicatorColor' => null,
            'unitConfirmed' => $confirmed,
            'sourceStatus' => 'active',
            'lastSeenAt' => '2026-11-02T22:00:00Z',
            'lastSeenLabel' => '5:00 PM ET',
            'sourceUrl' => $url,
            'location' => $url === null ? null : ['lat' => 25.79, 'lng' => -80.13, 'quality' => 'approximate'],
            'otherUnits' => [['designation' => 'E9', 'status' => 'Dispatched']],
        ];
    }

    /** @return array<string, mixed> */
    private static function profile(int $shiftRuns, int $lifetimeRuns, ?array $busiestDay, array $todayCalls): array
    {
        return ['shiftRuns' => $shiftRuns, 'lifetimeRuns' => $lifetimeRuns, 'busiestDay' => $busiestDay, 'todayCalls' => $todayCalls];
    }

    /** @return array<string, mixed> */
    private static function run(string $time, string $type, string $idLabel, string $status, bool $current): array
    {
        return ['attributedAt' => '2026-11-02T21:58:00Z', 'timeLabel' => $time, 'callType' => $type, 'idLabel' => $idLabel,
            'externalId' => str_starts_with($idLabel, '#') ? substr($idLabel, 1) : null, 'unitStatus' => $status, 'current' => $current, 'sourceUrl' => null];
    }

    /**
     * @param  list<array{rank: ?string, name: string}>  $onTruck
     * @return array<string, mixed>
     */
    private static function crew(string $state, string $label, array $onTruck, int $vacancies = 0): array
    {
        $short = static fn (array $p): array => ['rank' => $p['rank'] ?? null, 'short' => trim(($p['rank'] ?? '').' '.explode(',', $p['name'])[0])];

        return ['state' => $state, 'label' => $label, 'onTruck' => array_map($short, $onTruck),
            'later' => $state === 'confirmed' ? [['rank' => 'FF', 'short' => 'FF Ruiz', 'from' => '19:00', 'through' => '08:00']] : [],
            'vacancies' => $vacancies, 'asOf' => '2026-11-02T12:30:00Z', 'note' => null];
    }
}
