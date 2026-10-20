<?php

declare(strict_types=1);

namespace App\Services\CommandBoard;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * The Command Board's own report of its command PIN. It never contains the PIN
 * or its hash; the board only says whether one is set, where it came from, and
 * when and by whom it last changed.
 */
final class CommandBoardPinStatus
{
    public const SOURCE_LABELS = [
        'hub_admin' => 'Custom PIN set in the Hub',
        'video_conferencing_default' => 'Same as the 300 camera PIN',
        'none' => 'Not set',
    ];

    private const UPDATED_BY_MAX = 120;

    public function __construct(
        public readonly bool $configured,
        public readonly string $source,
        public readonly int $pinVersion,
        public readonly ?CarbonImmutable $updatedAt,
        public readonly ?string $updatedBy,
    ) {}

    /**
     * @param  array<mixed>  $payload  Untrusted response body from the Command Board.
     */
    public static function fromPayload(array $payload): ?self
    {
        $configured = $payload['configured'] ?? null;
        $source = $payload['source'] ?? null;
        if (! is_bool($configured) || ! is_string($source) || ! array_key_exists($source, self::SOURCE_LABELS)) {
            return null;
        }
        $version = $payload['pinVersion'] ?? 0;
        $updatedBy = $payload['updatedBy'] ?? null;

        return new self(
            $configured,
            $source,
            is_int($version) && $version >= 0 ? $version : 0,
            self::parseTimestamp($payload['updatedAt'] ?? null),
            is_string($updatedBy) && trim($updatedBy) !== '' ? mb_substr(trim($updatedBy), 0, self::UPDATED_BY_MAX) : null,
        );
    }

    public function sourceLabel(): string
    {
        return self::SOURCE_LABELS[$this->source];
    }

    /**
     * Plain values for Livewire state.
     *
     * @return array{configured: bool, source: string, source_label: string, pin_version: int, updated_at: string|null, updated_by: string|null}
     */
    public function toArray(): array
    {
        return [
            'configured' => $this->configured,
            'source' => $this->source,
            'source_label' => $this->sourceLabel(),
            'pin_version' => $this->pinVersion,
            'updated_at' => $this->updatedAt?->toIso8601String(),
            'updated_by' => $this->updatedBy,
        ];
    }

    private static function parseTimestamp(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
