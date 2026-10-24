<?php

declare(strict_types=1);

namespace App\Support\Fleet;

use App\Enums\DailyCheckoutChecklistTemplate;
use App\Models\Apparatus;

/**
 * The one list of facts a crew can be asked about a vehicle, the plain wording for each, the
 * "information not available" reasons, and how a typed value is cleaned and compared.
 *
 * Only real master vehicle fields appear here. Meters are not asked: Daily Checkout already
 * captures them.
 */
final class VehicleIdentificationFields
{
    public const KIND_VEHICLE = 'vehicle';

    public const KIND_BOAT = 'boat';

    public const VEHICLE_NUMBER = 'vehicle_number';

    public const VIN = 'vin';

    public const PLATE = 'plate';

    public const YEAR = 'year';

    public const MAKE = 'make';

    public const MODEL = 'model';

    /** Question order, and the field key => master column map. */
    public const MASTER_COLUMNS = [
        self::VEHICLE_NUMBER => 'veh_number',
        self::VIN => 'serial_number',
        self::PLATE => 'tag_number',
        self::YEAR => 'year',
        self::MAKE => 'make',
        self::MODEL => 'model',
    ];

    /** Fields a vehicle is matched by; the others only describe it. */
    public const STRONG_IDENTIFIERS = [self::VEHICLE_NUMBER, self::VIN];

    public const REASON_NOT_ACCESSIBLE_NOW = 'not_accessible_now';

    public const REASON_LOCATION_UNKNOWN = 'location_unknown';

    public const REASON_NOT_PRESENT = 'not_present';

    public const REASON_NOT_APPLICABLE = 'not_applicable';

    public const REASON_OTHER = 'other';

    /** code => [label, permanent, needs_note] */
    private const REASONS = [
        self::REASON_NOT_ACCESSIBLE_NOW => ["Can't get to it right now (unit in service, away, or blocked)", false, false],
        self::REASON_LOCATION_UNKNOWN => ["I don't know where to find it on this vehicle", false, false],
        self::REASON_NOT_PRESENT => ["It isn't on this vehicle (no plate, plate missing, ID worn off)", true, false],
        self::REASON_NOT_APPLICABLE => ["Doesn't apply to this apparatus", true, false],
        self::REASON_OTHER => ['Other (short note required)', false, true],
    ];

    /** field => [label for a vehicle, label for a boat, hint for a vehicle, hint for a boat, input, max length] */
    private const DEFINITIONS = [
        self::VEHICLE_NUMBER => [
            'Fleet vehicle number (the City number on the vehicle)', 'Fleet vessel number',
            'The number the City painted or posted on this vehicle.', 'The number the City posted on this vessel.',
            'text', 32,
        ],
        self::VIN => [
            'VIN (17 characters, driver door jamb or windshield)', 'Hull ID (HIN, transom, starboard side)',
            'Read it from the vehicle itself. 17 letters and numbers.', 'Read it from the hull itself, on the transom on the starboard side.',
            'text', 32,
        ],
        self::PLATE => [
            'License plate', 'Registration number (FL number on the bow)',
            'Read it from the plate on the vehicle.', 'Read it from the registration number on the bow.',
            'text', 32,
        ],
        self::YEAR => [
            'Model year', 'Model year',
            'Four digits, from the door jamb sticker or the registration.', 'Four digits, from the hull plate or the registration.',
            'text', 4,
        ],
        self::MAKE => [
            'Make', 'Builder / make',
            'The maker named on the vehicle, for example Ford or Pierce.', 'The builder named on the vessel.',
            'text', 64,
        ],
        self::MODEL => [
            'Model', 'Model',
            'The model name on the vehicle.', 'The model name or length on the vessel.',
            'text', 64,
        ],
    ];

    /** Master values that count as "nothing there". */
    private const BLANK_MARKERS = ['N/A', 'NA', 'NONE', '-'];

    /** What people type when they have nothing to say; never a real identifier, never matched or accepted. */
    private const PLACEHOLDERS = ['N/A', 'NA', 'NONE', '-', '0', 'UNKNOWN', 'X'];

