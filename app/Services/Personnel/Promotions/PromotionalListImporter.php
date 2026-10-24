<?php

declare(strict_types=1);

namespace App\Services\Personnel\Promotions;

use App\Models\PromotionalList;
use App\Models\PromotionalListEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Imports a promotional list from a CSV transcription of the official results.
 *
 * The importer never guesses. A re-run does not duplicate anything: a list is found by type and
 * effective date, an entry by SOI ID or original rank. Entries that already exist are left alone
 * (a difference is reported as a conflict, never overwritten). A person is linked only when the
 * printed first and last name match exactly one active employee; everything else stays unlinked
 * and is listed for review.
 */
final class PromotionalListImporter
{
    /** CSV columns, in the order of the printed results. */
    public const COLUMNS = ['original_rank', 'soi_id', 'last_name', 'first_name', 'written', 'oral', 'composite', 'seniority', 'education', 'max_promotional', 'veteran', 'total'];

    public function __construct(
        private readonly PromotionalListService $service,
        private readonly EmployeeNameMatcher $matcher,
    ) {}

    /**
     * Read the CSV. Blank lines and lines starting with # are ignored.
     *
     * @return list<array{line: int, original_rank: int, soi_id: string|null, last_name: string, first_name: string, scores: array<string, string>}>
     */
    public function parse(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new \InvalidArgumentException('The CSV file could not be read.');
        }
        if (filesize($path) > (int) config('promotional_lists.import.max_bytes')) {
            throw new \InvalidArgumentException('The CSV file is larger than the 1 MB limit.');
        }
        $handle = fopen($path, 'rb');
        $rows = [];
        $header = null;
        $line = 0;
        try {
            while (($cells = fgetcsv($handle, 2000, ',', '"', '')) !== false) {
                $line++;
                if ($line === 1 && isset($cells[0])) {
                    $cells[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $cells[0]);
                }
                if ($cells === [null] || trim(implode('', array_map('strval', $cells))) === '' || str_starts_with(trim((string) $cells[0]), '#')) {
                    continue;
                }
                if ($header === null) {
                    $header = array_map(fn ($cell): string => strtolower(trim((string) $cell)), $cells);
                    if ($header !== self::COLUMNS) {
                        throw new \InvalidArgumentException('The CSV header must be exactly: '.implode(',', self::COLUMNS));
                    }

                    continue;
                }
                $rows[] = $this->row($cells, $line);
                if (count($rows) > (int) config('promotional_lists.import.max_rows')) {
                    throw new \InvalidArgumentException('The CSV has more than '.config('promotional_lists.import.max_rows').' rows.');
                }
            }
        } finally {
            fclose($handle);
        }
        if ($header === null || $rows === []) {
            throw new \InvalidArgumentException('The CSV has no header or no rows.');
        }
        $this->assertUniqueInFile($rows);

