<?php

declare(strict_types=1);

namespace App\Services\UnitStatus;

use Carbon\CarbonImmutable;
use Throwable;

/** One validated CallV1 row from the Command feed. Built only through fromArray(), which rejects malformed rows. */
final readonly class SourceCall
{
    public const SECTIONS = ['active', 'recent', 'unknown'];

    /** @param  list<array{unitId: string, status: string}>  $units */
    public function __construct(
        public string $id,
        public ?string $externalId,
        public ?string $duplicateOf,
        public ?string $sourceUrl,
        public string $callType,
        public string $address,
        public ?float $lat,
        public ?float $lng,
        public string $locationQuality,
        public ?CarbonImmutable $receivedAt,
        public string $section,
        public string $sourceStatus,
        public ?CarbonImmutable $closedAt,
        public ?CarbonImmutable $disappearedAt,
        public CarbonImmutable $firstSeenAt,
        public CarbonImmutable $lastSeenAt,
        public CarbonImmutable $updatedAt,
        public array $units,
    ) {}

    /** @param  array<mixed>  $row */
    public static function fromArray(array $row): ?self
    {
        $id = $row['id'] ?? null;
        $section = $row['section'] ?? null;
        $units = self::units($row['units'] ?? null);
        $first = self::instant($row['firstSeenAt'] ?? null);
        $last = self::instant($row['lastSeenAt'] ?? null);
        $updated = self::instant($row['updatedAt'] ?? null);
        if (! is_string($id) || preg_match('/\Asrc_[A-Za-z0-9]{1,60}\z/', $id) !== 1
            || ! is_string($section) || ! in_array($section, self::SECTIONS, true)
            || $units === null || $first === null || $last === null || $updated === null) {
            return null;
        }

        $quality = ($row['locationQuality'] ?? null) === 'approximate' ? 'approximate' : 'none';
        $lat = self::coordinate($row['lat'] ?? null, 90.0);
        $lng = self::coordinate($row['lng'] ?? null, 180.0);
        $positioned = $lat !== null && $lng !== null && $quality === 'approximate';

        return new self(
            $id,
            self::optionalId($row['externalId'] ?? null),
            self::optionalId($row['duplicateOf'] ?? null),
            self::httpsUrl($row['sourceUrl'] ?? null),
            self::text($row['callType'] ?? null, 200),
            self::text($row['address'] ?? null, 300),
            $positioned ? $lat : null,
            $positioned ? $lng : null,
            $positioned ? 'approximate' : 'none',
            self::instant($row['receivedAt'] ?? null),
            $section,
            self::text($row['sourceStatus'] ?? null, 200),
            self::instant($row['closedAt'] ?? null),
            self::instant($row['disappearedAt'] ?? null),
            $first,
            $last,
            $updated,
            $units,
        );
    }

    /** @return list<array{unitId: string, status: string}>|null */
    private static function units(mixed $units): ?array
    {
        if (! is_array($units)) {
            return null;
        }
        $clean = [];
        foreach ($units as $unit) {
            if (! is_array($unit) || ! is_string($unit['unitId'] ?? null) || ! is_string($unit['status'] ?? '')) {
                return null;
            }
            $clean[] = ['unitId' => self::text($unit['unitId'], 32), 'status' => self::text($unit['status'] ?? '', 64)];
        }

        return $clean;
    }

    private static function instant(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }
    }

    private static function optionalId(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? self::text($value, 64) : null;
    }

    private static function text(mixed $value, int $max): string
    {
        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', is_string($value) ? $value : '') ?? '';

        return mb_substr(trim($clean), 0, $max);
    }

    /** Only an https link is kept, so a hostile `javascript:` value can never become an href. */
    private static function httpsUrl(mixed $value): ?string
    {
        if (! is_string($value) || strlen($value) > 500 || preg_match('/\A[\x21-\x7E]+\z/', $value) !== 1) {
            return null;
        }

        return strtolower((string) parse_url($value, PHP_URL_SCHEME)) === 'https' && parse_url($value, PHP_URL_HOST) ? $value : null;
    }

    private static function coordinate(mixed $value, float $limit): ?float
    {
        return (is_int($value) || is_float($value)) && abs((float) $value) <= $limit ? (float) $value : null;
    }
}