    /** Shortest real value per identifier field (plate, vehicle number, hull ID). */
    private const MIN_LENGTH = [self::PLATE => 2, self::VEHICLE_NUMBER => 4];

    private const MIN_HIN_LENGTH = 6;

    /** @return list<string> */
    public static function fields(): array
    {
        return array_keys(self::MASTER_COLUMNS);
    }

    public static function isField(mixed $field): bool
    {
        return is_string($field) && array_key_exists($field, self::MASTER_COLUMNS);
    }

    public static function masterColumn(string $field): string
    {
        return self::MASTER_COLUMNS[$field];
    }

    public static function kindFor(Apparatus $apparatus): string
    {
        $text = (string) $apparatus->getAttribute('type').' '.(string) $apparatus->getAttribute('class_description');

        return $apparatus->daily_checkout_template === DailyCheckoutChecklistTemplate::FireBoat6 || preg_match('/boat/i', $text) === 1
            ? self::KIND_BOAT
            : self::KIND_VEHICLE;
    }

    /** @return array{label: string, hint: string, input: string, max: int} */
    public static function describe(string $field, string $kind): array
    {
        [$vehicleLabel, $boatLabel, $vehicleHint, $boatHint, $input, $max] = self::DEFINITIONS[$field];
        $boat = $kind === self::KIND_BOAT;

        return [
            'label' => $boat ? $boatLabel : $vehicleLabel,
            'hint' => $boat ? $boatHint : $vehicleHint,
            'input' => $input,
            'max' => $max,
        ];
    }

    public static function label(string $field, string $kind = self::KIND_VEHICLE): string
    {
        return self::describe($field, $kind)['label'];
    }

    /** The short name used in sentences for Fleet: "plate", "VIN/HIN". */
    public static function shortLabel(string $field): string
    {
        return match ($field) {
            self::VEHICLE_NUMBER => 'vehicle number',
            self::VIN => 'VIN/HIN',
            self::PLATE => 'plate',
            default => $field,
        };
    }

    /** @return list<array{code: string, label: string, needs_note: bool}> */
    public static function reasons(): array
    {
        $reasons = [];
        foreach (self::REASONS as $code => [$label, , $needsNote]) {
            $reasons[] = ['code' => $code, 'label' => $label, 'needs_note' => $needsNote];
        }

        return $reasons;
    }

    public static function isReason(mixed $code): bool
    {
        return is_string($code) && array_key_exists($code, self::REASONS);
    }

    public static function reasonLabel(?string $code): ?string
    {
        return $code !== null && isset(self::REASONS[$code]) ? self::REASONS[$code][0] : null;
    }

    /** A permanent reason ends the question for everyone until Fleet asks again. */
    public static function isPermanentReason(string $code): bool
    {
        return (self::REASONS[$code][1] ?? false) === true;
    }

    public static function reasonNeedsNote(string $code): bool
    {
        return (self::REASONS[$code][2] ?? false) === true;
    }

    /** True when a master (or apparatus) value says nothing: empty, N/A, NA, NONE or a dash. */
    public static function isBlankValue(mixed $value): bool
    {
        $text = VehicleIdentity::text($value);

        return $text === null || in_array(mb_strtoupper($text), self::BLANK_MARKERS, true);
    }

    /**
     * True for an empty value, a blank marker (N/A, NA, NONE, -) and the other placeholders people
     * type instead of an identifier (0, UNKNOWN, X). Used to keep such values out of matching.
     */
    public static function isPlaceholderValue(mixed $value): bool
    {
        $text = VehicleIdentity::text($value);
        if ($text === null) {
            return true;
        }
        $upper = mb_strtoupper($text);
        $bare = (string) preg_replace('/[^A-Z0-9]/', '', $upper);

        return in_array($upper, self::PLACEHOLDERS, true) || in_array($bare, ['NA', 'NONE', 'UNKNOWN', 'X', '0'], true);
    }

