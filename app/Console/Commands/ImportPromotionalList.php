<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Personnel\Promotions\PromotionalListImporter;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

final class ImportPromotionalList extends Command
{
    protected $signature = 'personnel:import-promotional-list
        {csv : Path to the CSV transcription of the official results}
        {--type= : List type key (lieutenant, captain, driver_engineer, division_chief, other)}
        {--label= : Name of the list type when --type=other}
        {--title= : Title as printed, for example "Lieutenant Promotional Results 2025"}
        {--effective= : Effective date (YYYY-MM-DD)}
        {--expires= : Expiry date (YYYY-MM-DD)}
        {--source= : Describes the source document}
        {--status=active : Status for a new list (draft or active)}
        {--apply : Save the import. Without it nothing is written (dry run)}';

    protected $description = 'Import a promotional list from a CSV. Dry run by default; safe to run again.';

    public function handle(PromotionalListImporter $importer): int
    {
        $apply = (bool) $this->option('apply');
        $status = (string) $this->option('status');
        if (! in_array($status, ['draft', 'active'], true)) {
            $this->components->error('--status must be draft or active.');

            return self::FAILURE;
        }
        try {
            $rows = $importer->parse((string) $this->argument('csv'));
            $plan = $importer->plan([
                'list_type' => (string) $this->option('type'), 'type_label' => $this->option('label'), 'title' => (string) $this->option('title'),
                'effective_date' => (string) $this->option('effective'), 'expires_on' => $this->option('expires'),
                'source_reference' => (string) $this->option('source'), 'status' => $status,
            ], $rows);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->components->error($message);
                }
            }

            return self::FAILURE;
        } catch (\InvalidArgumentException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->report($plan);
        if (! $apply) {
            $this->newLine();
            $this->components->info('Dry run. Nothing was saved. Run again with --apply to import.');

            return self::SUCCESS;
        }
        $path = (string) $this->argument('csv');
        $result = $importer->apply($plan, basename($path), (string) hash_file('sha256', $path));
        $this->newLine();
        $this->components->info(sprintf('Imported into list %s: %d created, %d linked, %d unchanged, %d conflicts.',
            $result['list_id'], $result['created'], $result['linked'], $result['unchanged'], $result['conflicts']));

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $plan */
    private function report(array $plan): void
    {
        $this->line($plan['existing_list_id'] === null
            ? 'List: new ('.$plan['list']['title'].', effective '.$plan['list']['effective_date'].')'
            : 'List: already exists ('.$plan['existing_list_id'].'); only missing entries will be added.');
        $this->table(['Orig. rank', 'SOI ID', 'Name', 'Action', 'Personnel match'], array_map(fn (array $row): array => [
            $row['original_rank'], $row['soi_id'] ?? '-', $row['first_name'].' '.$row['last_name'], $row['action'].($row['note'] ? ' *' : ''),
            match ($row['employee']['status']) {
                'matched' => 'link to employee #'.$row['employee']['employee_id'],
                'linked' => 'already linked',
                'ambiguous' => 'review: '.($row['employee']['candidates'] > 1 ? $row['employee']['candidates'].' exact matches' : 'person already used on this list'),
                'unmatched' => 'review: no exact active match',
                default => '-',
            },
        ], $plan['rows']));
        foreach ($plan['rows'] as $row) {
            if ($row['note']) {
                $this->components->warn('Rank '.$row['original_rank'].': '.$row['note']);
            }
        }
        $counts = $plan['counts'];
        $this->line(sprintf('Create %d, unchanged %d, conflicts %d. Exact-name links %d; left for review %d.',
            $counts['create'], $counts['unchanged'], $counts['conflict'], $counts['linked'], $counts['unmatched'] + $counts['ambiguous']));
    }
}