        return $rows;
    }

    /**
     * Decide, without saving anything, what applying this CSV would do.
     *
     * @param  array<string, mixed>  $listAttributes
     * @param  list<array{line: int, original_rank: int, soi_id: string|null, last_name: string, first_name: string, scores: array<string, string>}>  $rows
     * @return array{list: array<string, mixed>, existing_list_id: string|null, rows: list<array<string, mixed>>, counts: array<string, int>}
     */
    public function plan(array $listAttributes, array $rows): array
    {
        $list = $this->service->validatedList($listAttributes);
        $existing = $this->findList($list);
        $entries = $existing?->entries()->get() ?? collect();
        $bySoi = $entries->filter(fn (PromotionalListEntry $entry): bool => $entry->soi_id !== null)->keyBy('soi_id');
        $byRank = $entries->keyBy('original_rank');
        $claimed = $entries->whereNotNull('employee_id')->pluck('employee_id')->flip()->all();
        $planned = [];
        $counts = ['create' => 0, 'unchanged' => 0, 'conflict' => 0, 'linked' => 0, 'unmatched' => 0, 'ambiguous' => 0];
        foreach ($rows as $row) {
            $soiEntry = $row['soi_id'] !== null ? $bySoi->get($row['soi_id']) : null;
            $rankEntry = $byRank->get($row['original_rank']);
            $entry = $soiEntry ?? $rankEntry;
            $action = 'create';
            $note = null;
            if ($entry !== null) {
                if ($soiEntry !== null && $rankEntry !== null && $soiEntry->is($rankEntry) && $this->sameAsPrinted($entry, $row)) {
                    $action = 'unchanged';
                } else {
                    $action = 'conflict';
                    $note = 'Differs from the stored entry (original rank '.$entry->original_rank.'). Edit it in Promotional Lists; the import never overwrites.';
                }
            }
            $employee = ['status' => 'none', 'employee_id' => null, 'candidates' => 0];
            if (in_array($action, ['create', 'unchanged'], true) && ($entry === null || $entry->employee_id === null)) {
                $employee = $this->match($row, $claimed);
                if ($employee['employee_id'] !== null) {
                    $claimed[$employee['employee_id']] = true;
                }
            } elseif ($entry?->employee_id !== null) {
                $employee = ['status' => 'linked', 'employee_id' => (int) $entry->employee_id, 'candidates' => 1];
            }
            $counts[$action]++;
            if ($employee['status'] === 'matched') {
                $counts['linked']++;
            } elseif (in_array($employee['status'], ['unmatched', 'ambiguous'], true)) {
                $counts[$employee['status']]++;
            }
            $planned[] = [...$row, 'action' => $action, 'note' => $note, 'existing_entry_id' => $entry?->id, 'employee' => $employee];
        }

        return ['list' => $list, 'existing_list_id' => $existing?->id, 'rows' => $planned, 'counts' => $counts];
    }

    /**
     * Save a plan. Idempotent: running the same plan again changes nothing.
     *
     * @param  array{list: array<string, mixed>, existing_list_id: string|null, rows: list<array<string, mixed>>, counts: array<string, int>}  $plan
     * @return array{list_id: string, created_list: bool, created: int, linked: int, conflicts: int, unchanged: int}
     */
    public function apply(array $plan, string $sourceName, string $sourceHash): array
    {
        return DB::transaction(function () use ($plan, $sourceName, $sourceHash): array {
            $createdList = false;
            $list = $this->findList($plan['list']);
            if ($list === null) {
                $list = $this->service->createList(null, $plan['list']);
                $createdList = true;
            }
            $created = $linked = 0;
            foreach ($plan['rows'] as $row) {
                $employeeId = $row['employee']['status'] === 'matched' ? $row['employee']['employee_id'] : null;
                if ($row['action'] === 'create') {
                    $this->service->addEntry(null, $list, [
                        'original_rank' => $row['original_rank'], 'soi_id' => $row['soi_id'], 'last_name' => $row['last_name'],
                        'first_name' => $row['first_name'], 'scores' => $row['scores'], 'employee_id' => $employeeId,
                    ], false, ['source' => 'import']);
                    $created++;
                    $linked += $employeeId === null ? 0 : 1;
                } elseif ($row['action'] === 'unchanged' && $employeeId !== null) {
                    $entry = PromotionalListEntry::query()->findOrFail($row['existing_entry_id']);
                    try {
                        $this->service->linkEmployee(null, $entry, (int) $employeeId, ['source' => 'import']);
                        $linked++;
                    } catch (ValidationException) {
                        // Someone linked that person to another entry since the plan; leave this one for review.
                    }
                }
            }
            $this->service->recomputeRanks($list->fresh(), null, 'import');
            $this->service->recordEvent(null, $list, 'imported', null, [
                'source_file' => $sourceName, 'sha256' => $sourceHash, 'rows' => count($plan['rows']), 'created' => $created, 'linked' => $linked,
                'unchanged' => $plan['counts']['unchanged'], 'conflicts' => $plan['counts']['conflict'], 'created_list' => $createdList,
            ]);

            return ['list_id' => $list->id, 'created_list' => $createdList, 'created' => $created, 'linked' => $linked,
                'conflicts' => $plan['counts']['conflict'], 'unchanged' => $plan['counts']['unchanged']];
        }, 3);
    }

    /** @param array<string, mixed> $list */
    private function findList(array $list): ?PromotionalList
    {
        return PromotionalList::query()->where('list_type', $list['list_type'])->whereDate('effective_date', $list['effective_date'])
            ->when($list['list_type'] === PromotionalList::TYPE_OTHER, fn ($query) => $query->where('type_label', $list['type_label']))->first();
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<int, bool>  $claimed  employee ids already linked on this list or earlier in this plan
     * @return array{status: string, employee_id: int|null, candidates: int}
     */
    private function match(array $row, array $claimed): array
    {
        $candidates = $this->matcher->candidates($row['first_name'], $row['last_name'], true);
        if ($candidates->isEmpty()) {
            return ['status' => 'unmatched', 'employee_id' => null, 'candidates' => 0];
        }
        $id = (int) $candidates->first()->id;
        if ($candidates->count() > 1 || isset($claimed[$id])) {
            return ['status' => 'ambiguous', 'employee_id' => null, 'candidates' => $candidates->count()];
        }

        return ['status' => 'matched', 'employee_id' => $id, 'candidates' => 1];
    }

    /** @param array<string, mixed> $row */
    private function sameAsPrinted(PromotionalListEntry $entry, array $row): bool
    {
        $scores = (array) $entry->scores;
        ksort($scores);
        $printed = $row['scores'];
        ksort($printed);

        return $entry->original_rank === $row['original_rank'] && $entry->soi_id === $row['soi_id']
            && $entry->last_name === $row['last_name'] && $entry->first_name === $row['first_name'] && $scores === $printed;
    }

    /**
     * @param  array<int, string|null>  $cells
     * @return array{line: int, original_rank: int, soi_id: string|null, last_name: string, first_name: string, scores: array<string, string>}
     */
    private function row(array $cells, int $line): array
    {
        if (count($cells) !== count(self::COLUMNS)) {
            throw new \InvalidArgumentException('Line '.$line.' has '.count($cells).' columns; expected '.count(self::COLUMNS).'.');
        }
        $values = array_combine(self::COLUMNS, array_map(fn ($cell): string => trim((string) $cell), $cells));
        if (preg_match('/^[1-9][0-9]{0,3}$/', $values['original_rank']) !== 1) {
            throw new \InvalidArgumentException('Line '.$line.': original_rank must be a whole number from 1 to 9999.');
        }
        if ($values['last_name'] === '' || $values['first_name'] === '') {
            throw new \InvalidArgumentException('Line '.$line.': first and last name are required.');
        }
        $scores = array_diff_key($values, array_flip(['original_rank', 'soi_id', 'last_name', 'first_name']));
        foreach ($scores as $score) {
            if (mb_strlen($score) > 20) {
                throw new \InvalidArgumentException('Line '.$line.': a score value is longer than 20 characters.');
            }
        }

        return ['line' => $line, 'original_rank' => (int) $values['original_rank'], 'soi_id' => $values['soi_id'] === '' ? null : $values['soi_id'],
            'last_name' => $values['last_name'], 'first_name' => $values['first_name'], 'scores' => $scores];
    }

    /** @param list<array<string, mixed>> $rows */
    private function assertUniqueInFile(array $rows): void
    {
        $ranks = [];
        $soi = [];
        foreach ($rows as $row) {
            if (isset($ranks[$row['original_rank']])) {
                throw new \InvalidArgumentException('Line '.$row['line'].': original rank '.$row['original_rank'].' appears twice.');
            }
            $ranks[$row['original_rank']] = true;
            if ($row['soi_id'] !== null) {
                if (isset($soi[$row['soi_id']])) {
                    throw new \InvalidArgumentException('Line '.$row['line'].': SOI ID '.$row['soi_id'].' appears twice.');
                }
                $soi[$row['soi_id']] = true;
            }
        }
    }
}
