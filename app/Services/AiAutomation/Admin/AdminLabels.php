<?php

declare(strict_types=1);

namespace App\Services\AiAutomation\Admin;

/**
 * Plain-English words for the Support Services Automation screens. Administrators never see a code, a slug
 * or a database value: every key the engine stores is turned into a sentence here, in one place.
 */
final class AdminLabels
{
    public const NAVIGATION_GROUP = 'Support Services Automation';

    /** @var array<string, string> */
    private const LEVELS = ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High'];

    /** @var array<string, string> */
    private const LEVEL_MEANINGS = [
        'low' => 'Routine. Support Services replaces it in the normal course of work.',
        'medium' => 'Needs attention soon. It is tracked and followed up.',
        'high' => 'Affects safety or readiness. Staff are alerted right away.',
    ];

    /** @var array<string, string> */
    private const LEVEL_COLORS = ['low' => 'success', 'medium' => 'warning', 'high' => 'danger'];

    /** @var array<string, string> */
    private const CATEGORIES = [
        'consumable' => 'Everyday supplies',
        'hand_tool' => 'Hand tools',
        'battery' => 'Batteries',
        'power_tool' => 'Power tools',
        'specialized_tool' => 'Specialized tools',
        'detection' => 'Detection and monitoring equipment',
        'scba_unit' => 'SCBA (complete air pack)',
        'scba_facepiece' => 'SCBA mask',
        'scba_component' => 'SCBA part',
        'scba_cylinder' => 'SCBA air bottle',
        'ppe' => 'Firefighting protective gear',
        'rescue_medical' => 'Rescue and medical equipment',
        'vehicle_component' => 'Vehicle parts',
        'station_facility' => 'Station and building',
        'electronics' => 'Radios and electronics',
        'other' => 'Something else',
    ];

    /** @var array<string, string> */
    private const HANDLERS = [
        'support_services' => 'Support Services',
        'air_tech' => 'Air Tech',
        'captain5' => 'Captain 5',
        'fleet' => 'Fleet',
        'station' => 'The station',
        'officer_300' => 'Officer and 300',
    ];

    /** @var array<string, string> */
    private const CONTACT_300 = [
        'never' => 'Never',
        'urgent' => 'Only if urgent',
        'always' => 'Always',
    ];

    /** @var array<string, string> */
    private const TRUCKS = [
        'engine' => 'Engines',
        'engine2' => 'Engine 2',
        'ladder1' => 'Ladder 1',
        'ladder3' => 'Ladder 3',
        'rescue' => 'Rescues',
        'fireboat6' => 'Fireboat 6',
        'default' => 'Other vehicles',
    ];

    /** @var array<string, string> */
    private const AREAS = ['vehicle' => 'Vehicle checkout', 'station' => 'Station request', 'support' => 'Support Services message'];

    /** @var array<string, string> */
    private const DECISIONS = [
        'receipt_only' => 'Acknowledged only',
        'receipt_plus_template_guidance' => 'Acknowledged with a standard message',
        'approved_rag_guidance' => 'Answered from the Knowledge & Instructions',
        'receipt_and_human_flag' => 'Acknowledged and flagged for staff',
    ];

    /** @return array<string, string> */
    public static function levels(): array
    {
        return self::LEVELS;
    }

    /** @return array<string, string> level => "Low - Routine..." for radio options */
    public static function levelChoices(): array
    {
        $out = [];
        foreach (self::LEVELS as $key => $label) {
            $out[$key] = $label;
        }

        return $out;
    }

    /** @return array<string, string> */
    public static function levelDescriptions(): array
    {
        return self::LEVEL_MEANINGS;
    }

    public static function level(?string $key): string
    {
        return self::LEVELS[strtolower((string) $key)] ?? 'Not set';
    }

    public static function levelColor(?string $key): string
    {
        return self::LEVEL_COLORS[strtolower((string) $key)] ?? 'gray';
    }

    public static function levelMeaning(?string $key): string
    {
        return self::LEVEL_MEANINGS[strtolower((string) $key)] ?? '';
    }

    /** @return array<string, string> */
    public static function categories(): array
    {
        return self::CATEGORIES;
    }

    public static function category(?string $key): string
    {
        return self::CATEGORIES[(string) $key] ?? self::headline($key);
    }

    /** @return array<string, string> */
    public static function handlers(): array
    {
        return self::HANDLERS;
    }

    public static function handler(?string $key): string
    {
        return self::HANDLERS[(string) $key] ?? self::headline($key);
    }

    /** @return array<string, string> */
    public static function contact300Options(): array
    {
        return self::CONTACT_300;
    }

    public static function contact300(?string $key): string
    {
        return self::CONTACT_300[(string) $key] ?? 'Only if urgent';
    }

    /** @return array<string, string> */
    public static function trucks(): array
    {
        return self::TRUCKS;
    }

    public static function truck(?string $key): string
    {
        return $key === null || $key === '' ? 'All vehicles' : (self::TRUCKS[$key] ?? self::headline($key));
    }

    /** @return array<string, string> */
    public static function areas(): array
    {
        return self::AREAS;
    }

    public static function area(?string $key): string
    {
        return self::AREAS[(string) $key] ?? self::headline($key);
    }

    public static function decision(?string $key): string
    {
        return self::DECISIONS[(string) $key] ?? ($key === null || $key === '' ? 'Nothing sent' : self::headline($key));
    }

    /** One sentence saying who hears about an item with these settings. */
    public static function alertSentence(string $level, string $handler, string $contact300): string
    {
        $who = [self::handler($handler).' gets the follow-up'];
        if ($level === 'high') {
            $who[] = 'staff are alerted right away';
        } elseif ($level === 'medium') {
            $who[] = 'it appears on the staff work list';
        }
        $who[] = match ($contact300) {
            'always' => '300 is always contacted',
            'never' => '300 is not contacted',
            default => '300 is contacted only if it is urgent',
        };

        return ucfirst(implode('; ', $who)).'.';
    }

    /** Words for a stored code that has no entry above: "air_tech" becomes "Air tech". */
    public static function headline(?string $code): string
    {
        $text = trim((string) preg_replace('/[_\-]+/', ' ', (string) $code));

        return $text === '' ? 'Not set' : ucfirst(mb_strtolower($text));
    }

    /** Reason codes stored with an automated response, in words. */
    public static function reasons(?array $codes, ?string $error = null): string
    {
        $words = [];
        foreach ($codes ?? [] as $code) {
            $words[] = self::headline((string) $code);
        }
        if ($error !== null && $error !== '') {
            $words[] = 'Problem: '.mb_strtolower(self::headline($error));
        }

        return implode(', ', array_unique($words));
    }
}
