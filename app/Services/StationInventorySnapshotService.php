<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Employee;
use App\Models\Station;
use App\Models\StationInventoryItem;
use App\Models\StationInventorySubmission;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class StationInventorySnapshotService
{
    public function submit(Station $station, User $actor, Employee $employee, array $data): StationInventorySubmission
    {
        $existing = StationInventorySubmission::query()->where('client_submission_id', $data['client_submission_id'])->first();
        if ($existing !== null) {
            return $this->ownedSubmission($existing, $station, $actor);
        }

        $items = StationInventoryItem::query()->where('station_id', $station->id)
            ->with('inventoryItem.category')->orderBy('inventory_item_id')->get()
            ->map(fn (StationInventoryItem $item): array => [
                'inventory_item_id' => $item->inventory_item_id,
                'category' => $item->inventoryItem->category?->name ?? 'Uncategorized',
                'name' => $item->inventoryItem->name,
                'sku' => $item->inventoryItem->sku,
                'quantity' => $item->on_hand,
                'unit' => $item->inventoryItem->unit_label,
                'par' => $item->inventoryItem->par_quantity,
            ])->all();
        abort_if($items === [], 422, 'This station has no inventory items to submit.');
        $submittedAt = now();
        $pdf = Pdf::loadView('pdf.station-inventory-snapshot', [
            'station' => $station, 'employee' => $employee, 'items' => $items,
            'shift' => $data['actor_shift'], 'notes' => $data['notes'] ?? null, 'submittedAt' => $submittedAt,
        ]);
        $disk = Storage::disk(config('filesystems.private', 'local'));
        $path = 'inventory-submissions/inventory-'.$station->id.'-'.Str::ulid().'.pdf';
        if (! $disk->put($path, $pdf->output())) {
            throw new \RuntimeException('Unable to save the inventory submission PDF.');
        }
        try {
            return DB::transaction(fn (): StationInventorySubmission => StationInventorySubmission::query()->create([
                'station_id' => $station->id, 'employee_name' => $employee->name,
                'created_by' => $actor->id, 'actor_employee_id' => $employee->id,
                'shift' => $data['actor_shift'], 'items' => $items, 'notes' => $data['notes'] ?? null,
                'pdf_path' => $path, 'submitted_at' => $submittedAt, 'client_submission_id' => $data['client_submission_id'],
            ]));
        } catch (\Throwable $exception) {
            $disk->delete($path);
            $existing = StationInventorySubmission::query()->where('client_submission_id', $data['client_submission_id'])->first();
            if ($existing !== null) {
                return $this->ownedSubmission($existing, $station, $actor);
            }
            throw $exception;
        }
    }

    private function ownedSubmission(StationInventorySubmission $submission, Station $station, User $actor): StationInventorySubmission
    {
        abort_unless($submission->station_id === $station->id && $submission->created_by === $actor->id, 409, 'This submission identifier has already been used.');

        return $submission;
    }
}
