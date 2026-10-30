<?php

declare(strict_types=1);

namespace App\Services\Roster;

use Closure;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Finds real Off Duty Detail and Special Event assignments in one TeleStaff
 * roster worksheet and returns them in the Hub-to-Command `events` shape
 * (docs/TELESTAFF_ROSTER_CONTRACT.md section 1).
 *
 * Pure: rows in, events out. No I/O, database, HTTP or roster-pipeline
 * dependency. Detection follows the section hierarchy and cell content, never
 * row numbers, columns, fonts or one work code:
 *
 *   department block heading  "Miami Beach Fire Department / Off Duty Detail / ..."
 *   section heading           "Off Duty Details / 29 - Title {unit; location; detail}"
 *   staffing lines            position, numeric ID, name, code, from, through, hours
 *   a blank row ends a section
 *
 * Sections whose title repeats with different `{...}` blocks are one event
 * with several groups. Anything uncertain is returned with quality "partial".
 */
final class RosterSpecialEventExtractor
{
    private const CATEGORIES = [
        'off_duty_detail' => '/^off[\s-]*duty\s+details?$/i',
        'special_event' => '/^special\s+events?$/i',
    ];

    /** Titles that never name an event, staffed or not. */
    private const GENERIC_TITLES = [
        'placeholder for deployment only', 'deploy here', 'off duty detail', 'off duty details',
        'special event', 'special events',
    ];

    /** @var Closure(string): ?string */
    private readonly Closure $rankResolver;

    /** @param  (Closure(string): ?string)|null  $rankResolver  Exact employee ID to rank; null when unknown. */
    public function __construct(?Closure $rankResolver = null)
    {
        $this->rankResolver = $rankResolver ?? static fn (string $employeeId): ?string => null;
    }

