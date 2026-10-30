<?php

declare(strict_types=1);

namespace Tests\Feature\DailyRoster\Pipeline\Support;

use App\Models\DailyRoster\RosterImport;
use App\Models\InboundEmail;
use App\Services\DailyRoster\Parsing\TeleStaffRosterBlockSummary;
use App\Services\DailyRoster\Parsing\TeleStaffRosterEntry;
use App\Services\DailyRoster\Parsing\TeleStaffRosterReport;
use App\Services\DailyRoster\Parsing\TeleStaffRosterSeat;
use App\Services\DailyRoster\Parsing\TeleStaffRosterSection;
use App\Support\Captain5\DutyCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Hand-built reports and ledger rows with synthetic people. Also loaded by the PostgreSQL race workers. */
final class RosterPipelineFixtures
{
    public const FAKE_NAMES = [
        'TESTPERSON, ALPHA', 'TESTPERSON, BRAVO', 'TESTPERSON, CHARLIE', 'TESTPERSON, DELTA',
        'TESTPERSON, ECHO', 'TESTÉ, FOXTROT',
    ];

    public const FAKE_IDS = ['90001', '90002', '90003', '90004', '90005', '90006'];

    public const FINGERPRINT = 'f00df00df00df00df00df00df00df00df00df00df00df00df00df00df00df00d';

    /**
     * @param  array<string, mixed>  $options  generated_at, shift, sections, variant (changes the hash), pad (extra
     *                                         rows), layout, warnings, drop (unit labels to leave out), unknown_codes
     */
    public static function report(string $dutyDate, array $options = []): TeleStaffRosterReport
    {
        $calendar = app(DutyCalendar::class);
        [$start, $end] = $calendar->window($dutyDate);
        $generatedAt = array_key_exists('generated_at', $options) ? $options['generated_at'] : $start->addMinutes(74);

        return new TeleStaffRosterReport(
            parserVersion: 'test-parser',
            sourceSha256: str_repeat('0', 64),
            sheetName: 'Roster Report',
            dutyDate: $dutyDate,
            shift: (string) ($options['shift'] ?? $calendar->shiftFor($dutyDate)),
            windowStartAt: $start->toIso8601ZuluString(),
            windowEndAt: $end->toIso8601ZuluString(),
            generatedAt: $generatedAt === null ? null : CarbonImmutable::parse($generatedAt)->utc()->toIso8601ZuluString(),
            divisionTitle: 'Miami Beach Fire Department / Suppression/Rescue / B Shift',
            titleRow: 15,
            layoutFingerprint: (string) ($options['layout'] ?? self::FINGERPRINT),
            sections: $options['sections'] ?? self::defaultSections($dutyDate, $options),
            otherBlocks: [new TeleStaffRosterBlockSummary(120, 'Administration', 'Days', 2, 5)],
            warnings: (array) ($options['warnings'] ?? []),
        );
    }

    /**
     * @param  array<string, mixed>  $options
     * @return list<TeleStaffRosterSection>
     */
    public static function defaultSections(string $dutyDate, array $options = []): array
    {
        $variant = isset($options['variant']) ? 'TESTPERSON, VARIANT'.$options['variant'] : self::FAKE_NAMES[4];
        $rows = 0;
        $row = static function () use (&$rows): int {
            return 30 + $rows++;
        };

        $sections = [
            self::section(0, 'Division Chief / Division Chief 300', [
                self::seat(0, 'Division Chief', [self::employee($dutyDate, $row(), self::FAKE_IDS[0], self::FAKE_NAMES[0], 'SP')]),
            ]),
            self::section(1, 'Station 2 / Captain 5', [
                self::seat(0, 'Captain', [self::employee($dutyDate, $row(), self::FAKE_IDS[1], self::FAKE_NAMES[1], 'SP')]),
            ]),
            self::section(2, 'Station 1 / Engine 1', [
                self::seat(0, 'Lieutenant', [
                    self::employee($dutyDate, $row(), self::FAKE_IDS[2], self::FAKE_NAMES[2], 'VACA', availability: 'not_working'),
                    self::employee($dutyDate, $row(), self::FAKE_IDS[3], self::FAKE_NAMES[3], '+OT', continuation: true),
                ]),
                self::seat(1, 'Firefighter', [self::employee($dutyDate, $row(), self::FAKE_IDS[4], $variant, 'SP')]),
                self::seat(2, 'Firefighter', [self::vacancy($row())]),
            ]),
            self::section(3, 'Station 1 / Ladder 1', [
                self::seat(0, 'Firefighter', [self::employee($dutyDate, $row(), self::FAKE_IDS[5], self::FAKE_NAMES[5], 'SP', certifications: ['CERT1', 'CERT2'])]),
            ]),
        ];

        $pad = (int) ($options['pad'] ?? 0);
        if ($pad > 0) {
            $seats = [];
            for ($index = 0; $index < $pad; $index++) {
                $seats[] = self::seat($index, 'Firefighter', [self::employee($dutyDate, $row(), (string) (91000 + $index), 'TESTPERSON, PAD', 'SP')]);
            }
            $sections[] = self::section(4, 'Station 4 / Engine 4', $seats);
        }

        $unknown = (int) ($options['unknown_codes'] ?? 0);
        if ($unknown > 0) {
            $seats = [];
            for ($index = 0; $index < $unknown; $index++) {
                $seats[] = self::seat($index, 'Firefighter', [self::employee($dutyDate, $row(), (string) (92000 + $index), 'TESTPERSON, UNKNOWN', 'GWA', availability: 'unknown', reviewReasons: ['unknown_code'])]);
            }
            $sections[] = self::section(5, 'Station 3 / Engine 3', $seats);
        }

        $dropped = (array) ($options['drop'] ?? []);

        return array_values(array_filter($sections, static fn (TeleStaffRosterSection $section): bool => ! in_array($section->unit, $dropped, true)));
    }

