<?php

declare(strict_types=1);

namespace App\Services\DailyRoster;

/** How a roster section header shows its unit: a transparent cutout, a small photo, or the pictogram. */
final readonly class RosterUnitImage
{
    public const CUTOUT = 'cutout';

    public const PHOTO = 'photo';

    public const PICTOGRAM = 'pictogram';

    public function __construct(
        public string $kind,
        public ?string $src = null,
        public ?string $srcset = null,
        public ?int $width = null,
        public ?int $height = null,
    ) {}
}
