<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Builds TeleStaff "Roster Report" rows in the layout seen in the Oct 30 2026
 * workbook (position, ID, name, code, from, through, hours in columns 1-8).
 * All people here are invented.
 */
final class RosterSheetFixture
{
    /** @return array<int, mixed> */
    public static function block(string $text): array
    {
        return [1 => $text];
    }

    /** @return array<int, mixed> */
    public static function header(): array
    {
        return [1 => 'Rank', 2 => 'ID', 3 => 'Name', 6 => 'From', 7 => 'Through'];
    }

    /** @return array<int, mixed> */
    public static function blank(): array
    {
        return [3 => ' '];
    }

    /** @return array<int, mixed> */
    public static function slot(string $position, int|string|null $id, string $name, string $code = '', string $from = '', string $through = '', int|float|string $hours = ''): array
    {
        return array_filter([1 => $position, 2 => $id, 3 => $name, 5 => $code, 6 => $from, 7 => $through, 8 => $hours], static fn (mixed $v): bool => $v !== '' && $v !== null);
    }

    /** @return array<int, mixed> */
    public static function placeholder(string $position = 'Deploy Here', string $name = '-,-', string $from = '00:00', string $through = '00:00', int $hours = 24): array
    {
        return self::slot($position, null, $name, '', $from, $through, $hours);
    }

    /**
     * A section: header row, heading, staffing lines, blank row.
     *
     * @param  list<array<int, mixed>>  $slots
     * @return list<array<int, mixed>>
     */
    public static function section(string $heading, array $slots): array
    {
        return [self::header(), self::block($heading), ...$slots, self::blank()];
    }

    /**
     * Invented Oct 30 style sheet: ordinary divisions, two real Off Duty
     * Details, USAR placeholders and an all-placeholder Special Events block.
     *
     * @return list<array<int, mixed>>
     */
    public static function october30(): array
    {
        return [
            self::block("Miami Beach Fire Department / Chief's Office / D Shift 4/10,D Shift 5/8"),
            self::header(),
            self::block("Chief's Office / Administration"),
            self::slot('Fire Chief', 10001, 'ALPHA, TESTER (AO/HMA)', 'SP', '08:00', '18:00', 10),
            self::blank(),
            self::header(),
            self::block('Prevention / Special Events'),
            self::slot('SE Coordinator CPT', 10002, 'BRAVO, TESTER (INSP)', 'SP', '08:00', '18:00', 10),
            self::slot('', 10003, 'CHARLIE, TESTER (INSP)', '.DAYOFF', '08:00', '18:00', 10),
            self::blank(),
            self::block('Miami Beach Fire Department / Off Duty Detail / Off Duty Detail'),
            ...self::section('Off Duty Details / Placeholder For Deployment Only', [self::placeholder()]),
            ...self::section('Off Duty Details / 29 - Obsession Fire Show {DETINSP29, Faena Theater}', [
                self::slot('Fire Inspector INSP', 10004, 'DELTA, TESTER (AO/CSTE/DE)', '+OTD15FI', '19:00', '01:00', 6),
            ]),
            ...self::section('Off Duty Details / 30 - MILA Fire Show {DETINSP30, 1636 Meridian Ave}', [
                self::slot('Fire Inspector INSP', 10005, 'ECHO, TESTER (AO/AT)', '+OTD15FI', '19:00', '00:30', 5.5),
            ]),
            self::block('Miami Beach Fire Department / USAR Deployment / USAR Deployment'),
            ...self::section('Strike Team 1A / Placeholder For Deployment Only', [self::placeholder('Deploy Here', '?,?', '00:00', '12:00', 12)]),
            ...self::section('Strike Team 1B / Placeholder For Deployment Only', [self::placeholder('Deploy Here', '?,?', '00:00', '12:00', 12)]),
            self::block('Miami Beach Fire Department / Special Event / Special Events'),
            ...self::section('Special Events / Placeholder For Deployment Only', [self::placeholder()]),
            ...self::section('Chief Non-Working Event / Chief Non-Working Event', [
                self::placeholder('Fire Chief', '?,?'),
                self::placeholder('Division Chief', '?,?'),
            ]),
            ...self::section('Recall/ICS / ICS', [self::placeholder('Firefighter', '?,?'), self::placeholder('Firefighter', '?,?')]),
            ...self::section('Temporary Special Assignment / Temporary Special Assignment', [
                self::placeholder('Captain', '?,?'),
                self::slot('Community Resource Coordinator', null, '-,-', '', '08:00', '16:00', 8),
            ]),
        ];
    }

    /**
     * Invented Halloween shaped sheet (UKG screen layout exported to rows): one
     * event split over five headings, 11 members, spanning the Nov 1 DST change.
     *
     * @return list<array<int, mixed>>
     */
    public static function halloween(): array
    {
        $title = '01 - Halloween on Lincoln Rd';

        return [
            self::block('Miami Beach Fire Department / Special Event / Special Events'),
            ...self::section("Special Events / {$title} {CMD1; Lincoln Lane S; Command}", [
                self::slot('Command/Event Coordinator PM,INSP', 20001, 'NOVEMBER, ALEX (HMO/HMT)', '+OTDCD10', '16:30', '02:00', 10.5),
            ]),
            ...self::section("Special Events / {$title} {G1; Lincoln Rd & Euclid Ave; ALS non-transport}", [
                self::slot('Rescue Supervisor', 20002, 'OSCAR, BETTY (INSP/LI)', '+OTE20F', '16:30', '02:00', 10.5),
                self::slot('Paramedic', 20003, 'PAPA, CARL (CSTE/HMT)', '+OTE15', '16:30', '02:00', 10.5),
            ]),
            ...self::section("Special Events / {$title} {G2; Lincoln Rd & Euclid Ave; ALS non-transport}", [
                self::slot('Rescue Supervisor', 20004, 'QUEBEC JR., DANA (DE/INSP) {S2}', '+OTE20F', '16:30', '02:00', 10.5),
                self::slot('Paramedic', 20005, 'ROMEO, ERIN (COWD/DE)', '+OTE15', '16:30', '02:00', 10.5),
            ]),
            ...self::section("Special Events / {$title} {R6; Lakeview Dr. then Lincoln Road & Euclid Ave; ALS Transport}", [
                self::slot('Rescue Supervisor', 20006, 'SIERRA, FRANK (AT/DE)', '+OTE20F', '16:30', '02:00', 10.5),
                self::slot('Paramedic', 20007, 'TANGO, GINA (AT/DE)', '+OTE20F', '16:30', '02:00', 10.5),
                self::slot('Paramedic', 20008, 'UNIFORM, HANK (EM/IADRS)', '+OTE15', '16:30', '02:00', 10.5),
            ]),
            ...self::section("Special Events / {$title} {DETINDP2 - am Setup / pm event}", [
                self::slot('Lead Inspector LI,INSP', 20009, 'VICTOR, IVY (COWD/DE) {S1}', '+OTE20F', '17:00', '02:00', 10),
                self::slot('Fire Inspector INSP', 20010, 'WHISKEY, JACK (DE/EM)', '+OTE15', '10:00', '16:00', 6),
                self::slot('', 20011, 'XRAY, KATE (AT/CSTE)', '+OTE15', '17:00', '02:00', 10),
            ]),
            ...self::section('Special Events / Placeholder For Deployment Only', [self::placeholder()]),
        ];
    }
}