    /**
     * @param  list<array<int|string, mixed>>  $rows  One worksheet, top to bottom.
     * @param  string  $rosterDate  Y-m-d; every time in the sheet belongs to this date.
     * @return list<array<string, mixed>>
     */
    public function extract(array $rows, string $rosterDate): array
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $rosterDate, $d) !== 1 || ! checkdate((int) $d[2], (int) $d[3], (int) $d[1])) {
            throw new InvalidArgumentException('rosterDate must be a valid Y-m-d date.');
        }

        $events = [];
        foreach ($this->sections($rows) as $section) {
            $this->addSection($events, $section, $rosterDate);
        }

        return array_values(array_filter(
            array_map(fn (array $event): ?array => $this->finish($event), $events),
        ));
    }

    /**
     * @param  list<array<int|string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function sections(array $rows): array
    {
        $read = array_map(static fn (array $row): array => RosterRowReader::read($row), $rows);
        $sections = [];
        /** @var array<string, mixed>|null $current */
        $current = null;
        $blockKnown = false;
        $blockType = null;

        $close = static function (bool $terminated) use (&$current, &$sections): void {
            if ($current !== null) {
                $sections[] = $current + ['terminated' => $terminated];
                $current = null;
            }
        };

        foreach ($read as $i => $row) {
            switch ($row['kind']) {
                case 'blank':
                case 'header':
                    $close(true);
                    break;
                case 'slot':
                    if ($current !== null) {
                        $current['slots'][] = $row;
                    }
                    break;
                case 'heading':
                    $close(true);
                    $text = (string) $row['text'];
                    $segments = preg_split('~\s+/\s+~', preg_replace('/\{[^{}]*\}/', '', $text) ?? $text) ?: [$text];
                    $followedByHeader = ($read[$i + 1]['kind'] ?? null) === 'header';
                    $standalone = count($segments) === 1 && ! str_contains($text, '{') && self::categoryType($segments[0]) !== null;

                    if ($followedByHeader || $standalone || count($segments) >= 3 || preg_match('/fire department/i', $segments[0]) === 1) {
                        $blockKnown = true;
                        $blockType = self::blockType(count($segments) > 1 ? array_slice($segments, 1) : $segments);
                        break;
                    }

                    $current = $this->openSection($text, $blockKnown, $blockType, in_array($read[$i - 1]['kind'] ?? null, ['header', 'heading'], true));
                    break;
                default:
                    if ($current !== null) {
                        $current['partial'] = true;
                    }
            }
        }
        $close(false);

        return $sections;
    }

    /** @return array<string, mixed> */
    private function openSection(string $text, bool $blockKnown, ?string $blockType, bool $structured): array
    {
        $slash = self::firstSlashOutsideBraces($text);
        $category = $slash === null ? null : trim(substr($text, 0, $slash));
        $rest = $slash === null ? $text : trim(substr($text, $slash + 1));
        preg_match_all('/\{([^{}]*)\}/', $rest, $braces);
        $title = trim(preg_replace('/\s+/', ' ', str_replace(['{', '}'], '', preg_replace('/\{[^{}]*\}/', '', $rest) ?? $rest)) ?? '');
        $title = trim(preg_replace('/^\d+\s*[-–.:]\s*/', '', $title) ?? $title);

        return [
            // Inside a known department block only the block decides eligibility.
            'type' => $blockKnown ? $blockType : ($category !== null ? self::categoryType($category) : null),
            'title' => $title,
            'group' => self::group($braces[1][0] ?? ''),
            'slots' => [],
            'partial' => ! $structured || substr_count($text, '{') !== substr_count($text, '}'),
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $events
     * @param  array<string, mixed>  $section
     */
    private function addSection(array &$events, array $section, string $date): void
    {
        $title = (string) $section['title'];
        $slug = Str::slug($title);
        if ($section['type'] === null || $slug === '' || preg_match('/\p{L}{3}/u', $title) !== 1
            || in_array(mb_strtolower($title), self::GENERIC_TITLES, true)) {
            return;
        }

        $partial = $section['partial'] || ! $section['terminated'];
        $members = [];
        foreach ($section['slots'] as $slot) {
            if ($slot['nameKind'] === 'none') {
                continue;
            }
            if ($slot['nameKind'] === 'placeholder') {
                continue;
            }
            if ($slot['id'] === null) {
                $partial = true; // a named person without a numeric ID is not a real identity

                continue;
            }
            $member = $this->member($slot, $date);
            if ($member === null) {
                continue;
            }
            $partial = $partial || $member['startAt'] === null;
            $members[] = $member;
        }
        if ($members === []) {
            return;
        }

        $key = "telestaff:{$date}:{$slug}";
        $events[$key] ??= [
            'sourceEventKey' => $key,
            'classification' => $section['type'],
            'title' => $title,
            'eventDate' => $date,
            'location' => '',
            'quality' => 'complete',
            'partial' => false,
            'groups' => [],
        ];
        $events[$key]['partial'] = $events[$key]['partial'] || $partial;

        $group = $section['group'];
        $groupKey = $group['unitDesignation'] === '' ? 'MAIN' : $group['unitDesignation'];
        $groupId = mb_strtolower($groupKey);
        $existing = $events[$key]['groups'][$groupId] ?? $group + ['groupKey' => $groupKey, 'label' => $group['unitDesignation'] ?: 'Crew', 'members' => []];
        foreach (['locationText', 'detailText'] as $field) {
            $existing[$field] = $existing[$field] !== '' ? $existing[$field] : $group[$field];
        }
        foreach ($members as $member) {
            $identity = $member['employeeId'].'|'.$member['startAt'].'|'.$member['endAt'];
            $existing['members'][$identity] = $existing['members'][$identity] ?? $member;
        }
        $events[$key]['groups'][$groupId] = $existing;
    }

    /**
     * @param  array<string, mixed>  $slot
     * @return array<string, mixed>|null
     */
    private function member(array $slot, string $date): ?array
    {
        if ($slot['from'] === '00:00' && $slot['through'] === '00:00') {
            return null; // TeleStaff's untouched default shift
        }
        $window = RosterEventClock::window($date, $slot['from'], $slot['through'], $slot['hours']);
        $rank = trim((string) ($this->rankResolver)((string) $slot['id']));

        return [
            'employeeId' => (string) $slot['id'],
            'name' => (string) $slot['name'],
            'rank' => $rank === '' ? null : $rank,
            'rankVerified' => $rank !== '',
            'sourcePosition' => (string) $slot['position'],
            'workCode' => (string) $slot['code'],
            'startAt' => $window['startAt'] ?? null,
            'endAt' => $window['endAt'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>|null
     */
    private function finish(array $event): ?array
    {
        $groups = [];
        foreach ($event['groups'] as $group) {
            $group['members'] = array_values($group['members']);
            $groups[] = $group;
        }
        if ($groups === []) {
            return null;
        }

        $locations = array_values(array_unique(array_filter(array_column($groups, 'locationText'))));
        $event['location'] = count($locations) === 1 ? $locations[0] : '';
        $event['quality'] = $event['partial'] ? 'partial' : 'complete';
        $event['groups'] = array_map(static fn (array $group): array => [
            'groupKey' => $group['groupKey'],
            'unitDesignation' => $group['unitDesignation'],
            'label' => $group['label'],
            'locationText' => $group['locationText'],
            'detailText' => $group['detailText'],
            'members' => $group['members'],
        ], $groups);
        unset($event['partial']);

        return $event;
    }

    /**
     * `{G1; Lincoln Rd & Euclid Ave; ALS non-transport}`: unit before the first
     * semicolon, then location, then detail. Without semicolons the unit ends at
     * the first comma (location follows) or spaced dash (detail follows).
     *
     * @return array{unitDesignation: string, locationText: string, detailText: string}
     */
    private static function group(string $inner): array
    {
        $parts = array_map('trim', explode(';', $inner));
        if (count($parts) > 1) {
            return [
                'unitDesignation' => $parts[0],
                'locationText' => $parts[1],
                'detailText' => implode('; ', array_slice($parts, 2)),
            ];
        }
        if (preg_match('/^(.*?)(,|\s-\s)(.*)$/s', $parts[0], $m) === 1) {
            return [
                'unitDesignation' => trim($m[1]),
                'locationText' => $m[2] === ',' ? trim($m[3]) : '',
                'detailText' => $m[2] === ',' ? '' : trim($m[3]),
            ];
        }

        return ['unitDesignation' => $parts[0], 'locationText' => '', 'detailText' => ''];
    }

    private static function categoryType(string $text): ?string
    {
        foreach (self::CATEGORIES as $type => $pattern) {
            if (preg_match($pattern, trim($text)) === 1) {
                return $type;
            }
        }

        return null;
    }

    /** @param  list<string>  $segments */
    private static function blockType(array $segments): ?string
    {
        foreach ($segments as $segment) {
            $type = self::categoryType($segment);
            if ($type !== null) {
                return $type;
            }
        }

        return null;
    }

    private static function firstSlashOutsideBraces(string $text): ?int
    {
        $depth = 0;
        for ($i = 0, $n = strlen($text); $i < $n; $i++) {
            $char = $text[$i];
            $depth += $char === '{' ? 1 : ($char === '}' ? -1 : 0);
            if ($char === '/' && $depth <= 0 && $i > 0 && $text[$i - 1] === ' ' && ($text[$i + 1] ?? '') === ' ') {
                return $i;
            }
        }

        return null;
    }
}
