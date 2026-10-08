<?php

declare(strict_types=1);

namespace Mbfd\PolicyLibrary\Console;

use Illuminate\Console\Command;
use Mbfd\PolicyLibrary\Models\Manual;
use Mbfd\PolicyLibrary\Services\ManualDeliveryService;

final class DeliveryCommand extends Command
{
    protected $signature = 'policy-library:delivery {slug : sogs or medical-protocols} {--manifest=} {--pdf=} {--qa=}';

    protected $description = 'Inspect current source bindings, or install an independently reviewed complete-manual delivery copy.';

    public function handle(ManualDeliveryService $deliveries): int
    {
        try {
            $manual = Manual::query()->where('slug', $this->argument('slug'))->firstOrFail();
            if (! $this->option('manifest') && ! $this->option('pdf') && ! $this->option('qa')) {
                $this->line(json_encode($deliveries->snapshot($manual), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

                return self::SUCCESS;
            }
            foreach (['manifest', 'pdf', 'qa'] as $input) {
                if (is_link((string) $this->option($input))) {
                    throw new \RuntimeException('Delivery inputs must be regular files, not symbolic links.');
                }
            }
            $path = realpath((string) $this->option('manifest'));
            $pdf = realpath((string) $this->option('pdf'));
            $qa = realpath((string) $this->option('qa'));
            if (! $path || ! is_file($path) || filesize($path) > 1048576 || ! $pdf || ! is_file($pdf) || ! $qa || ! is_file($qa)) {
                throw new \RuntimeException('Provide a delivery manifest, its validated PDF, and the independent QA receipt.');
            }
            $manifest = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            $delivery = $deliveries->install($manual, $manifest, $pdf, $qa);
            $this->line(json_encode(['manual' => $manual->slug, 'edition_id' => $delivery['edition_id'],
                'sha256' => $delivery['sha256'], 'page_count' => $delivery['page_count'], 'byte_size' => $delivery['byte_size']], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            report($exception);
            $this->error('Complete-manual delivery preparation failed. Current policy revisions were preserved.');

            return self::FAILURE;
        }
    }
}
