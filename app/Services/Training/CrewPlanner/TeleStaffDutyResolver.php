<?php

declare(strict_types=1);

namespace App\Services\Training\CrewPlanner;

use DateTimeImmutable;
use InvalidArgumentException;

/** Pure schedule projection: never reads or writes an authoritative personnel record. */
final class TeleStaffDutyResolver
{
    public function resolve(array $snapshot, array $slots): array
    {
        $staff = [];
        foreach ($snapshot['parsed_assignments'] ?? [] as $row) {
            $id = (string) ($row['raw_employee_id'] ?? '');
            if ($id === '' || in_array($id, ['-,-', '?,?', '#1'], true)) {
                continue;
            }
            $staff[$id] ??= [
                'raw_employee_id' => $id,
                'raw_name' => (string) ($row['raw_name'] ?? ''),
                'working_intervals' => [],
                'needs_review' => [],
                'global_review_reasons' => [],
                'review_intervals' => [],
                'observed_unit_context' => [],
                '_events' => [],
            ];
            $person = $staff[$id];
            $interval = $this->interval($row);
            if ($interval === null) {
                $person['global_review_reasons'][] = 'Duty time needs verification';
                $staff[$id] = $person;

                continue;
            }
            $class = $row['code_class'] ?? 'UNKNOWN';
            $effective = ($row['approval_state'] ?? 'UNVERIFIED') === 'VERIFIED_EFFECTIVE';
            foreach ($row['review_reasons'] ?? [] as $reason) {
                $person['review_intervals'][] = [...$interval, 'reason' => $reason];
            }
            if ($class === 'UNKNOWN' || $class === 'ANNOTATION' || ! $effective) {
                $person['review_intervals'][] = [...$interval, 'reason' => 'Duty assignment needs verification'];
            } elseif ($class === 'WORKING') {
                $person['working_intervals'][] = $interval;
                $person['observed_unit_context'][] = [
                    'station' => $row['reported_station'] ?? null,
                    'apparatus' => $row['reported_apparatus'] ?? null,
                    'seat_rank' => $row['seat_rank'] ?? null,
                    ...$interval,
                    'source_page' => $row['source_page'] ?? null,
                ];
            }
            $person['_events'][] = [...$row, ...$interval];
            $staff[$id] = $person;
        }

        foreach ($staff as &$person) {
            // Explicit verified overtime or exchange-on overrides an A-Day only
            // for its actual interval. A regular-duty base row does not override it.
            // Other simultaneous absences always subtract from positive coverage.
            foreach ($person['_events'] as $row) {
                if (($row['code_class'] ?? '') !== 'NOT_WORKING' || ($row['approval_state'] ?? '') !== 'VERIFIED_EFFECTIVE') {
                    continue;
                }
                $absences = [$this->interval($row)];
                if (($row['normalized_code'] ?? '') === 'ADONIS DAY') {
                    foreach ($person['_events'] as $override) {
                        if (in_array($override['normalized_code'] ?? '', ['+OT', 'OT', '2WXON'], true) && ($override['approval_state'] ?? '') === 'VERIFIED_EFFECTIVE' && ($override['code_class'] ?? '') === 'WORKING') {
                            $absences = $this->subtract($absences, $this->interval($override));
                        }
                    }
                }
                foreach ($absences as $absence) {
                    $person['working_intervals'] = $this->subtract($person['working_intervals'], $absence);
                }
            }
            $contexts = $person['observed_unit_context'];
            foreach ($contexts as $index => $context) {
                foreach (array_slice($contexts, $index + 1) as $other) {
                    if ($this->overlaps($context, $other) && [$context['station'], $context['apparatus']] !== [$other['station'], $other['apparatus']]) {
                        $person['review_intervals'][] = [
                            'start_at' => $this->stamp($context['start_at']) > $this->stamp($other['start_at']) ? $context['start_at'] : $other['start_at'],
                            'end_at' => $this->stamp($context['end_at']) < $this->stamp($other['end_at']) ? $context['end_at'] : $other['end_at'],
                            'reason' => 'Overlapping duty locations need verification',
                        ];
                    }
                }
            }
            if (($snapshot['definitive_eligibility_blocked'] ?? false) === true) {
                $person['global_review_reasons'][] = 'Report completeness needs verification';
            }
            unset($person['_events']);
        }
        unset($person);

        return $this->resolveStaff($staff, $slots);
    }

