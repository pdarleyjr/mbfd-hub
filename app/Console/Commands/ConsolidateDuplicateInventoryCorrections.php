<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ApparatusInventoryCorrection;
use App\Models\User;
use App\Services\Inventory\InventoryCorrectionConsolidator;
use App\Support\Fleet\InventoryCorrectionAccess;
use Illuminate\Console\Command;
use Throwable;

/**
 * Combines open equipment change requests that ask for the same change (filed by different
 * members before submit joined them automatically) into the oldest one. Dry run unless --apply.
 */
final class ConsolidateDuplicateInventoryCorrections extends Command
{
    protected $signature = 'inventory-corrections:consolidate-duplicates
                            {--apply : Combine the duplicates; without it nothing is written}
                            {--actor= : User id or email of the reviewer recorded as combining them (default: the system)}';

    protected $description = 'Combine open duplicate equipment change requests into the oldest request for the same change (dry run unless --apply)';

    public function handle(InventoryCorrectionConsolidator $consolidator): int
    {
        $apply = (bool) $this->option('apply');
        $actor = null;
        $actorOption = trim((string) $this->option('actor'));
        if ($actorOption !== '') {
            $actor = ctype_digit($actorOption)
                ? User::query()->find((int) $actorOption)
                : User::query()->where('email', $actorOption)->first();
            if (! $actor instanceof User || ! InventoryCorrectionAccess::canReview($actor)) {
                $this->error('--actor must name an active user who reviews inventory corrections.');

                return self::FAILURE;
            }
        }

        $groups = $consolidator->duplicateGroups();
        if ($groups === []) {
            $this->info('No open duplicate requests found.');

            return self::SUCCESS;
        }

        $rows = [];
        $candidates = 0;
        $merged = 0;
        $skipped = 0;
        foreach ($groups as $group) {
            $survivor = $group['survivor'];
            foreach ($group['duplicates'] as $duplicate) {
                $outcome = 'would combine';
                if ($apply) {
                    try {
                        $done = $consolidator->merge((int) $survivor->getKey(), (int) $duplicate->getKey(), $actor);
                    } catch (Throwable $exception) {
                        $this->error("{$duplicate->reference()} into {$survivor->reference()} failed and was rolled back: {$exception->getMessage()}");

                        return self::FAILURE;
                    }
                    $done ? $merged++ : $skipped++;
                    $outcome = $done ? 'combined' : 'skipped (changed meanwhile)';
                }
                $rows[] = [$this->unit($survivor), $this->subject($survivor), $survivor->reference(), $duplicate->reference(), 'same', $outcome];
                $candidates++;
            }
            // Never combined: the members would be told a decision on a change they did not ask for.
            foreach ($group['different'] as $different) {
                $rows[] = [$this->unit($survivor), $this->subject($survivor), $survivor->reference(), $different->reference(), 'different', 'different proposal, not merged'];
            }
        }

        $this->table(['Apparatus', 'Item', 'Keeps', 'Duplicate', 'Proposal', 'Result'], $rows);
        if (! $apply) {
            $this->warn("{$candidates} duplicate request(s) can be combined; ".(count($rows) - $candidates).' propose something different and stay open. Dry run: nothing was changed. Run again with --apply to combine them.');

            return self::SUCCESS;
        }
        $this->info("Combined {$merged} duplicate request(s); {$skipped} skipped.");

        return self::SUCCESS;
    }

    private function unit(ApparatusInventoryCorrection $correction): string
    {
        $apparatus = $correction->apparatus;

        return trim((string) ($apparatus?->designation ?: $apparatus?->name ?: $apparatus?->getAttribute('unit_id'))) ?: '#'.$correction->apparatus_id;
    }

    private function subject(ApparatusInventoryCorrection $correction): string
    {
        $snapshot = (array) $correction->target_snapshot;
        $name = $snapshot['name'] ?? 'New: '.(((array) $correction->proposal)['name'] ?? 'unnamed');

        return $name.' ('.($snapshot['compartment'] ?? $correction->compartment_key).', '.$correction->reason.')';
    }
}