    /** @param list<TeleStaffRosterSeat> $seats */
    public static function section(int $order, string $title, array $seats): TeleStaffRosterSection
    {
        [$parent, $unit] = explode(' / ', $title, 2);

        return new TeleStaffRosterSection($order, 20 + $order, $title, $parent, $unit, $seats);
    }

    /** @param list<TeleStaffRosterEntry> $entries */
    public static function seat(int $order, string $rank, array $entries): TeleStaffRosterSeat
    {
        return new TeleStaffRosterSeat($order, 30 + $order, $rank, $entries);
    }

    /**
     * @param  list<string>  $certifications
     * @param  list<string>  $reviewReasons
     */
    public static function employee(
        string $dutyDate,
        int $sourceRow,
        string $id,
        string $name,
        string $code,
        string $availability = 'working',
        bool $continuation = false,
        array $certifications = [],
        array $reviewReasons = [],
    ): TeleStaffRosterEntry {
        [$start, $end] = app(DutyCalendar::class)->window($dutyDate);

        return new TeleStaffRosterEntry(
            sourceRow: $sourceRow,
            isContinuation: $continuation,
            kind: 'employee',
            employeeId: $id,
            rawName: $name,
            displayName: $name,
            certifications: $certifications,
            rawCode: $code,
            baseCode: ltrim($code, '.'),
            prefix: null,
            availability: $availability,
            category: 'Scheduled',
            displayLabel: $code,
            rawFrom: '08:00',
            rawThrough: '08:00',
            reportedHours: 24.0,
            startAt: $start->toIso8601ZuluString(),
            endAt: $end->toIso8601ZuluString(),
            reviewReasons: $reviewReasons,
        );
    }

    public static function vacancy(int $sourceRow): TeleStaffRosterEntry
    {
        return new TeleStaffRosterEntry(
            sourceRow: $sourceRow,
            isContinuation: false,
            kind: 'vacancy_suppressed',
            employeeId: null,
            rawName: '-,-',
            displayName: null,
            certifications: [],
            rawCode: null,
            baseCode: null,
            prefix: null,
            availability: 'vacant',
            category: null,
            displayLabel: null,
            rawFrom: '20:00',
            rawThrough: '08:00',
            reportedHours: 12.5,
            startAt: null,
            endAt: null,
            reviewReasons: [],
        );
    }

    /**
     * A ledger row whose attachment really is on the local disk, at the path the inbound gateway uses.
     *
     * @param  array<string, mixed>  $overrides  Ledger columns, plus 'bytes' for the attachment's content and 'message_id'
     */
    public static function import(array $overrides = []): RosterImport
    {
        $messageId = (string) ($overrides['message_id'] ?? '<'.Str::uuid().'@example.test>');
        $messageHash = hash('sha256', $messageId);
        $bytes = (string) ($overrides['bytes'] ?? 'PK'.Str::random(32));
        unset($overrides['bytes'], $overrides['message_id']);
        $path = 'inbound-email-attachments/'.$messageHash.'/'.Str::uuid().'.xlsx';
        Storage::disk('local')->put($path, $bytes);

        return RosterImport::query()->create([
            'correlation_id' => (string) Str::uuid(),
            'state' => RosterImport::STATE_QUEUED,
            'state_changed_at' => CarbonImmutable::now('UTC'),
            'message_id_sha256' => $messageHash,
            'sender' => (string) config('daily_roster.classifier.sender'),
            'subject' => (string) config('daily_roster.classifier.subject'),
            'filename' => (string) config('daily_roster.classifier.filename'),
            'attachment_bytes' => strlen($bytes),
            'attachment_sha256' => hash('sha256', $bytes),
            'attachment_disk' => 'local',
            'attachment_path' => $path,
            'received_at' => CarbonImmutable::now('UTC'),
            'classification' => ['verdict' => 'match', 'reasons' => ['authenticated_by_authentication_results']],
            ...$overrides,
        ]);
    }

    /**
     * A stored email and its ledger row, linked the way the registrar links them.
     *
     * @param  array<string, mixed>  $overrides
     * @return array{0: InboundEmail, 1: RosterImport}
     */
    public static function linkedImport(array $overrides = []): array
    {
        $messageId = '<'.Str::uuid().'@example.test>';
        $import = self::import([...$overrides, 'message_id' => $messageId]);
        $email = new InboundEmail;
        $email->forceFill([
            'provider_message_id' => $messageId,
            'from_address' => (string) config('daily_roster.classifier.sender'),
            'to_address' => 'info@mbfdhub.com',
            'subject' => (string) config('daily_roster.classifier.subject'),
            'received_at' => $import->received_at,
            'processing_status' => 'received',
            'attachment_metadata' => [[
                'filename' => (string) config('daily_roster.classifier.filename'),
                'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'size' => (int) $import->attachment_bytes,
                'disk' => (string) $import->attachment_disk,
                'path' => (string) $import->attachment_path,
            ]],
        ])->save();
        $import->forceFill(['inbound_email_id' => $email->getKey()])->save();

        return [$email, $import];
    }
}