    /** Re-evaluate an encrypted minimal draft without retaining codes or leave reasons. */
    public function resolveStaff(array $resolvedStaff, array $slots): array
    {
        $validatedSlots = [];
        foreach ($slots as $slot) {
            $id = (string) ($slot['id'] ?? '');
            $interval = $this->interval($slot);
            if ($id === '' || $interval === null || isset($validatedSlots[$id])) {
                throw new InvalidArgumentException('Each planning slot needs a unique identifier and valid start and end times.');
            }
            $validatedSlots[$id] = $interval;
        }
        foreach ($resolvedStaff as &$person) {
            $person['working_intervals'] = $this->merge($person['working_intervals'] ?? []);
            $person['global_review_reasons'] ??= array_key_exists('review_intervals', $person) ? [] : ($person['needs_review'] ?? []);
            $person['review_intervals'] ??= [];
            $person['needs_review'] = array_values(array_unique([...$person['global_review_reasons'], ...array_column($person['review_intervals'], 'reason')]));
            $person['eligible_slots'] = $person['unavailable_slots'] = $person['partial_slots'] = [];
            $person['slot_review_reasons'] = [];
            foreach ($validatedSlots as $id => $slot) {
                $full = false;
                $overlap = false;
                foreach ($person['working_intervals'] as $duty) {
                    $full = $full || ($this->stamp($duty['start_at']) <= $this->stamp($slot['start_at']) && $this->stamp($duty['end_at']) >= $this->stamp($slot['end_at']));
                    $overlap = $overlap || $this->overlaps($duty, $slot);
                }
                $reasons = $person['global_review_reasons'];
                foreach ($person['review_intervals'] as $review) {
                    if ($this->interval($review) === null || $this->overlaps($review, $slot)) {
                        $reasons[] = $review['reason'] ?? 'Duty assignment needs verification';
                    }
                }
                $person['slot_review_reasons'][(string) $id] = array_values(array_unique($reasons));
                if ($reasons !== []) {
                    $person['partial_slots'][] = (string) $id;
                } elseif ($full) {
                    $person['eligible_slots'][] = (string) $id;
                } elseif ($overlap) {
                    $person['partial_slots'][] = (string) $id;
                } else {
                    $person['unavailable_slots'][] = (string) $id;
                }
            }
        }
        unset($person);

        return $resolvedStaff;
    }

    private function interval(array $row): ?array
    {
        try {
            $start = (string) ($row['start_at'] ?? '');
            $end = (string) ($row['end_at'] ?? '');
            if (! preg_match('/T\d{2}:\d{2}.*(?:Z|[+-]\d{2}:\d{2})$/', $start) || ! preg_match('/T\d{2}:\d{2}.*(?:Z|[+-]\d{2}:\d{2})$/', $end) || $this->stamp($end) <= $this->stamp($start)) {
                return null;
            }

            return ['start_at' => $start, 'end_at' => $end];
        } catch (\Exception) {
            return null;
        }
    }

    private function stamp(string $date): int
    {
        $parsed = new DateTimeImmutable($date);
        $errors = DateTimeImmutable::getLastErrors();
        if (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            throw new InvalidArgumentException('The duty time is invalid.');
        }

        return $parsed->getTimestamp();
    }

    private function overlaps(array $first, array $second): bool
    {
        return $this->stamp($first['start_at']) < $this->stamp($second['end_at']) && $this->stamp($second['start_at']) < $this->stamp($first['end_at']);
    }

    private function merge(array $intervals): array
    {
        $intervals = array_values(array_filter(array_map(fn (array $row): ?array => $this->interval($row), $intervals)));
        usort($intervals, fn (array $a, array $b): int => $this->stamp($a['start_at']) <=> $this->stamp($b['start_at']));
        $result = [];
        foreach ($intervals as $interval) {
            $last = count($result) - 1;
            if ($last >= 0 && $this->stamp($interval['start_at']) <= $this->stamp($result[$last]['end_at'])) {
                if ($this->stamp($interval['end_at']) > $this->stamp($result[$last]['end_at'])) {
                    $result[$last]['end_at'] = $interval['end_at'];
                }
            } else {
                $result[] = $interval;
            }
        }

        return $result;
    }

    private function subtract(array $intervals, array $absence): array
    {
        $result = [];
        foreach ($intervals as $interval) {
            if (! $this->overlaps($interval, $absence)) {
                $result[] = $interval;

                continue;
            }
            if ($this->stamp($interval['start_at']) < $this->stamp($absence['start_at'])) {
                $result[] = ['start_at' => $interval['start_at'], 'end_at' => $absence['start_at']];
            }
            if ($this->stamp($interval['end_at']) > $this->stamp($absence['end_at'])) {
                $result[] = ['start_at' => $absence['end_at'], 'end_at' => $interval['end_at']];
            }
        }

        return $result;
    }
}