    /**
     * Clean a typed answer. Returns the value to store, or null when it cannot be a real value
     * for this field and kind.
     */
    public static function clean(string $field, string $kind, mixed $raw): ?string
    {
        $text = VehicleIdentity::text($raw);
        if ($text === null || mb_strlen($text) > 64) {
            return null;
        }

        $value = match ($field) {
            self::VEHICLE_NUMBER => self::cleanVehicleNumber($text),
            self::VIN => self::cleanVin($text, $kind),
            self::PLATE => self::cleanPlate($text),
            self::YEAR => self::cleanYear($text),
            default => self::cleanDescription($text),
        };

        if ($value === null) {
            return null;
        }

        return self::isIdentifier($field) && self::looksLikeFiller($field, $kind, $value) ? null : $value;
    }

    private static function isIdentifier(string $field): bool
    {
        return in_array($field, [self::VEHICLE_NUMBER, self::VIN, self::PLATE], true);
    }

    /** A placeholder, a run of one repeated character, or too short to be what it claims to be. */
    private static function looksLikeFiller(string $field, string $kind, string $value): bool
    {
        if (self::isPlaceholderValue($value)) {
            return true;
        }
        $key = (string) VehicleIdentity::vinKey($value);
        if (mb_strlen($key) >= 2 && preg_match('/\A(.)\1+\z/u', $key) === 1) {
            return true;
        }
        $minimum = $field === self::VIN && $kind === self::KIND_BOAT ? self::MIN_HIN_LENGTH : (self::MIN_LENGTH[$field] ?? 0);

        return mb_strlen($field === self::VEHICLE_NUMBER ? $value : $key) < $minimum;
    }

    /** The plain-language reason a value was refused, for the screen that asked. */
    public static function refusal(string $field, string $kind): string
    {
        return match ($field) {
            self::VEHICLE_NUMBER => 'Enter the number as it appears on the vehicle (letters, numbers, dashes).',
            self::VIN => $kind === self::KIND_BOAT
                ? 'Enter the hull ID exactly as it appears on the hull (letters and numbers).'
                : 'Enter all 17 letters and numbers of the VIN.',
            self::PLATE => 'Enter the plate with letters and numbers only.',
            self::YEAR => 'Enter a four digit model year.',
            default => 'Enter the name as it appears on the vehicle.',
        };
    }

    /** A comparison key: equal keys mean the same value for this field. Null when nothing comparable. */
    public static function key(string $field, mixed $value): ?string
    {
        return match ($field) {
            self::VEHICLE_NUMBER => VehicleIdentity::numberKey($value),
            self::VIN, self::PLATE => VehicleIdentity::vinKey($value),
            self::YEAR => ($year = VehicleIdentity::year($value)) === null ? null : (string) $year,
            default => ($text = VehicleIdentity::text($value)) === null ? null : mb_strtoupper($text),
        };
    }

    private static function cleanVehicleNumber(string $text): ?string
    {
        $number = VehicleNumber::normalize($text);

        return VehicleNumber::isWellFormed($number) ? $number : null;
    }

    private static function cleanVin(string $text, string $kind): ?string
    {
        $key = VehicleIdentity::vinKey($text);
        if ($key === null) {
            return null;
        }
        // A hull ID has no fixed length; a vehicle VIN is exactly 17 letters and digits.
        if ($kind === self::KIND_BOAT) {
            return preg_match('/\A[A-Z0-9]{1,32}\z/', $key) === 1 ? $key : null;
        }

        return preg_match('/\A[A-Z0-9]{17}\z/', $key) === 1 ? $key : null;
    }

    private static function cleanPlate(string $text): ?string
    {
        $key = VehicleIdentity::vinKey($text);

        return $key !== null && preg_match('/\A[A-Z0-9]{1,16}\z/', $key) === 1 ? $key : null;
    }

    private static function cleanYear(string $text): ?string
    {
        return preg_match('/\A(?:19|20)\d{2}\z/', $text) === 1 ? $text : null;
    }

    private static function cleanDescription(string $text): ?string
    {
        return preg_match('/[\p{L}\p{N}]/u', $text) === 1 ? $text : null;
    }
}
