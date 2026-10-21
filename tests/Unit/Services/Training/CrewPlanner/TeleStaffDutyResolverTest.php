<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Training\CrewPlanner;

use App\Services\Training\CrewPlanner\TeleStaffDutyResolver;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class TeleStaffDutyResolverTest extends TestCase
{
    private function event(string $code, string $from = '08:00', string $to = '20:00', string $approval = 'VERIFIED_EFFECTIVE', string $id = 'SYN90001'): array
    {
        return [
            'raw_employee_id' => $id, 'raw_name' => 'SYNTHETIC, PERSON',
            'normalized_code' => $code, 'code_class' => in_array($code, ['ADONIS DAY', '2WXOFF', 'VACA', 'SIC'], true) ? 'NOT_WORKING' : (in_array($code, ['SP', '+OT', 'OT', '2WXON'], true) ? 'WORKING' : 'UNKNOWN'),
            'approval_state' => $approval,
            'start_at' => '2026-10-21T'.$from.':00-04:00',
            'end_at' => ($to <= $from ? '2026-10-22T' : '2026-10-21T').$to.':00-04:00',
            'reported_station' => 'Station 9', 'reported_apparatus' => 'Engine 9',
        ];
    }

    private function slots(): array
    {
        return [
            ['id' => 'morning', 'start_at' => '2026-10-21T08:30:00-04:00', 'end_at' => '2026-10-21T11:30:00-04:00'],
            ['id' => 'afternoon', 'start_at' => '2026-10-21T13:00:00-04:00', 'end_at' => '2026-10-21T16:00:00-04:00'],
        ];
    }

    public function test_day_overtime_covers_both_slots_and_night_covers_neither(): void
    {
        $resolver = new TeleStaffDutyResolver;
        $rows = [$this->event('+OT'), $this->event('+OT', '20:00', '08:00', id: 'SYN90002')];
        $staff = $resolver->resolve(['parsed_assignments' => $rows], $this->slots());
        self::assertSame(['morning', 'afternoon'], $staff['SYN90001']['eligible_slots']);
        self::assertSame(['morning', 'afternoon'], $staff['SYN90002']['unavailable_slots']);
    }

    public function test_partial_duty_never_becomes_full_eligibility(): void
    {
        $resolver = new TeleStaffDutyResolver;
        $staff = $resolver->resolve(['parsed_assignments' => [$this->event('SP', '10:00', '14:00')]], $this->slots());
        self::assertSame([], $staff['SYN90001']['eligible_slots']);
        self::assertSame(['morning', 'afternoon'], $staff['SYN90001']['partial_slots']);
        $staff = $resolver->resolve(['parsed_assignments' => [$this->event('SP', '08:00', '12:00')]], $this->slots());
        self::assertSame(['morning'], $staff['SYN90001']['eligible_slots']);
        self::assertSame(['afternoon'], $staff['SYN90001']['unavailable_slots']);
    }

    public function test_approved_exchange_overrides_only_overlapping_aday_hours(): void
    {
        $rows = [$this->event('ADONIS DAY', '08:00', '08:00'), $this->event('2WXON', '08:00', '12:00')];
        $staff = (new TeleStaffDutyResolver)->resolve(['parsed_assignments' => $rows], $this->slots());
        self::assertSame(['morning'], $staff['SYN90001']['eligible_slots']);
        self::assertSame(['afternoon'], $staff['SYN90001']['unavailable_slots']);
    }

    public function test_verified_overtime_overrides_aday_only_for_the_worked_interval(): void
    {
        foreach (['+OT', 'OT'] as $code) {
            $rows = [$this->event('ADONIS DAY', '08:00', '08:00'), $this->event($code, '08:00', '12:00')];
            $staff = (new TeleStaffDutyResolver)->resolve(['parsed_assignments' => $rows], $this->slots());
            self::assertSame(['morning'], $staff['SYN90001']['eligible_slots']);
            self::assertSame(['afternoon'], $staff['SYN90001']['unavailable_slots']);
        }
    }

    public function test_regular_duty_does_not_override_aday_and_other_absences_still_subtract_overtime(): void
    {
        $resolver = new TeleStaffDutyResolver;
        $aday = $this->event('ADONIS DAY', '08:00', '08:00');
        $staff = $resolver->resolve(['parsed_assignments' => [$aday, $this->event('SP')]], $this->slots());
        self::assertSame([], $staff['SYN90001']['eligible_slots']);
        self::assertSame(['morning', 'afternoon'], $staff['SYN90001']['unavailable_slots']);
        foreach (['VACA', 'SIC', '2WXOFF'] as $absence) {
            $rows = [$aday, $this->event('+OT'), $this->event($absence, '12:00', '20:00')];
            $staff = $resolver->resolve(['parsed_assignments' => $rows], $this->slots());
            self::assertSame(['morning'], $staff['SYN90001']['eligible_slots']);
            self::assertSame(['afternoon'], $staff['SYN90001']['unavailable_slots']);
        }
    }

    public function test_unapproved_exchange_or_overtime_cannot_override_aday(): void
    {
        foreach (['2WXON', 'OT', '+OT'] as $code) {
            $rows = [$this->event('ADONIS DAY', '08:00', '08:00'), $this->event($code, approval: 'UNVERIFIED')];
            $staff = (new TeleStaffDutyResolver)->resolve(['parsed_assignments' => $rows], $this->slots());
            self::assertSame([], $staff['SYN90001']['eligible_slots']);
            self::assertNotEmpty($staff['SYN90001']['slot_review_reasons']['morning']);
        }
    }

    public function test_pending_exchange_unknown_code_and_annotation_remain_review(): void
    {
        foreach ([$this->event('2WXON', approval: 'UNVERIFIED'), $this->event('2WXEON'), $this->event('^OOC'), $this->event('.SP')] as $uncertain) {
            $staff = (new TeleStaffDutyResolver)->resolve(['parsed_assignments' => [$this->event('SP'), $uncertain]], $this->slots());
            self::assertSame([], $staff['SYN90001']['eligible_slots']);
            self::assertNotEmpty($staff['SYN90001']['needs_review']);
        }
    }

    public function test_exchange_off_and_leave_subtract_hours_even_from_positive_work(): void
    {
        foreach (['2WXOFF', 'VACA'] as $absence) {
            $staff = (new TeleStaffDutyResolver)->resolve(['parsed_assignments' => [$this->event('SP'), $this->event($absence, '12:00', '20:00')]], $this->slots());
            self::assertSame(['morning'], $staff['SYN90001']['eligible_slots']);
            self::assertSame(['afternoon'], $staff['SYN90001']['unavailable_slots']);
        }
    }

    public function test_contiguous_segments_merge_and_duplicate_people_count_once(): void
    {
        $rows = [$this->event('SP', '08:00', '10:00'), $this->event('SP', '10:00', '20:00'), $this->event('SP', '10:00', '20:00')];
        $staff = (new TeleStaffDutyResolver)->resolve(['parsed_assignments' => $rows], $this->slots());
        self::assertCount(1, $staff);
        self::assertCount(1, $staff['SYN90001']['working_intervals']);
        self::assertSame(['morning', 'afternoon'], $staff['SYN90001']['eligible_slots']);
    }

    public function test_conflicting_locations_and_incomplete_report_block_definitive_eligibility(): void
    {
        $other = $this->event('SP');
        $other['reported_apparatus'] = 'Rescue 9';
        $staff = (new TeleStaffDutyResolver)->resolve(['parsed_assignments' => [$this->event('SP'), $other]], $this->slots());
        self::assertSame([], $staff['SYN90001']['eligible_slots']);
        self::assertNotEmpty($staff['SYN90001']['needs_review']);
        $staff = (new TeleStaffDutyResolver)->resolve(['parsed_assignments' => [$this->event('SP')], 'definitive_eligibility_blocked' => true], $this->slots());
        self::assertSame([], $staff['SYN90001']['eligible_slots']);
    }

    public function test_edited_slots_recheck_coverage_without_losing_review_or_selections(): void
    {
        $resolver = new TeleStaffDutyResolver;
        $staff = $resolver->resolve(['parsed_assignments' => [$this->event('SP', '08:00', '12:00')]], $this->slots());
        $slots = $this->slots();
        $slots[0]['end_at'] = '2026-10-21T12:30:00-04:00';
        $again = $resolver->resolveStaff($staff, $slots);
        self::assertSame(array_keys($staff), array_keys($again));
        self::assertSame([], $again['SYN90001']['eligible_slots']);
        self::assertSame(['morning'], $again['SYN90001']['partial_slots']);
        self::assertArrayNotHasKey('_events', $again['SYN90001']);
    }

    public function test_half_open_boundaries_and_overnight_slots(): void
    {
        $slots = [['id' => 'night', 'start_at' => '2026-10-21T20:00:00-04:00', 'end_at' => '2026-10-22T08:00:00-04:00']];
        $staff = (new TeleStaffDutyResolver)->resolve(['parsed_assignments' => [$this->event('+OT')]], $slots);
        self::assertSame(['night'], $staff['SYN90001']['unavailable_slots']);
        $staff = (new TeleStaffDutyResolver)->resolve(['parsed_assignments' => [$this->event('+OT', '20:00', '08:00')]], $slots);
        self::assertSame(['night'], $staff['SYN90001']['eligible_slots']);
    }

    public function test_vacancies_are_not_people_and_invalid_times_require_review(): void
    {
        $invalid = $this->event('SP');
        $invalid['end_at'] = null;
        $staff = (new TeleStaffDutyResolver)->resolve(['parsed_assignments' => [['raw_employee_id' => null], $invalid]], $this->slots());
        self::assertCount(1, $staff);
        self::assertNotEmpty($staff['SYN90001']['needs_review']);
        self::assertSame([], $staff['SYN90001']['eligible_slots']);
    }

    public function test_duplicate_slot_ids_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new TeleStaffDutyResolver)->resolveStaff([], [$this->slots()[0], $this->slots()[0]]);
    }

    public function test_uncertain_night_does_not_block_verified_day_and_review_survives_revalidation(): void
    {
        $resolver = new TeleStaffDutyResolver;
        $slots = [...$this->slots(), ['id' => 'night', 'start_at' => '2026-10-21T20:00:00-04:00', 'end_at' => '2026-10-22T08:00:00-04:00']];
        $staff = $resolver->resolve(['parsed_assignments' => [$this->event('SP'), $this->event('2WXEON', '20:00', '08:00')]], $slots);
        self::assertSame(['morning', 'afternoon'], $staff['SYN90001']['eligible_slots']);
        self::assertSame(['night'], $staff['SYN90001']['partial_slots']);
        self::assertSame([], $staff['SYN90001']['slot_review_reasons']['morning']);
        self::assertNotEmpty($staff['SYN90001']['slot_review_reasons']['night']);
        self::assertSame($staff, $resolver->resolveStaff($staff, $slots));
    }

    public function test_day_of_riding_context_retains_split_unit_and_station_intervals(): void
    {
        $morning = $this->event('SP', '08:00', '12:00');
        $morning['reported_station'] = 'Station 1';
        $morning['reported_apparatus'] = 'Engine 1';
        $afternoon = $this->event('SP', '12:00', '20:00');
        $afternoon['reported_station'] = 'Station 2';
        $afternoon['reported_apparatus'] = 'Rescue 2';
        $staff = (new TeleStaffDutyResolver)->resolve(['parsed_assignments' => [$morning, $afternoon]], $this->slots());
        $person = $staff['SYN90001'];
        self::assertSame(['morning', 'afternoon'], $person['eligible_slots']);
        self::assertSame(['Engine 1', 'Rescue 2'], array_column($person['observed_unit_context'], 'apparatus'));
        self::assertSame('2026-10-21T12:00:00-04:00', $person['observed_unit_context'][0]['end_at']);
        self::assertSame('2026-10-21T12:00:00-04:00', $person['observed_unit_context'][1]['start_at']);
        self::assertSame([], $person['needs_review']);
    }
}
