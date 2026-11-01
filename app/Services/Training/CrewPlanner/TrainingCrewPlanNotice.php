<?php

declare(strict_types=1);

namespace App\Services\Training\CrewPlanner;

use Carbon\CarbonImmutable;

/**
 * The message people read: class, date, source, sessions and names. It always says this is a proposed roster,
 * and it never carries employee IDs, duty codes, leave reasons or why someone needs confirmation.
 */
final class TrainingCrewPlanNotice
{
    public const QUALIFIER = 'Proposed class roster. This is not verified attendance.';

    private const TZ = \App\Support\HubTimezone::NAME;

    private const MAX_BODY = 9500;

    /** @return array{subject: string, body: string} */
    public function compose(array $report, bool $updated = false): array
    {
        $date = CarbonImmutable::parse($report['schedule_date'], self::TZ);
        $class = trim($report['course_name'].($report['module_name'] !== '' ? ' · '.$report['module_name'] : ''));
        $subject = mb_substr(($updated ? 'Updated training roster: ' : 'Training roster: ').$class.' · '.$date->format('M j'), 0, 150);

        $lines = [self::QUALIFIER, '', 'Class: '.$class, 'Date: '.$date->format('l, F j, Y'), 'Roster: '.$this->source($report), 'Members listed: '.$report['total']];
        foreach ($this->sections($report) as $section) {
            $lines[] = '';
            $lines[] = $section['heading'].' ('.count($section['names']).')';
            foreach ($section['names'] as $name) {
                $lines[] = '- '.$name;
            }
        }
        $body = implode("\n", $lines);

        return ['subject' => $subject, 'body' => mb_strlen($body) <= self::MAX_BODY ? $body : $this->counts($lines, $report)];
    }

    /** @return list<array{heading: string, names: list<string>}> */
    private function sections(array $report): array
    {
        $sections = [];
        foreach ($report['groups'] as $group) {
            if ($group['rows'] === []) {
                continue;
            }
            $heading = match ($group['kind']) {
                'session' => implode(' · ', array_filter([$group['label'], $group['time'], $group['location'] ?? ''])),
                'list' => 'Class list',
                'review' => 'To be confirmed',
                default => 'Session to be assigned',
            };
            $sections[] = ['heading' => $heading, 'names' => array_map(static fn (array $row): string => trim($row['rank'].' '.$row['name']), $group['rows'])];
        }

        return $sections;
    }

    private function source(array $report): string
    {
        if (! ($report['roster_verified'] ?? false)) {
            return 'Provisional. No TeleStaff roster is available for this date yet, so nobody is confirmed on duty.';
        }
        $asOf = ($report['roster_as_of'] ?? '') !== '' ? ', as of '.CarbonImmutable::parse($report['roster_as_of'])->setTimezone(self::TZ)->format('M j g:i A') : '';

        return ($report['shift_label'] !== '' ? $report['shift_label'].', ' : '').'TeleStaff'.$asOf;
    }

    /** A very long roster keeps its session headings and counts rather than being cut off mid-list. */
    private function counts(array $lines, array $report): string
    {
        $head = array_slice($lines, 0, 6);
        foreach ($this->sections($report) as $section) {
            $head[] = '';
            $head[] = $section['heading'].': '.count($section['names']).' members';
        }
        $head[] = '';
        $head[] = 'The full list is too long for this message. Training has the complete roster.';

        return implode("\n", $head);
    }
}
