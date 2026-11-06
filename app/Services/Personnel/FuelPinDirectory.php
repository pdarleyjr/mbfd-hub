<?php

declare(strict_types=1);

namespace App\Services\Personnel;

use App\Models\Employee;
use App\Models\FuelIdEvent;
use App\Models\FuelIdRow;
use App\Models\FuelIdSource;
use App\Models\User;
use App\Services\Personnel\FuelIds\FuelIdAccess;
use App\Services\Personnel\FuelIds\FuelIdImportException;
use App\Services\Personnel\FuelIds\FuelPinView;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Read and administer current Fuel PINs.
 *
 * currentFor() is the one read path for an employee's own PIN (Employee Portal) and for an authorized admin view of
 * one profile; callers outside the portal must check FuelIdAccess::canView() first. Revealing a PIN on the admin page
 * goes through reveal(), which checks access and writes an audit event. Nothing here caches a PIN or logs it.
 */
final class FuelPinDirectory
{
    public function currentFor(Employee $employee): ?FuelPinView
    {
        $row = $this->currentRow((int) $employee->getKey());
        if ($row === null) {
            return null;
        }

        return new FuelPinView(
            pin: (string) $row->prompt_id,
            matchStatus: $row->match_status,
            sourceStatus: $row->status,
            department: $row->department,
            sourceName: (string) $row->source?->original_name,
            importedAt: $row->source?->imported_at,
            employeeDeparted: $row->employee_departed,
            rowId: (int) $row->id,
        );
    }

    public function currentRow(int $employeeId): ?FuelIdRow
    {
        return FuelIdRow::query()->with('source')
            ->where('current_employee_id', $employeeId)
            ->whereIn('match_status', FuelIdRow::ASSIGNED)
            ->first();
    }

    /** Reveal one PIN to an authorized administrator and audit it. */
    public function reveal(User $actor, FuelIdRow $row): string
    {
        abort_unless(FuelIdAccess::canView($actor), 403);
        $this->record(FuelIdEvent::REVEALED, $actor, row: $row, employeeId: $row->employee_id ?? $row->candidate_employee_id, metadata: [
            'match_status' => $row->match_status,
        ]);

        return (string) $row->prompt_id;
    }

    /**
     * Make $row the employee's one current PIN; the previous current row becomes superseded.
     *
     * @return bool True when an older row was superseded.
     */
    public function makeCurrent(FuelIdRow $row, int $employeeId): bool
    {
        $previous = FuelIdRow::query()->where('current_employee_id', $employeeId)->lockForUpdate()->first();
        $superseded = false;
        if ($previous instanceof FuelIdRow && $previous->id !== $row->id) {
            $previous->forceFill([
                'current_employee_id' => null,
                'match_status' => FuelIdRow::SUPERSEDED,
                'superseded_by_row_id' => $row->id,
                'superseded_at' => now(),
            ])->save();
            $superseded = true;
        }
        $row->forceFill(['current_employee_id' => $employeeId, 'employee_id' => $employeeId])->save();

        return $superseded;
    }

    /** Assign an exception row to the chosen employee. */
    public function resolve(User $actor, FuelIdRow $row, Employee $employee, string $note): FuelIdRow
    {
        abort_unless(FuelIdAccess::canManage($actor), 403);
        $note = $this->requiredNote($note);

        return DB::transaction(function () use ($actor, $row, $employee, $note): FuelIdRow {
            $row = FuelIdRow::query()->lockForUpdate()->findOrFail($row->getKey());
            if (! $row->isOpenException()) {
                throw new FuelIdImportException('This row has already been decided.');
            }
            $previousStatus = $row->match_status;
            $row->forceFill([
                'match_status' => FuelIdRow::RESOLVED,
                'resolved_by_user_id' => $actor->getKey(),
                'resolved_at' => now(),
                'resolution_note' => $note,
                'employee_departed' => $employee->roster_status === 'departed',
            ])->save();
            $this->makeCurrent($row, (int) $employee->getKey());
            $this->record(FuelIdEvent::RESOLVED, $actor, row: $row, employeeId: (int) $employee->getKey(), metadata: [
                'previous_status' => $previousStatus,
                'source_employee_number' => $row->source_employee_number,
            ]);

            return $row->refresh();
        });
    }

    public function dismiss(User $actor, FuelIdRow $row, string $note): FuelIdRow
    {
        abort_unless(FuelIdAccess::canManage($actor), 403);
        $note = $this->requiredNote($note);

        return DB::transaction(function () use ($actor, $row, $note): FuelIdRow {
            $row = FuelIdRow::query()->lockForUpdate()->findOrFail($row->getKey());
            if (! $row->isOpenException()) {
                throw new FuelIdImportException('This row has already been decided.');
            }
            $previousStatus = $row->match_status;
            $row->forceFill([
                'match_status' => FuelIdRow::DISMISSED,
                'resolved_by_user_id' => $actor->getKey(),
                'resolved_at' => now(),
                'resolution_note' => $note,
            ])->save();
            $this->record(FuelIdEvent::DISMISSED, $actor, row: $row, metadata: [
                'previous_status' => $previousStatus,
                'source_employee_number' => $row->source_employee_number,
            ]);

            return $row;
        });
    }

    /** @return Builder<FuelIdRow> Current PIN rows with their employee, filtered by name or employee ID. */
    public function mappingQuery(string $search = ''): Builder
    {
        $search = trim(mb_substr($search, 0, 100));

        return FuelIdRow::query()->with(['employee:id,employee_id,name,rank,roster_status', 'source:id,original_name,imported_at'])
            ->whereNotNull('current_employee_id')
            ->when($search !== '', function (Builder $query) use ($search): void {
                $like = '%'.mb_strtolower(addcslashes($search, '%_\\')).'%';
                $query->where(function (Builder $query) use ($like): void {
                    $query->whereRaw('LOWER(source_employee_number) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(last_name) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(first_name) LIKE ?', [$like])
                        ->orWhereHas('employee', fn (Builder $employee) => $employee->where(fn (Builder $nested) => $nested
                            ->whereRaw('LOWER(name) LIKE ?', [$like])
                            ->orWhereRaw('LOWER(employee_id) LIKE ?', [$like])));
                });
            })
            ->orderBy('last_name')->orderBy('first_name')->orderBy('id');
    }

    /** @return Builder<FuelIdRow> */
    public function exceptionsQuery(): Builder
    {
        return FuelIdRow::query()->with(['candidateEmployee:id,employee_id,name,roster_status'])
            ->whereIn('match_status', FuelIdRow::OPEN_EXCEPTIONS)
            ->orderBy('normalized_employee_number')->orderBy('row_number');
    }

    public function latestSource(): ?FuelIdSource
    {
        return FuelIdSource::query()->latest('imported_at')->latest('id')->first();
    }

    /** @param array<string, mixed> $metadata */
    public function record(
        string $action,
        ?User $actor,
        ?FuelIdSource $source = null,
        ?FuelIdRow $row = null,
        ?int $employeeId = null,
        array $metadata = [],
    ): void {
        FuelIdEvent::query()->create([
            'action' => $action,
            'actor_user_id' => $actor?->getKey(),
            'fuel_id_source_id' => $source?->getKey() ?? $row?->fuel_id_source_id,
            'fuel_id_row_id' => $row?->getKey(),
            'employee_id' => $employeeId,
            'metadata' => $metadata,
        ]);
    }

    private function requiredNote(string $note): string
    {
        $note = trim($note);
        if ($note === '') {
            throw new FuelIdImportException('A note is required.');
        }

        return mb_substr($note, 0, 1000);
    }
}
