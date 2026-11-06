<?php

declare(strict_types=1);

namespace Tests\Feature\Ems\Concerns;

use App\Services\Ems\Import\SourceImporter;
use Illuminate\Support\Facades\File;

/**
 * Imports one tracked extraction from database/data/ems on its own. A sheet test only needs its own list;
 * importing the whole folder (with the large supply-room source) in every setUp made each sheet test take minutes.
 */
trait ImportsTrackedList
{
    private ?string $trackedListDirectory = null;

    /** @return array{counts: array<string, int>, messages: list<string>, dry_run: bool} */
    protected function importTrackedList(string $file): array
    {
        if ($this->trackedListDirectory === null) {
            $this->trackedListDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ems-sheet-'.bin2hex(random_bytes(6));
            File::ensureDirectoryExists($this->trackedListDirectory);
            File::copy(base_path('database/data/ems/'.$file), $this->trackedListDirectory.DIRECTORY_SEPARATOR.$file);
            $this->beforeApplicationDestroyed(fn () => File::deleteDirectory((string) $this->trackedListDirectory));
        }

        return app(SourceImporter::class)->import($this->trackedListDirectory);
    }
}
