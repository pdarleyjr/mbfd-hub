<?php

declare(strict_types=1);

namespace App\Services\MediaControl;

use App\Support\HubTimezone;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;

/**
 * Normalises Media Control ContentSummary payloads into the small, scalar
 * shape the admin page renders. Untrusted fields are type-checked and bounded;
 * thumbnail links are only kept when they point at the Media Control origin.
 */
final class MediaControlContent
{
    public const DISPLAY_LAYOUTS = [
        1 => 'Single display',
        2 => '2 displays',
        3 => '3 displays',
        4 => '4 displays',
        5 => '5 displays',
    ];

    public const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    public const VIDEO_MIMES = ['video/mp4', 'video/webm', 'video/quicktime'];

    public const IMAGE_MAX_BYTES = 200 * 1024 * 1024;

    /**
     * @param  array<mixed>  $item
     * @return array{id: string, title: string, kind: string, mime: string, width: int|null, height: int|null, duration_sec: float|null, file_size: int|null, display_span: int|null, display_span_source: string|null, category: string|null, tags: list<string>, processing_status: string|null, processing_error: string|null, created_at: string|null, uploaded_via_hub: bool, uploaded_by: string|null, thumbnail_url: string|null}|null
     */
    public static function fromSummary(array $item, ?string $origin): ?array
    {
        $id = self::string($item['id'] ?? null, 100);
        $kind = $item['kind'] ?? null;
        if ($id === null || ! in_array($kind, ['image', 'video'], true)) {
            return null;
        }

        return [
            'id' => $id,
            'title' => self::string($item['title'] ?? null, 200) ?? 'Untitled',
            'kind' => $kind,
            'mime' => self::string($item['mime'] ?? null, 100) ?? '',
            'width' => self::int($item['width'] ?? null),
            'height' => self::int($item['height'] ?? null),
            'duration_sec' => is_numeric($item['duration_sec'] ?? null) ? (float) $item['duration_sec'] : null,
            'file_size' => self::int($item['file_size'] ?? null),
            'display_span' => self::span($item['display_span'] ?? null),
            'display_span_source' => self::string($item['display_span_source'] ?? null, 20),
            'category' => self::string($item['category'] ?? null, 60),
            'tags' => self::tags($item['tags'] ?? []),
            'processing_status' => self::string($item['processing_status'] ?? null, 40),
            'processing_error' => self::string($item['processing_error'] ?? null, 300),
            'created_at' => self::string($item['created_at'] ?? null, 40),
            'uploaded_via_hub' => ($item['uploaded_via_hub'] ?? false) === true,
            'uploaded_by' => self::string($item['uploaded_by'] ?? null, 120),
            'thumbnail_url' => self::sameOriginUrl($item['thumbnail_url'] ?? null, $origin),
        ];
    }

    public static function layoutLabel(?int $span): string
    {
        return $span === null ? 'Unsorted' : self::DISPLAY_LAYOUTS[$span];
    }

    /** API timestamps describe UTC instants; malformed upstream values must not break the library. */
    public static function createdAt(?string $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, 'UTC')->setTimezone(HubTimezone::NAME);
        } catch (InvalidFormatException) {
            return null;
        }
    }

    public static function span(mixed $value): ?int
    {
        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }

        return is_int($value) && $value >= 1 && $value <= 5 ? $value : null;
    }

    /** @return list<string> */
    public static function tags(mixed $value): array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }
        if (! is_array($value)) {
            return [];
        }
        $tags = [];
        foreach ($value as $tag) {
            if (is_string($tag) && ($tag = trim($tag)) !== '') {
                $tags[] = mb_substr($tag, 0, 40);
            }
        }

        return array_slice(array_values(array_unique($tags)), 0, 10);
    }

    public static function sameOriginUrl(mixed $url, ?string $origin): ?string
    {
        if (! is_string($url) || $origin === null) {
            return null;
        }
        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            return $origin.$url;
        }

        return str_starts_with(strtolower($url), $origin.'/') ? $url : null;
    }

    private static function string(mixed $value, int $max): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    private static function int(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
