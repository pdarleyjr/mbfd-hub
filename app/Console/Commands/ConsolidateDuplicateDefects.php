<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Filament\Support\DefectCorrectiveActions;
use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\User;
use App\Services\Defects\DefectConsolidationService;
use App\Services\Defects\DuplicateDefectFinder;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Merges open reports of the same equipment issue on one vehicle (filed before submit joined them
 * automatically) into the oldest report, so every member follows ONE issue that is resolved once.
 * Dry run unless --apply; writing needs --actor, someone who may act on equipment issues (the same
 * manageCorrectiveAction gate the admin's Resolve uses). Each group merges in its own transaction: a group
 * that fails is rolled back, reported, and the rest carry on; the command then exits non-zero.
 */
final class ConsolidateDuplicateDefects extends Command
{
    private const DEFAULT_REASON = 'Duplicate reports of the same issue on this vehicle.';

    protected $signature = 'defects:consolidate-duplicates
                            {--apply : Merge the duplicates; without it nothing is written}
                            {--apparatus= : Only this apparatus id}
                            {--actor= : User id or email recorded as merging them (required with --apply)}
                            {--reason= : Why they are the same issue (recorded on every merge)}';

    protected $description = 'Merge open duplicate equipment issues on the same apparatus into the oldest report (dry run unless --apply)';

    public function handle(DuplicateDefectFinder $finder, DefectConsolidationService $consolidation): int
    {
        $apply = (bool) $this->option('apply');
        $apparatusOption = trim((string) $this->option('apparatus'));
        if ($apparatusOption !== '' && (! ctype_digit($apparatusOption) || ! Apparatus::query()->whereKey((int) $apparatusOption)->exists())) {
            $this->error('--apparatus must be the id of an existing apparatus.');

            return self::FAILURE;
        }
        $actor = $apply ? $this->actor() : null;
        if ($apply && $actor === null) {
            $this->error('--apply needs --actor=<user id or email> of an active user who may act on equipment issues.');

            return self::FAILURE;
        }
        $reason = trim((string) $this->option('reason')) ?: self::DEFAULT_REASON;

        $found = $finder->find($apparatusOption !== '' ? (int) $apparatusOption : null);
        $this->possible($found['possible']);
        if ($found['groups'] === []) {
            $this->info('No open duplicate issues found.');

            return self::SUCCESS;
        }

        $rows = [];
        $merged = $failed = 0;
        foreach ($found['groups'] as $group) {
            $outcomes = [];
            if ($actor instanceof User) {
                [$outcomes, $count, $ok] = $this->mergeGroup($consolidation, $group, $actor, $reason);
                $merged += $count;
                $failed += $ok ? 0 : 1;
            }
            foreach ($group['duplicates'] as $duplicate) {
                $rows[] = [$this->unit($group['survivor']), $this->describe($group['survivor']), $this->describe($duplicate),
                    $outcomes[(int) $duplicate->id] ?? ($apply ? 'skipped' : 'would merge')];
            }
        }

        $this->table(['Apparatus', 'Keeps', 'Duplicate', 'Result'], $rows);
        if (! $apply) {
            $this->warn(count($rows).' duplicate issue(s) in '.count($found['groups']).' group(s). Dry run: nothing was changed. Run again with --apply --actor=<user id> to merge them.');

            return self::SUCCESS;
        }
        $this->info("Merged {$merged} duplicate issue(s).");
        if ($failed > 0) {
            $this->error("{$failed} group(s) failed and were left as they were.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  array{survivor: ApparatusDefect, duplicates: list<ApparatusDefect>}  $group
     * @return array{0: array<int, string>, 1: int, 2: bool} outcome per duplicate id, merged count, success
     */
    private function mergeGroup(DefectConsolidationService $consolidation, array $group, User $actor, string $reason): array
    {
        $outcomes = [];
        try {
            $result = $consolidation->merge($group['survivor'], $group['duplicates'], $actor, $reason);
        } catch (Throwable $exception) {
            $why = $exception instanceof ValidationException ? implode(' ', $exception->validator->errors()->all()) : $exception->getMessage();
            foreach ($group['duplicates'] as $duplicate) {
                $outcomes[(int) $duplicate->id] = 'failed, rolled back: '.$why;
            }

            return [$outcomes, 0, false];
        }
        foreach ($result['merged'] as $id) {
            $outcomes[$id] = 'merged';
        }
        foreach ($result['skipped'] as $id) {
            $outcomes[$id] = 'skipped (already merged)';
        }

        return [$outcomes, count($result['merged']), true];
    }

    private function actor(): ?User
    {
        $option = trim((string) $this->option('actor'));
        if ($option === '') {
            return null;
        }
        $actor = ctype_digit($option) ? User::query()->find((int) $option) : User::query()->where('email', $option)->first();

        return $actor instanceof User && DefectCorrectiveActions::canManage($actor) ? $actor : null;
    }

    /** @param  list<list<ApparatusDefect>>  $possible */
    private function possible(array $possible): void
    {
        if ($possible === []) {
            return;
        }
        $rows = [];
        foreach ($possible as $defects) {
            $rows[] = [$this->unit($defects[0]), implode("\n", array_map($this->describe(...), $defects)), 'possible, not merged'];
        }
        $this->warn('Same item text in different places. These are never merged automatically; merge them by hand if they are one issue.');
        $this->table(['Apparatus', 'Reports', 'Result'], $rows);
    }

    private function unit(ApparatusDefect $defect): string
    {
        $apparatus = Apparatus::query()->find($defect->apparatus_id, ['id', 'designation', 'name']);

        return trim((string) ($apparatus?->designation ?: $apparatus?->name)) ?: '#'.$defect->apparatus_id;
    }

    /** "#12 Cab / Radio [cab|cab-item-1]": id, names and stable key, so a reader can check the match. */
    private function describe(ApparatusDefect $defect): string
    {
        $key = $defect->item_key !== null ? ' ['.$defect->compartment_key.'|'.$defect->item_key.']' : ' [no key]';

        return '#'.$defect->id.' '.trim((string) $defect->compartment).' / '.mb_strimwidth(trim((string) $defect->item), 0, 60, '...').$key;
    }
}
