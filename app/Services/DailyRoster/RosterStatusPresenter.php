<?php

declare(strict_types=1);

namespace App\Services\DailyRoster;

use App\Models\DailyRoster\RosterAssignment;

/**
 * Turns a roster row into its status pill. The printed code stays verbatim, except A-Day, which
 * MBFD says instead of TeleStaff's ADONIS DAY; the raw code stays in the title and screen reader text.
 */
final class RosterStatusPresenter
{
    public const TONE_ORDER = ['sp', 'ot', 'aday', 'vaca', 'leave', 'sick', 'exchange', 'ooc', 'pms', 'other', 'unknown', 'vacant'];

    /**
     * Read from the TeleStaff online roster on 2026-10-30. TeleStaff's red (#FF0000) and pink (#FF007F) are
     * darkened about 10 percent, because its white text on them falls short of WCAG AA. These also drive
     * the PDF; the page reads the same values from the --hub-roster-* tokens in mbfd-theme.css.
     */
    public const TONE_COLORS = [
        'sp' => ['bg' => '#FFFFFF', 'ink' => '#000000'],
        'ot' => ['bg' => '#00FF00', 'ink' => '#000000'],
        'aday' => ['bg' => '#008EE5', 'ink' => '#000000'],
        'vaca' => ['bg' => '#E6006F', 'ink' => '#FFFFFF'],
        'leave' => ['bg' => '#E60000', 'ink' => '#FFFFFF'],
        'sick' => ['bg' => '#AA0000', 'ink' => '#FFFFFF'],
        'exchange' => ['bg' => '#FFAA56', 'ink' => '#000000'],
        'ooc' => ['bg' => '#00BFBF', 'ink' => '#000000'],
        'pms' => ['bg' => '#FF00FF', 'ink' => '#000000'],
        'other' => ['bg' => '#F1F5F9', 'ink' => '#475569'],
    ];

    /** A dotted or marked code keeps its color: the parser has already split the prefix off. */
    private const TONES_BY_BASE_CODE = [
        'SP' => 'sp',
        '+OT' => 'ot',
        'OT' => 'ot',
        'HOOT' => 'ot',
        'ADONIS DAY' => 'aday',
        'VACA' => 'vaca',
        'VAC' => 'vaca',
        'EVAC' => 'leave',
        'ADMS' => 'leave',
        'MILNP' => 'leave',
        'PENFML' => 'leave',
        'SIC' => 'sick',
        'SIC RVT' => 'sick',
        '2WXON' => 'exchange',
        '2WXEON' => 'exchange',
        '2WXOFF' => 'exchange',
        '2WXEOFF' => 'exchange',
        '^OOC' => 'ooc',
        'PMS' => 'pms',
    ];

    private const TONES_BY_CATEGORY = [
        'Scheduled' => 'sp',
        'Overtime' => 'ot',
        'A-Day' => 'aday',
        'Vacation' => 'vaca',
        'Leave' => 'leave',
        'Sick' => 'sick',
        'Exchange' => 'exchange',
        'Exchange off' => 'exchange',
    ];

    private const MEANINGS_BY_TONE = [
        'sp' => 'Scheduled',
        'ot' => 'Overtime',
        'vaca' => 'Vacation',
        'leave' => 'Leave',
        'sick' => 'Sick',
        'exchange' => 'Exchange',
        'ooc' => 'Incentive',
    ];

    private const AVAILABILITY_TEXT = [
        RosterAssignment::AVAILABILITY_WORKING => 'working',
        RosterAssignment::AVAILABILITY_NOT_WORKING => 'not working',
    ];

    public function present(RosterAssignment $assignment): RosterStatusPill
    {
        if ($assignment->kind !== RosterAssignment::KIND_EMPLOYEE) {
            return $this->vacancy($assignment);
        }

        $rawCode = trim((string) $assignment->raw_code);
        if ($assignment->availability === RosterAssignment::AVAILABILITY_UNKNOWN || $rawCode === '') {
            $label = $rawCode === '' ? 'No code' : $rawCode;

            // TeleStaff may color a code the Hub cannot classify. Color never decides who is working.
            return new RosterStatusPill($label, $label, self::TONES_BY_BASE_CODE[(string) $assignment->base_code] ?? 'unknown', 'Not recognized', $label, 'Status not recognized');
        }

        $tone = self::TONES_BY_BASE_CODE[(string) $assignment->base_code]
            ?? self::TONES_BY_CATEGORY[(string) $assignment->category]
            ?? 'other';
        $label = $tone === 'aday' ? ((string) $assignment->display_label ?: 'A-Day') : $rawCode;
        $meaning = $tone === 'aday'
            ? 'ADONIS DAY in TeleStaff'
            : (string) ($assignment->category ?: (self::MEANINGS_BY_TONE[$tone] ?? 'Other'));

        $spoken = [self::AVAILABILITY_TEXT[$assignment->availability] ?? null];
        if ($label !== $rawCode) {
            $spoken[] = 'source code '.$rawCode;
        }

        return new RosterStatusPill($label, $label, $tone, $meaning, $rawCode, implode(', ', array_filter($spoken)));
    }

    private function vacancy(RosterAssignment $assignment): RosterStatusPill
    {
        $filterLabel = self::vacancyLabel($assignment);
        $marker = trim((string) $assignment->raw_name);

        return new RosterStatusPill(
            'Vacant',
            $filterLabel,
            'vacant',
            'No member assigned',
            $marker,
            $marker === '' ? $filterLabel : $filterLabel.', source marker '.$marker,
        );
    }

    public static function vacancyLabel(RosterAssignment $assignment): string
    {
        return $assignment->kind === RosterAssignment::KIND_VACANCY_SUPPRESSED ? 'Suppressed vacancy' : 'Open seat';
    }
}
