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
    public const TONE_ORDER = ['sp', 'ot', 'aday', 'vaca', 'evac', 'milnp', '2wxon', '2wxeon', 'other', 'unknown', 'vacant'];

    private const TONES_BY_BASE_CODE = [
        'SP' => 'sp',
        '+OT' => 'ot',
        'OT' => 'ot',
        'ADONIS DAY' => 'aday',
        'VACA' => 'vaca',
        'VAC' => 'vaca',
        'EVAC' => 'evac',
        'MILNP' => 'milnp',
        '2WXON' => '2wxon',
        '2WXEON' => '2wxeon',
    ];

    private const TONES_BY_CATEGORY = [
        'Scheduled' => 'sp',
        'Overtime' => 'ot',
        'A-Day' => 'aday',
        'Vacation' => 'vaca',
    ];

    private const MEANINGS_BY_TONE = [
        'sp' => 'Scheduled',
        'ot' => 'Overtime',
        'vaca' => 'Vacation',
        'evac' => 'Leave',
        'milnp' => 'Leave',
        '2wxon' => 'Exchange',
        '2wxeon' => 'Exchange',
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

            return new RosterStatusPill($label, $label, 'unknown', 'Not recognized', $label, 'Status not recognized');
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
