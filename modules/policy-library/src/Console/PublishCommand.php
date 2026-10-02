<?php

declare(strict_types=1);

namespace Mbfd\PolicyLibrary\Console;

use Illuminate\Console\Command;
use Mbfd\PolicyLibrary\Models\Edition;
use Mbfd\PolicyLibrary\Services\ImportService;

final class PublishCommand extends Command
{
    protected $signature = 'policy-library:publish {edition}';

    protected $description = 'Validate and atomically publish a staged library edition.';

    public function handle(ImportService $imports): int
    {
        $imports->publish(Edition::query()->findOrFail($this->argument('edition')), null);
        $this->info('Edition published.');

        return self::SUCCESS;
    }
}
