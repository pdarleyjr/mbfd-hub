<?php

declare(strict_types=1);

namespace App\Services\AiAutomation\Admin;

use App\Models\AiAutomation\AiAutomationPlaybook;

/**
 * Which section of Response Messages each stored message belongs to. The engine's keys never reach the
 * screen: this map turns them into the sections an administrator thinks in. A key that matches nothing lands
 * in "Other messages", so a message added later is never hidden.
 */
final class ResponseMessageGroups
{
    public const OTHER = 'other';

    public const WORD_LISTS = 'word_lists';

    /**
     * Section key => label and the words that, when found in a message key, put it there. Order matters: the first
     * section that matches wins.
     *
     * @var array<string, array{label: string, contains: list<string>}>
     */
    private const SECTIONS = [
        'air_tech' => ['label' => 'Air Tech coordination', 'contains' => ['air_tech', 'airtech', 'scba', 'cylinder']],
        'captain5' => ['label' => 'Captain 5 referrals', 'contains' => ['captain5', 'captain_5', 'captain']],
        'batteries' => ['label' => 'Batteries', 'contains' => ['battery', 'batteries']],
        'weekend' => ['label' => 'Weekend instructions', 'contains' => ['weekend']],
        'safety' => ['label' => 'Safety escalation', 'contains' => ['readiness', 'safety', 'escalat', 'critical', 'hazard', 'ppe']],
        'station2' => ['label' => 'Station 2 guidance', 'contains' => ['station2', 'station_2', 'bunker']],
        'missing' => ['label' => 'Missing items', 'contains' => ['missing', 'damaged', 'medium_']],
        'standard' => ['label' => 'Standard acknowledgment', 'contains' => ['receipt', 'ack', 'plain']],
    ];

    /** @return array<string, string> section key => label, in display order, for filters and headings */
    public static function labels(): array
    {
        $labels = [];
        foreach (self::SECTIONS as $key => $section) {
            $labels[$key] = $section['label'];
        }
        $labels[self::OTHER] = 'Other messages';

        return $labels;
    }

    public static function label(string $section): string
    {
        return $section === self::WORD_LISTS ? 'Advanced: word lists' : (self::labels()[$section] ?? 'Other messages');
    }

    /** Section key for a stored message. */
    public static function sectionOf(string $key, string $kind = AiAutomationPlaybook::KIND_TEMPLATE): string
    {
        if ($kind === AiAutomationPlaybook::KIND_LIST) {
            return self::WORD_LISTS;
        }
        $lower = strtolower($key);
        foreach (self::SECTIONS as $section => $definition) {
            foreach ($definition['contains'] as $needle) {
                if (str_contains($lower, $needle)) {
                    return $section;
                }
            }
        }

        return self::OTHER;
    }

    public static function sectionLabelOf(AiAutomationPlaybook $playbook): string
    {
        return self::label(self::sectionOf((string) $playbook->key, (string) $playbook->kind));
    }

    /** @return int display position of a section */
    public static function position(string $section): int
    {
        $index = array_search($section, array_keys(self::labels()), true);

        return $section === self::WORD_LISTS ? 99 : ($index === false ? 98 : (int) $index);
    }

    /**
     * Keys of stored messages that fall in a section.
     *
     * @param  iterable<array{key: string, kind: string}>  $rows
     * @return list<string>
     */
    public static function keysIn(string $section, iterable $rows): array
    {
        $keys = [];
        foreach ($rows as $row) {
            if (self::sectionOf((string) $row['key'], (string) $row['kind']) === $section) {
                $keys[] = (string) $row['key'];
            }
        }

        return $keys;
    }

    /** Plain version of a stored help line: the placeholder token becomes words. */
    public static function plainHelp(?string $help): ?string
    {
        if ($help === null || trim($help) === '') {
            return null;
        }

        return str_replace('{item}', 'the item name', $help);
    }

    /** Sample words for previews. */
    public static function preview(string $text): string
    {
        return str_replace('{item}', 'spanner wrench', $text);
    }
}
